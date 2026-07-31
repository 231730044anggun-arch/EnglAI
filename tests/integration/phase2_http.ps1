$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$port=8778;$base="http://127.0.0.1:$port"
$beforeMember=[int](& php (Join-Path $PSScriptRoot 'phase2_http_cleanup.php'))
$before=@(Get-Process -Name php -ErrorAction SilentlyContinue|Select-Object -ExpandProperty Id)
$server=Start-Process -FilePath 'php' -ArgumentList '-S',"127.0.0.1:$port",'router.php' -WorkingDirectory $root -WindowStyle Hidden -PassThru
Start-Sleep -Seconds 2
function Assert-True([bool]$Condition,[string]$Message){if(-not $Condition){throw $Message}}
function Csrf-From([string]$Html){$m=[regex]::Match($Html,'name="csrf_token" value="([^"]+)"');if(-not $m.Success){throw 'CSRF missing.'};return $m.Groups[1].Value}
function New-Student(){
  $s=New-Object Microsoft.PowerShell.Commands.WebRequestSession
  $r=Invoke-WebRequest -UseBasicParsing -WebSession $s -Method Post -Body @{classroom_code='ENG-DEMO'} "$base/student/join.php"
  Assert-True ($r.BaseResponse.ResponseUri.AbsolutePath -eq '/student/dashboard.php') 'Student join failed.'
  return $s
}
function Start-Activity($Session,[string]$Skill,[string]$Level='intermediate'){
  $r=Invoke-WebRequest -UseBasicParsing -WebSession $Session "$base/student/activity.php?skill=$Skill&level=$Level"
  Assert-True ($r.BaseResponse.ResponseUri.AbsolutePath -eq '/student/activity.php') "$Skill activity route failed."
  $id=[regex]::Match($r.BaseResponse.ResponseUri.Query,'attempt=(\d+)').Groups[1].Value
  return [pscustomobject]@{Response=$r;Id=$id;Csrf=(Csrf-From $r.Content)}
}
try{
  $student=New-Student
  foreach($skill in @('reading','listening','speaking','writing')){
    $overview=Invoke-WebRequest -UseBasicParsing -WebSession $student "$base/student/skill.php?skill=$skill&level=intermediate"
    Assert-True ($overview.StatusCode -eq 200 -and $overview.Content -match 'Continue Learning') "$skill overview unavailable."
  }
  $reading=Start-Activity $student 'reading'
  $readingResult=Invoke-WebRequest -UseBasicParsing -WebSession $student -Method Post -Body @{csrf_token=$reading.Csrf;attempt_id=$reading.Id;answer='A'} "$base/student/activity.php?attempt=$($reading.Id)"
  Assert-True ($readingResult.Content -match 'Activity Completed' -and $readingResult.Content -match 'Explanation') 'Reading objective correction failed.'
  $listening=Start-Activity $student 'listening'
  Assert-True ($listening.Response.Content -match 'Generated Listening Audio' -and $listening.Response.Content -notmatch 'Transcript unlocked:') 'Listening transcript was exposed before submit.'
  $listenResult=Invoke-WebRequest -UseBasicParsing -WebSession $student -Method Post -Body @{csrf_token=$listening.Csrf;attempt_id=$listening.Id;answer='A'} "$base/student/activity.php?attempt=$($listening.Id)"
  Assert-True ($listenResult.Content -match 'Transcript unlocked:') 'Listening transcript did not unlock after submit.'
  $speaking=Start-Activity $student 'speaking'
  $transcript='This lesson topic is important because it develops language context and helps students explain relevant ideas with clear complete sentences.'
  $speakResult=Invoke-WebRequest -UseBasicParsing -WebSession $student -Method Post -Body @{csrf_token=$speaking.Csrf;attempt_id=$speaking.Id;transcript=$transcript} "$base/student/activity.php?attempt=$($speaking.Id)"
  Assert-True ($speakResult.Content -match 'Assessment' -and $speakResult.Content -match 'Suggested revision') 'Speaking fallback assessment failed.'
  try{Invoke-WebRequest -UseBasicParsing -WebSession $student -Method Post -Body @{csrf_token=$speaking.Csrf;attempt_id=$speaking.Id;transcript=$transcript} "$base/student/activity.php?attempt=$($speaking.Id)"|Out-Null;throw 'Duplicate submission accepted.'}catch{if(-not $_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 409){throw}}
  $writing=Start-Activity $student 'writing'
  $writingText=(1..90|ForEach-Object{'learning'}) -join ' '
  $writeResult=Invoke-WebRequest -UseBasicParsing -WebSession $student -Method Post -Body @{csrf_token=$writing.Csrf;attempt_id=$writing.Id;writing=$writingText} "$base/student/activity.php?attempt=$($writing.Id)"
  Assert-True ($writeResult.Content -match 'Assessment' -and $writeResult.Content -match 'Example answer') 'Writing fallback assessment failed.'
  $progress=Invoke-WebRequest -UseBasicParsing -WebSession $student "$base/student/progress.php"
  Assert-True ($progress.Content -match 'Real Activity Data' -and $progress.Content -match 'Reading') 'Progress page failed.'
  $other=New-Student
  try{Invoke-WebRequest -UseBasicParsing -WebSession $other "$base/student/activity.php?attempt=$($reading.Id)"|Out-Null;throw 'Cross-student attempt access accepted.'}catch{if(-not $_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 404){throw}}
  $js=Get-Content -Raw (Join-Path $root 'assets\js\learning-activities.js')
  Assert-True ($js -match 'Speech Recognition tidak didukung' -and $js -match 'localStorage') 'Speech fallback or writing autosave missing.'
  Write-Output 'Phase 2 Reading, Listening, Speaking, Writing, progress, idempotency, and isolation HTTP tests OK.'
}finally{
  & php (Join-Path $PSScriptRoot 'phase2_http_cleanup.php') $beforeMember
  Get-Process -Name php -ErrorAction SilentlyContinue|Where-Object{$before -notcontains $_.Id}|Stop-Process -Force -ErrorAction SilentlyContinue
}
