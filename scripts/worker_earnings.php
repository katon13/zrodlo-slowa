<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Core\SlowoSnajperConfig;
use App\Infrastructure\Valkey\NullQueueSignal;
use App\Infrastructure\Valkey\ValkeyClientFactory;
use App\Infrastructure\Valkey\ValkeyQueueSignal;
use App\Jobs\EarningsJobHandler;
use App\Services\DurableJobQueue;
use App\Services\DurableJobWorker;
use App\Services\EarningsQueueService;
use App\Services\EarningsWorkerRuntime;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$options = getopt('', ['once', 'daemon']);
if (!isset($options['once']) && !isset($options['daemon'])) {
    fwrite(STDERR, "Użycie: php scripts/worker_earnings.php --once albo --daemon\n");
    exit(1);
}

$workerId = sprintf('earnings:%s:%d:%s', gethostname() ?: 'host', getmypid() ?: 0, bin2hex(random_bytes(4)));
$state = (object)['stopping' => false];
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use ($state): void { $state->stopping = true; });
    pcntl_signal(SIGINT, static function () use ($state): void { $state->stopping = true; });
}

/** @return array{claimed:int,completed:int,retry:int,dead_letter:int,rejected:int} */
function runEarningsBatch(DurableJobWorker $worker, int $limit): array
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
    $rootPath = dirname(__DIR__);
    $snajper = SlowoSnajperConfig::fromRoot($rootPath);
    $databaseConfig = require $rootPath . '/config/database.php';
    $db = new Database($databaseConfig['default']);
    $valkeyConfig = require $rootPath . '/config/valkey.php';
    $valkey = ($valkeyConfig['queue_signal_driver'] ?? 'none') === 'valkey'
        ? ValkeyClientFactory::connect($valkeyConfig)
        : null;
    $signals = $valkey !== null ? new ValkeyQueueSignal($valkey) : new NullQueueSignal();
    $runtime = new EarningsWorkerRuntime($valkey, $snajper->earningsHeartbeatSeconds() * 3);
    $worker = new DurableJobWorker(
        new DurableJobQueue($db),
        new EarningsJobHandler($db, $snajper, $valkey),
        EarningsQueueService::QUEUE,
        $workerId,
        120,
    );

    if (isset($options['once'])) {
        $result = $worker->runOne();
        echo json_encode(['ok' => true, 'worker' => $workerId, 'mode' => 'once', 'result' => $result], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    $lastSweepAt = 0;
    $lastSignalAt = null;
    $metrics = [
        'signal_batches' => 0,
        'safety_sweeps' => 0,
        'claimed' => 0,
        'completed' => 0,
        'retry' => 0,
        'dead_letter' => 0,
        'rejected' => 0,
    ];

    // Flaga zmienia się asynchronicznie w handlerze SIGTERM/SIGINT.
    // @phpstan-ignore booleanNot.alwaysTrue
    while (!$state->stopping) {
        $now = time();
        $enabled = $snajper->earningsWorkerEnabled();
        $heartbeat = $snajper->earningsHeartbeatSeconds();
        $sweepSeconds = $snajper->earningsSafetySweepSeconds();
        $fallbackSeconds = $snajper->earningsFallbackPollSeconds();
        $batchLimit = $snajper->earningsBatchLimit();

        $mode = $enabled ? 'idle_wait' : 'disabled';
        $signalId = null;
        if ($enabled) {
            $dueIn = $lastSweepAt === 0 ? 0 : max(0, $sweepSeconds - ($now - $lastSweepAt));
            if ($dueIn > 0) {
                $waitSeconds = max(1, min($heartbeat, $dueIn));
                if ($snajper->earningsWakeOnEvent() && !($signals instanceof NullQueueSignal)) {
                    try {
                        $signalId = $signals->wait(EarningsQueueService::QUEUE, $waitSeconds);
                    } catch (\Throwable $error) {
                        error_log('Valkey wake-up worker-earnings niedostępny; aktywny fallback: ' . $error->getMessage());
                        $signals = new NullQueueSignal();
                        sleep($waitSeconds);
                    }
                } else {
                    sleep(min($waitSeconds, $fallbackSeconds));
                }
                $now = time();
            }

            if ($signalId !== null) {
                $mode = 'valkey_signal';
                $lastSignalAt = gmdate('c', $now);
                $metrics['signal_batches']++;
                $result = runEarningsBatch($worker, $batchLimit);
                foreach ($result as $key => $value) {
                    $metrics[$key] += $value;
                }
                if ($result['claimed'] > 0) {
                    echo json_encode(['ok' => true, 'worker' => $workerId, 'mode' => $mode, 'result' => $result], JSON_UNESCAPED_UNICODE) . PHP_EOL;
                }
            }

            if ($lastSweepAt === 0 || ($now - $lastSweepAt) >= $sweepSeconds) {
                $mode = 'safety_sweep';
                $lastSweepAt = $now;
                $metrics['safety_sweeps']++;
                $result = runEarningsBatch($worker, $batchLimit);
                foreach ($result as $key => $value) {
                    $metrics[$key] += $value;
                }
                if ($result['claimed'] > 0) {
                    echo json_encode(['ok' => true, 'worker' => $workerId, 'mode' => $mode, 'result' => $result], JSON_UNESCAPED_UNICODE) . PHP_EOL;
                }
            }
        } else {
            sleep($heartbeat);
        }

        $runtime->heartbeat([
            'worker_id' => $workerId,
            'enabled' => $enabled,
            'mode' => $mode,
            'wake_on_event' => $snajper->earningsWakeOnEvent(),
            'safety_sweep_seconds' => $sweepSeconds,
            'batch_limit' => $batchLimit,
            'last_signal_at' => $lastSignalAt,
            'last_safety_sweep_at' => $lastSweepAt > 0 ? gmdate('c', $lastSweepAt) : null,
            'valkey_available' => !($signals instanceof NullQueueSignal),
            'metrics' => $metrics,
        ]);
    }

    // @phpstan-ignore deadCode.unreachable
    $runtime->heartbeat([
        'worker_id' => $workerId,
        'enabled' => false,
        'mode' => 'stopped',
        'metrics' => $metrics,
    ]);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD WORKERA NALICZEŃ: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
