<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\SlowoSnajperConfig;
use App\Infrastructure\Valkey\ValkeyQueueSignal;
use App\Jobs\EarningsJobHandler;
use App\Services\DurableJobQueue;
use App\Services\BusinessClock;
use App\Services\EarningsJobDispatcher;
use App\Services\EarningsPresenceService;
use Tests\Support\InMemoryValkeyClient;

final class EarningsPresenceTest extends DatabaseTestCase
{
    public function testVisiblePingCreatesAtMostOnePresenceVerifiedJobPerDay(): void
    {
        $userId = $this->testUser();
        $valkey = new InMemoryValkeyClient();
        $config = SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2));
        $dispatcher = new EarningsJobDispatcher(
            $this->database,
            new DurableJobQueue($this->database),
            new ValkeyQueueSignal($valkey),
            $config,
        );
        $presence = new EarningsPresenceService($valkey, $config, $dispatcher);

        $first = $presence->ping($userId, true);
        $second = $presence->ping($userId, true);

        self::assertTrue($first['present']);
        self::assertTrue($first['first_in_interval']);
        self::assertTrue($first['queued']);
        self::assertFalse($second['first_in_interval']);
        self::assertFalse($second['queued']);
        self::assertNotNull($presence->current($userId));
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM background_jobs
             WHERE queue_name=\'earnings.critical\'
               AND payload_json->>\'user_id\'=:user
               AND payload_json->>\'activity_type\'=\'day_visit_bonus\'',
            ['user' => (string)$userId]
        ));
        $payload = json_decode((string)$this->database->cell(
            'SELECT payload_json FROM background_jobs
             WHERE queue_name=\'earnings.critical\' AND payload_json->>\'user_id\'=:user
             ORDER BY id DESC LIMIT 1',
            ['user' => (string)$userId]
        ), true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['presence_verified']);
        self::assertNotEmpty($payload['observed_at']);

        $hidden = $presence->ping($userId, false);
        self::assertSame('tab_hidden', $hidden['reason']);
        self::assertNull($presence->current($userId));
    }

    public function testWorkerRejectsDailyVisitWithoutPresenceBeforeRuleLookup(): void
    {
        $handler = new EarningsJobHandler(
            $this->database,
            SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2))
        );

        $result = $handler->handle([
            'job_type' => 'earnings.talent_award',
            'payload_json' => json_encode([
                'user_id' => $this->testUser(),
                'activity_type' => 'day_visit_bonus',
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame('not_eligible', $result['decision']);
        self::assertSame('not_present', $result['reason']);
        self::assertFalse($result['awarded']);
    }

    public function testWorkerRejectsMismatchedPresenceProof(): void
    {
        $handler = new EarningsJobHandler(
            $this->database,
            SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2))
        );

        $result = $handler->handle([
            'job_type' => 'earnings.talent_award',
            'idempotency_key' => 'presence-proof-test',
            'public_id' => 'presence-proof-test',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'payload_json' => json_encode([
                'user_id' => $this->testUser(),
                'activity_type' => 'day_visit_bonus',
                'presence_verified' => true,
                'visibility_state' => 'hidden',
                'observed_at' => gmdate('c'),
                'interval_key' => BusinessClock::fromEnvironment()->dayKey(),
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame('not_eligible', $result['decision']);
        self::assertSame('not_present', $result['reason']);
        self::assertFalse($result['awarded']);
    }

    private function testUser(): int
    {
        $id = $this->database->insert(
            'INSERT INTO users(
                email,password_hash,display_name,status,can_write,talent_enabled,
                wallet_enabled,payout_enabled,created_at
             ) VALUES(:email,:hash,\'PHPUnit Presence\',\'active\',0,1,1,0,NOW())',
            [
                'email' => 'presence-' . bin2hex(random_bytes(8)) . '@phpunit.example',
                'hash' => password_hash('PHPUnit-Presence-2026!', PASSWORD_DEFAULT),
            ]
        );
        $this->database->query(
            'INSERT INTO wallets(
                user_id,main_available_minor,main_reserved_minor,slowo_available_minor,
                slowo_reserved_minor,available_minor,pending_minor,reserved_minor,
                points_balance,currency,created_at
             ) VALUES(:id,0,0,0,0,0,0,0,0,\'PLN\',NOW())',
            ['id' => $id]
        );
        return $id;
    }
}
