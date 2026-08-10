<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Contracts\QueueSignalInterface;
use App\Core\SlowoSnajperConfig;
use App\Services\DurableJobQueue;
use App\Services\EarningsJobDispatcher;
use PHPUnit\Framework\TestCase;

final class DatabaseAfterCommitTest extends TestCase
{
    public function testCallbackRunsOnlyAfterManagedCommit(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $database = new Database($config['default']);
        $called = false;

        $database->transaction(function (Database $db) use (&$called): void {
            self::assertTrue($db->afterCommit(static function () use (&$called): void {
                $called = true;
            }));
            self::assertFalse($called);
        });

        // Callback zmienia wartość dopiero po COMMIT poza analizowanym closure.
        // @phpstan-ignore staticMethod.impossibleType
        self::assertTrue($called);
    }

    public function testCallbackIsDiscardedOnRollback(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $database = new Database($config['default']);
        $called = false;

        try {
            $database->transaction(function (Database $db) use (&$called): void {
                $db->afterCommit(static function () use (&$called): void {
                    $called = true;
                });
                throw new \RuntimeException('rollback');
            });
            self::fail('Transakcja miała zostać wycofana.');
        } catch (\RuntimeException $error) {
            self::assertSame('rollback', $error->getMessage());
        }

        self::assertFalse($called);
    }

    public function testValkeySignalFailureDoesNotLoseDurableEarningsJob(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $database = new Database($config['default']);
        $userId = random_int(900000000, 999999999);
        $dispatcher = new EarningsJobDispatcher(
            $database,
            new DurableJobQueue($database),
            new class implements QueueSignalInterface {
                public function notify(string $queue, string $durableJobId): bool
                {
                    throw new \RuntimeException('Valkey offline in PHPUnit');
                }
                public function consume(string $queue): ?string { return null; }
                public function wait(string $queue, int $timeoutSeconds): ?string { return null; }
            },
            SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2)),
        );

        $job = $dispatcher->queueTalentAward($userId, 'share_bonus', 'phpunit_signal_failure', $userId);
        try {
            self::assertTrue($job['queued']);
            self::assertFalse($job['signal_scheduled']);
            self::assertSame(1, (int)$database->cell(
                'SELECT COUNT(*) FROM background_jobs WHERE public_id=:public_id',
                ['public_id' => $job['public_id']]
            ));
        } finally {
            $row = $database->one(
                'SELECT id FROM background_jobs WHERE public_id=:public_id',
                ['public_id' => $job['public_id']]
            );
            if ($row !== null) {
                $database->query('DELETE FROM background_job_events WHERE background_job_id=:id', ['id' => (int)$row['id']]);
                $database->query('DELETE FROM background_jobs WHERE id=:id', ['id' => (int)$row['id']]);
            }
        }
    }
}
