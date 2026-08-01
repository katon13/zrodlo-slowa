<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\MailQueueWorker;
use App\Services\MailService;
use App\Services\MailTransportService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Dostęp tylko przez CLI.";
    exit(1);
}

$options = getopt('', ['once', 'daemon', 'limit::', 'poll-seconds::']);
if (!isset($options['once']) && !isset($options['daemon'])) {
    fwrite(STDERR, "Użycie: php scripts/mail_worker.php --once [--limit=20] albo --daemon [--poll-seconds=5]\n");
    exit(1);
}
$limit = max(1, min(100, (int)($options['limit'] ?? 20)));
$pollSeconds = max(1, min(60, (int)($options['poll-seconds'] ?? 5)));
$workerId = sprintf('mail:%s:%d:%s', gethostname() ?: 'host', getmypid() ?: 0, bin2hex(random_bytes(4)));
$state = (object)['stopping' => false];
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use ($state): void { $state->stopping = true; });
    pcntl_signal(SIGINT, static function () use ($state): void { $state->stopping = true; });
}

try {
    $config = require __DIR__ . '/../config/database.php';
    $queue = new MailService(new Database($config['default']));
    $worker = new MailQueueWorker($queue, MailTransportService::fromEnvironment(), $workerId);
    do {
        $result = $worker->runBatch($limit);
        if (!isset($options['daemon']) || $result['claimed'] > 0) {
            echo json_encode(
                ['ok' => true, 'worker' => $workerId, 'result' => $result],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) . PHP_EOL;
        }
        // Zmiana następuje asynchronicznie w handlerze SIGTERM/SIGINT.
        // @phpstan-ignore booleanOr.rightAlwaysFalse
        if (!isset($options['daemon']) || $state->stopping) {
            break;
        }
        if ($result['claimed'] === 0) {
            sleep($pollSeconds);
        }
    // @phpstan-ignore booleanNot.alwaysTrue
    } while (!$state->stopping);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD WORKERA POCZTY: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
