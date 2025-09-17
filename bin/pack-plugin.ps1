# bin\pack-plugin.ps1
# Builds: release\bloomreach-contact-forms-<version>.zip
# Internal top-level folder in the ZIP is always: bloomreach-contact-forms/

param(
  [string]$VersionOverride = ""  # optionally override version header
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# --- Config ---
$PluginSlug = 'bloomreach-contact-forms'     # folder name inside the ZIP
$MainFile   = 'bloomreach-contact-forms.php' # file containing "Version:" header

# --- Paths (repo root is parent of /bin) ---
$RepoRoot  = Split-Path -Parent $PSScriptRoot
$OutDir    = Join-Path $RepoRoot 'release'
$TempRoot  = Join-Path $RepoRoot 'tmp_pack'
$StageDir  = Join-Path $TempRoot $PluginSlug

# --- Clean + prep dirs ---
if (Test-Path $TempRoot) { Remove-Item $TempRoot -Recurse -Force }
New-Item -ItemType Directory -Path $StageDir -Force | Out-Null
New-Item -ItemType Directory -Path $OutDir -Force | Out-Null

# --- Determine version from header unless overridden ---
$MainPath = Join-Path $RepoRoot $MainFile
if (-not (Test-Path $MainPath)) { throw "Main file not found: $MainPath" }

$Version = $VersionOverride
if (-not $Version) {
  $content = Get-Content -LiteralPath $MainPath -Raw
  if ($content -match '(?im)^[ \t\/*#@]*Version:\s*([0-9A-Za-z\.\-\+]+)') {
    $Version = $Matches[1].Trim()
  } else {
    throw "Couldn't find a Version: header in $MainFile"
  }
}

$ZipPath = Join-Path $OutDir "$PluginSlug-$Version.zip"

# --- Copy files into stage (adjust as needed) ---
$includeFiles = @(
  'README.md',
  'readme.txt',
  'LICENSE',
  $MainFile
) | ForEach-Object { Join-Path $RepoRoot $_ } | Where-Object { Test-Path $_ }

foreach ($f in $includeFiles) {
  Copy-Item -LiteralPath $f -Destination (Join-Path $StageDir (Split-Path $f -Leaf)) -Force
}

# Copy common plugin folders if present
$includeDirs = @(
  'inc',
  'assets',
  'languages',
  'vendor'
)

foreach ($d in $includeDirs) {
  $src = Join-Path $RepoRoot $d
  if (Test-Path $src) {
    Copy-Item -Recurse -Force -LiteralPath $src -Destination (Join-Path $StageDir $d)
  }
}

# --- Create ZIP with forward-slash entry names ---
Add-Type -AssemblyName 'System.IO.Compression'
Add-Type -AssemblyName 'System.IO.Compression.FileSystem'

if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }

$fs = [System.IO.File]::Open($ZipPath, [System.IO.FileMode]::Create)
try {
  $archive = New-Object System.IO.Compression.ZipArchive($fs, [System.IO.Compression.ZipArchiveMode]::Create, $false)

  Get-ChildItem -Path $StageDir -File -Recurse | ForEach-Object {
    $rel = $_.FullName.Substring($StageDir.Length + 1)
    $rel = $rel -replace '\\','/'                    # <-- normalize separators
    $entryName = "$PluginSlug/$rel"                  # ensure top-level folder in zip
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
      $archive, $_.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal
    ) | Out-Null
  }
}
finally {
  if ($archive) { $archive.Dispose() }
  $fs.Dispose()
}

# --- Optional: write hashes ---
$sha256 = (Get-FileHash -Algorithm SHA256 -Path $ZipPath).Hash
$sha1   = (Get-FileHash -Algorithm SHA1   -Path $ZipPath).Hash
$sha256 | Out-File -Encoding ASCII -FilePath ($ZipPath + '.sha256')
$sha1   | Out-File -Encoding ASCII -FilePath ($ZipPath + '.sha1')

Write-Host "`nBuilt:  $ZipPath"
Write-Host "SHA256: $sha256"
Write-Host "SHA1  : $sha1"

# --- Cleanup temp ---
Remove-Item $TempRoot -Recurse -Force
