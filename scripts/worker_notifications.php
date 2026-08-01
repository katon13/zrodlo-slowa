<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Core\SlowoSnajperConfig;
use App\Infrastructure\Valkey\NullQueueSignal;
use App\Infrastructure\Valkey\ValkeyClientFactory;
use App\Infrastructure\Valkey\ValkeyQueueSignal;
use App\Jobs\NotificationOutboxJobHandler;
use App\Services\DurableJobQueue;
use App\Services\DurableJobWorker;
use App\Services\EarningsWorkerRuntime;
use App\Services\NotificationOutboxDispatcher;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
$options = getopt('', ['once', 'daemon']);
if (!isset($options['once']) && !isset($options['daemon'])) {
    fwrite(STDERR, "Użycie: php scripts/worker_notifications.php --once albo --daemon\n");
    exit(1);
}

/** @return array{claimed:int,completed:int,retry:int,dead_letter:int,rejected:int} */
function runNotificationBatch(DurableJobWorker $worker, int $limit): array
{
    $total = ['claimed' => 0, 'completed' => 0, 'retry' => 0, 'dead_letter' => 0, 'rejected' => 0];
    for ($index = 0; $index < max(1, $limit); $index++) {
        $result = $worker->runOne();
        foreach ($total as $key => $unused) {
            $total[$key] += $result[$key];
        }
        if ($result['claimed'] === 0) {
            break;
        }
    }
    return $total;
}

try {
    $root = dirname(__DIR__);
    $config = SlowoSnajperConfig::fromRoot($root);
    $databaseConfig = require $root . '/config/database.php';
    $db = new Database($databaseConfig['default']);
    $valkeyConfig = require $root . '/config/valkey.php';
    $valkey = ($valkeyConfig['queue_signal_driver'] ?? 'none') === 'valkey'
        ? ValkeyClientFactory::connect($valkeyConfig)
        : null;
    $signals = $valkey !== null ? new ValkeyQueueSignal($valkey) : new NullQueueSignal();
    $runtime = new EarningsWorkerRuntime(
        $valkey,
        $config->earningsHeartbeatSeconds() * 3,
        'notifications-worker:runtime',
    );
    $workerId = sprintf('notifications:%s:%d:%s', gethostname() ?: 'host', getmypid() ?: 0, bin2hex(random_bytes(4)));
    $worker = new DurableJobWorker(
        new DurableJobQueue($db),
        new NotificationOutboxJobHandler($db, $valkey),
        NotificationOutboxDispatcher::QUEUE,
        $workerId,
        120,
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
    $lastSweep = 0;
    $lastSignalAt = null;
    $metrics = ['signal_batches' => 0, 'safety_sweeps' => 0, 'claimed' => 0, 'completed' => 0, 'retry' => 0, 'dead_letter' => 0, 'rejected' => 0];
    // Flaga jest zmieniana asynchronicznie przez obsługę SIGTERM/SIGINT.
    // @phpstan-ignore booleanNot.alwaysTrue
    while (!$state->stopping) {
        $now = time();
        $sweepSeconds = $config->earningsSafetySweepSeconds();
        $dueIn = $lastSweep === 0 ? 0 : max(0, $sweepSeconds - ($now - $lastSweep));
        $signalId = null;
        $mode = 'idle_wait';
        if ($dueIn > 0) {
            $wait = max(1, min($config->earningsHeartbeatSeconds(), $dueIn));
            if (!($signals instanceof NullQueueSignal)) {
                try {
                    $signalId = $signals->wait(NotificationOutboxDispatcher::QUEUE, $wait);
                } catch (\Throwable $error) {
                    error_log('Valkey worker-notifications niedostępny; aktywny safety sweep: ' . $error->getMessage());
                    $signals = new NullQueueSignal();
                    sleep($wait);
                }
            } else {
                sleep($wait);
            }
            $now = time();
        }
        if ($signalId !== null || $lastSweep === 0 || ($now - $lastSweep) >= $sweepSeconds) {
            $mode = $signalId !== null ? 'valkey_signal' : 'safety_sweep';
            if ($signalId !== null) {
                $lastSignalAt = gmdate('c', $now);
                $metrics['signal_batches']++;
            } else {
                $lastSweep = $now;
                $metrics['safety_sweeps']++;
            }
            $result = runNotificationBatch($worker, $config->earningsBatchLimit());
            foreach ($result as $key => $value) {
                $metrics[$key] += $value;
            }
            if ($result['claimed'] > 0) {
                echo json_encode(['ok' => true, 'mode' => $mode, 'result' => $result], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            }
        }
        $runtime->heartbeat([
            'worker_id' => $workerId,
            'enabled' => true,
            'mode' => $mode,
            'wake_on_event' => !($signals instanceof NullQueueSignal),
            'safety_sweep_seconds' => $sweepSeconds,
            'batch_limit' => $config->earningsBatchLimit(),
            'last_signal_at' => $lastSignalAt,
            'last_safety_sweep_at' => $lastSweep > 0 ? gmdate('c', $lastSweep) : null,
            'metrics' => $metrics,
        ]);
    }
    // @phpstan-ignore deadCode.unreachable
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD WORKERA POWIADOMIEŃ: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
