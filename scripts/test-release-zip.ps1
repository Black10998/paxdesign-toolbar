# Post-build QA: lint every PHP file inside a release ZIP + optional upgrade simulation.
param(
    [Parameter(Mandatory = $true)]
    [string]$ZipPath,
    [string]$FromZip = ''
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent

if (-not (Test-Path $ZipPath)) {
    throw "ZIP not found: $ZipPath"
}

$staging = Join-Path $env:TEMP "pdx-zip-qa-$(Get-Random)"
Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue
Expand-Archive $ZipPath -DestinationPath $staging -Force

$pluginDir = Join-Path $staging 'paxdesign-toolbar'
if (-not (Test-Path $pluginDir)) {
    throw 'ZIP must contain paxdesign-toolbar/ at root'
}

$env:PHP_BIN = $env:PHP_BIN
& (Join-Path $PSScriptRoot 'lint-php.ps1') -PluginDir $pluginDir
if ($LASTEXITCODE -ne 0) {
    exit 1
}

$main = Join-Path $pluginDir 'paxdesign-toolbar.php'
$mainContent = Get-Content $main -Raw
if ($mainContent -notmatch "define\s*\(\s*'PDX_VERSION'\s*,\s*'([^']+)'\s*\)") {
    throw 'PDX_VERSION missing in release ZIP'
}
$ver = $Matches[1]
Write-Host "OK: Release ZIP version $ver"

if ($FromZip -and (Test-Path $FromZip)) {
    & (Join-Path $PSScriptRoot 'simulate-wp-upgrade.ps1') -FromZip $FromZip -ToZip $ZipPath
}

Remove-Item $staging -Recurse -Force
Write-Host 'Release ZIP QA passed.' -ForegroundColor Green
