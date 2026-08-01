<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Cache\ValkeyCacheStore;
use App\Infrastructure\Valkey\ValkeyDistributedLock;
use App\Infrastructure\Valkey\ValkeyQueueSignal;
use App\Infrastructure\Valkey\ValkeyRateLimiter;
use App\Services\CacheService;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryValkeyClient;

final class ValkeyFoundationTest extends TestCase
{
    public function testCacheIsSharedAndGroupInvalidationUsesGeneration(): void
    {
        $client = new InMemoryValkeyClient();
        $locks = new ValkeyDistributedLock($client);
        $first = new CacheService(new ValkeyCacheStore($client), $locks);
        $second = new CacheService(new ValkeyCacheStore($client), $locks);
        $calls = 0;

        self::assertSame('first', $first->remember('site_menu:pl', 60, function () use (&$calls): string {
            $calls++;
            return 'first';
        }));
        self::assertSame('first', $second->remember('site_menu:pl', 60, function () use (&$calls): string {
            $calls++;
            return 'second';
        }));
        self::assertSame(1, $calls);

        $second->flushGroup('site_menu');
        $nextRequest = new CacheService(new ValkeyCacheStore($client), $locks);
        self::assertSame('after-invalidation', $nextRequest->remember('site_menu:pl', 60, function () use (&$calls): string {
            $calls++;
            return 'after-invalidation';
        }));
        self::assertSame(2, $calls);
    }

    public function testDistributedLockUsesOwnershipToken(): void
    {
        $client = new InMemoryValkeyClient();
        $locks = new ValkeyDistributedLock($client);
        $first = $locks->acquire('article:42', 5000);
        self::assertNotNull($first);
        self::assertNull($locks->acquire('article:42', 5000));

        $locks->release($first);
        self::assertNotNull($locks->acquire('article:42', 5000));
    }

    public function testRateLimiterAndQueueSignalsAreAtomicPrimitives(): void
    {
        $client = new InMemoryValkeyClient();
        $limiter = new ValkeyRateLimiter($client);
        self::assertSame(1, $limiter->hit('login:user', 60));
        self::assertSame(2, $limiter->hit('login:user', 60));
        self::assertTrue($limiter->tooManyAttempts('login:user', 2));
        $limiter->clear('login:user');
        self::assertFalse($limiter->tooManyAttempts('login:user', 2));

        $signals = new ValkeyQueueSignal($client);
        self::assertTrue($signals->notify('email', '101'));
        self::assertTrue($signals->notify('email', '102'));
        self::assertSame('101', $signals->consume('email'));
        self::assertSame('102', $signals->wait('email', 1));
        self::assertNull($signals->consume('email'));
    }
}
