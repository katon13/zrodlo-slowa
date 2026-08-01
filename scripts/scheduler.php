<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\DurableJobQueue;
use App\Services\LedgerAnchorService;
use App\Services\MailService;
use App\Services\SchedulerService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$options = getopt('', ['once', 'daemon', 'poll-seconds::']);
if (!isset($options['once']) && !isset($options['daemon'])) {
    fwrite(STDERR, "Użycie: php scripts/scheduler.php --once albo --daemon [--poll-seconds=60]\n");
    exit(1);
}
$pollSeconds = max(10, min(300, (int)($options['poll-seconds'] ?? 60)));
$state = (object)['stopping' => false];
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use ($state): void { $state->stopping = true; });
    pcntl_signal(SIGINT, static function () use ($state): void { $state->stopping = true; });
}

try {
    $config = require __DIR__ . '/../config/database.php';
    $db = new Database($config['default']);
    $scheduler = new SchedulerService(
        $db,
        new DurableJobQueue($db),
        new MailService($db),
        new LedgerAnchorService($db)
    );
    do {
        $result = $scheduler->runMinute();
        echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        // Zmiana następuje asynchronicznie w handlerze SIGTERM/SIGINT.
        // @phpstan-ignore booleanOr.rightAlwaysFalse
        if (!isset($options['daemon']) || $state->stopping) {
            break;
        }
        sleep($pollSeconds);
    // @phpstan-ignore booleanNot.alwaysTrue
    } while (!$state->stopping);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD SCHEDULERA: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
