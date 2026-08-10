<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\FinancialService;
use App\Services\LedgerService;
use App\Services\TalentService;

final class EarningsDecisionTest extends DatabaseTestCase
{
    public function testMissingInactiveAndZeroRulesHaveExplicitReasons(): void
    {
        $service = $this->service();
        $userId = $this->testUser();

        $missing = $service->award($userId, 'phpunit_missing_rule');
        self::assertSame('missing_rule', $missing['decision']);
        self::assertFalse($missing['awarded']);

        $inactive = $service->award($userId, 'day_visit_bonus');
        self::assertSame('inactive_rule', $inactive['decision']);
        self::assertFalse($inactive['awarded']);

        $this->database->query(
            'UPDATE activity_reward_rules SET is_active=1,points_amount=0,amount_minor=0
             WHERE activity_type=\'day_visit_bonus\''
        );
        $zero = $service->award($userId, 'day_visit_bonus');
        self::assertSame('zero_value', $zero['decision']);
        self::assertFalse($zero['awarded']);
    }

    public function testInactiveUserHasExplicitReason(): void
    {
        $userId = $this->testUser();
        $this->database->query('UPDATE users SET status=\'blocked\' WHERE id=:id', ['id' => $userId]);

        $result = $this->service()->award($userId, 'day_visit_bonus');

        self::assertSame('user_inactive', $result['decision']);
        self::assertFalse($result['awarded']);
    }

    public function testTalentAndWalletFlagsAreEnforcedBeforeLedgerWork(): void
    {
        $userId = $this->testUser();
        $this->database->query(
            'UPDATE activity_reward_rules
             SET is_active=1,points_amount=10,amount_minor=0,daily_limit=1
             WHERE activity_type=\'day_visit_bonus\''
        );
        $this->database->query(
            'UPDATE users SET talent_enabled=0,wallet_enabled=1 WHERE id=:id',
            ['id' => $userId]
        );

        $talentDisabled = $this->service()->award($userId, 'day_visit_bonus');
        self::assertSame('talent_disabled', $talentDisabled['decision']);

        $this->database->query(
            'UPDATE users SET talent_enabled=1,wallet_enabled=0 WHERE id=:id',
            ['id' => $userId]
        );
        $walletDisabled = $this->service()->award($userId, 'day_visit_bonus');
        self::assertSame('wallet_disabled', $walletDisabled['decision']);

        self::assertSame(0, (int)$this->database->cell(
            'SELECT COUNT(*) FROM wallet_transactions WHERE user_id=:id',
            ['id' => $userId]
        ));
    }

    public function testAwardBooksPointsAndMoneyExactlyOnceForJobRetry(): void
    {
        $userId = $this->testUser();
        $this->database->query(
            'UPDATE activity_reward_rules
             SET is_active=1,points_amount=10,amount_minor=25,daily_limit=5
             WHERE activity_type=\'share_bonus\''
        );
        $context = ['job_idempotency_key' => 'phpunit-award-' . bin2hex(random_bytes(8))];

        $first = $this->service()->award($userId, 'share_bonus', context: $context);
        $retry = $this->service()->award($userId, 'share_bonus', context: $context);

        self::assertSame('awarded', $first['decision']);
        self::assertSame('duplicate', $retry['decision']);
        self::assertSame($first['transaction_id'], $retry['transaction_id']);
        self::assertSame($first['money_transaction_id'], $retry['money_transaction_id']);
        self::assertSame(10, (int)$this->database->cell(
            'SELECT points_balance FROM wallets WHERE user_id=:id',
            ['id' => $userId]
        ));
        self::assertSame(25, (int)$this->database->cell(
            'SELECT slowo_available_minor FROM wallets WHERE user_id=:id',
            ['id' => $userId]
        ));
        self::assertSame(2, (int)$this->database->cell(
            'SELECT COUNT(*) FROM wallet_transactions WHERE user_id=:id',
            ['id' => $userId]
        ));
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM activity_reward_logs WHERE user_id=:id',
            ['id' => $userId]
        ));
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM activity_bonus_notifications WHERE user_id=:id',
            ['id' => $userId]
        ));
    }

    private function service(): TalentService
    {
        return new TalentService(
            $this->database,
            new LedgerService($this->database, new FinancialService($this->database))
        );
    }

    private function testUser(): int
    {
        $id = $this->database->insert(
            'INSERT INTO users(
                email,password_hash,display_name,status,can_write,talent_enabled,
                wallet_enabled,payout_enabled,created_at
             ) VALUES(:email,:hash,\'PHPUnit Earnings\',\'active\',0,1,1,0,NOW())',
            [
                'email' => 'earnings-' . bin2hex(random_bytes(8)) . '@phpunit.example',
                'hash' => password_hash('PHPUnit-Earnings-2026!', PASSWORD_DEFAULT),
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
