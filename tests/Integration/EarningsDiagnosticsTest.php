<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\SlowoSnajperConfig;
use App\Services\EarningsDiagnosticsService;
use App\Services\EarningsWorkerRuntime;
use Tests\Support\InMemoryValkeyClient;

final class EarningsDiagnosticsTest extends DatabaseTestCase
{
    public function testSnapshotCombinesDatabaseAndWorkerRuntimeWithoutMutation(): void
    {
        $valkey = new InMemoryValkeyClient();
        (new EarningsWorkerRuntime($valkey))->heartbeat([
            'worker_id' => 'phpunit-earnings',
            'metrics' => ['safety_sweeps' => 2],
        ]);
        (new EarningsWorkerRuntime($valkey, 120, 'notifications-worker:runtime'))->heartbeat([
            'worker_id' => 'phpunit-notifications',
            'metrics' => ['safety_sweeps' => 1],
        ]);

        $snapshot = (new EarningsDiagnosticsService(
            $this->database,
            $valkey,
            SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2)),
        ))->snapshot();

        self::assertTrue($snapshot['earnings_worker']['healthy']);
        self::assertTrue($snapshot['notifications_worker']['healthy']);
        self::assertSame('phpunit-earnings', $snapshot['earnings_worker']['state']['worker_id']);
        self::assertArrayHasKey('latency_ms', $snapshot);
        self::assertArrayHasKey('rules', $snapshot);
        self::assertFalse($snapshot['idle_database_polling']);
    }
}
