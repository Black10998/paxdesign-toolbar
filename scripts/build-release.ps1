# Build WordPress-installable ZIP: paxdesign-toolbar-<version>.zip
# Run from repository root: .\scripts\build-release.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Set-Location $root

$mainFile = Join-Path $root 'paxdesign-toolbar\paxdesign-toolbar.php'
if (-not (Test-Path $mainFile)) {
    throw "Plugin main file not found: $mainFile"
}

$content = Get-Content $mainFile -Raw
if ($content -match "define\s*\(\s*'PDX_VERSION'\s*,\s*'([^']+)'\s*\)") {
    $version = $Matches[1]
} else {
    throw 'Could not read PDX_VERSION from paxdesign-toolbar.php'
}

$lint = Join-Path $PSScriptRoot 'lint-php.ps1'
if (Test-Path $lint) {
    & $lint
    if ($LASTEXITCODE -ne 0) {
        throw 'PHP lint failed — fix parse errors before building a release.'
    }
}

$releasesDir = Join-Path $root 'releases'
$stagingRoot = Join-Path $env:TEMP "pdx-release-$version"
$pluginRoot  = Join-Path $stagingRoot 'paxdesign-toolbar'
$zipName     = "paxdesign-toolbar-$version.zip"
$zipPath     = Join-Path $releasesDir $zipName

if (Test-Path $stagingRoot) { Remove-Item $stagingRoot -Recurse -Force }
New-Item -ItemType Directory -Path $pluginRoot -Force | Out-Null

$exclude = @('.git', '.github', '.devcontainer', 'releases', 'scripts', 'node_modules', '.cursor')
$sourceRoot = Join-Path $root 'paxdesign-toolbar'
Get-ChildItem $sourceRoot -Recurse -Force | ForEach-Object {
    $rel = $_.FullName.Substring($sourceRoot.Length + 1)
    $skip = $false
    foreach ($e in $exclude) {
        if ($rel -like "$e*") { $skip = $true; break }
    }
    if ($skip) { return }

    $dest = Join-Path $pluginRoot $rel
    if ($_.PSIsContainer) {
        New-Item -ItemType Directory -Path $dest -Force | Out-Null
    } else {
        $destDir = Split-Path $dest -Parent
        if (-not (Test-Path $destDir)) { New-Item -ItemType Directory -Path $destDir -Force | Out-Null }
        Copy-Item $_.FullName $dest -Force
    }
}

$nestedMain = Join-Path $pluginRoot 'paxdesign-toolbar\paxdesign-toolbar.php'
if (Test-Path $nestedMain) {
    throw "Staging is double-nested ($nestedMain). Fix source tree before building."
}
$stagedMain = Join-Path $pluginRoot 'paxdesign-toolbar.php'
if (-not (Test-Path $stagedMain)) {
    throw "Staging missing main file: $stagedMain"
}

New-Item -ItemType Directory -Path $releasesDir -Force | Out-Null
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

# Forward-slash entry names (WordPress/Linux expect paxdesign-toolbar/... not backslashes).
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipStream = [System.IO.File]::Create($zipPath)
$zip       = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)

Get-ChildItem $pluginRoot -Recurse -File | ForEach-Object {
    $rel = $_.FullName.Substring($pluginRoot.Length).TrimStart('\', '/').Replace('\', '/')
    $entryName = "paxdesign-toolbar/$rel"
    [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
        $zip,
        $_.FullName,
        $entryName,
        [System.IO.Compression.CompressionLevel]::Optimal
    )
}

$zip.Dispose()
$zipStream.Dispose()
Remove-Item $stagingRoot -Recurse -Force

$hash = (Get-FileHash $zipPath -Algorithm SHA256).Hash
Write-Host "Built: $zipPath"
Write-Host "Version: $version"
Write-Host "SHA256: $hash"

$verify = Join-Path $PSScriptRoot 'verify-wp-plugin-zip.ps1'
if (-not (Test-Path $verify)) {
    throw "Missing verifier: $verify"
}
& $verify -ZipPath $zipPath

if (-not $env:PHP_BIN) {
    $localPhp = Join-Path $root '.tools\php\php.exe'
    if (Test-Path $localPhp) { $env:PHP_BIN = $localPhp }
}
if ($env:PHP_BIN) {
    $smoke = Join-Path $PSScriptRoot 'wp-bootstrap-smoke.php'
    if (Test-Path $smoke) {
        & $env:PHP_BIN $smoke
        if ($LASTEXITCODE -ne 0) { throw 'Plugin bootstrap smoke test failed.' }
    }
    $hasZipArchive = & $env:PHP_BIN -r "echo class_exists('ZipArchive') ? '1' : '0';"
    if ($hasZipArchive -eq '1') {
        $detect = Join-Path $PSScriptRoot 'simulate-wp-plugin-detect.php'
        if (Test-Path $detect) {
            & $env:PHP_BIN $detect $zipPath
            if ($LASTEXITCODE -ne 0) { throw 'WordPress plugin detection simulation failed.' }
        }
    } else {
        $tmpDetect = Join-Path $env:TEMP "pdx-wp-detect-$(Get-Random)"
        Expand-Archive -Path $zipPath -DestinationPath $tmpDetect -Force
        $detectMain = Join-Path $tmpDetect 'paxdesign-toolbar\paxdesign-toolbar.php'
        if (-not (Test-Path $detectMain)) {
            Remove-Item $tmpDetect -Recurse -Force -ErrorAction SilentlyContinue
            throw "WordPress detection failed: missing $detectMain after extract"
        }
        $hdr = Get-Content $detectMain -TotalCount 15 | Out-String
        if ($hdr -notmatch 'Plugin Name:') {
            Remove-Item $tmpDetect -Recurse -Force -ErrorAction SilentlyContinue
            throw 'WordPress detection failed: Plugin Name header missing'
        }
        Remove-Item $tmpDetect -Recurse -Force -ErrorAction SilentlyContinue
        Write-Host "OK: WordPress would detect paxdesign-toolbar/paxdesign-toolbar.php (Expand-Archive test)"
    }
}
