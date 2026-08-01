<?php
declare(strict_types=1);

use App\Core\Database;
use App\Infrastructure\Cache\ValkeyCacheStore;
use App\Infrastructure\Session\SharedSessionHandler;
use App\Infrastructure\Storage\ObjectStorageFactory;
use App\Infrastructure\Valkey\PhpRedisValkeyClient;
use App\Infrastructure\Valkey\ValkeyDistributedLock;
use App\Services\CacheService;

require_once dirname(__DIR__) . '/app/Core/bootstrap.php';

const STAGE8_VERSION = 1;
const STAGE8_USER_PREFIX = 'stage8-two-instances-';
const STAGE8_CACHE_PREFIX = 'stage8_two_instances:';
const STAGE8_OBJECT_PREFIX = 'public/stage8-two-instances/';
const STAGE8_REWARD_TYPES = ['day_visit_bonus'];

/** @return never */
function stage8Fail(string $message): void
{
    throw new RuntimeException($message);
}

function stage8Assert(bool $condition, string $message): void
{
    if (!$condition) {
        stage8Fail($message);
    }
}

function stage8Database(string $rootPath): Database
{
    $config = require $rootPath . '/config/database.php';
    $database = new Database($config['default']);
    stage8Assert($database->isPostgres(), 'ETAP 8 wymaga PostgreSQL w izolowanym środowisku Docker.');
    return $database;
}

/** @return array{client: PhpRedisValkeyClient, cache: CacheService} */
function stage8Cache(string $rootPath): array
{
    $config = require $rootPath . '/config/valkey.php';
    stage8Assert(($config['cache_driver'] ?? '') === 'valkey', 'ETAP 8 wymaga współdzielonego cache Valkey.');
    $client = PhpRedisValkeyClient::connect($config);
    stage8Assert($client->ping(), 'Valkey nie odpowiedział na PING.');
    return [
        'client' => $client,
        'cache' => new CacheService(
            new ValkeyCacheStore($client),
            new ValkeyDistributedLock($client),
        ),
    ];
}

/**
 * @param array<string,string> $headers
 * @return array{status:int,body:string,headers:array<string,list<string>>}
 */
function stage8Http(string $url, string $method = 'GET', array $headers = [], ?string $body = null): array
{
    $responseHeaders = [];
    $curl = curl_init($url);
    stage8Assert($curl !== false, 'Nie udało się utworzyć klienta HTTP.');

    $headerLines = ['Host: localhost', 'User-Agent: zrodlo-slowa-stage8-acceptance/1'];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $separator = strpos($line, ':');
            if ($separator !== false) {
                $name = strtolower(trim(substr($line, 0, $separator)));
                $responseHeaders[$name] ??= [];
                $responseHeaders[$name][] = trim(substr($line, $separator + 1));
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    stage8Assert(is_string($responseBody), 'Żądanie HTTP nie powiodło się: ' . ($error !== '' ? $error : 'brak odpowiedzi'));

    return ['status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders];
}

function stage8Header(array $response, string $name): ?string
{
    $values = $response['headers'][strtolower($name)] ?? [];
    return $values !== [] ? (string)end($values) : null;
}

function stage8CookieFromResponse(array $response, string $cookieName): ?string
{
    foreach ($response['headers']['set-cookie'] ?? [] as $cookie) {
        if (preg_match('/(?:^|;\s*)' . preg_quote($cookieName, '/') . '=([^;]*)/i', $cookie, $match) === 1) {
            return rawurldecode($match[1]);
        }
    }
    return null;
}

function stage8CsrfFromResponse(array $response): string
{
    stage8Assert($response['status'] === 200, 'Formularz logowania nie zwrócił HTTP 200.');
    stage8Assert(
        preg_match('/name=["\']_csrf["\'][^>]*value=["\']([^"\']+)["\']/i', $response['body'], $match) === 1,
        'Nie znaleziono tokenu CSRF formularza logowania.'
    );
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** @return array{session_id:string,response:array,anonymous_session_id:string} */
function stage8Login(string $instance, string $email, string $password, string $sessionName, ?string $sessionId = null): array
{
    $baseUrl = 'http://' . $instance . ':8080';
    $cookieHeader = $sessionId !== null ? ['Cookie' => $sessionName . '=' . rawurlencode($sessionId)] : [];
    $form = stage8Http($baseUrl . '/login', 'GET', $cookieHeader);
    stage8Assert(stage8Header($form, 'x-app-instance') === $instance, "Formularza logowania nie obsłużył {$instance}.");
    $anonymousSessionId = stage8CookieFromResponse($form, $sessionName) ?? $sessionId;
    stage8Assert(is_string($anonymousSessionId) && $anonymousSessionId !== '', 'Brak identyfikatora sesji formularza logowania.');

    $post = stage8Http(
        $baseUrl . '/login',
        'POST',
        [
            'Cookie' => $sessionName . '=' . rawurlencode($anonymousSessionId),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        http_build_query([
            '_csrf' => stage8CsrfFromResponse($form),
            'email' => $email,
            'password' => $password,
        ])
    );
    stage8Assert(in_array($post['status'], [302, 303], true), "Logowanie na {$instance} nie zakończyło się przekierowaniem.");
    stage8Assert(stage8Header($post, 'x-app-instance') === $instance, "Logowania nie obsłużył {$instance}.");
    $authenticatedSessionId = stage8CookieFromResponse($post, $sessionName);
    stage8Assert(
        is_string($authenticatedSessionId) && $authenticatedSessionId !== '' && $authenticatedSessionId !== $anonymousSessionId,
        'Logowanie nie zregenerowało identyfikatora sesji.'
    );

    return [
        'session_id' => $authenticatedSessionId,
        'response' => $post,
        'anonymous_session_id' => $anonymousSessionId,
    ];
}

/** @return array{status:int,body:string,headers:array<string,list<string>>} */
function stage8AssertAuthenticatedPage(string $instance, string $sessionName, string $sessionId): array
{
    $response = stage8Http(
        'http://' . $instance . ':8080/account/settings',
        'GET',
        ['Cookie' => $sessionName . '=' . rawurlencode($sessionId)]
    );
    stage8Assert($response['status'] === 200, "Sesja nie dała dostępu do chronionej strony na {$instance}.");
    stage8Assert(stage8Header($response, 'x-app-instance') === $instance, "Chronionej strony nie obsłużył {$instance}.");
    stage8Assert(
        str_contains($response['body'], 'id="account-settings-form"'),
        "Odpowiedź z {$instance} nie potwierdza zalogowanej sesji."
    );
    return $response;
}

/** @param array{status:int,body:string,headers:array<string,list<string>>} $authenticatedPage */
function stage8PingPresence(
    string $instance,
    string $sessionName,
    string $sessionId,
    array $authenticatedPage,
): void {
    $csrf = stage8CsrfFromResponse($authenticatedPage);
    $response = stage8Http(
        'http://' . $instance . ':8080/api/earnings/presence',
        'POST',
        [
            'Cookie' => $sessionName . '=' . rawurlencode($sessionId),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-CSRF-TOKEN' => $csrf,
            'X-Requested-With' => 'XMLHttpRequest',
        ],
        http_build_query(['visible' => '1'])
    );
    stage8Assert($response['status'] === 200, 'Ping obecności zalogowanego użytkownika nie zwrócił HTTP 200.');
    $payload = json_decode($response['body'], true);
    stage8Assert(
        is_array($payload) && ($payload['ok'] ?? false) === true && !empty($payload['job_public_id']),
        'Ping obecności nie utworzył zadania nagrody dziennej.'
    );
}

/** @return array<string,array<string,int>> */
function stage8Rules(Database $database): array
{
    $rows = $database->all(
        "SELECT activity_type,points_amount,amount_minor,daily_limit,is_active
         FROM activity_reward_rules
         WHERE activity_type IN ('login_bonus','day_visit_bonus')
         ORDER BY activity_type"
    );
    $rules = [];
    foreach ($rows as $row) {
        $rules[(string)$row['activity_type']] = [
            'present' => 1,
            'points_amount' => (int)$row['points_amount'],
            'amount_minor' => (int)$row['amount_minor'],
            'daily_limit' => (int)$row['daily_limit'],
            'is_active' => (int)$row['is_active'],
        ];
    }
    foreach (STAGE8_REWARD_TYPES as $type) {
        $rules[$type] ??= [
            'present' => 0,
            'points_amount' => 0,
            'amount_minor' => 0,
            'daily_limit' => 0,
            'is_active' => 0,
        ];
    }
    return $rules;
}

/** @param array<string,string> $jobKeys */
function stage8WaitForJobs(Database $database, array $jobKeys, int $timeoutSeconds = 45): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $completed = 0;
        foreach ($jobKeys as $key) {
            $job = $database->one(
                'SELECT status,last_error FROM background_jobs WHERE queue_name=:queue AND idempotency_key=:key LIMIT 1',
                ['queue' => 'earnings.critical', 'key' => $key]
            );
            if (($job['status'] ?? '') === 'completed') {
                $completed++;
            } elseif (in_array((string)($job['status'] ?? ''), ['dead_letter', 'rejected', 'cancelled'], true)) {
                stage8Fail('Zadanie naliczenia zakończyło się stanem ' . $job['status'] . '.');
            }
        }
        if ($completed === count($jobKeys)) {
            return;
        }
        usleep(250_000);
    } while (microtime(true) < $deadline);

    stage8Fail('Worker naliczeń nie zakończył zadań ETAPU 8 w wyznaczonym czasie.');
}

/**
 * @param array<string,string> $jobKeys
 * @param array<string,array<string,int>> $rules
 * @return array{jobs:int,reward_logs:int,wallet_transactions:int,points_balance:int}
 */
function stage8AssertRewards(Database $database, int $userId, int $walletId, array $jobKeys, array $rules): array
{
    $expectedAwards = 0;
    $expectedPoints = 0;
    $actualLogs = 0;
    $actualTransactions = 0;

    foreach (STAGE8_REWARD_TYPES as $type) {
        $key = $jobKeys[$type] ?? '';
        stage8Assert($key !== '', "Brak klucza idempotencji zadania {$type}.");
        $jobs = $database->all(
            'SELECT status FROM background_jobs WHERE queue_name=:queue AND idempotency_key=:key',
            ['queue' => 'earnings.critical', 'key' => $key]
        );
        stage8Assert(count($jobs) === 1, "Zadanie {$type} nie jest unikalne.");
        stage8Assert(($jobs[0]['status'] ?? '') === 'completed', "Zadanie {$type} nie jest zakończone.");

        $rule = $rules[$type];
        $shouldAward = $rule['present'] === 1
            && $rule['is_active'] === 1
            && ($rule['points_amount'] > 0 || $rule['amount_minor'] > 0)
            && ($rule['daily_limit'] === 0 || $rule['daily_limit'] >= 1);
        $expectedForType = $shouldAward ? 1 : 0;
        $expectedAwards += $expectedForType;
        $expectedPoints += $expectedForType * $rule['points_amount'];

        $logCount = (int)$database->cell(
            'SELECT COUNT(*) FROM activity_reward_logs WHERE user_id=:user AND activity_type=:type',
            ['user' => $userId, 'type' => $type]
        );
        $transactionCount = (int)$database->cell(
            'SELECT COUNT(*) FROM wallet_transactions WHERE user_id=:user AND wallet_id=:wallet AND type=:type',
            ['user' => $userId, 'wallet' => $walletId, 'type' => $type]
        );
        stage8Assert($logCount === $expectedForType, "Naliczenie {$type} zostało wykonane nieprawidłową liczbę razy.");
        stage8Assert($transactionCount === $expectedForType, "Transakcja {$type} została zaksięgowana nieprawidłową liczbę razy.");
        $actualLogs += $logCount;
        $actualTransactions += $transactionCount;
    }

    $pointsBalance = (int)$database->cell('SELECT points_balance FROM wallets WHERE id=:wallet', ['wallet' => $walletId]);
    stage8Assert($pointsBalance === $expectedPoints, 'Saldo punktów nie odpowiada dokładnie pojedynczym naliczeniom.');

    return [
        'jobs' => count($jobKeys),
        'reward_logs' => $actualLogs,
        'wallet_transactions' => $actualTransactions,
        'points_balance' => $pointsBalance,
    ];
}

/** @return array<string,mixed> */
function stage8DecodeState(array $arguments): array
{
    $encoded = null;
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--state-base64=')) {
            $encoded = substr($argument, strlen('--state-base64='));
            break;
        }
    }
    stage8Assert(is_string($encoded) && $encoded !== '' && strlen($encoded) < 131_072, 'Brak prawidłowego stanu ETAPU 8.');
    $json = base64_decode($encoded, true);
    stage8Assert(is_string($json), 'Stan ETAPU 8 nie jest prawidłowym Base64.');
    $state = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    stage8Assert(is_array($state), 'Stan ETAPU 8 nie jest obiektem JSON.');
    stage8ValidateState($state);
    return $state;
}

/** @param array<string,mixed> $state */
function stage8ValidateState(array $state): void
{
    stage8Assert(($state['version'] ?? null) === STAGE8_VERSION, 'Nieobsługiwana wersja stanu ETAPU 8.');
    stage8Assert(preg_match('/^[a-f0-9]{24}$/D', (string)($state['token'] ?? '')) === 1, 'Nieprawidłowy token ETAPU 8.');
    stage8Assert(str_starts_with((string)($state['email'] ?? ''), STAGE8_USER_PREFIX), 'Stan nie wskazuje użytkownika ETAPU 8.');
    stage8Assert((int)($state['user_id'] ?? 0) > 0 && (int)($state['wallet_id'] ?? 0) > 0, 'Stan nie zawiera identyfikatorów danych testowych.');
    stage8Assert(str_starts_with((string)($state['cache_key'] ?? ''), STAGE8_CACHE_PREFIX), 'Nieprawidłowy klucz cache ETAPU 8.');
    stage8Assert(str_starts_with((string)($state['object_reference'] ?? ''), '/objects/'), 'Nieprawidłowa referencja obiektu ETAPU 8.');
    stage8Assert(is_array($state['sessions'] ?? null) && count($state['sessions']) >= 1, 'Stan nie zawiera sesji ETAPU 8.');
}

/** @return array<string,mixed> */
function stage8Prepare(string $rootPath): array
{
    stage8Assert((string)env('APP_ENV', '') === 'local', 'Test ETAPU 8 wolno uruchamiać wyłącznie w APP_ENV=local.');
    stage8Assert((string)env('APP_INSTANCE_ID', '') === 'app-1', 'Przygotowanie ETAPU 8 musi działać w app-1.');
    $database = stage8Database($rootPath);
    $cacheBundle = stage8Cache($rootPath);
    $storageConfig = require $rootPath . '/config/storage.php';
    stage8Assert(($storageConfig['driver'] ?? '') === 's3', 'ETAP 8 wymaga współdzielonego magazynu S3/MinIO.');
    $storage = ObjectStorageFactory::create($rootPath, $storageConfig);
    stage8Assert($storage->healthCheck(), 'Magazyn S3/MinIO nie jest gotowy.');

    $token = bin2hex(random_bytes(12));
    $email = STAGE8_USER_PREFIX . $token . '@example.test';
    $password = 'Stage8!' . bin2hex(random_bytes(16));
    $sessionConfig = require $rootPath . '/config/app.php';
    $sessionName = (string)$sessionConfig['session_name'];
    $state = [
        'version' => STAGE8_VERSION,
        'token' => $token,
        'email' => $email,
        'user_id' => 0,
        'wallet_id' => 0,
        'session_name' => $sessionName,
        'sessions' => [],
        'cache_key' => STAGE8_CACHE_PREFIX . $token,
        'cache_value' => ['origin' => 'app-1', 'token' => $token],
        'object_reference' => '',
        'object_sha256' => '',
        'job_keys' => [],
        'rules' => [],
    ];

    try {
        $ids = $database->transaction(function (Database $db) use ($email, $password, $token): array {
            $userId = $db->insert(
                'INSERT INTO users(email,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,created_at)
                 VALUES(:email,:hash,:name,\'active\',0,1,1,0,NOW())',
                [
                    'email' => $email,
                    'hash' => password_hash($password . env('PASSWORD_PEPPER', ''), PASSWORD_DEFAULT),
                    'name' => 'Stage 8 ' . strtoupper(substr($token, 0, 8)),
                ]
            );
            $db->query('INSERT INTO user_roles(user_id,role) VALUES(:user,\'reader\')', ['user' => $userId]);
            $walletId = $db->insert(
                'INSERT INTO wallets(user_id,main_available_minor,main_reserved_minor,slowo_available_minor,slowo_reserved_minor,available_minor,pending_minor,reserved_minor,points_balance,currency,created_at)
                 VALUES(:user,0,0,0,0,0,0,0,0,\'PLN\',NOW())',
                ['user' => $userId]
            );
            return ['user_id' => $userId, 'wallet_id' => $walletId];
        });
        $state['user_id'] = $ids['user_id'];
        $state['wallet_id'] = $ids['wallet_id'];

        $cacheBundle['cache']->set($state['cache_key'], $state['cache_value'], 600);
        $cached = $cacheBundle['cache']->get($state['cache_key']);
        stage8Assert($cached['hit'] === true && $cached['value'] === $state['cache_value'], 'app-1 nie odczytał zapisanego cache.');

        $source = tempnam(sys_get_temp_dir(), 'zs_stage8_');
        stage8Assert(is_string($source), 'Nie udało się utworzyć tymczasowego obrazu.');
        try {
            $image = imagecreatetruecolor(4, 4);
            stage8Assert($image instanceof GdImage, 'Rozszerzenie GD nie utworzyło obrazu testowego.');
            $color = imagecolorallocate($image, 31, 79, 121);
            imagefill($image, 0, 0, $color);
            stage8Assert(imagewebp($image, $source, 82), 'Nie udało się zapisać obrazu WebP.');
            imagedestroy($image);
            $bytes = file_get_contents($source);
            stage8Assert(is_string($bytes), 'Nie udało się odczytać obrazu testowego.');
            $state['object_sha256'] = hash('sha256', $bytes);
            $state['object_reference'] = $storage->putFile(STAGE8_OBJECT_PREFIX . $token . '.webp', $source, 'image/webp');
        } finally {
            @unlink($source);
        }
        $objectOnApp1 = stage8Http('http://app-1:8080' . $state['object_reference']);
        stage8Assert($objectOnApp1['status'] === 200, 'app-1 nie odczytał pliku z MinIO.');
        stage8Assert(hash('sha256', $objectOnApp1['body']) === $state['object_sha256'], 'app-1 zwrócił inną zawartość pliku.');
        stage8Assert(stage8Header($objectOnApp1, 'x-app-instance') === 'app-1', 'Pliku nie obsłużył app-1.');

        $firstLogin = stage8Login('app-1', $email, $password, $sessionName);
        $state['sessions'][] = $firstLogin['anonymous_session_id'];
        $state['sessions'][] = $firstLogin['session_id'];
        stage8AssertAuthenticatedPage('app-2', $sessionName, $firstLogin['session_id']);

        $secondLogin = stage8Login('app-2', $email, $password, $sessionName, $firstLogin['session_id']);
        $state['sessions'][] = $secondLogin['anonymous_session_id'];
        $state['sessions'][] = $secondLogin['session_id'];
        $state['active_session_id'] = $secondLogin['session_id'];
        $authenticatedPage = stage8AssertAuthenticatedPage('app-2', $sessionName, $secondLogin['session_id']);
        stage8PingPresence('app-2', $sessionName, $secondLogin['session_id'], $authenticatedPage);

        $day = gmdate('Y-m-d');
        foreach (STAGE8_REWARD_TYPES as $type) {
            $reference = $type === 'day_visit_bonus' ? 'presence-day:' : 'day:';
            $state['job_keys'][$type] = "talent:{$state['user_id']}:{$type}:{$reference}{$day}";
        }
        $state['rules'] = stage8Rules($database);
        stage8WaitForJobs($database, $state['job_keys']);
        $state['reward_summary'] = stage8AssertRewards(
            $database,
            (int)$state['user_id'],
            (int)$state['wallet_id'],
            $state['job_keys'],
            $state['rules'],
        );
        $shadowUserId = (int)$database->cell(
            'SELECT user_id FROM sessions WHERE id=:id',
            ['id' => $state['active_session_id']]
        );
        stage8Assert($shadowUserId === (int)$state['user_id'], 'PostgreSQL nie zawiera prawidłowej kopii aktywnej sesji.');

        return $state;
    } catch (Throwable $error) {
        try {
            stage8Cleanup($rootPath, $state, false);
        } catch (Throwable $cleanupError) {
            error_log('Niepełne sprzątanie ETAPU 8: ' . $cleanupError->getMessage());
        }
        throw $error;
    }
}

/** @param array<string,mixed> $state */
function stage8Verify(string $rootPath, array $state): array
{
    stage8Assert((string)env('APP_ENV', '') === 'local', 'Weryfikację ETAPU 8 wolno uruchamiać wyłącznie lokalnie.');
    stage8Assert((string)env('APP_INSTANCE_ID', '') === 'app-2', 'Weryfikacja awaryjna musi działać w app-2.');
    $database = stage8Database($rootPath);
    $user = $database->one('SELECT id,email FROM users WHERE id=:id AND email=:email', [
        'id' => (int)$state['user_id'],
        'email' => (string)$state['email'],
    ]);
    stage8Assert($user !== null, 'Po wyłączeniu app-1 brakuje użytkownika testowego.');
    stage8Assert(
        (int)$database->cell('SELECT user_id FROM sessions WHERE id=:id', ['id' => $state['active_session_id']]) === (int)$state['user_id'],
        'Po wyłączeniu app-1 brakuje współdzielonej sesji.'
    );

    $cacheBundle = stage8Cache($rootPath);
    $cached = $cacheBundle['cache']->get((string)$state['cache_key']);
    stage8Assert($cached['hit'] === true, 'app-2 nie odczytał cache zapisanego przez app-1.');
    stage8Assert($cached['value'] === $state['cache_value'], 'Cache app-2 ma inną wartość niż cache app-1.');

    $storage = ObjectStorageFactory::create($rootPath, require $rootPath . '/config/storage.php');
    $stored = $storage->read((string)$state['object_reference']);
    stage8Assert(hash('sha256', $stored->contents) === $state['object_sha256'], 'app-2 odczytał inną zawartość obiektu S3.');
    $objectOnApp2 = stage8Http('http://app-2:8080' . $state['object_reference']);
    stage8Assert($objectOnApp2['status'] === 200, 'app-2 nie udostępnił pliku z MinIO.');
    stage8Assert(stage8Header($objectOnApp2, 'x-app-instance') === 'app-2', 'Pliku po awarii nie obsłużył app-2.');
    stage8Assert(hash('sha256', $objectOnApp2['body']) === $state['object_sha256'], 'HTTP app-2 zwrócił inną zawartość pliku.');

    stage8AssertAuthenticatedPage(
        'app-2',
        (string)$state['session_name'],
        (string)$state['active_session_id'],
    );
    $rewardSummary = stage8AssertRewards(
        $database,
        (int)$state['user_id'],
        (int)$state['wallet_id'],
        (array)$state['job_keys'],
        (array)$state['rules'],
    );

    return [
        'status' => 'ok',
        'instance' => 'app-2',
        'session' => 'shared',
        'cache' => 'shared',
        'object_storage' => 'shared',
        'rewards' => $rewardSummary,
    ];
}

/** @param array<string,mixed> $state */
function stage8Cleanup(string $rootPath, array $state, bool $validate = true): array
{
    if ($validate) {
        stage8ValidateState($state);
    }
    $database = stage8Database($rootPath);
    $userId = (int)($state['user_id'] ?? 0);
    $walletId = (int)($state['wallet_id'] ?? 0);
    $email = (string)($state['email'] ?? '');

    if (isset($state['cache_key']) && str_starts_with((string)$state['cache_key'], STAGE8_CACHE_PREFIX)) {
        stage8Cache($rootPath)['cache']->forget((string)$state['cache_key']);
    }
    if (isset($state['object_reference']) && str_starts_with((string)$state['object_reference'], '/objects/')) {
        $storage = ObjectStorageFactory::create($rootPath, require $rootPath . '/config/storage.php');
        if ($storage->exists((string)$state['object_reference'])) {
            $storage->delete((string)$state['object_reference']);
        }
    }

    $sessionHandler = new SharedSessionHandler(
        stage8Cache($rootPath)['client'],
        $database,
        86400,
    );
    foreach (array_unique(array_filter((array)($state['sessions'] ?? []), 'is_string')) as $sessionId) {
        if (preg_match('/^[A-Za-z0-9,-]{1,128}$/D', $sessionId) === 1) {
            $sessionHandler->destroy($sessionId);
        }
    }

    if ($userId > 0 && str_starts_with($email, STAGE8_USER_PREFIX)) {
        $deadline = microtime(true) + 15;
        do {
            $running = 0;
            foreach ((array)($state['job_keys'] ?? []) as $key) {
                $running += (int)$database->cell(
                    'SELECT COUNT(*) FROM background_jobs WHERE queue_name=:queue AND idempotency_key=:key AND status=\'running\'',
                    ['queue' => 'earnings.critical', 'key' => (string)$key]
                );
            }
            if ($running === 0) {
                break;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        foreach ((array)($state['job_keys'] ?? []) as $key) {
            $database->query(
                'DELETE FROM background_jobs WHERE queue_name=:queue AND idempotency_key=:key',
                ['queue' => 'earnings.critical', 'key' => (string)$key]
            );
        }
        $database->query('DELETE FROM auth_login_events WHERE user_id=:user OR email=:email', ['user' => $userId, 'email' => $email]);
        if ($walletId > 0) {
            $database->query('DELETE FROM financial_operations WHERE wallet_id=:wallet OR transaction_id IN (SELECT id FROM wallet_transactions WHERE wallet_id=:wallet)', ['wallet' => $walletId]);
            $database->query('DELETE FROM financial_audit_log WHERE wallet_id=:wallet OR user_id=:user', ['wallet' => $walletId, 'user' => $userId]);
        }
        $database->query('DELETE FROM users WHERE id=:user AND email=:email', ['user' => $userId, 'email' => $email]);
        stage8Assert(
            (int)$database->cell('SELECT COUNT(*) FROM users WHERE id=:user OR email=:email', ['user' => $userId, 'email' => $email]) === 0,
            'Nie udało się usunąć użytkownika testowego ETAPU 8.'
        );
    }

    return ['status' => 'clean', 'resources_removed' => true];
}

$rootPath = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$mode = in_array('--prepare', $arguments, true)
    ? 'prepare'
    : (in_array('--verify', $arguments, true)
        ? 'verify'
        : (in_array('--cleanup', $arguments, true) ? 'cleanup' : ''));

try {
    stage8Assert($mode !== '', 'Użycie: php scripts/stage8_two_instances.php --prepare|--verify|--cleanup [--state-base64=...]');
    $result = match ($mode) {
        'prepare' => stage8Prepare($rootPath),
        'verify' => stage8Verify($rootPath, stage8DecodeState($arguments)),
        'cleanup' => stage8Cleanup($rootPath, stage8DecodeState($arguments)),
    };
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'mode' => $mode !== '' ? $mode : 'unknown',
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
