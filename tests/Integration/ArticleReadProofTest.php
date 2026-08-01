<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\SlowoSnajperConfig;
use App\Infrastructure\Valkey\ValkeyQueueSignal;
use App\Services\ArticleReadProofService;
use App\Services\DurableJobQueue;
use App\Services\EarningsJobDispatcher;
use Tests\Support\InMemoryValkeyClient;

final class ArticleReadProofTest extends DatabaseTestCase
{
    public function testArticleRewardRequiresServerTimeVisibleTimeAndProgress(): void
    {
        $userId = $this->user();
        $valkey = new InMemoryValkeyClient();
        $config = SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2));
        $now = 1900000000;
        $service = new ArticleReadProofService(
            $valkey,
            $config,
            new EarningsJobDispatcher(
                $this->database,
                new DurableJobQueue($this->database),
                new ValkeyQueueSignal($valkey),
                $config,
            ),
            static function () use (&$now): int { return $now; },
        );
        $proof = $service->start($userId, 987);
        self::assertNotNull($proof);

        $early = $service->complete($userId, 987, (string)$proof['token'], 30, 100, true);
        self::assertFalse($early['accepted']);
        self::assertSame('minimum_time', $early['reason']);

        $now += 31;
        $notVisible = $service->complete($userId, 987, (string)$proof['token'], 10, 100, true);
        self::assertSame('minimum_visible_time', $notVisible['reason']);
        $notRead = $service->complete($userId, 987, (string)$proof['token'], 31, 30, true);
        self::assertSame('minimum_progress', $notRead['reason']);

        $accepted = $service->complete($userId, 987, (string)$proof['token'], 31, 75, true);
        self::assertTrue($accepted['accepted']);
        self::assertTrue($accepted['queued']);
        self::assertNotEmpty($accepted['job_public_id']);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM background_jobs
             WHERE queue_name=\'earnings.critical\'
               AND payload_json->>\'user_id\'=:user
               AND payload_json->>\'activity_type\'=\'article_read_bonus\'',
            ['user' => (string)$userId]
        ));

        $replay = $service->complete($userId, 987, (string)$proof['token'], 31, 75, true);
        self::assertFalse($replay['accepted']);
        self::assertSame('invalid_proof', $replay['reason']);
    }

    private function user(): int
    {
        return $this->database->insert(
            'INSERT INTO users(email,password_hash,display_name,status,created_at)
             VALUES(:email,:hash,\'Article Proof PHPUnit\',\'active\',NOW())',
            [
                'email' => 'article-proof-' . bin2hex(random_bytes(5)) . '@phpunit.example',
                'hash' => password_hash('PHPUnit-Article-Proof-2026!', PASSWORD_DEFAULT),
            ]
        );
    }
}
