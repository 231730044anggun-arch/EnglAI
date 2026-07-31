param([Parameter(Mandatory=$true)][string]$Destination)
$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$resolved=[IO.Path]::GetFullPath($Destination)
if($resolved.StartsWith($root,[StringComparison]::OrdinalIgnoreCase)){throw 'Backup destination must be outside the public project.'}
New-Item -ItemType Directory -Path $resolved -Force|Out-Null
robocopy (Join-Path $root 'uploads') (Join-Path $resolved 'uploads') /E | Out-Null
Write-Output "Storage backup completed: $resolved"
