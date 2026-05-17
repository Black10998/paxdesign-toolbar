# Lint every PHP file under paxdesign-toolbar/ — fails the script on any parse error.
param(
    [string]$PluginDir = ''
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
if (-not $PluginDir) {
    $PluginDir = Join-Path $root 'paxdesign-toolbar'
}

$php = $env:PHP_BIN
if (-not $php) {
    foreach ($candidate in @(
        'php',
        'C:\php\php.exe',
        'C:\xampp\php\php.exe',
        'C:\laragon\bin\php\php-8.3.12-Win32-vs16-x64\php.exe',
        'C:\laragon\bin\php\php-8.2.12-Win32-vs16-x64\php.exe'
    )) {
        if ($candidate -eq 'php') {
            $cmd = Get-Command php -ErrorAction SilentlyContinue
            if ($cmd) { $php = $cmd.Source; break }
        } elseif (Test-Path $candidate) {
            $php = $candidate
            break
        }
    }
}

if (-not $php) {
    $laragon = Get-ChildItem 'C:\laragon\bin\php' -Directory -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Select-Object -First 1
    if ($laragon) {
        $try = Join-Path $laragon.FullName 'php.exe'
        if (Test-Path $try) { $php = $try }
    }
}

if (-not $php) {
    Write-Error 'PHP not found. Set PHP_BIN to your php.exe path.'
}

$files = Get-ChildItem $PluginDir -Filter '*.php' -Recurse -File
$failed = @()
foreach ($f in $files) {
    $out = & $php -l $f.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        $failed += [pscustomobject]@{ File = $f.FullName; Output = ($out -join ' ') }
    }
}

Write-Host "Linted $($files.Count) PHP files using $php"
if ($failed.Count -gt 0) {
    foreach ($item in $failed) {
        Write-Host "FAIL: $($item.File)" -ForegroundColor Red
        Write-Host $item.Output
    }
    exit 1
}

Write-Host 'All PHP files passed syntax check.' -ForegroundColor Green
exit 0
