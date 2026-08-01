[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost:8080',
    [int]$PostgresPort = 5433,
    [int]$ValkeyPort = 6380,
    [int]$MinioConsolePort = 19001,
    [int]$MailpitPort = 8025
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Test-TcpPort {
    param(
        [Parameter(Mandatory)]
        [string]$TargetHost,
        [Parameter(Mandatory)]
        [int]$Port
    )

    $client = [System.Net.Sockets.TcpClient]::new()
    try {
        $task = $client.ConnectAsync($TargetHost, $Port)
        if (-not $task.Wait([TimeSpan]::FromSeconds(3))) {
            throw "Przekroczono czas oczekiwania na ${TargetHost}:${Port}."
        }
    }
    finally {
        $client.Dispose()
    }
}

$live = Invoke-RestMethod -Uri "$BaseUrl/health/live" -TimeoutSec 5
if ($live.status -ne 'ok') {
    throw "Liveness zwrocil status '$($live.status)'."
}

$ready = Invoke-RestMethod -Uri "$BaseUrl/health/ready" -TimeoutSec 5
if ($ready.status -ne 'ok' -or -not $ready.checks.postgres) {
    throw 'Readiness nie potwierdzil polaczenia z PostgreSQL.'
}

$instances = [System.Collections.Generic.HashSet[string]]::new()
1..20 | ForEach-Object {
    $response = Invoke-RestMethod -Uri "$BaseUrl/health/live" -TimeoutSec 5
    [void]$instances.Add([string]$response.instance)
}

if (-not $instances.Contains('app-1') -or -not $instances.Contains('app-2')) {
    throw "Proxy nie potwierdzil obu instancji. Zaobserwowano: $($instances -join ', ')."
}

Test-TcpPort -TargetHost '127.0.0.1' -Port $PostgresPort
Test-TcpPort -TargetHost '127.0.0.1' -Port $ValkeyPort

$minio = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$MinioConsolePort" -TimeoutSec 5
$mailpit = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$MailpitPort" -TimeoutSec 5
if ($minio.StatusCode -notin 200, 302, 307 -or $mailpit.StatusCode -ne 200) {
    throw 'Panel MinIO lub Mailpit nie odpowiedzial prawidlowo.'
}

Write-Host "OK: proxy rozdziela ruch na $($instances.Count) instancje aplikacji."
Write-Host 'OK: PostgreSQL, Valkey, MinIO i Mailpit sa dostepne na portach Docker.'
