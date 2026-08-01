<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Infrastructure\Cache\NullCacheStore;
use App\Infrastructure\Cache\ValkeyCacheStore;
use App\Infrastructure\Valkey\ValkeyClientFactory;
use App\Infrastructure\Valkey\ValkeyDistributedLock;
use App\Jobs\AiJobHandler;
use App\Services\AiBackgroundJobService;
use App\Services\CacheService;
use App\Services\DurableJobQueue;
use App\Services\DurableJobWorker;
use App\Services\OpenAiClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$options = getopt('', ['once', 'daemon', 'poll-seconds::']);
if (!isset($options['once']) && !isset($options['daemon'])) {
    fwrite(STDERR, "Użycie: php scripts/worker_ai.php --once albo --daemon [--poll-seconds=5]\n");
    exit(1);
}
$pollSeconds = max(1, min(60, (int)($options['poll-seconds'] ?? 5)));
$workerId = sprintf('ai:%s:%d:%s', gethostname() ?: 'host', getmypid() ?: 0, bin2hex(random_bytes(4)));
$state = (object)['stopping' => false];
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use ($state): void { $state->stopping = true; });
    pcntl_signal(SIGINT, static function () use ($state): void { $state->stopping = true; });
}

try {
    $databaseConfig = require __DIR__ . '/../config/database.php';
    $db = new Database($databaseConfig['default']);
    $valkey = ValkeyClientFactory::connect(require __DIR__ . '/../config/valkey.php');
    $cache = $valkey !== null
        ? new CacheService(new ValkeyCacheStore($valkey), new ValkeyDistributedLock($valkey))
        : new CacheService(new NullCacheStore());
    $handler = new AiJobHandler(
        $db,
        new OpenAiClient(require __DIR__ . '/../config/ai.php'),
        require __DIR__ . '/../config/languages.php',
        $cache,
    );
    $worker = new DurableJobWorker(
        new DurableJobQueue($db),
        $handler,
        AiBackgroundJobService::QUEUE,
        $workerId,
        900,
    );
    do {
        $result = $worker->runOne();
        if (!isset($options['daemon']) || $result['claimed'] > 0) {
            echo json_encode(['ok' => true, 'worker' => $workerId, 'result' => $result], JSON_UNESCAPED_UNICODE) . PHP_EOL;
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
    fwrite(STDERR, 'BŁĄD WORKERA AI: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
