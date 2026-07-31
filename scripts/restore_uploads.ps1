param([Parameter(Mandatory=$true)][string]$BackupDirectory)
$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$source=Join-Path ([IO.Path]::GetFullPath($BackupDirectory)) 'uploads'
if(-not(Test-Path $source)){throw 'Backup uploads directory not found.'}
robocopy $source (Join-Path $root 'uploads') /E | Out-Null
Write-Output 'Uploads restored.'
