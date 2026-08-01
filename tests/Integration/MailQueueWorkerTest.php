<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\MailQueueWorker;
use App\Services\MailService;
use App\Services\MailTransportService;

final class MailQueueWorkerTest extends DatabaseTestCase
{
    public function testQueuedMessageIsClaimedAndMarkedSent(): void
    {
        // Izoluj kolejkę testową od wiadomości oczekujących w lokalnej bazie.
        // Zewnętrzna transakcja DatabaseTestCase cofnie tę zmianę po teście.
        $this->database->query(
            'UPDATE mail_queue SET available_at=' . $this->database->nowPlus(1, 'day') . '
             WHERE status IN (\'queued\',\'retry\')'
        );
        $queue = new MailService($this->database);
        $id = $queue->queue(null, 'recipient@example.test', 'Test', 'Treść wiadomości');
        $worker = new MailQueueWorker(
            $queue,
            new MailTransportService('null://null', 'sender@example.test', 'Źródło Słowa'),
            'phpunit-worker'
        );
        $result = $worker->runBatch(10);

        self::assertSame(1, $result['claimed']);
        self::assertSame(1, $result['sent']);
        $row = $this->database->one('SELECT status,attempts,sent_at FROM mail_queue WHERE id=:id', ['id' => $id]);
        self::assertSame('sent', $row['status']);
        self::assertSame(1, (int)$row['attempts']);
        self::assertNotNull($row['sent_at']);
    }

    public function testFailureIsRetriedAndEventuallyBecomesTerminal(): void
    {
        $this->database->query(
            'UPDATE mail_queue SET available_at=' . $this->database->nowPlus(1, 'day') . '
             WHERE status IN (\'queued\',\'retry\')'
        );
        $queue = new MailService($this->database);
        $id = $queue->queue(null, 'retry@example.test', 'Próba', 'Treść próby', 2);

        $first = $queue->claimBatch('phpunit-retry-worker', 1);
        self::assertCount(1, $first);
        self::assertSame('retry', $queue->markFailed(
            $id,
            'phpunit-retry-worker',
            new \RuntimeException('Awaria testowa')
        ));
        $retry = $this->database->one(
            'SELECT status,attempts,available_at,failed_at FROM mail_queue WHERE id=:id',
            ['id' => $id]
        );
        self::assertSame('retry', $retry['status']);
        self::assertSame(1, (int)$retry['attempts']);
        self::assertNull($retry['failed_at']);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT CASE WHEN available_at>NOW() THEN 1 ELSE 0 END FROM mail_queue WHERE id=:id',
            ['id' => $id]
        ));

        $this->database->query(
            'UPDATE mail_queue SET available_at=NOW() WHERE id=:id',
            ['id' => $id]
        );
        $second = $queue->claimBatch('phpunit-retry-worker', 1);
        self::assertCount(1, $second);
        self::assertSame('dead_letter', $queue->markFailed(
            $id,
            'phpunit-retry-worker',
            new \RuntimeException('Druga awaria testowa')
        ));
        $failed = $this->database->one(
            'SELECT status,attempts,failed_at FROM mail_queue WHERE id=:id',
            ['id' => $id]
        );
        self::assertSame('dead_letter', $failed['status']);
        self::assertSame(2, (int)$failed['attempts']);
        self::assertNotNull($failed['failed_at']);
    }
}
