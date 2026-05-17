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
$stagingDir  = Join-Path $env:TEMP "pdx-release-$version"
$zipName     = "paxdesign-toolbar-$version.zip"
$zipPath     = Join-Path $releasesDir $zipName

if (Test-Path $stagingDir) { Remove-Item $stagingDir -Recurse -Force }
New-Item -ItemType Directory -Path (Join-Path $stagingDir 'paxdesign-toolbar') -Force | Out-Null

$exclude = @('.git', '.github', '.devcontainer', 'releases', 'scripts', 'node_modules', '.cursor')
Get-ChildItem (Join-Path $root 'paxdesign-toolbar') -Recurse -Force | ForEach-Object {
    $rel = $_.FullName.Substring((Join-Path $root 'paxdesign-toolbar').Length + 1)
    $skip = $false
    foreach ($e in $exclude) {
        if ($rel -like "$e*") { $skip = $true; break }
    }
    if ($skip) { return }
    $dest = Join-Path (Join-Path $stagingDir 'paxdesign-toolbar') $rel
    if ($_.PSIsContainer) {
        New-Item -ItemType Directory -Path $dest -Force | Out-Null
    } else {
        $destDir = Split-Path $dest -Parent
        if (-not (Test-Path $destDir)) { New-Item -ItemType Directory -Path $destDir -Force | Out-Null }
        Copy-Item $_.FullName $dest -Force
    }
}

New-Item -ItemType Directory -Path $releasesDir -Force | Out-Null
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

# Archive MUST contain exactly one root folder: paxdesign-toolbar/
# (Compress-Archive -Path folder can omit the wrapper on some hosts — use .NET ZIP API.)
Add-Type -AssemblyName System.IO.Compression.FileSystem
$sourceFolder = Join-Path $stagingDir 'paxdesign-toolbar'
if (-not (Test-Path $sourceFolder)) { throw "Staging folder missing: $sourceFolder" }
[System.IO.Compression.ZipFile]::CreateFromDirectory(
    $sourceFolder,
    $zipPath,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $true
)

Remove-Item $stagingDir -Recurse -Force

$hash = (Get-FileHash $zipPath -Algorithm SHA256).Hash
Write-Host "Built: $zipPath"
Write-Host "Version: $version"
Write-Host "SHA256: $hash"

$verify = Join-Path $PSScriptRoot 'verify-release-zip.ps1'
if (Test-Path $verify) {
    & $verify -ZipPath $zipPath
}

$smoke = Join-Path $PSScriptRoot 'wp-bootstrap-smoke.php'
if (Test-Path $smoke) {
    if (-not $env:PHP_BIN) {
        $localPhp = Join-Path $root '.tools\php\php.exe'
        if (Test-Path $localPhp) { $env:PHP_BIN = $localPhp }
    }
    if ($env:PHP_BIN) {
        & $env:PHP_BIN $smoke
        if ($LASTEXITCODE -ne 0) {
            throw 'Plugin bootstrap smoke test failed.'
        }
    }
}
