# Validates a release ZIP for WordPress plugin updates.
param(
    [Parameter(Mandatory = $true)]
    [string]$ZipPath
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path $ZipPath)) { throw "Not found: $ZipPath" }

$tmp = Join-Path $env:TEMP "pdx-verify-$(Get-Random)"
Expand-Archive -Path $ZipPath -DestinationPath $tmp -Force
$roots = Get-ChildItem $tmp -Directory
if ($roots.Count -ne 1 -or $roots[0].Name -ne 'paxdesign-toolbar') {
    throw "ZIP must contain exactly one root folder: paxdesign-toolbar/"
}
$plugin = $roots[0].FullName
$main   = Join-Path $plugin 'paxdesign-toolbar.php'
if (-not (Test-Path $main)) { throw 'Missing paxdesign-toolbar.php at plugin root' }

$content = Get-Content $main -Raw
$ver = ''
if ($content -match "define\s*\(\s*'PDX_VERSION'\s*,\s*'([^']+)'\s*\)") { $ver = $Matches[1] }
if ($content -match '\*\s*Version:\s*([0-9.]+)') { $headerVer = $Matches[1] } else { $headerVer = '' }
if ($ver -ne $headerVer) { throw "Version mismatch header=$headerVer constant=$ver" }

$manifest = Join-Path $plugin 'includes\pdx-upgrade-manifest.php'
if (Test-Path $manifest) {
    $m = Get-Content $manifest -Raw
    $matches = [regex]::Matches($m, "'(includes/[^']+)'")
    foreach ($match in $matches) {
        $rel = $match.Groups[1].Value -replace '/', '\'
        $path = Join-Path $plugin $rel
        if (-not (Test-Path $path)) { throw "Manifest file missing in ZIP: $rel" }
    }
}

Remove-Item $tmp -Recurse -Force
Write-Host "VALID: $ZipPath (version $ver, structure OK)"
