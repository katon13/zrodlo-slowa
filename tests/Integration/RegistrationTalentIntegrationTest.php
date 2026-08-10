<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\EarningsJobHandler;
use App\Services\AuthService;
use App\Services\DurableJobQueue;
use App\Services\DurableJobWorker;
use App\Services\EarningsQueueService;
use App\Services\FinancialService;
use App\Services\LedgerService;
use App\Services\TalentService;

final class RegistrationTalentIntegrationTest extends DatabaseTestCase
{
    public function testAccountAndDurableRegistrationEntitlementUseStableUserReference(): void
    {
        $this->database->query(
            'UPDATE background_jobs SET available_at=' . $this->database->nowPlus(1, 'day') . "
             WHERE queue_name='earnings.critical' AND status IN ('queued','retry')"
        );
        $this->database->query(
            "UPDATE activity_reward_rules
             SET is_active=1,points_amount=19,amount_minor=0,daily_limit=0
             WHERE activity_type='registration_bonus'"
        );
        $talent = new TalentService(
            $this->database,
            new LedgerService($this->database, new FinancialService($this->database)),
        );
        $user = (new AuthService($this->database))->registerWithTalentEntitlement([
            'email' => 'registration-entitlement-' . bin2hex(random_bytes(6)) . '@phpunit.example',
            'display_name' => 'Registration entitlement PHPUnit',
            'phone' => '',
            'password' => 'Registration-PHPUnit-2026!',
            'role' => 'reader',
        ], $talent);
        $userId = (int)$user['id'];

        $job = $this->database->one(
            "SELECT * FROM background_jobs
             WHERE queue_name='earnings.critical'
               AND idempotency_key=:key",
            ['key' => "talent:{$userId}:registration_bonus:user_registration:{$userId}"]
        );
        self::assertNotNull($job);
        $payload = json_decode((string)$job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('registration_bonus', $payload['activity_type']);
        self::assertSame('user_registration', $payload['reference_type']);
        self::assertSame($userId, $payload['reference_id']);

        $worker = new DurableJobWorker(
            new DurableJobQueue($this->database),
            new EarningsJobHandler($this->database),
            EarningsQueueService::QUEUE,
            'phpunit-registration-entitlement-worker',
        );
        self::assertSame(1, $worker->runOne()['completed']);
        self::assertSame(19, (int)$this->database->cell(
            'SELECT points_balance FROM wallets WHERE user_id=:user',
            ['user' => $userId]
        ));
        self::assertSame(1, (int)$this->database->cell(
            "SELECT COUNT(*) FROM activity_reward_logs
             WHERE user_id=:user AND activity_type='registration_bonus'
               AND reference_type='user_registration' AND reference_id=:reference",
            ['user' => $userId, 'reference' => $userId]
        ));
    }
}
