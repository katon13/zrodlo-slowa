<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\SlowoSnajperConfig;
use App\Infrastructure\Valkey\NullQueueSignal;
use App\Jobs\NotificationOutboxJobHandler;
use App\Services\DurableJobQueue;
use App\Services\NotificationOutboxDispatcher;
use Tests\Support\InMemoryValkeyClient;

final class NotificationOutboxTest extends DatabaseTestCase
{
    public function testOutboxMaterializesNotificationExactlyOnceAndPublishesHint(): void
    {
        $authorId = $this->user('outbox-author');
        $buyerId = $this->user('outbox-buyer');
        $purchaseId = random_int(100000, 999999);
        $dispatcher = $this->dispatcher();
        $job = $dispatcher->articleSale($authorId, $buyerId, 77, $purchaseId, 1234);
        $valkey = new InMemoryValkeyClient();
        $handler = new NotificationOutboxJobHandler($this->database, $valkey);

        $first = $handler->handle($job);
        $second = $handler->handle($job);

        self::assertSame($first['notification_id'], $second['notification_id']);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM activity_bonus_notifications WHERE source_event_key=:key',
            ['key' => 'article-sale:' . $purchaseId]
        ));
        self::assertSame(
            (string)$first['notification_id'],
            $valkey->get(NotificationOutboxJobHandler::hintKey($authorId))
        );
    }

    public function testOutboxJobRollsBackWithFinancialTransaction(): void
    {
        $authorId = $this->user('rollback-author');
        $readerId = $this->user('rollback-reader');
        $paymentId = random_int(100000, 999999);
        $key = 'outbox:article-support:' . $paymentId;
        $this->database->query('SAVEPOINT notification_outbox_test');
        $this->dispatcher()->articleSupport($authorId, $readerId, 88, $paymentId, 500);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM background_jobs WHERE queue_name=:queue AND idempotency_key=:key',
            ['queue' => NotificationOutboxDispatcher::QUEUE, 'key' => $key]
        ));

        $this->database->query('ROLLBACK TO SAVEPOINT notification_outbox_test');

        self::assertSame(0, (int)$this->database->cell(
            'SELECT COUNT(*) FROM background_jobs WHERE queue_name=:queue AND idempotency_key=:key',
            ['queue' => NotificationOutboxDispatcher::QUEUE, 'key' => $key]
        ));
    }

    public function testPublicTranslationsCoverEveryEnabledLanguage(): void
    {
        $translations = json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/resources/lang/public.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        foreach (['article_sale_income', 'article_support_income'] as $suffix) {
            foreach (['type', 'message', 'description'] as $kind) {
                $key = 'bonus.' . $kind . '.' . $suffix;
                self::assertArrayHasKey($key, $translations);
                foreach (['pl', 'en', 'de', 'fr', 'it', 'es'] as $language) {
                    self::assertNotSame('', trim((string)($translations[$key][$language] ?? '')), $key . ':' . $language);
                }
            }
        }
    }

    private function dispatcher(): NotificationOutboxDispatcher
    {
        return new NotificationOutboxDispatcher(
            $this->database,
            new DurableJobQueue($this->database),
            new NullQueueSignal(),
            SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2)),
        );
    }

    private function user(string $prefix): int
    {
        return $this->database->insert(
            'INSERT INTO users(email,password_hash,display_name,status,created_at)
             VALUES(:email,:hash,:name,\'active\',NOW())',
            [
                'email' => $prefix . '-' . bin2hex(random_bytes(5)) . '@phpunit.example',
                'hash' => password_hash('PHPUnit-Outbox-2026!', PASSWORD_DEFAULT),
                'name' => $prefix,
            ]
        );
    }
}
