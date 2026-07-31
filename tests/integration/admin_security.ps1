$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$port = 8771
$base = "http://127.0.0.1:$port"
$password = [Guid]::NewGuid().ToString('N') + '!aA7'
$env:ADMIN_USERNAME = 'verification-admin'
$env:ADMIN_PASSWORD_HASH = (& php -r "echo password_hash('$password', PASSWORD_DEFAULT);")
$env:ADMIN_SESSION_TIMEOUT_SECONDS = '60'
$before = @(Get-Process -Name php -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id)
$server = Start-Process -FilePath 'php' -ArgumentList '-S',"127.0.0.1:$port",'router.php' -WorkingDirectory $root -WindowStyle Hidden -PassThru
Start-Sleep -Seconds 2

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}
function Csrf-From([string]$Html) {
    $match = [regex]::Match($Html, 'name="csrf_token" value="([^"]+)"')
    if (-not $match.Success) { throw 'CSRF token not found.' }
    return $match.Groups[1].Value
}
function Multipart-Upload($Session, [string]$Url, [string]$Csrf, [byte[]]$Bytes, [string]$Filename, [string]$Mime) {
    Add-Type -AssemblyName System.Net.Http
    $handler = New-Object System.Net.Http.HttpClientHandler
    $handler.CookieContainer = $Session.Cookies
    $handler.AllowAutoRedirect = $true
    $client = New-Object System.Net.Http.HttpClient($handler)
    $content = New-Object System.Net.Http.MultipartFormDataContent
    $content.Add((New-Object System.Net.Http.StringContent($Csrf)), 'csrf_token')
    $fileContent = New-Object System.Net.Http.ByteArrayContent -ArgumentList (, $Bytes)
    $fileContent.Headers.ContentType = New-Object System.Net.Http.Headers.MediaTypeHeaderValue($Mime)
    $content.Add($fileContent, 'rpp_file', $Filename)
    try {
        return $client.PostAsync($Url, $content).GetAwaiter().GetResult()
    } finally {
        $content.Dispose()
        $client.Dispose()
        $handler.Dispose()
    }
}

try {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest -UseBasicParsing -WebSession $session "$base/admin/"
    Assert-True ($login.BaseResponse.ResponseUri.AbsolutePath -eq '/admin/login.php') 'Anonymous admin request was not redirected to login.'
    $csrf = Csrf-From $login.Content
    $beforeId = ($session.Cookies.GetCookies($base) | Where-Object Name -eq 'englai_admin').Value

    $wrong = Invoke-WebRequest -UseBasicParsing -WebSession $session -Method Post -Body @{csrf_token=$csrf;username='verification-admin';password='wrong'} "$base/admin/login.php"
    Assert-True ($wrong.Content -match 'tidak valid') 'Wrong password was not rejected.'
    $csrf = Csrf-From $wrong.Content

    $correct = Invoke-WebRequest -UseBasicParsing -WebSession $session -Method Post -Body @{csrf_token=$csrf;username='verification-admin';password=$password} "$base/admin/login.php"
    Assert-True ($correct.BaseResponse.ResponseUri.AbsolutePath -eq '/admin/index.php') 'Correct password did not open admin.'
    $afterId = ($session.Cookies.GetCookies($base) | Where-Object Name -eq 'englai_admin').Value
    Assert-True ($beforeId -ne $afterId) 'Session ID was not regenerated after login.'

    foreach ($path in @('/admin/upload_rpp.php','/admin/delete_rpp.php')) {
        $anonymous = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $response = Invoke-WebRequest -UseBasicParsing -WebSession $anonymous -Method Post "$base$path"
        Assert-True ($response.BaseResponse.ResponseUri.AbsolutePath -eq '/admin/login.php') "Direct unauthenticated access was allowed: $path"
    }

    foreach ($request in @(
        @{Path='/admin/index.php'; Body=@{select_rpp='1'}},
        @{Path='/admin/delete_rpp.php'; Body=@{id='1'}},
        @{Path='/admin/upload_rpp.php'; Body=@{}}
    )) {
        try {
            Invoke-WebRequest -UseBasicParsing -WebSession $session -Method Post -Body $request.Body "$base$($request.Path)" | Out-Null
            throw "Missing CSRF was accepted: $($request.Path)"
        } catch {
            if ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq 419) { continue }
            throw
        }
    }

    $admin = Invoke-WebRequest -UseBasicParsing -WebSession $session "$base/admin/index.php"
    $csrf = Csrf-From $admin.Content
    $rppBefore = ((Invoke-WebRequest -UseBasicParsing "$base/api/get_rpp.php").Content | ConvertFrom-Json).rpps.Count
    $uploadsBefore = @(Get-ChildItem (Join-Path $root 'uploads') -File | Where-Object Name -ne '.htaccess').Count
    $parseFailure = Multipart-Upload $session "$base/admin/upload_rpp.php" $csrf ([Text.Encoding]::ASCII.GetBytes("%PDF-invalid-parser-fixture")) 'parse-failure.pdf' 'application/pdf'
    Assert-True ($parseFailure.IsSuccessStatusCode) 'Parse-failure upload did not return a safe redirect response.'
    $rppAfter = ((Invoke-WebRequest -UseBasicParsing "$base/api/get_rpp.php").Content | ConvertFrom-Json).rpps.Count
    $uploadsAfter = @(Get-ChildItem (Join-Path $root 'uploads') -File | Where-Object Name -ne '.htaccess').Count
    Assert-True ($rppBefore -eq $rppAfter) 'Parse-failure upload entered the database.'
    Assert-True ($uploadsBefore -eq $uploadsAfter) 'Parse-failure upload remained in active storage.'

    foreach ($path in @('/admin/upload_rpp.php','/admin/delete_rpp.php')) {
        $getResponse = Invoke-WebRequest -UseBasicParsing -WebSession $session "$base$path"
        Assert-True ($getResponse.BaseResponse.ResponseUri.AbsolutePath -eq '/admin/index.php') "State endpoint GET behavior is unsafe: $path"
    }

    $admin = Invoke-WebRequest -UseBasicParsing -WebSession $session "$base/admin/index.php"
    $logoutCsrf = Csrf-From $admin.Content
    $logout = Invoke-WebRequest -UseBasicParsing -WebSession $session -Method Post -Body @{csrf_token=$logoutCsrf} "$base/admin/logout.php"
    Assert-True ($logout.BaseResponse.ResponseUri.AbsolutePath -eq '/admin/login.php') 'Logout did not return to login.'
    $afterLogout = Invoke-WebRequest -UseBasicParsing -WebSession $session "$base/admin/"
    Assert-True ($afterLogout.BaseResponse.ResponseUri.AbsolutePath -eq '/admin/login.php') 'Session remained authenticated after logout.'

    Write-Output 'Admin authentication, session regeneration, logout, direct access, and CSRF integration tests OK.'
} finally {
    Remove-Item Env:ADMIN_USERNAME -ErrorAction SilentlyContinue
    Remove-Item Env:ADMIN_PASSWORD_HASH -ErrorAction SilentlyContinue
    Remove-Item Env:ADMIN_SESSION_TIMEOUT_SECONDS -ErrorAction SilentlyContinue
    Get-Process -Name php -ErrorAction SilentlyContinue | Where-Object { $before -notcontains $_.Id } | Stop-Process -Force -ErrorAction SilentlyContinue
}
