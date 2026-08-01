[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost:8080'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
Add-Type -AssemblyName System.Net.Http

function Invoke-Compose {
    param(
        [Parameter(Mandatory)]
        [string[]]$Arguments,
        [switch]$ReturnOutput
    )

    $commandOutput = @(& docker compose @Arguments)
    if ($LASTEXITCODE -ne 0) {
        throw "Polecenie docker compose $($Arguments -join ' ') zakonczylo sie kodem $LASTEXITCODE."
    }
    if ($ReturnOutput) {
        return ($commandOutput -join "`n").Trim()
    }
}

function Wait-AppHealthy {
    param(
        [Parameter(Mandatory)]
        [string]$Service,
        [int]$TimeoutSeconds = 90
    )

    $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    do {
        $containerId = (Invoke-Compose -Arguments @('ps', '-q', $Service) -ReturnOutput)
        if ($containerId) {
            $health = @(& docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' $containerId)
            if ($LASTEXITCODE -eq 0 -and ($health -join '').Trim() -eq 'healthy') {
                return
            }
        }
        Start-Sleep -Milliseconds 500
    } while ([DateTime]::UtcNow -lt $deadline)

    throw "Kontener $Service nie osiagnal stanu healthy w ciagu $TimeoutSeconds s."
}

if ($BaseUrl -ne 'http://localhost:8080') {
    throw 'Test ETAPU 8 jest celowo ograniczony do izolowanego proxy Docker http://localhost:8080.'
}

$stateJson = $null
$state = $null
$stateEncoded = $null
$app1Stopped = $false
$primaryError = $null
$cleanupErrors = [System.Collections.Generic.List[string]]::new()

try {
    Wait-AppHealthy -Service 'app-1'
    Wait-AppHealthy -Service 'app-2'

    $stateJson = Invoke-Compose -Arguments @(
        'exec', '-T', 'app-1', 'php', 'scripts/stage8_two_instances.php', '--prepare'
    ) -ReturnOutput
    $state = $stateJson | ConvertFrom-Json
    if ($state.version -ne 1 -or -not $state.active_session_id) {
        throw 'Przygotowanie ETAPU 8 nie zwrocilo kompletnego stanu.'
    }
    $stateEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($stateJson))

    Invoke-Compose -Arguments @('stop', '--timeout', '25', 'app-1')
    $app1Stopped = $true

    $verifyJson = Invoke-Compose -Arguments @(
        'exec', '-T', 'app-2', 'php', 'scripts/stage8_two_instances.php', '--verify', "--state-base64=$stateEncoded"
    ) -ReturnOutput
    $verification = $verifyJson | ConvertFrom-Json
    if ($verification.status -ne 'ok' -or $verification.instance -ne 'app-2') {
        throw 'Weryfikacja wewnatrz app-2 nie potwierdzila przelaczenia awaryjnego.'
    }

    $cookie = "$($state.session_name)=$($state.active_session_id)"
    $httpHandler = [System.Net.Http.HttpClientHandler]::new()
    $httpHandler.AllowAutoRedirect = $false
    $httpHandler.UseCookies = $false
    $httpHandler.UseProxy = $false
    $httpClient = [System.Net.Http.HttpClient]::new($httpHandler)
    $httpClient.Timeout = [TimeSpan]::FromSeconds(15)
    $accountRequest = $null
    try {
        $accountRequest = [System.Net.Http.HttpRequestMessage]::new(
            [System.Net.Http.HttpMethod]::Get,
            "$BaseUrl/account/settings"
        )
        [void]$accountRequest.Headers.TryAddWithoutValidation('Cookie', $cookie)
        $accountResponse = $httpClient.SendAsync($accountRequest).GetAwaiter().GetResult()
        $accountBody = $accountResponse.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        $accountStatus = [int]$accountResponse.StatusCode
        if ($accountStatus -ne 200) {
            throw "Proxy po wylaczeniu app-1 zwrocilo HTTP $accountStatus."
        }
        $instanceHeaders = [string[]]@($accountResponse.Headers.GetValues('X-App-Instance'))
        if (($instanceHeaders -join ',') -ne 'app-2') {
            throw 'Proxy nie skierowalo zalogowanego zadania do app-2.'
        }
        if (-not $accountBody.Contains('id="account-settings-form"')) {
            throw 'Sesja uzytkownika nie przetrwala przelaczenia app-1 -> app-2.'
        }
    }
    finally {
        if ($accountRequest) {
            $accountRequest.Dispose()
        }
        $httpClient.Dispose()
        $httpHandler.Dispose()
    }

    1..10 | ForEach-Object {
        $live = Invoke-RestMethod -Uri "$BaseUrl/health/live" -TimeoutSec 5
        if ($live.instance -ne 'app-2' -or $live.status -ne 'ok') {
            throw 'Po wylaczeniu app-1 proxy zwrocilo odpowiedz z innej lub niezdrowej instancji.'
        }
    }
}
catch {
    $primaryError = $_
}
finally {
    if ($app1Stopped) {
        try {
            Invoke-Compose -Arguments @('start', 'app-1')
            Wait-AppHealthy -Service 'app-1'
        }
        catch {
            $cleanupErrors.Add("Nie udalo sie przywrocic app-1: $($_.Exception.Message)")
        }
    }

    if ($stateEncoded) {
        try {
            $cleanupJson = Invoke-Compose -Arguments @(
                'exec', '-T', 'app-2', 'php', 'scripts/stage8_two_instances.php', '--cleanup', "--state-base64=$stateEncoded"
            ) -ReturnOutput
            $cleanup = $cleanupJson | ConvertFrom-Json
            if ($cleanup.status -ne 'clean') {
                throw 'Skrypt nie potwierdzil usuniecia danych testowych.'
            }
        }
        catch {
            $cleanupErrors.Add("Nie udalo sie posprzatac danych testowych: $($_.Exception.Message)")
        }
    }
}

if ($primaryError) {
    if ($cleanupErrors.Count -gt 0) {
        throw "$($primaryError.Exception.Message) Dodatkowo: $($cleanupErrors -join ' ')"
    }
    throw $primaryError
}
if ($cleanupErrors.Count -gt 0) {
    throw ($cleanupErrors -join ' ')
}

$finalLive = Invoke-RestMethod -Uri "$BaseUrl/health/live" -TimeoutSec 5
if ($finalLive.status -ne 'ok') {
    throw 'Proxy nie jest zdrowe po przywroceniu app-1.'
}

Write-Host 'OK: logowanie wykonano na app-1, a te sama sesje obsluzyl app-2.'
Write-Host 'OK: app-2 odczytal wspolny cache i ten sam obiekt z MinIO.'
Write-Host 'OK: dwukrotne logowanie nie utworzylo podwojnych zadan ani naliczen.'
Write-Host 'OK: po zatrzymaniu app-1 zalogowane zadania dzialaly przez proxy na app-2.'
Write-Host 'OK: app-1 przywrocono, a tymczasowe dane ETAPU 8 usunieto.'
