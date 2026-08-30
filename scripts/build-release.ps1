[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$plugin = Join-Path $root 'openbooking-wp'
$dist = Join-Path $root 'dist'
$zip = Join-Path $dist 'openbooking-wp-opensource-1.2.4.zip'

& (Join-Path $PSScriptRoot 'verify-release.ps1') -PluginPath $plugin
if (-not $?) { exit 1 }

New-Item -ItemType Directory -Path $dist -Force | Out-Null
if (Test-Path -LiteralPath $zip) {
    Remove-Item -LiteralPath $zip -Force
}
Compress-Archive -LiteralPath $plugin -DestinationPath $zip -CompressionLevel Optimal
Write-Output $zip
