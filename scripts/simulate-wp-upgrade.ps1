# Simulates WordPress plugin upgrade: 7.1.10 -> target ZIP (default GitHub 8.0.0).
# Usage: .\scripts\simulate-wp-upgrade.ps1 [-FromZip releases\paxdesign-toolbar-7.1.10.zip] [-ToZip releases\gh-paxdesign-toolbar-8.0.0.zip]

param(
    [string]$FromZip = '',
    [string]$ToZip   = '',
    [string]$WorkDir = ''
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
if (-not $WorkDir) { $WorkDir = Join-Path $env:TEMP "pdx-wp-upgrade-test-$(Get-Random)" }

if (-not $FromZip) { $FromZip = Join-Path $root 'releases\paxdesign-toolbar-7.1.10.zip' }
if (-not $ToZip)   { $ToZip   = Join-Path $root 'releases\gh-paxdesign-toolbar-8.0.0.zip' }

foreach ($z in @($FromZip, $ToZip)) {
    if (-not (Test-Path $z)) { throw "ZIP not found: $z" }
}

$pluginsDir = Join-Path $WorkDir 'wp-content\plugins'
$upgradeDir = Join-Path $WorkDir 'wp-content\upgrade'
$pluginDir  = Join-Path $pluginsDir 'paxdesign-toolbar'
$maintenance = Join-Path $WorkDir '.maintenance'

if (Test-Path $WorkDir) { Remove-Item $WorkDir -Recurse -Force }
New-Item -ItemType Directory -Path $pluginsDir -Force | Out-Null
New-Item -ItemType Directory -Path $upgradeDir -Force | Out-Null

function Expand-ZipTo($zip, $dest) {
    if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }
    Expand-Archive -Path $zip -DestinationPath $dest -Force
    $inner = Get-ChildItem $dest -Directory | Select-Object -First 1
    if ($inner.Name -ne 'paxdesign-toolbar') {
        throw "ZIP root must be paxdesign-toolbar/, got: $($inner.Name)"
    }
    return $inner.FullName
}

function Read-PluginVersion($mainPhp) {
    $c = Get-Content $mainPhp -Raw
    if ($c -match "define\s*\(\s*'PDX_VERSION'\s*,\s*'([^']+)'\s*\)") { return $Matches[1] }
    if ($c -match '\*\s*Version:\s*([0-9.]+)') { return $Matches[1] }
    return ''
}

function Test-Health($dir) {
    $manifestPath = Join-Path $dir 'includes\pdx-upgrade-manifest.php'
    $required = @(
        'includes\class-pdx-loader.php',
        'includes\class-pdx-settings.php',
        'includes\class-pdx-target.php',
        'includes\class-pdx-http.php',
        'includes\class-pdx-intelligence.php'
    )
    if (Test-Path $manifestPath) {
        $content = Get-Content $manifestPath -Raw
        $required = [regex]::Matches($content, "'([^']+\.php)'") | ForEach-Object { $_.Groups[1].Value -replace '/', '\' }
    }
    foreach ($rel in $required) {
        $p = Join-Path $dir $rel
        if (-not (Test-Path $p)) { return @{ ok = $false; missing = $rel } }
    }
    $main = Join-Path $dir 'paxdesign-toolbar.php'
    if (-not (Test-Path $main)) { return @{ ok = $false; missing = 'paxdesign-toolbar.php' } }
    return @{ ok = $true; version = (Read-PluginVersion $main) }
}

Write-Host "=== PaxDesign WP upgrade simulation ===" -ForegroundColor Cyan
Write-Host "WorkDir: $WorkDir"

# 1) Install 7.1.10
$stage711 = Join-Path $WorkDir 'stage-711'
Expand-ZipTo $FromZip $stage711 | Out-Null
Copy-Item -Path (Join-Path $stage711 'paxdesign-toolbar') -Destination $pluginDir -Recurse -Force
$v711 = Read-PluginVersion (Join-Path $pluginDir 'paxdesign-toolbar.php')
Write-Host "OK: Installed base version: $v711"

# 2) Simulate .maintenance during upgrade
Set-Content -Path $maintenance -Value "<?php `$upgrading = time();" -Encoding UTF8
Write-Host 'OK: Created .maintenance'

# 3) Backup (copy-based)
$backupDir = Join-Path $WorkDir 'wp-content\upgrade\pdx-toolbar-backup'
Copy-Item -Path $pluginDir -Destination $backupDir -Recurse -Force
Write-Host "OK: Backup at $backupDir"

# 4) Extract update package to upgrade working dir (like WP upgrader)
$extractRoot = Join-Path $upgradeDir 'paxdesign-toolbar-extract'
Expand-ZipTo $ToZip $extractRoot | Out-Null
$source = Join-Path $extractRoot 'paxdesign-toolbar'
$vTarget = Read-PluginVersion (Join-Path $source 'paxdesign-toolbar.php')
Write-Host "OK: Package version: $vTarget"

# 5) clear_destination + install (full replace)
Remove-Item $pluginDir -Recurse -Force
Copy-Item -Path $source -Destination $pluginDir -Recurse -Force
Write-Host 'OK: Replaced plugin directory (clear_destination)'

# 6) Remove maintenance
Remove-Item $maintenance -Force -ErrorAction SilentlyContinue
Write-Host 'OK: Removed .maintenance'

# 7) Health check
$health = Test-Health $pluginDir
if (-not $health.ok) {
    Write-Host ('FAIL: Health check missing: ' + $health.missing) -ForegroundColor Red
    exit 1
}
Write-Host "OK: Health check passed - version $($health.version)" -ForegroundColor Green

# 8) Rollback simulation
Remove-Item $pluginDir -Recurse -Force
Copy-Item -Path $backupDir -Destination $pluginDir -Recurse -Force
$vRollback = Read-PluginVersion (Join-Path $pluginDir 'paxdesign-toolbar.php')
if ($vRollback -ne $v711) {
    Write-Host ('FAIL: Rollback version ' + $vRollback + ' expected ' + $v711) -ForegroundColor Red
    exit 1
}
Write-Host "OK: Rollback restored $vRollback" -ForegroundColor Green

# 9) Re-apply upgrade
Remove-Item $pluginDir -Recurse -Force
Copy-Item -Path $source -Destination $pluginDir -Recurse -Force
$health2 = Test-Health $pluginDir
if (-not $health2.ok) { throw 'Health failed after re-apply' }
Write-Host "OK: Re-apply upgrade successful - $($health2.version)" -ForegroundColor Green

Write-Host "`nAll simulation checks passed." -ForegroundColor Cyan
