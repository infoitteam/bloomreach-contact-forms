# bin\pack-plugin.ps1
# Builds: bin\release\cmdb-endpoints-<version>.zip
# Internal top-level folder in the ZIP is always: cmdb-endpoints/

param([string]$VersionOverride = "")

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# --- Config ---
$PluginSlug = 'cmdb-endpoints'       # folder name inside the ZIP
$MainFile   = 'cmdb-endpoints.php'   # file containing "Version:" header

# --- Paths (repo root is parent of /bin) ---
$ScriptRoot  = Split-Path -Parent $PSCommandPath
$ProjectRoot = Split-Path -Parent $ScriptRoot
$OutDir      = Join-Path $ProjectRoot 'bin\release'
$TempRoot    = Join-Path ([IO.Path]::GetTempPath()) ("$PluginSlug-build-" + (Get-Date -Format 'yyyyMMddHHmmss'))
$StageDir    = Join-Path $TempRoot $PluginSlug

# --- Ensure output dir ---
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

# --- Read version from header (or override) ---
$MainPath = Join-Path $ProjectRoot $MainFile
if (!(Test-Path $MainPath)) { throw "Cannot find $MainFile in $ProjectRoot" }
$verMatch = (Select-String -Path $MainPath -Pattern '^\s*\*\s*Version:\s*([0-9][0-9\.A-Za-z\-+]*)').Matches
if (-not $VersionOverride -and $verMatch.Count -lt 1) { throw "Couldn't read Version from $MainFile and no -VersionOverride provided." }
$Version = if ($VersionOverride) { $VersionOverride } else { $verMatch[0].Groups[1].Value }

# --- Stage files (exclude dev/build stuff, including previous releases) ---
if (Test-Path $TempRoot) { Remove-Item $TempRoot -Recurse -Force }
New-Item -ItemType Directory -Force -Path $StageDir | Out-Null

$excludeDirs = @(
  '.git','.github','.vscode','.idea','.gitlab',
  'bin','release','node_modules','vendor','tests','test'
)
$excludeFiles = @(
  '.gitignore','.gitattributes','composer.lock','package-lock.json',
  'pack-plugin.ps1','build.ps1'
)

# Build robocopy args with proper quoting
$xdArgs = @()
foreach ($d in $excludeDirs) { $xdArgs += '/XD'; $xdArgs += "`"$([IO.Path]::Combine($ProjectRoot,$d))`"" }
$xfArgs = @()
foreach ($f in $excludeFiles) { $xfArgs += '/XF'; $xfArgs += "`"$([IO.Path]::Combine($ProjectRoot,$f))`"" }

$robocopyArgs = @(
  "`"$ProjectRoot`"","`"$StageDir`"",
  '/MIR','/NFL','/NDL','/NJH','/NJS','/NP','/R:1','/W:1'
) + $xdArgs + $xfArgs

& robocopy @robocopyArgs | Out-Null
$rc = $LASTEXITCODE
if ($rc -gt 7) { throw "Robocopy failed with exit code $rc" }

# --- Load compression types (ZipArchiveMode lives in System.IO.Compression) ---
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

# --- Choose output file; if existing is locked, fall back to timestamped name ---
function Test-FileLocked([string]$Path) {
  if (!(Test-Path $Path)) { return $false }
  try { $fs = [System.IO.File]::Open($Path,'Open','ReadWrite','None'); $fs.Close(); return $false } catch { return $true }
}

$BaseZipName = "$PluginSlug-$Version.zip"
$ZipPath     = Join-Path $OutDir $BaseZipName

if (Test-Path $ZipPath) {
  if (Test-FileLocked $ZipPath) {
    $stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
    $ZipPath = Join-Path $OutDir "$PluginSlug-$Version-$stamp.zip"
    Write-Warning "Existing ZIP is locked; writing new file: $(Split-Path $ZipPath -Leaf)"
  } else {
    Remove-Item $ZipPath -Force
  }
}

# --- Create ZIP manually with forward slashes (ZipFileExtensions) ---
$zipStream = [System.IO.File]::Open($ZipPath, [System.IO.FileMode]::CreateNew)
$zip = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)

$baseLen = $StageDir.Length + 1
Get-ChildItem -Path $StageDir -Recurse -File | ForEach-Object {
  $rel       = $_.FullName.Substring($baseLen) -replace '\\','/'   # normalize
  $entryPath = "$PluginSlug/$rel"
  [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
    $zip, $_.FullName, $entryPath, [System.IO.Compression.CompressionLevel]::Optimal
  ) | Out-Null
}

$zip.Dispose()
$zipStream.Dispose()

# --- Sanity check: forward slashes + rooted in cmdb-endpoints/ ---
$ok = $true
$zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
foreach ($e in $zip.Entries) {
  if ($e.FullName -match '\\') { $ok = $false; Write-Warning ("Entry has backslash: " + $e.FullName) }
  if ($e.FullName -notmatch "^$PluginSlug/") { $ok = $false; Write-Warning ("Entry not rooted in " + $PluginSlug + "/: " + $e.FullName) }
}
$zip.Dispose()

# --- Hashes (saved alongside the ZIP; not included in it) ---
$sha256 = (Get-FileHash -Algorithm SHA256 -Path $ZipPath).Hash
$sha1   = (Get-FileHash -Algorithm SHA1   -Path $ZipPath).Hash
$sha256 | Out-File -Encoding ASCII -FilePath ($ZipPath + '.sha256')
$sha1   | Out-File -Encoding ASCII -FilePath ($ZipPath + '.sha1')

Write-Host ""
Write-Host ("Built: " + $ZipPath)
Write-Host ("SHA256: " + $sha256)
Write-Host ("SHA1  : " + $sha1)
if ($ok) { Write-Host "ZIP structure looks good" } else { Write-Warning "ZIP structure had anomalies (see warnings above)" }

# --- Cleanup ---
Remove-Item $TempRoot -Recurse -Force
