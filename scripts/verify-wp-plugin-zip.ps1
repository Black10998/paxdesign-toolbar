# Strict WordPress plugin ZIP layout verification.
# Required layout:
#   paxdesign-toolbar/paxdesign-toolbar.php
#   paxdesign-toolbar/includes/
#   paxdesign-toolbar/assets/
#   paxdesign-toolbar/templates/
param(
    [Parameter(Mandatory = $true)]
    [string]$ZipPath
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path $ZipPath)) { throw "Not found: $ZipPath" }

Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead((Resolve-Path $ZipPath))

try {
    $entries = @(
        $zip.Entries | ForEach-Object {
            ($_.FullName -replace '\\', '/').TrimEnd('/')
        } | Where-Object { $_ -ne '' }
    )

    if ($entries.Count -eq 0) {
        throw 'ZIP is empty.'
    }

    $roots = @(
        $entries | ForEach-Object {
            if ($_ -match '^([^/]+)/') { $Matches[1] }
        } | Sort-Object -Unique
    )

    if ($roots.Count -ne 1 -or $roots[0] -ne 'paxdesign-toolbar') {
        throw "ZIP must have exactly one root folder 'paxdesign-toolbar/' (found: $($roots -join ', '))"
    }

    $badRoots = $entries | Where-Object { $_ -match '^paxdesign-toolbar-[0-9]' }
    if ($badRoots) {
        throw 'ZIP must not contain a versioned root folder (paxdesign-toolbar-x.y.z/).'
    }

    $mainExact = 'paxdesign-toolbar/paxdesign-toolbar.php'
    if ($entries -notcontains $mainExact) {
        throw "Missing required main file: $mainExact"
    }

    $doubleNested = $entries | Where-Object { $_ -match '^paxdesign-toolbar/paxdesign-toolbar/' }
    if ($doubleNested) {
        throw 'ZIP must not be double-nested (paxdesign-toolbar/paxdesign-toolbar/...).'
    }

    $flatMain = $entries | Where-Object { $_ -eq 'paxdesign-toolbar.php' }
    if ($flatMain) {
        throw 'ZIP must not place paxdesign-toolbar.php at archive root (missing plugin folder wrapper).'
    }

    foreach ($required in @(
        'paxdesign-toolbar/includes',
        'paxdesign-toolbar/assets',
        'paxdesign-toolbar/templates'
    )) {
        $has = $entries | Where-Object { $_ -eq $required -or $_ -like "$required/*" }
        if (-not $has) {
            throw "Missing required directory in ZIP: $required/"
        }
    }

    $tmp = Join-Path $env:TEMP "pdx-wpzip-$(Get-Random)"
    Expand-Archive -Path $ZipPath -DestinationPath $tmp -Force
    $mainPath = Join-Path $tmp 'paxdesign-toolbar\paxdesign-toolbar.php'
    if (-not (Test-Path $mainPath)) {
        throw 'Extract test failed: paxdesign-toolbar/paxdesign-toolbar.php not found on disk.'
    }

    $header = (Get-Content $mainPath -TotalCount 20) -join "`n"
    if ($header -notmatch 'Plugin Name:\s*.+') {
        throw 'Main file is missing a valid WordPress Plugin Name header.'
    }

    Remove-Item $tmp -Recurse -Force
    Write-Host "WP-ZIP-OK: $ZipPath (WordPress-uploadable layout verified)"
} finally {
    $zip.Dispose()
}
