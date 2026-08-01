<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EarningsWorkerRuntime;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryValkeyClient;

final class EarningsWorkerRuntimeTest extends TestCase
{
    public function testHeartbeatRoundTripsWithoutPostgres(): void
    {
        $runtime = new EarningsWorkerRuntime(new InMemoryValkeyClient(), 90);

        self::assertTrue($runtime->heartbeat([
            'worker_id' => 'phpunit-worker',
            'mode' => 'valkey_signal',
            'metrics' => ['safety_sweeps' => 1],
        ]));

        $state = $runtime->read();
        self::assertSame('phpunit-worker', $state['worker_id']);
        self::assertSame('valkey_signal', $state['mode']);
        self::assertSame(1, $state['metrics']['safety_sweeps']);
        self::assertNotEmpty($state['heartbeat_at']);
    }

    public function testIndependentRuntimeKeysDoNotOverwriteEachOther(): void
    {
        $valkey = new InMemoryValkeyClient();
        $earnings = new EarningsWorkerRuntime($valkey, 120);
        $notifications = new EarningsWorkerRuntime($valkey, 120, 'notifications-worker:runtime');
        $earnings->heartbeat(['worker_id' => 'earnings-worker']);
        $notifications->heartbeat(['worker_id' => 'notifications-worker']);

        self::assertSame('earnings-worker', $earnings->read()['worker_id'] ?? null);
        self::assertSame('notifications-worker', $notifications->read()['worker_id'] ?? null);
    }
}
