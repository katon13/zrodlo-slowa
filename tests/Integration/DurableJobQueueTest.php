<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\AiProviderInterface;
use App\Contracts\BackgroundJobHandlerInterface;
use App\Jobs\AiJobHandler;
use App\Jobs\UnknownOutcomeJobException;
use App\Security\Authorization\PermissionCatalog;
use App\Services\DurableJobQueue;
use App\Services\DurableJobWorker;
use App\Services\MailService;
use App\Services\SchedulerService;

final class DurableJobQueueTest extends DatabaseTestCase
{
    public function testEnqueueIsIdempotentAndRejectsPayloadReuse(): void
    {
        $queue = new DurableJobQueue($this->database);
        $first = $queue->enqueue('test.queue', 'test.job', ['value' => 1], 'same-key');
        $duplicate = $queue->enqueue('test.queue', 'test.job', ['value' => 1], 'same-key');

        self::assertFalse((bool)$first['duplicate']);
        self::assertTrue((bool)$duplicate['duplicate']);
        self::assertSame((int)$first['id'], (int)$duplicate['id']);

        $this->expectException(\LogicException::class);
        $queue->enqueue('test.queue', 'test.job', ['value' => 2], 'same-key');
    }

    public function testControlledDuplicateKeepsFirstPayload(): void
    {
        $queue = new DurableJobQueue($this->database);
        $first = $queue->enqueue('test.controlled', 'test.job', ['value' => 1], 'controlled-key');
        $duplicate = $queue->enqueue(
            'test.controlled',
            'test.job',
            ['value' => 2, 'request_id' => 'later-request'],
            'controlled-key',
            allowPayloadMismatchOnDuplicate: true,
        );

        self::assertFalse((bool)$first['duplicate']);
        self::assertTrue((bool)$duplicate['duplicate']);
        self::assertSame($first['public_id'], $duplicate['public_id']);
        self::assertSame(
            ['value' => 1],
            json_decode((string)$duplicate['payload_json'], true, 16, JSON_THROW_ON_ERROR)
        );
    }

    public function testWorkerCompletesJobExactlyOnce(): void
    {
        $queue = new DurableJobQueue($this->database);
        $job = $queue->enqueue('test.complete', 'test.complete', ['value' => 7], 'complete-key');
        $handler = new class implements BackgroundJobHandlerInterface {
            public function supports(string $jobType): bool { return $jobType === 'test.complete'; }
            public function handle(array $job): array { return ['handled' => true]; }
        };
        $worker = new DurableJobWorker($queue, $handler, 'test.complete', 'phpunit-worker', 60);

        self::assertSame(1, $worker->runOne()['completed']);
        self::assertSame(0, $worker->runOne()['claimed']);
        self::assertSame('completed', $this->database->cell('SELECT status FROM background_jobs WHERE id=:id', ['id' => (int)$job['id']]));
    }

    public function testUnknownPaidOutcomeGoesDirectlyToDeadLetter(): void
    {
        $queue = new DurableJobQueue($this->database);
        $job = $queue->enqueue(
            'test.manual',
            'test.manual',
            ['value' => 1],
            'manual-key',
            maxAttempts: 5,
            retryPolicy: 'manual',
        );
        $handler = new class implements BackgroundJobHandlerInterface {
            public function supports(string $jobType): bool { return true; }
            public function handle(array $job): array { throw new UnknownOutcomeJobException('unknown'); }
        };
        $result = (new DurableJobWorker($queue, $handler, 'test.manual', 'manual-worker'))->runOne();

        self::assertSame(1, $result['dead_letter']);
        self::assertSame('dead_letter', $this->database->cell('SELECT status FROM background_jobs WHERE id=:id', ['id' => (int)$job['id']]));
        self::assertSame(1, (int)$this->database->cell('SELECT attempts FROM background_jobs WHERE id=:id', ['id' => (int)$job['id']]));
    }

    public function testExpiredWorkerLeaseIsRecoveredForRetry(): void
    {
        $queue = new DurableJobQueue($this->database);
        $job = $queue->enqueue('earnings.critical', 'test.worker_crash', ['value' => 1], 'worker-crash-key');
        $claimed = $queue->claimOne('earnings.critical', 'crashed-worker', 30);
        self::assertSame((int)$job['id'], (int)$claimed['id']);

        $this->database->query(
            "UPDATE background_jobs SET lease_expires_at=" . $this->database->nowMinus(1, 'second') . " WHERE id=:id",
            ['id' => $job['id']]
        );
        $recovered = $queue->recoverExpiredLeases('earnings.critical');

        self::assertSame(1, $recovered['retry']);
        self::assertSame('retry', $this->database->cell('SELECT status FROM background_jobs WHERE id=:id', ['id' => $job['id']]));
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM background_job_events WHERE background_job_id=:id AND event_type=\'lease_expired\'',
            ['id' => $job['id']]
        ));
    }

    public function testAiWorkerRejectsOrdinaryUserBeforeProviderCall(): void
    {
        $userId = $this->database->insert(
            'INSERT INTO users(email,password_hash,display_name,status,created_at)
             VALUES(:email,:hash,\'Reader\',\'active\',NOW())',
            ['email' => 'stage6-reader-' . bin2hex(random_bytes(5)) . '@example.test', 'hash' => password_hash('test-password', PASSWORD_DEFAULT)]
        );
        $this->database->query('INSERT INTO user_roles(user_id,role) VALUES(:user,\'reader\')', ['user' => $userId]);
        $provider = new class implements AiProviderInterface {
            public bool $called = false;
            public function configured(): bool { return true; }
            public function testConnection(?string $model = null): array { $this->called = true; return []; }
            public function structuredJson(string $systemPrompt, string $userPrompt, array $schema, ?string $model = null): array { $this->called = true; return []; }
        };
        $queue = new DurableJobQueue($this->database);
        $job = $queue->enqueue(
            'admin.ai',
            'ai.provider_test',
            ['model' => 'test'],
            'ordinary-user-ai',
            retryPolicy: 'manual',
            actorUserId: $userId,
            requiredPermission: PermissionCatalog::AI_PROVIDER_TEST,
        );
        $worker = new DurableJobWorker(
            $queue,
            new AiJobHandler($this->database, $provider, []),
            'admin.ai',
            'ai-test-worker',
        );
        $result = $worker->runOne();

        self::assertSame(1, $result['rejected']);
        self::assertFalse($provider->called);
        self::assertSame('rejected', $this->database->cell('SELECT status FROM background_jobs WHERE id=:id', ['id' => (int)$job['id']]));
    }

    public function testSchedulerAndMailKeysAreIdempotent(): void
    {
        $mail = new MailService($this->database);
        $mailId = $mail->queue(null, 'idempotent@example.test', 'Test', 'Treść', 5, 'mail-test-key');
        self::assertSame($mailId, $mail->queue(null, 'idempotent@example.test', 'Test', 'Treść', 5, 'mail-test-key'));

        $scheduler = new SchedulerService($this->database, new DurableJobQueue($this->database), $mail);
        $slot = (new \DateTimeImmutable('@' . (1_900_000_000 + random_int(0, 100_000_000))))
            ->setTimezone(new \DateTimeZone('UTC'));
        self::assertFalse((bool)$scheduler->runMinute($slot)['duplicate']);
        self::assertTrue((bool)$scheduler->runMinute($slot)['duplicate']);
    }
}
