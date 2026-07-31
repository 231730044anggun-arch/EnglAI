$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$port = 8772
$base = "http://127.0.0.1:$port"
$oldKey = [Environment]::GetEnvironmentVariable('GEMINI_API_KEY','Process')
Remove-Item Env:GEMINI_API_KEY -ErrorAction SilentlyContinue
$before = @(Get-Process -Name php -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id)
$server = Start-Process -FilePath 'php' -ArgumentList '-S',"127.0.0.1:$port",'router.php' -WorkingDirectory $root -WindowStyle Hidden -PassThru
Start-Sleep -Seconds 2

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

try {
    foreach ($mode in @('quiz','speaking')) {
        $response = Invoke-WebRequest -UseBasicParsing -Method Post -ContentType 'application/json' -Body (@{mode=$mode;difficulty='easy';unit=1} | ConvertTo-Json -Compress) "$base/api/generate_question.php"
        $json = $response.Content | ConvertFrom-Json
        Assert-True ($response.StatusCode -eq 200) "$mode fallback did not return HTTP 200."
        Assert-True ($json.success -eq $true -and $json.source -eq 'fallback') "$mode fallback response envelope is invalid."
        Assert-True (-not [string]::IsNullOrWhiteSpace($json.warning)) "$mode fallback warning is missing."
        if ($mode -eq 'quiz') {
            Assert-True ($json.data.op.Count -eq 4 -and @('A','B','C','D') -contains $json.data.ans) 'Quiz fallback schema is invalid.'
        } else {
            Assert-True (-not [string]::IsNullOrWhiteSpace($json.data.phrase)) 'Speaking fallback schema is invalid.'
        }
    }
    Write-Output 'Backend Quiz and Speaking fallback integration tests OK.'
} finally {
    if ($null -ne $oldKey) { $env:GEMINI_API_KEY = $oldKey } else { Remove-Item Env:GEMINI_API_KEY -ErrorAction SilentlyContinue }
    Get-Process -Name php -ErrorAction SilentlyContinue | Where-Object { $before -notcontains $_.Id } | Stop-Process -Force -ErrorAction SilentlyContinue
}
