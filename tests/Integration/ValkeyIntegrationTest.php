<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Cache\ValkeyCacheStore;
use App\Infrastructure\Session\SharedSessionHandler;
use App\Infrastructure\Valkey\PhpRedisValkeyClient;
use App\Infrastructure\Valkey\ValkeyDistributedLock;
use App\Infrastructure\Valkey\ValkeyQueueSignal;
use App\Infrastructure\Valkey\ValkeyRateLimiter;
use App\Services\CacheService;
use PHPUnit\Framework\TestCase;

final class ValkeyIntegrationTest extends TestCase
{
    public function testTwoIndependentClientsShareSessionCacheLimitsLocksAndSignals(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Rozszerzenie redis jest dostępne w obrazie Docker ETAPU 4.');
        }

        $config = require dirname(__DIR__, 2) . '/config/valkey.php';
        $suffix = bin2hex(random_bytes(8));
        $config['prefix'] = 'zrodlo-slowa:test:' . $suffix;
        $firstClient = PhpRedisValkeyClient::connect($config);
        $secondClient = PhpRedisValkeyClient::connect($config);

        $databaseConfig = require dirname(__DIR__, 2) . '/config/database.php';
        $database = new \App\Core\Database($databaseConfig['default']);
        $sessionId = bin2hex(random_bytes(16));
        $firstSession = new SharedSessionHandler($firstClient, $database, 300);
        $secondSession = new SharedSessionHandler($secondClient, $database, 300);
        self::assertTrue($firstSession->write($sessionId, 'user_id|i:123;'));
        self::assertSame('user_id|i:123;', $secondSession->read($sessionId));
        self::assertTrue($secondSession->destroy($sessionId));

        $firstCache = new CacheService(
            new ValkeyCacheStore($firstClient),
            new ValkeyDistributedLock($firstClient)
        );
        $secondCache = new CacheService(
            new ValkeyCacheStore($secondClient),
            new ValkeyDistributedLock($secondClient)
        );
        $firstCache->set('multi_instance:value', ['instance' => 'app-1'], 60);
        self::assertSame(
            ['hit' => true, 'value' => ['instance' => 'app-1']],
            $secondCache->get('multi_instance:value')
        );
        $secondCache->flushGroup('multi_instance');
        $nextRequestCache = new CacheService(
            new ValkeyCacheStore($firstClient),
            new ValkeyDistributedLock($firstClient)
        );
        self::assertFalse($nextRequestCache->get('multi_instance:value')['hit']);

        $firstLock = new ValkeyDistributedLock($firstClient);
        $secondLock = new ValkeyDistributedLock($secondClient);
        $handle = $firstLock->acquire('multi-instance-lock', 5000);
        self::assertNotNull($handle);
        self::assertNull($secondLock->acquire('multi-instance-lock', 5000));
        $firstLock->release($handle);

        $firstLimiter = new ValkeyRateLimiter($firstClient);
        $secondLimiter = new ValkeyRateLimiter($secondClient);
        self::assertSame(1, $firstLimiter->hit('multi-instance-rate', 60));
        self::assertTrue($secondLimiter->tooManyAttempts('multi-instance-rate', 1));
        $secondLimiter->clear('multi-instance-rate');

        $firstSignals = new ValkeyQueueSignal($firstClient);
        $secondSignals = new ValkeyQueueSignal($secondClient);
        self::assertTrue($firstSignals->notify('test', 'durable-job-1'));
        self::assertSame('durable-job-1', $secondSignals->wait('test', 1));
    }
}
