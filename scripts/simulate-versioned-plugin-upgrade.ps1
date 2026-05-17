# Simulates wp-admin update when the live plugin is in a versioned folder
# (paxdesign-toolbar-8.1.5) while the updater wrongly checked wp-content/plugins/paxdesign-toolbar/.
# Usage: .\scripts\simulate-versioned-plugin-upgrade.ps1 -ToZip releases\paxdesign-toolbar-8.2.1.zip

param(
    [string]$FromZip = '',
    [string]$ToZip   = ''
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
if (-not $FromZip) { $FromZip = Join-Path $root 'releases\paxdesign-toolbar-8.1.5.zip' }
if (-not $ToZip)   { $ToZip   = Join-Path $root 'releases\paxdesign-toolbar-8.2.1.zip' }

foreach ($z in @($FromZip, $ToZip)) {
    if (-not (Test-Path $z)) { throw "ZIP not found: $z" }
}

$work = Join-Path $env:TEMP "pdx-versioned-upgrade-$(Get-Random)"
$plugins = Join-Path $work 'wp-content\plugins'
$canonical = Join-Path $plugins 'paxdesign-toolbar'
$versioned = Join-Path $plugins 'paxdesign-toolbar-8.1.5'

function Expand-Root($zip, $dest) {
    if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }
    Expand-Archive $zip $dest -Force
    $inner = Join-Path $dest (Get-ChildItem $dest -Directory | Select-Object -First 1).Name
    return (Join-Path $dest 'paxdesign-toolbar')
}

function Read-Ver($main) {
    $c = Get-Content $main -Raw
    if ($c -match "define\s*\(\s*'PDX_VERSION'\s*,\s*'([^']+)'\s*\)") { return $Matches[1] }
    if ($c -match '\*\s*Version:\s*([0-9.]+)') { return $Matches[1] }
    return ''
}

if (Test-Path $work) { Remove-Item $work -Recurse -Force }
New-Item -ItemType Directory -Path $plugins -Force | Out-Null

$stage = Join-Path $work 'stage'
$src711 = Expand-Root $FromZip $stage
Copy-Item $src711 $versioned -Recurse -Force
# Stale canonical copy (old bug: verify_install read this path)
Copy-Item $src711 $canonical -Recurse -Force

Write-Host "Versioned (active): $(Read-Ver (Join-Path $versioned 'paxdesign-toolbar.php'))"
Write-Host "Canonical (stale):  $(Read-Ver (Join-Path $canonical 'paxdesign-toolbar.php'))"

$stage2 = Join-Path $work 'stage2'
$pkg = Expand-Root $ToZip $stage2
$targetVer = Read-Ver (Join-Path $pkg 'paxdesign-toolbar.php')
Write-Host "Package version:    $targetVer"

# WordPress updates the ACTIVE (versioned) folder
Remove-Item $versioned -Recurse -Force
Copy-Item $pkg $versioned -Recurse -Force

# BUG (pre-8.2.1): verify_install only inspected canonical
$wrongCheck = Read-Ver (Join-Path $canonical 'paxdesign-toolbar.php')
$rightCheck = Read-Ver (Join-Path $versioned 'paxdesign-toolbar.php')
Write-Host "After WP install — versioned: $rightCheck | canonical (old verify path): $wrongCheck"

if ($rightCheck -ne $targetVer) { throw 'Versioned folder was not updated' }
if ($wrongCheck -eq $targetVer) {
    Write-Host 'NOTE: canonical also matches (unusual in this scenario)' -ForegroundColor Yellow
} else {
    Write-Host 'FAIL (old updater): verify_install would see stale canonical and reject/rollback' -ForegroundColor Red
    Write-Host 'PASS (8.2.1+): verify_install must read versioned/destination path, not hardcoded canonical only' -ForegroundColor Green
}

Remove-Item $work -Recurse -Force
Write-Host 'Simulation complete.' -ForegroundColor Cyan
