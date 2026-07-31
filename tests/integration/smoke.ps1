$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$port = 8774
$base = "http://127.0.0.1:$port"
$before = @(Get-Process -Name php -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id)
$server = Start-Process -FilePath 'php' -ArgumentList '-S',"127.0.0.1:$port",'router.php' -WorkingDirectory $root -WindowStyle Hidden -PassThru
Start-Sleep -Seconds 2

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}
function Status-Of([string]$Url) {
    try { return (Invoke-WebRequest -UseBasicParsing $Url).StatusCode }
    catch { return [int]$_.Exception.Response.StatusCode }
}

try {
    $main = Invoke-WebRequest -UseBasicParsing "$base/index.php"
    Assert-True ($main.StatusCode -eq 200) 'Main page failed.'
    Assert-True (-not [string]::IsNullOrWhiteSpace($main.Headers['Content-Security-Policy'])) 'CSP header missing.'
    Assert-True (-not [string]::IsNullOrWhiteSpace($main.Headers['X-Request-ID'])) 'Request ID header missing.'
    Assert-True ($main.Headers['X-Content-Type-Options'] -eq 'nosniff') 'nosniff header missing.'

    $health = (Invoke-WebRequest -UseBasicParsing "$base/api/health.php").Content | ConvertFrom-Json
    Assert-True ($health.success -eq $true -and $health.checks.database -eq 'ok') 'Database health check failed.'

    $rpps = (Invoke-WebRequest -UseBasicParsing "$base/api/get_rpp.php").Content | ConvertFrom-Json
    Assert-True ($rpps.success -eq $true -and $rpps.rpps.Count -ge 1) 'RPP API failed.'

    foreach ($privatePath in @('/.env','/config/koneksi.php','/database/englai.sql','/storage/logs/','/uploads/23388c5247cafb065081698b6db64e91.docx')) {
        Assert-True ((Status-Of "$base$privatePath") -eq 404) "Private path exposed: $privatePath"
    }

    try {
        Invoke-WebRequest -UseBasicParsing "$base/api/generate_question.php" | Out-Null
        throw 'Generate endpoint accepted GET.'
    } catch {
        if ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq 405) { }
        else { throw }
    }

    Write-Output 'HTTP, database, security headers, private path, and method smoke tests OK.'
} finally {
    Get-Process -Name php -ErrorAction SilentlyContinue | Where-Object { $before -notcontains $_.Id } | Stop-Process -Force -ErrorAction SilentlyContinue
}
