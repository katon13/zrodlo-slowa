[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost:8080',
    [ValidateRange(100, 60000)][int]$P95Ms = 2500,
    [ValidateRange(1, 50)][int]$ReadVus = 4,
    [ValidateRange(2, 50)][int]$SameUserVus = 6,
    [ValidateRange(1, 100)][int]$ScaleVus = 8
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Invoke-Compose {
    param(
        [Parameter(Mandatory)]
        [string[]]$Arguments,
        [switch]$ReturnOutput
    )

    $commandOutput = @(& docker compose @Arguments)
    if ($LASTEXITCODE -ne 0) {
        $safeArguments = $Arguments | ForEach-Object {
            if ($_ -like 'STAGE9_STATE_BASE64=*') {
                'STAGE9_STATE_BASE64=[ukryto]'
            }
            elseif ($_ -like '--state-base64=*') {
                '--state-base64=[ukryto]'
            }
            else {
                $_
            }
        }
        throw "Polecenie docker compose $($safeArguments -join ' ') zakonczylo sie kodem $LASTEXITCODE."
    }
    if ($ReturnOutput) {
        return ($commandOutput -join "`n").Trim()
    }
    foreach ($line in $commandOutput) {
        Write-Host $line
    }
}

function Test-ServiceRunning {
    param([Parameter(Mandatory)][string]$Service)
    $containerId = (Invoke-Compose -Arguments @('ps', '-q', $Service) -ReturnOutput)
    if (-not $containerId) {
        return $false
    }
    $status = @(& docker inspect --format '{{.State.Running}}' $containerId)
    return $LASTEXITCODE -eq 0 -and ($status -join '').Trim() -eq 'true'
}

function Wait-ServiceHealthy {
    param(
        [Parameter(Mandatory)][string]$Service,
        [int]$TimeoutSeconds = 120,
        [switch]$LoadProfile
    )
    $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    do {
        $arguments = if ($LoadProfile) { @('--profile', 'loadtest', 'ps', '-q', $Service) } else { @('ps', '-q', $Service) }
        $containerId = (Invoke-Compose -Arguments $arguments -ReturnOutput)
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

function Invoke-K6Suite {
    param(
        [Parameter(Mandatory)][string]$Suite,
        [Parameter(Mandatory)][string]$TargetUrl,
        [Parameter(Mandatory)][string]$ExpectedInstances,
        [Parameter(Mandatory)][string]$StateEncoded
    )
    $resultName = if ($Suite -eq 'scale') { 'stage9-scale.json' } else { 'stage9-core.json' }
    Invoke-Compose -Arguments @(
        '--profile', 'loadtest', 'run', '--rm',
        '-e', "STAGE9_STATE_BASE64=$StateEncoded",
        '-e', "STAGE9_SUITE=$Suite",
        '-e', "STAGE9_BASE_URL=$TargetUrl",
        '-e', "STAGE9_EXPECTED_INSTANCES=$ExpectedInstances",
        '-e', "STAGE9_P95_MS=$P95Ms",
        '-e', "STAGE9_READ_VUS=$ReadVus",
        '-e', "STAGE9_SAME_USER_VUS=$SameUserVus",
        '-e', "STAGE9_SCALE_VUS=$ScaleVus",
        'loadtest', 'run', "--summary-export=/results/$resultName", '/scripts/stage9.js'
    )
}

if ($BaseUrl -ne 'http://localhost:8080') {
    throw 'Test ETAPU 9 jest ograniczony do izolowanego proxy Docker http://localhost:8080.'
}

$stateEncoded = $null
$workerWasRunning = Test-ServiceRunning -Service 'worker-earnings'
$schedulerWasRunning = Test-ServiceRunning -Service 'scheduler'
$loadServicesStarted = $false
$primaryError = $null
$cleanupErrors = [System.Collections.Generic.List[string]]::new()

try {
    Wait-ServiceHealthy -Service 'app-1'
    Wait-ServiceHealthy -Service 'app-2'
    Wait-ServiceHealthy -Service 'proxy'
    if (-not $workerWasRunning) {
        throw 'Worker naliczen musi dzialac przed testem, aby mozna bylo sprawdzic jego kontrolowana awarie i powrot.'
    }

    $stateJson = Invoke-Compose -Arguments @(
        'exec', '-T', 'app-1', 'php', 'scripts/stage9_fixtures.php', '--prepare'
    ) -ReturnOutput
    $state = $stateJson | ConvertFrom-Json
    if ($state.version -ne 1 -or -not $state.article_id -or $state.users.Count -lt 2) {
        throw 'Przygotowanie ETAPU 9 nie zwrocilo kompletnego stanu.'
    }
    $stateEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($stateJson))

    Invoke-Compose -Arguments @('stop', '--timeout', '15', 'worker-earnings')
    if ($schedulerWasRunning) {
        Invoke-Compose -Arguments @('stop', '--timeout', '15', 'scheduler')
    }

    $retryJson = Invoke-Compose -Arguments @(
        'exec', '-T', 'app-2', 'php', 'scripts/stage9_fixtures.php', '--inject-retry', "--state-base64=$stateEncoded"
    ) -ReturnOutput
    $retry = $retryJson | ConvertFrom-Json
    if ($retry.status -ne 'retry_ready' -or $retry.attempts -ne 1) {
        throw 'Kontrolowane zadanie retry nie osiagnelo oczekiwanego stanu.'
    }

    $liveDuringOutage = Invoke-RestMethod -Uri "$BaseUrl/health/live" -TimeoutSec 10
    if ($liveDuringOutage.status -ne 'ok') {
        throw 'Sciezka HTTP nie dziala po zatrzymaniu workera naliczen.'
    }

    Invoke-K6Suite -Suite 'core' -TargetUrl 'http://proxy:8080' -ExpectedInstances 'app-1,app-2' -StateEncoded $stateEncoded

    $pendingJson = Invoke-Compose -Arguments @(
        'exec', '-T', 'app-2', 'php', 'scripts/stage9_fixtures.php', '--verify-pending', "--state-base64=$stateEncoded"
    ) -ReturnOutput
    $pending = $pendingJson | ConvertFrom-Json
    if ($pending.status -ne 'worker_outage_verified') {
        throw 'Nie potwierdzono trwalosci kolejki podczas awarii workera.'
    }

    Invoke-Compose -Arguments @('start', 'worker-earnings')
    $completeJson = Invoke-Compose -Arguments @(
        'exec', '-T', 'app-2', 'php', 'scripts/stage9_fixtures.php', '--verify-complete', "--state-base64=$stateEncoded"
    ) -ReturnOutput
    $complete = $completeJson | ConvertFrom-Json
    if ($complete.status -ne 'completed' -or -not $complete.ledger.ok) {
        throw 'Weryfikacja naliczen i ksiegi po powrocie workera nie powiodla sie.'
    }

    Invoke-Compose -Arguments @('--profile', 'loadtest', 'up', '-d', 'app-3', 'proxy-load')
    $loadServicesStarted = $true
    Wait-ServiceHealthy -Service 'app-3' -LoadProfile
    Wait-ServiceHealthy -Service 'proxy-load' -LoadProfile
    Invoke-K6Suite -Suite 'scale' -TargetUrl 'http://proxy-load:8080' -ExpectedInstances 'app-1,app-2,app-3' -StateEncoded $stateEncoded
}
catch {
    $primaryError = $_
}
finally {
    if ($stateEncoded) {
        try {
            if (Test-ServiceRunning -Service 'worker-earnings') {
                Invoke-Compose -Arguments @('stop', '--timeout', '15', 'worker-earnings')
            }
            $cleanupJson = Invoke-Compose -Arguments @(
                'exec', '-T', 'app-2', 'php', 'scripts/stage9_fixtures.php', '--cleanup', "--state-base64=$stateEncoded"
            ) -ReturnOutput
            $cleanup = $cleanupJson | ConvertFrom-Json
            if ($cleanup.status -ne 'clean') {
                throw 'Skrypt nie potwierdzil usuniecia danych testowych.'
            }
        }
        catch {
            $cleanupErrors.Add("Nie udalo sie posprzatac danych ETAPU 9: $($_.Exception.Message)")
        }
    }

    if ($workerWasRunning) {
        try {
            Invoke-Compose -Arguments @('start', 'worker-earnings')
        }
        catch {
            $cleanupErrors.Add("Nie udalo sie przywrocic workera naliczen: $($_.Exception.Message)")
        }
    }
    if ($schedulerWasRunning) {
        try {
            Invoke-Compose -Arguments @('start', 'scheduler')
        }
        catch {
            $cleanupErrors.Add("Nie udalo sie przywrocic schedulera: $($_.Exception.Message)")
        }
    }
    if ($loadServicesStarted) {
        try {
            Invoke-Compose -Arguments @('--profile', 'loadtest', 'stop', '--timeout', '15', 'proxy-load', 'app-3')
            Invoke-Compose -Arguments @('--profile', 'loadtest', 'rm', '-f', 'proxy-load', 'app-3')
        }
        catch {
            $cleanupErrors.Add("Nie udalo sie usunac tymczasowej trzeciej instancji: $($_.Exception.Message)")
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

$finalReady = Invoke-RestMethod -Uri "$BaseUrl/health/ready" -TimeoutSec 10
if ($finalReady.status -ne 'ok' -or $finalReady.check -ne 'readiness') {
    throw 'Aplikacja nie jest gotowa po zakonczeniu ETAPU 9.'
}

Write-Host 'OK: k6 sprawdzil artykuly, logowanie, konto, portfel i naliczanie zarobku.'
Write-Host 'OK: jednoczesne zadania tego samego uzytkownika utworzyly jedno naliczenie i jedna transakcje.'
Write-Host 'OK: kolejka przetrwala awarie workera, a wygasla dzierzawa zakonczyla sie poprawnym retry.'
Write-Host 'OK: profil skalowania obsluzyl ruch przez app-1, app-2 i tymczasowe app-3.'
Write-Host 'OK: salda i lancuchy ksiag sa spojne, dane testowe usunieto, worker i scheduler przywrocono.'
