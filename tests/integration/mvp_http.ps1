$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$port = 8776
$base = "http://127.0.0.1:$port"
$password = [Guid]::NewGuid().ToString('N') + '!aA7'
$env:ADMIN_USERNAME = 'admin'
$env:ADMIN_PASSWORD_HASH = (& php -r "echo password_hash('$password', PASSWORD_DEFAULT);")
$before = @(Get-Process -Name php -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id)
$server = Start-Process -FilePath 'php' -ArgumentList '-S',"127.0.0.1:$port",'router.php' -WorkingDirectory $root -WindowStyle Hidden -PassThru
$quizId = 0
Start-Sleep -Seconds 2

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}
function Csrf-From([string]$Html) {
    $match = [regex]::Match($Html, 'name="csrf_token" value="([^"]+)"')
    if (-not $match.Success) { throw 'CSRF token not found.' }
    return $match.Groups[1].Value
}

try {
    $public = Invoke-WebRequest -UseBasicParsing "$base/"
    Assert-True ($public.StatusCode -eq 200 -and $public.Content -match 'AI Classroom Platform') 'Redesigned Public Page failed.'
    Assert-True ($public.Content -match '/admin/login.php' -and $public.Content -match '/student/join.php') 'Teacher/Student entry link missing.'
    Assert-True ($public.Content -match 'name="viewport"' -and $public.Headers['Content-Security-Policy']) 'Responsive markup or CSP missing.'
    Assert-True ($public.Content -notmatch 'AIza[0-9A-Za-z_-]{20,}') 'API key marker exposed in Public Page.'
    foreach ($asset in @(
        '/assets/css/design-system.css','/assets/css/public.css','/assets/css/teacher.css',
        '/assets/css/student.css','/assets/css/game.css','/assets/js/visual-effects.js',
        '/assets/js/teacher.js','/assets/js/student.js','/assets/js/self-learning.js','/assets/js/live-quiz.js'
    )) {
        $assetResponse = Invoke-WebRequest -UseBasicParsing "$base$asset"
        Assert-True ($assetResponse.StatusCode -eq 200) "Redesign asset unavailable: $asset"
    }
    $teacher = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest -UseBasicParsing -WebSession $teacher "$base/admin/login.php"
    $csrf = Csrf-From $login.Content
    $dashboard = Invoke-WebRequest -UseBasicParsing -WebSession $teacher -Method Post -Body @{csrf_token=$csrf;username='admin';password=$password} "$base/admin/login.php"
    $classroomLink = [regex]::Match($dashboard.Content, '/admin/classroom\.php\?id=(\d+)')
    Assert-True $classroomLink.Success 'Demo classroom not found.'
    $classroomId = [int]$classroomLink.Groups[1].Value
    $classroom = Invoke-WebRequest -UseBasicParsing -WebSession $teacher "$base/admin/classroom.php?id=$classroomId"
    $codeMatch = [regex]::Match($classroom.Content, 'ENG-[A-Z0-9-]+')
    Assert-True $codeMatch.Success 'Classroom code missing.'
    $code = $codeMatch.Value
    $csrf = Csrf-From $classroom.Content
    $quizPage = Invoke-WebRequest -UseBasicParsing -WebSession $teacher -Method Post -Body @{csrf_token=$csrf;classroom_id=$classroomId;question_count=10;difficulty='medium'} "$base/admin/create_quiz.php"
    $quizMatch = [regex]::Match($quizPage.BaseResponse.ResponseUri.Query, 'id=(\d+)')
    Assert-True $quizMatch.Success 'Quiz lobby was not created.'
    Assert-True ($quizPage.Content -match 'avatar-grid' -and $quizPage.Content -match '/assets/js/live-quiz.js') 'Teacher lobby redesign missing.'
    $quizId = [int]$quizMatch.Groups[1].Value
    $quizCsrf = $csrf

    $students = @()
    foreach ($name in @('Browser Alpha','Browser Beta')) {
        $student = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $joined = Invoke-WebRequest -UseBasicParsing -WebSession $student -Method Post -Body @{classroom_code=$code} "$base/student/join.php"
        Assert-True ($joined.BaseResponse.ResponseUri.AbsolutePath -eq '/student/dashboard.php') 'Student join failed.'
        Assert-True ($joined.Content -match 'Learning Skills' -and $joined.Content -match 'Progress') 'Student Dashboard redesign missing.'
        $learningSetup = Invoke-WebRequest -UseBasicParsing -WebSession $student "$base/student/self_learning.php"
        Assert-True ($learningSetup.StatusCode -eq 200 -and $learningSetup.Content -match 'Self Learning Setup') 'Self Learning setup route failed.'
        $joinForm = Invoke-WebRequest -UseBasicParsing -WebSession $student "$base/student/quiz_join.php?id=$quizId"
        $studentCsrf = Csrf-From $joinForm.Content
        $panda = [string][char]0xD83D + [string][char]0xDC3C
        $play = Invoke-WebRequest -UseBasicParsing -WebSession $student -Method Post -Body @{csrf_token=$studentCsrf;quiz_id=$quizId;display_name=$name;avatar=$panda} "$base/student/quiz_join.php"
        Assert-True ($play.BaseResponse.ResponseUri.AbsolutePath -eq '/student/quiz_play.php') 'Lobby identity join failed.'
        $students += [pscustomobject]@{ Session=$student; Csrf=$studentCsrf }
    }

    $lobby = ((Invoke-WebRequest -UseBasicParsing -WebSession $teacher "$base/api/mvp/teacher_quiz_status.php?id=$quizId").Content | ConvertFrom-Json)
    Assert-True ($lobby.success -and $lobby.data.participants.Count -eq 2) 'Teacher lobby did not show two participants.'
    $started = ((Invoke-WebRequest -UseBasicParsing -WebSession $teacher -Method Post -Body @{csrf_token=$quizCsrf;quiz_id=$quizId;action='start'} "$base/api/mvp/teacher_quiz_action.php").Content | ConvertFrom-Json)
    Assert-True ($started.success -and $started.data.state -eq 'ACTIVE') 'Teacher could not start quiz.'

    $statuses = @()
    foreach ($student in $students) {
        $statuses += ((Invoke-WebRequest -UseBasicParsing -WebSession $student.Session "$base/api/mvp/student_quiz_status.php?id=$quizId").Content | ConvertFrom-Json)
    }
    Assert-True ($statuses[0].data.question.id -eq $statuses[1].data.question.id) 'Two browsers received different questions.'
    Assert-True (($statuses[0].data.question.options -join '|') -eq ($statuses[1].data.question.options -join '|')) 'Two browsers received different option order.'

    $answer = ((Invoke-WebRequest -UseBasicParsing -WebSession $students[0].Session -Method Post -Body @{csrf_token=$students[0].Csrf;quiz_id=$quizId;answer='A'} "$base/api/mvp/quiz_answer.php").Content | ConvertFrom-Json)
    Assert-True $answer.success 'Backend did not persist student answer.'
    try {
        Invoke-WebRequest -UseBasicParsing -WebSession $students[0].Session -Method Post -Body @{csrf_token=$students[0].Csrf;quiz_id=$quizId;answer='A'} "$base/api/mvp/quiz_answer.php" | Out-Null
        throw 'Double submission was accepted.'
    } catch {
        if (-not $_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 409) { throw }
    }
    Write-Output 'Two-session HTTP join, lobby, synchronized question, answer persistence, and double-submit tests OK.'
} finally {
    if ($quizId -gt 0) { & php (Join-Path $PSScriptRoot 'mvp_http_cleanup.php') $quizId }
    Remove-Item Env:ADMIN_USERNAME -ErrorAction SilentlyContinue
    Remove-Item Env:ADMIN_PASSWORD_HASH -ErrorAction SilentlyContinue
    Get-Process -Name php -ErrorAction SilentlyContinue | Where-Object { $before -notcontains $_.Id } | Stop-Process -Force -ErrorAction SilentlyContinue
}
