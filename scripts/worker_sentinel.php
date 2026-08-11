<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Infrastructure\Valkey\NullQueueSignal;
use App\Infrastructure\Valkey\ValkeyClientFactory;
use App\Infrastructure\Valkey\ValkeyQueueSignal;
use App\Jobs\Dors3SentinelArchiveJobHandler;
use App\Services\DurableJobQueue;
use App\Services\DurableJobWorker;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
$options = getopt('', ['once', 'daemon']);
if (!isset($options['once']) && !isset($options['daemon'])) {
    fwrite(STDERR, "Usage: php scripts/worker_sentinel.php --once or --daemon\n");
    exit(1);
}

try {
    $root = dirname(__DIR__);
    $databaseConfig = require $root . '/config/database.php';
    $db = new Database($databaseConfig['default']);
    $valkeyConfig = require $root . '/config/valkey.php';
    $valkey = ($valkeyConfig['queue_signal_driver'] ?? 'none') === 'valkey'
        ? ValkeyClientFactory::connect($valkeyConfig)
        : null;
    $signals = $valkey !== null ? new ValkeyQueueSignal($valkey) : new NullQueueSignal();
    $queue = new DurableJobQueue($db, $signals);
    $workerId = sprintf('sentinel:%s:%d:%s', gethostname() ?: 'host', getmypid() ?: 0, bin2hex(random_bytes(4)));
    $worker = new DurableJobWorker(
        $queue,
        new Dors3SentinelArchiveJobHandler($db, $queue),
        Dors3SentinelArchiveJobHandler::QUEUE,
        $workerId,
        60,
    );

    if (isset($options['once'])) {
        echo json_encode(['ok' => true, 'result' => $worker->runOne()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    $state = (object)['stopping' => false];
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use ($state): void { $state->stopping = true; });
        pcntl_signal(SIGINT, static function () use ($state): void { $state->stopping = true; });
    }
    // A short safety sweep keeps the database queue durable when Valkey is unavailable.
    // @phpstan-ignore booleanNot.alwaysTrue
    while (!$state->stopping) {
        if (!($signals instanceof NullQueueSignal)) {
            try {
                $signals->wait(Dors3SentinelArchiveJobHandler::QUEUE, 5);
            } catch (\Throwable $error) {
                error_log('[sentinel_worker_signal] ' . $error->getMessage());
                $signals = new NullQueueSignal();
            }
        } else {
            sleep(5);
        }
        $result = $worker->runOne();
        if ($result['claimed'] > 0) {
            echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            // Maintenance work deliberately yields I/O and CPU to user-facing services.
            usleep(250000);
        }
    }
    // @phpstan-ignore deadCode.unreachable
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'SENTINEL WORKER ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
