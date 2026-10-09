# Run on the home PC with Docker Desktop running. Prints a fresh laptop URL.
$ErrorActionPreference = 'Stop'
Push-Location $PSScriptRoot
try {
    docker-compose up -d app
    if ($LASTEXITCODE -ne 0) { throw 'Could not start the application. Check Docker Desktop.' }

    docker-compose --profile quick up -d --no-deps --force-recreate cloudflared-quick
    if ($LASTEXITCODE -ne 0) { throw 'Could not start the internet tunnel.' }

    $demoPublicUrl = $null
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        $demoLogs = docker-compose logs --no-color --tail 100 cloudflared-quick 2>&1
        $demoMatches = [regex]::Matches(($demoLogs -join "`n"), 'https://[a-z0-9-]+\.trycloudflare\.com')
        if ($demoMatches.Count -gt 0) {
            $demoPublicUrl = $demoMatches[$demoMatches.Count - 1].Value
            break
        }
        Start-Sleep -Seconds 2
    }
    if (-not $demoPublicUrl) { throw 'No public URL was created. Check docker-compose logs cloudflared-quick.' }

    $demoEnvPath = Join-Path $PSScriptRoot '.env'
    $demoEnvText = if (Test-Path -LiteralPath $demoEnvPath) { [IO.File]::ReadAllText($demoEnvPath) } else { '' }
    $demoSettings = [ordered]@{
        APP_URL = $demoPublicUrl
        APP_DEBUG = 'false'
        SESSION_SECURE_COOKIE = 'true'
    }
    foreach ($demoSetting in $demoSettings.GetEnumerator()) {
        $demoPattern = '(?m)^' + $demoSetting.Key + '=[^\r\n]*'
        $demoLine = $demoSetting.Key + '=' + $demoSetting.Value
        if ([regex]::IsMatch($demoEnvText, $demoPattern)) {
            $demoEnvText = [regex]::Replace($demoEnvText, $demoPattern, $demoLine)
        } else {
            $demoEnvText = $demoEnvText.TrimEnd() + "`r`n" + $demoLine + "`r`n"
        }
    }
    [IO.File]::WriteAllText($demoEnvPath, $demoEnvText, (New-Object Text.UTF8Encoding($false)))

    docker-compose up -d --no-deps --force-recreate app
    if ($LASTEXITCODE -ne 0) { throw 'Could not apply the public URL to the application.' }

    $demoReady = $false
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        $demoStatus = curl.exe -sS -o NUL -w '%{http_code}' --max-time 5 ($demoPublicUrl + '/admin/login') 2>$null
        if ($LASTEXITCODE -eq 0 -and $demoStatus -eq '200') { $demoReady = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $demoReady) { throw 'The public login page did not become ready. Run this script again after checking the internet connection.' }

    Write-Host "`nOpen this address on your laptop: $demoPublicUrl/admin"
    Write-Host 'Sign in with your existing school administrator account.'
    Write-Host 'Keep this PC awake, online, and Docker Desktop running during the demonstration.'
    Write-Host 'This is a temporary link; restarting the tunnel can change it.'
    Write-Host 'To close internet access: docker-compose --profile quick stop cloudflared-quick'
} finally {
    Pop-Location
}
