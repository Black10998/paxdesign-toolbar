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
    throw "ZIP must contain exactly one root folder: paxdesign-toolbar/ (found: $($roots.Name -join ', '))"
}
if ($roots[0].Name -match '^paxdesign-toolbar-[0-9]') {
    throw "ZIP root must be paxdesign-toolbar/ not a versioned folder name"
}
# Re-pack check: list zip entries via .NET (catches flat ZIPs with no wrapper folder).
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead((Resolve-Path $ZipPath))
try {
    $top = @(
        $zip.Entries | ForEach-Object {
            $p = $_.FullName -replace '\\','/'
            if ($p -match '^([^/]+)/') { $Matches[1] }
        } | Sort-Object -Unique
    )
    if ($top.Count -ne 1 -or $top[0] -ne 'paxdesign-toolbar') {
        throw "ZIP entry roots must be only paxdesign-toolbar/ (found: $($top -join ', '))"
    }
    $bad = $zip.Entries | Where-Object { $_.FullName -match '^paxdesign-toolbar-[0-9]' }
    if ($bad) { throw 'ZIP must not contain versioned root folder paxdesign-toolbar-x.y.z' }
} finally {
    $zip.Dispose()
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
    if ($m -match "required_files'\s*=>\s*\[([\s\S]*?)\]") {
        $block = $Matches[1]
        $fileMatches = [regex]::Matches($block, "'(includes/[^']+)'")
        foreach ($match in $fileMatches) {
            $rel = $match.Groups[1].Value -replace '/', '\'
            $path = Join-Path $plugin $rel
            if (-not (Test-Path $path)) { throw "Manifest required file missing in ZIP: $rel" }
        }
    }
}

Remove-Item $tmp -Recurse -Force
Write-Host "VALID: $ZipPath (version $ver, structure OK)"
