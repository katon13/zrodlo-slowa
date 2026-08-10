<?php
declare(strict_types=1);

use App\Core\Database;
use App\Infrastructure\Session\SharedSessionHandler;
use App\Infrastructure\Valkey\PhpRedisValkeyClient;
use App\Services\DurableJobQueue;
use App\Services\EarningsQueueService;
use App\Services\LedgerIntegrityService;

require_once dirname(__DIR__) . '/app/Core/bootstrap.php';

const STAGE9_VERSION = 1;
const STAGE9_USER_PREFIX = 'stage9-load-';
const STAGE9_ACTIVITY = 'bug_report_bonus';
const STAGE9_REFERENCE_PREFIX = 'stage9_';

/** @return never */
function stage9Fail(string $message): void
{
    throw new RuntimeException($message);
}

function stage9Assert(bool $condition, string $message): void
{
    if (!$condition) {
        stage9Fail($message);
    }
}

function stage9Database(string $rootPath): Database
{
    $config = require $rootPath . '/config/database.php';
    $database = new Database($config['default']);
    stage9Assert($database->isPostgres(), 'ETAP 9 wymaga izolowanego PostgreSQL w Dockerze.');
    stage9Assert((string)env('APP_ENV', '') === 'local', 'ETAP 9 wolno uruchamiać wyłącznie w APP_ENV=local.');
    return $database;
}

function stage9Canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('stage9Canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = stage9Canonicalize($item);
    }
    return $value;
}

/** @param array<string,mixed> $state */
function stage9Signature(array $state): string
{
    unset($state['signature']);
    $canonical = json_encode(stage9Canonicalize($state), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $key = hash('sha256', (string)env('APP_KEY', '') . '|zrodlo-slowa-stage9', true);
    return hash_hmac('sha256', $canonical, $key);
}

/** @return array<string,mixed> */
function stage9DecodeState(array $arguments): array
{
    $encoded = null;
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--state-base64=')) {
            $encoded = substr($argument, strlen('--state-base64='));
            break;
        }
    }
    stage9Assert(is_string($encoded) && $encoded !== '' && strlen($encoded) < 262_144, 'Brak prawidłowego stanu ETAPU 9.');
    $json = base64_decode($encoded, true);
    stage9Assert(is_string($json), 'Stan ETAPU 9 nie jest prawidłowym Base64.');
    $state = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    stage9Assert(is_array($state), 'Stan ETAPU 9 nie jest obiektem JSON.');
    stage9ValidateState($state);
    return $state;
}

/** @param array<string,mixed> $state */
function stage9ValidateState(array $state): void
{
    stage9Assert(($state['version'] ?? null) === STAGE9_VERSION, 'Nieobsługiwana wersja stanu ETAPU 9.');
    $signature = (string)($state['signature'] ?? '');
    stage9Assert(preg_match('/^[a-f0-9]{64}$/D', $signature) === 1, 'Stan ETAPU 9 nie ma podpisu HMAC.');
    stage9Assert(hash_equals(stage9Signature($state), $signature), 'Podpis stanu ETAPU 9 jest nieprawidłowy.');
    stage9Assert(preg_match('/^[a-f0-9]{24}$/D', (string)($state['token'] ?? '')) === 1, 'Nieprawidłowy token ETAPU 9.');
    stage9Assert((string)($state['reference_type'] ?? '') === STAGE9_REFERENCE_PREFIX . $state['token'], 'Nieprawidłowy typ referencji ETAPU 9.');
    stage9Assert((int)($state['article_id'] ?? 0) > 0, 'Stan ETAPU 9 nie zawiera artykułu.');
    stage9Assert(is_array($state['users'] ?? null) && count($state['users']) >= 2, 'Stan ETAPU 9 nie zawiera użytkowników.');
    stage9Assert(is_array($state['many_reference_ids'] ?? null) && count($state['many_reference_ids']) === count($state['users']), 'Stan ETAPU 9 nie zawiera referencji dla wszystkich użytkowników.');
    stage9Assert(preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)($state['test_day'] ?? '')) === 1, 'Stan ETAPU 9 nie zawiera dnia testu.');
    stage9Assert((string)($state['password'] ?? '') !== '' && (string)($state['session_name'] ?? '') !== '', 'Stan ETAPU 9 nie zawiera danych sesji testowej.');
    foreach ($state['users'] as $user) {
        stage9Assert(is_array($user), 'Nieprawidłowy użytkownik w stanie ETAPU 9.');
        stage9Assert((int)($user['id'] ?? 0) > 0 && (int)($user['wallet_id'] ?? 0) > 0, 'Brak identyfikatorów użytkownika ETAPU 9.');
        stage9Assert(str_starts_with((string)($user['email'] ?? ''), STAGE9_USER_PREFIX), 'Stan wskazuje użytkownika spoza ETAPU 9.');
    }
}

/** @return array<string,mixed> */
function stage9Prepare(string $rootPath, int $userCount = 6): array
{
    stage9Assert((string)env('APP_INSTANCE_ID', '') === 'app-1', 'Dane ETAPU 9 muszą być przygotowane w app-1.');
    $database = stage9Database($rootPath);
    $userCount = max(2, min(20, $userCount));
    $token = bin2hex(random_bytes(12));
    $password = 'Stage9!' . bin2hex(random_bytes(16));
    $referenceBase = random_int(100_000_000, 1_900_000_000 - $userCount - 10);
    $sessionConfig = require $rootPath . '/config/app.php';
    $state = [
        'version' => STAGE9_VERSION,
        'token' => $token,
        'password' => $password,
        'session_name' => (string)$sessionConfig['session_name'],
        'reference_type' => STAGE9_REFERENCE_PREFIX . $token,
        'same_reference_id' => $referenceBase,
        'many_reference_ids' => [],
        'retry_reference_id' => $referenceBase + $userCount + 1,
        'retry_job_key' => '',
        'article_id' => 0,
        'article_marker' => 'STAGE9-' . strtoupper(substr($token, 0, 10)),
        'test_day' => gmdate('Y-m-d'),
        // Samo logowanie nie nalicza nagrody. Obecność jest testowana osobno
        // przez ETAP 8, a tutaj saldo obejmuje wyłącznie jawne zdarzenia obciążeniowe.
        'automatic_rule_points' => [],
        'users' => [],
        'rule_created' => false,
        'rule_snapshot' => null,
        'signature' => '',
    ];

    try {
        $unrelatedJobs = (int)$database->cell(
            'SELECT COUNT(*) FROM background_jobs
             WHERE job_type=\'earnings.talent_award\'
               AND payload_json->>\'activity_type\'=:type
               AND status IN (\'queued\',\'running\',\'retry\')',
            ['type' => STAGE9_ACTIVITY]
        );
        stage9Assert($unrelatedJobs === 0, 'Przed testem istnieją niezakończone zadania bug_report_bonus; ETAP 9 nie zmieni ich reguły.');
        $rule = $database->one('SELECT * FROM activity_reward_rules WHERE activity_type=:type', ['type' => STAGE9_ACTIVITY]);
        if ($rule === null) {
            $database->query(
                'INSERT INTO activity_reward_rules(activity_type,points_amount,amount_minor,label,daily_limit,is_active,created_at,updated_at)
                 VALUES(:type,1,0,:label,100000,1,NOW(),NOW())',
                ['type' => STAGE9_ACTIVITY, 'label' => 'ETAP 9 — test obciążeniowy']
            );
            $state['rule_created'] = true;
        } else {
            $state['rule_snapshot'] = $rule;
            $database->query(
                'UPDATE activity_reward_rules
                 SET points_amount=1,amount_minor=0,daily_limit=100000,is_active=1,updated_at=NOW()
                 WHERE id=:id',
                ['id' => (int)$rule['id']]
            );
        }

        for ($index = 0; $index < $userCount; $index++) {
            $email = STAGE9_USER_PREFIX . $token . '-' . ($index + 1) . '@example.test';
            $userId = $database->insert(
                'INSERT INTO users(email,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,created_at)
                 VALUES(:email,:hash,:name,\'active\',0,1,1,0,NOW())',
                [
                    'email' => $email,
                    'hash' => password_hash($password . env('PASSWORD_PEPPER', ''), PASSWORD_DEFAULT),
                    'name' => 'Stage 9 Load ' . ($index + 1),
                ]
            );
            $database->query('INSERT INTO user_roles(user_id,role) VALUES(:user,\'reader\')', ['user' => $userId]);
            $walletId = $database->insert(
                'INSERT INTO wallets(user_id,main_available_minor,main_reserved_minor,slowo_available_minor,slowo_reserved_minor,available_minor,pending_minor,reserved_minor,points_balance,currency,created_at)
                 VALUES(:user,0,0,0,0,0,0,0,0,\'PLN\',NOW())',
                ['user' => $userId]
            );
            $state['users'][] = ['id' => $userId, 'wallet_id' => $walletId, 'email' => $email];
            $state['many_reference_ids'][] = $referenceBase + $index + 1;
        }

        $state['article_id'] = $database->insert(
            'INSERT INTO articles(author_id,title,slug,lead,body,status,access_mode,price_minor,currency,published_at,created_at,updated_at,source_language)
             VALUES(:author,:title,:slug,:lead,:body,\'published\',\'free\',0,\'PLN\',NOW(),NOW(),NOW(),\'pl\')',
            [
                'author' => (int)$state['users'][0]['id'],
                'title' => $state['article_marker'] . ' Test obciążeniowy',
                'slug' => 'stage9-load-' . $token,
                'lead' => 'Izolowany artykuł do testu obciążeniowego ETAPU 9.',
                'body' => '<p>' . $state['article_marker'] . ' — treść testowa.</p>',
            ]
        );
        $firstUserId = (int)$state['users'][0]['id'];
        $state['retry_job_key'] = sprintf(
            'talent:%d:%s:%s:%d',
            $firstUserId,
            STAGE9_ACTIVITY,
            $state['reference_type'],
            $state['retry_reference_id'],
        );
        $state['signature'] = stage9Signature($state);
        stage9ValidateState($state);
        return $state;
    } catch (Throwable $error) {
        try {
            stage9Cleanup($rootPath, $state, false);
        } catch (Throwable $cleanupError) {
            error_log('Niepełne sprzątanie przygotowania ETAPU 9: ' . $cleanupError->getMessage());
        }
        throw $error;
    }
}

/** @param array<string,mixed> $state
 *  @return array<string,mixed>
 */
function stage9InjectRetry(string $rootPath, array $state): array
{
    $database = stage9Database($rootPath);
    $queue = new DurableJobQueue($database);
    $userId = (int)$state['users'][0]['id'];
    $job = $queue->enqueue(
        EarningsQueueService::QUEUE,
        'earnings.talent_award',
        [
            'user_id' => $userId,
            'activity_type' => STAGE9_ACTIVITY,
            'reference_type' => (string)$state['reference_type'],
            'reference_id' => (int)$state['retry_reference_id'],
        ],
        (string)$state['retry_job_key'],
        priority: 1_000_000,
        maxAttempts: 8,
    );
    $claimed = $queue->claimOne(EarningsQueueService::QUEUE, 'stage9-crashed-worker', 30);
    stage9Assert($claimed !== null && (int)$claimed['id'] === (int)$job['id'], 'Kontrolowany worker nie przejął zadania retry ETAPU 9.');
    $database->query(
        'UPDATE background_jobs SET lease_expires_at=NOW() - INTERVAL \'1 second\' WHERE id=:id',
        ['id' => (int)$job['id']]
    );
    $recovered = $queue->recoverExpiredLeases(EarningsQueueService::QUEUE);
    $row = $database->one('SELECT status,attempts FROM background_jobs WHERE id=:id', ['id' => (int)$job['id']]);
    stage9Assert(($recovered['retry'] ?? 0) === 1, 'Wygasła dzierżawa nie została przeniesiona do retry.');
    stage9Assert(($row['status'] ?? '') === 'retry' && (int)($row['attempts'] ?? 0) === 1, 'Zadanie retry ma nieprawidłowy stan po awarii workera.');
    return ['status' => 'retry_ready', 'job' => $state['retry_job_key'], 'attempts' => 1];
}

/** @param array<string,mixed> $state
 *  @return list<string>
 */
function stage9RewardJobKeys(array $state): array
{
    $keys = [(string)$state['retry_job_key']];
    $users = (array)$state['users'];
    $keys[] = sprintf(
        'talent:%d:%s:%s:%d',
        (int)$users[0]['id'],
        STAGE9_ACTIVITY,
        $state['reference_type'],
        (int)$state['same_reference_id'],
    );
    foreach ($users as $index => $user) {
        $keys[] = sprintf(
            'talent:%d:%s:%s:%d',
            (int)$user['id'],
            STAGE9_ACTIVITY,
            $state['reference_type'],
            (int)$state['many_reference_ids'][$index],
        );
    }
    return $keys;
}

/** @param array<string,mixed> $state
 *  @return array<string,mixed>
 */
function stage9VerifyPending(string $rootPath, array $state): array
{
    $database = stage9Database($rootPath);
    foreach (stage9RewardJobKeys($state) as $key) {
        $rows = $database->all(
            'SELECT status,attempts FROM background_jobs WHERE queue_name=:queue AND idempotency_key=:key',
            ['queue' => EarningsQueueService::QUEUE, 'key' => $key]
        );
        stage9Assert(count($rows) === 1, 'Idempotencja kolejki nie zachowała jednego zadania: ' . $key);
        stage9Assert(in_array((string)$rows[0]['status'], ['queued', 'retry'], true), 'Worker naliczeń wykonał zadanie podczas kontrolowanej awarii.');
    }
    $retry = $database->one(
        'SELECT id,status,attempts FROM background_jobs WHERE queue_name=:queue AND idempotency_key=:key',
        ['queue' => EarningsQueueService::QUEUE, 'key' => $state['retry_job_key']]
    );
    stage9Assert($retry !== null && (string)$retry['status'] === 'retry' && (int)$retry['attempts'] === 1, 'Kontrolowane retry nie oczekuje na wznowienie workera.');
    $leaseEvents = (int)$database->cell(
        'SELECT COUNT(*) FROM background_job_events WHERE background_job_id=:job AND event_type=\'lease_expired\'',
        ['job' => (int)$retry['id']]
    );
    stage9Assert($leaseEvents === 1, 'Zdarzenie wygaśnięcia dzierżawy nie jest zapisane dokładnie raz.');
    return ['status' => 'worker_outage_verified', 'pending_reward_jobs' => count(stage9RewardJobKeys($state)), 'lease_expired_events' => 1];
}

/** @param array<string,mixed> $state */
function stage9WaitForRewards(Database $database, array $state, int $timeoutSeconds = 90): void
{
    $keys = stage9RewardJobKeys($state);
    $deadline = microtime(true) + max(10, min(180, $timeoutSeconds));
    do {
        $completed = 0;
        foreach ($keys as $key) {
            $job = $database->one(
                'SELECT status,last_error FROM background_jobs WHERE queue_name=:queue AND idempotency_key=:key',
                ['queue' => EarningsQueueService::QUEUE, 'key' => $key]
            );
            if (($job['status'] ?? '') === 'completed') {
                $completed++;
            } elseif (in_array((string)($job['status'] ?? ''), ['dead_letter', 'rejected', 'cancelled'], true)) {
                stage9Fail('Zadanie naliczenia zakończyło się błędem: ' . (string)($job['last_error'] ?? $job['status']));
            }
        }
        if ($completed === count($keys)) {
            return;
        }
        usleep(250_000);
    } while (microtime(true) < $deadline);
    stage9Fail('Worker naliczeń nie zakończył zadań ETAPU 9 w wyznaczonym czasie.');
}

/** @param array<string,mixed> $state
 *  @return array<string,mixed>
 */
function stage9VerifyComplete(string $rootPath, array $state): array
{
    $database = stage9Database($rootPath);
    stage9WaitForRewards($database, $state);
    $referenceType = (string)$state['reference_type'];
    $users = (array)$state['users'];
    foreach ($users as $index => $user) {
        $userId = (int)$user['id'];
        $references = [(int)$state['many_reference_ids'][$index]];
        if ($index === 0) {
            $references[] = (int)$state['same_reference_id'];
            $references[] = (int)$state['retry_reference_id'];
        }
        foreach ($references as $referenceId) {
            $logCount = (int)$database->cell(
                'SELECT COUNT(*) FROM activity_reward_logs
                 WHERE user_id=:user AND activity_type=:type AND reference_type=:reference_type AND reference_id=:reference_id',
                ['user' => $userId, 'type' => STAGE9_ACTIVITY, 'reference_type' => $referenceType, 'reference_id' => $referenceId]
            );
            $transactionCount = (int)$database->cell(
                'SELECT COUNT(*) FROM wallet_transactions
                 WHERE user_id=:user AND type=:type AND ref_type=:reference_type AND ref_id=:reference_id',
                ['user' => $userId, 'type' => STAGE9_ACTIVITY, 'reference_type' => $referenceType, 'reference_id' => $referenceId]
            );
            stage9Assert($logCount === 1, "Referencja {$referenceId} nie ma dokładnie jednego naliczenia.");
            stage9Assert($transactionCount === 1, "Referencja {$referenceId} nie ma dokładnie jednej transakcji.");
        }
        $automaticPoints = array_sum(array_map('intval', (array)($state['automatic_rule_points'] ?? [])));
        $expectedPoints = ($index === 0 ? 3 : 1) + $automaticPoints;
        $actualPoints = (int)$database->cell('SELECT points_balance FROM wallets WHERE id=:wallet', ['wallet' => (int)$user['wallet_id']]);
        stage9Assert($actualPoints === $expectedPoints, "Saldo użytkownika #{$userId} nie odpowiada oczekiwanym {$expectedPoints} pkt.");
    }

    $sameEvents = (int)$database->cell(
        'SELECT COUNT(*) FROM user_activity_events
         WHERE user_id=:user AND activity_type=:type AND reference_type=:reference_type AND reference_id=:reference_id',
        [
            'user' => (int)$users[0]['id'],
            'type' => STAGE9_ACTIVITY,
            'reference_type' => $referenceType,
            'reference_id' => (int)$state['same_reference_id'],
        ]
    );
    stage9Assert($sameEvents >= 2, 'Scenariusz jednoczesnych żądań tego samego użytkownika nie wykonał współbieżnych zapisów.');
    $retry = $database->one(
        'SELECT id,status,attempts FROM background_jobs WHERE queue_name=:queue AND idempotency_key=:key',
        ['queue' => EarningsQueueService::QUEUE, 'key' => $state['retry_job_key']]
    );
    stage9Assert($retry !== null && (string)$retry['status'] === 'completed' && (int)$retry['attempts'] === 2, 'Zadanie po awarii nie zakończyło się dokładnie w drugiej próbie.');
    $leaseEvents = (int)$database->cell(
        'SELECT COUNT(*) FROM background_job_events WHERE background_job_id=:job AND event_type=\'lease_expired\'',
        ['job' => (int)$retry['id']]
    );
    stage9Assert($leaseEvents === 1, 'Historia retry nie zawiera dokładnie jednego wygaśnięcia dzierżawy.');
    $integrity = (new LedgerIntegrityService($database))->verify(true);
    stage9Assert($integrity['ok'], 'Księga jest niespójna po teście: ' . implode('; ', $integrity['errors']));

    return [
        'status' => 'completed',
        'reward_jobs' => count(stage9RewardJobKeys($state)),
        'same_user_requests' => $sameEvents,
        'retry_attempts' => 2,
        'wallets_verified' => count($users),
        'ledger' => ['ok' => true, 'wallet_transactions' => $integrity['wallet_transactions']],
    ];
}

/** @param array<string,mixed> $state
 *  @return array<string,mixed>
 */
function stage9Cleanup(string $rootPath, array $state, bool $validate = true): array
{
    if ($validate) {
        stage9ValidateState($state);
    }
    $database = stage9Database($rootPath);
    $users = array_values(array_filter((array)($state['users'] ?? []), 'is_array'));
    $activeUsers = [];
    foreach ($users as $user) {
        if (!$validate) {
            $activeUsers[] = $user;
            continue;
        }
        $owned = $database->one(
            'SELECT u.id,u.email,w.id AS wallet_id
             FROM users u JOIN wallets w ON w.user_id=u.id
             WHERE u.id=:user AND u.email=:email',
            ['user' => (int)$user['id'], 'email' => (string)$user['email']]
        );
        if ($owned === null) {
            $conflict = (int)$database->cell(
                'SELECT COUNT(*) FROM users WHERE id=:user OR email=:email',
                ['user' => (int)$user['id'], 'email' => (string)$user['email']]
            );
            stage9Assert($conflict === 0, 'Stan cleanupu nie odpowiada właścicielowi konta testowego.');
            continue;
        }
        stage9Assert((int)$owned['wallet_id'] === (int)$user['wallet_id'], 'Stan cleanupu nie odpowiada portfelowi konta testowego.');
        $activeUsers[] = $user;
    }
    $userIds = array_values(array_filter(array_map(static fn(array $user): int => (int)($user['id'] ?? 0), $activeUsers)));
    $walletIds = array_values(array_filter(array_map(static fn(array $user): int => (int)($user['wallet_id'] ?? 0), $activeUsers)));

    if ($userIds !== []) {
        $sessionIds = [];
        foreach ($userIds as $userId) {
            foreach ($database->all('SELECT id FROM sessions WHERE user_id=:user', ['user' => $userId]) as $row) {
                $sessionIds[] = (string)$row['id'];
            }
        }
        $valkeyConfig = require $rootPath . '/config/valkey.php';
        $handler = new SharedSessionHandler(PhpRedisValkeyClient::connect($valkeyConfig), $database, (int)($valkeyConfig['session_ttl_seconds'] ?? 86400));
        foreach (array_unique($sessionIds) as $sessionId) {
            if (preg_match('/^[A-Za-z0-9,-]{1,128}$/D', $sessionId) === 1) {
                $handler->destroy($sessionId);
            }
        }

        foreach ($userIds as $userId) {
            $prefix = 'talent:' . $userId . ':%';
            stage9Assert(
                (int)$database->cell('SELECT COUNT(*) FROM background_jobs WHERE queue_name=:queue AND idempotency_key LIKE :prefix AND status=\'running\'', ['queue' => EarningsQueueService::QUEUE, 'prefix' => $prefix]) === 0,
                'Nie można sprzątnąć danych, gdy worker przetwarza zadanie ETAPU 9.'
            );
            $database->query('DELETE FROM background_jobs WHERE queue_name=:queue AND idempotency_key LIKE :prefix', ['queue' => EarningsQueueService::QUEUE, 'prefix' => $prefix]);
        }
        foreach ($walletIds as $walletId) {
            $database->query('DELETE FROM financial_operations WHERE wallet_id=:wallet OR transaction_id IN (SELECT id FROM wallet_transactions WHERE wallet_id=:wallet)', ['wallet' => $walletId]);
            $database->query('DELETE FROM financial_audit_log WHERE wallet_id=:wallet', ['wallet' => $walletId]);
            // Głowa wskazuje ostatnią transakcję przez FK RESTRICT. Usuwamy wyłącznie
            // głowę izolowanego portfela testowego, zanim CASCADE usunie jego historię.
            $database->query('DELETE FROM financial_wallet_ledger_heads WHERE wallet_id=:wallet', ['wallet' => $walletId]);
        }
        foreach ($activeUsers as $user) {
            $database->query('DELETE FROM auth_login_events WHERE user_id=:user OR email=:email', ['user' => (int)$user['id'], 'email' => (string)$user['email']]);
            $database->query('DELETE FROM admin_audit_logs WHERE user_id=:user', ['user' => (int)$user['id']]);
        }
    }

    $articleId = (int)($state['article_id'] ?? 0);
    if ($articleId > 0) {
        $database->query(
            'DELETE FROM articles WHERE id=:article AND slug=:slug',
            ['article' => $articleId, 'slug' => 'stage9-load-' . (string)$state['token']]
        );
    }
    foreach ($users as $user) {
        if (str_starts_with((string)($user['email'] ?? ''), STAGE9_USER_PREFIX)) {
            $database->query('DELETE FROM users WHERE id=:user AND email=:email', ['user' => (int)$user['id'], 'email' => (string)$user['email']]);
        }
    }

    if (($state['rule_created'] ?? false) === true) {
        $database->query('DELETE FROM activity_reward_rules WHERE activity_type=:type', ['type' => STAGE9_ACTIVITY]);
    } elseif (is_array($state['rule_snapshot'] ?? null)) {
        $rule = $state['rule_snapshot'];
        $database->query(
            'UPDATE activity_reward_rules SET points_amount=:points,amount_minor=:amount,label=:label,
                live_message_template=:live,title_key=:title,message_key=:message,description_key=:description,
                daily_limit=:daily,is_active=:active,created_at=:created,updated_at=:updated
             WHERE id=:id AND activity_type=:type',
            [
                'points' => (int)$rule['points_amount'],
                'amount' => (int)$rule['amount_minor'],
                'label' => $rule['label'],
                'live' => $rule['live_message_template'],
                'title' => $rule['title_key'],
                'message' => $rule['message_key'],
                'description' => $rule['description_key'],
                'daily' => (int)$rule['daily_limit'],
                'active' => (int)$rule['is_active'],
                'created' => $rule['created_at'],
                'updated' => $rule['updated_at'],
                'id' => (int)$rule['id'],
                'type' => STAGE9_ACTIVITY,
            ]
        );
    }

    if ($validate) {
        foreach ($users as $user) {
            stage9Assert(
                (int)$database->cell('SELECT COUNT(*) FROM users WHERE id=:user OR email=:email', ['user' => (int)$user['id'], 'email' => (string)$user['email']]) === 0,
                'Nie usunięto użytkownika testowego ETAPU 9.'
            );
        }
        $integrity = (new LedgerIntegrityService($database))->verify(true);
        stage9Assert($integrity['ok'], 'Księga jest niespójna po sprzątaniu ETAPU 9: ' . implode('; ', $integrity['errors']));
    }
    return ['status' => 'clean', 'users_removed' => count($users), 'article_removed' => $articleId > 0];
}

$rootPath = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$mode = '';
foreach (['prepare', 'inject-retry', 'verify-pending', 'verify-complete', 'cleanup'] as $candidate) {
    if (in_array('--' . $candidate, $arguments, true)) {
        $mode = $candidate;
        break;
    }
}

try {
    stage9Assert($mode !== '', 'Użycie: php scripts/stage9_fixtures.php --prepare|--inject-retry|--verify-pending|--verify-complete|--cleanup [--state-base64=...]');
    $result = match ($mode) {
        'prepare' => stage9Prepare($rootPath),
        'inject-retry' => stage9InjectRetry($rootPath, stage9DecodeState($arguments)),
        'verify-pending' => stage9VerifyPending($rootPath, stage9DecodeState($arguments)),
        'verify-complete' => stage9VerifyComplete($rootPath, stage9DecodeState($arguments)),
        'cleanup' => stage9Cleanup($rootPath, stage9DecodeState($arguments)),
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
