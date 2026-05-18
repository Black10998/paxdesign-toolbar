# Build WordPress-installable ZIP: paxdesign-toolbar-<version>.zip
# Run from repository root: .\scripts\build-release.ps1
#
# Required ZIP layout (WordPress → Plugins → Add New → Upload Plugin):
#   paxdesign-toolbar/paxdesign-toolbar.php
#   paxdesign-toolbar/includes/
#   paxdesign-toolbar/assets/
#   paxdesign-toolbar/templates/

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

# Sanity: refuse double-nested staging.
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

# Match GitHub Actions: zip the paxdesign-toolbar folder inside staging (one root in archive).
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory(
    $stagingRoot,
    $zipPath,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $false
)

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

$verifyLegacy = Join-Path $PSScriptRoot 'verify-release-zip.ps1'
if (Test-Path $verifyLegacy) {
    & $verifyLegacy -ZipPath $zipPath
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
