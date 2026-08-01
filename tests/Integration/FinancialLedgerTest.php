<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\FinancialService;
use App\Services\LedgerAnchorService;
use App\Services\LedgerIntegrityService;
use App\Services\AuthService;

final class FinancialLedgerTest extends DatabaseTestCase
{
    public function testPostingIsIdempotentAndUpdatesOnlyWalletHeadAtomically(): void
    {
        $user = $this->database->one(
            'SELECT u.id,w.points_balance
             FROM users u JOIN wallets w ON w.user_id=u.id
             ORDER BY u.id LIMIT 1'
        );
        self::assertNotNull($user);
        $_SESSION['user_id'] = (int)$user['id'];

        $service = new FinancialService($this->database);
        $legacyHeadBefore = $this->database->one('SELECT last_transaction_id,last_entry_hash FROM financial_ledger_head WHERE id=1');
        $key = 'phpunit-ledger-' . bin2hex(random_bytes(8));
        $first = $service->postTransaction(
            (int)$user['id'],
            'phpunit_reward',
            1,
            'points',
            'Test',
            ['source_module' => 'test', 'idempotency_key' => $key]
        );
        $second = $service->postTransaction(
            (int)$user['id'],
            'phpunit_reward',
            1,
            'points',
            'Test',
            ['source_module' => 'test', 'idempotency_key' => $key]
        );

        self::assertSame($first, $second);
        self::assertSame(
            (int)$user['points_balance'] + 1,
            (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $user['id']])
        );
        $head = $this->database->one(
            'SELECT h.last_transaction_id,h.last_entry_hash
             FROM financial_wallet_ledger_heads h
             JOIN wallets w ON w.id=h.wallet_id
             WHERE w.user_id=:user',
            ['user' => $user['id']]
        );
        $transaction = $this->database->one(
            'SELECT wallet_entry_hash,entry_hash,previous_hash FROM wallet_transactions WHERE id=:id',
            ['id' => $first]
        );
        self::assertSame($first, (int)$head['last_transaction_id']);
        self::assertSame($transaction['wallet_entry_hash'], $head['last_entry_hash']);
        self::assertNull($transaction['entry_hash']);
        self::assertNull($transaction['previous_hash']);
        self::assertSame(
            $legacyHeadBefore,
            $this->database->one('SELECT last_transaction_id,last_entry_hash FROM financial_ledger_head WHERE id=1')
        );
    }

    public function testIdempotencyKeyCannotBeReusedForDifferentOperation(): void
    {
        $userId = (int)$this->database->cell(
            'SELECT w.user_id FROM wallets w WHERE w.is_locked=0 ORDER BY w.id LIMIT 1'
        );
        self::assertGreaterThan(0, $userId);
        $_SESSION['user_id'] = $userId;
        $service = new FinancialService($this->database);
        $key = 'phpunit-idem-collision-' . bin2hex(random_bytes(8));

        $service->postTransaction(
            $userId,
            'phpunit_reward',
            1,
            'points',
            'Pierwsza operacja',
            ['source_module' => 'test', 'idempotency_key' => $key]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Klucz idempotencji');
        $service->postTransaction(
            $userId,
            'phpunit_reward',
            2,
            'points',
            'Inna operacja',
            ['source_module' => 'test', 'idempotency_key' => $key]
        );
    }

    public function testHourlyAnchorIsIdempotentAndFullBalanceVerificationPasses(): void
    {
        $service = new LedgerAnchorService($this->database);
        $start = new \DateTimeImmutable('2099-01-01 04:00:00', new \DateTimeZone('UTC'));
        $first = $service->create($start, $start->modify('+1 hour'));
        $second = $service->create($start, $start->modify('+1 hour'));

        self::assertFalse((bool)$first['duplicate']);
        self::assertTrue((bool)$second['duplicate']);
        self::assertSame((int)$first['id'], (int)$second['id']);
        self::assertSame(64, strlen((string)$first['merkle_root']));
        self::assertSame(64, strlen((string)$first['anchor_hash']));

        $report = (new LedgerIntegrityService($this->database))->verify(true);
        self::assertTrue($report['ok'], implode(' | ', $report['errors']));
    }

    public function testMakerCheckerRequiresDifferentUserAndComplementaryRole(): void
    {
        $adminId = (int)$this->database->cell(
            'SELECT user_id FROM user_roles WHERE role=\'admin\' ORDER BY user_id LIMIT 1'
        );
        $publisherId = (int)$this->database->cell(
            'SELECT u.id FROM users u
             WHERE u.id<>:admin AND u.status=\'active\'
             ORDER BY u.id LIMIT 1',
            ['admin' => $adminId]
        );
        if ($publisherId <= 0) {
            $created = (new AuthService($this->database))->register([
                'email' => 'publisher-fixture-' . bin2hex(random_bytes(6)) . '@phpunit.example',
                'phone' => '',
                'password' => 'Phpunit-Publisher-2026!',
                'display_name' => 'Wydawca testowy',
                'role' => 'reader',
            ]);
            $publisherId = (int)$created['id'];
        }
        $target = $this->database->one(
            'SELECT w.id AS wallet_id,w.user_id,w.points_balance
             FROM wallets w WHERE w.is_locked=0 ORDER BY w.id LIMIT 1'
        );
        self::assertGreaterThan(0, $adminId);
        self::assertGreaterThan(0, $publisherId);
        self::assertNotNull($target);
        $this->database->query(
            $this->database->isPostgres()
                ? 'INSERT INTO user_roles(user_id,role) VALUES(:id,\'publisher\')
                   ON CONFLICT (user_id,role) DO NOTHING'
                : 'INSERT INTO user_roles(user_id,role) VALUES(:id,\'publisher\')
                   ON DUPLICATE KEY UPDATE role=VALUES(role)',
            ['id' => $publisherId]
        );

        $_SESSION['user_id'] = $adminId;
        $_SESSION['role'] = 'admin';
        $service = new FinancialService($this->database);
        $approvalId = $service->requestApproval(
            'manual_reward',
            3,
            'TT',
            (int)$target['wallet_id'],
            (int)$target['user_id'],
            ['account_type' => 'points', 'points' => 3, 'description' => 'Test Maker-Checker'],
            'Test Maker-Checker'
        );

        try {
            $service->approve($approvalId);
            self::fail('Twórca zlecenia nie może zatwierdzić własnej operacji.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Maker', $error->getMessage());
        }

        $_SESSION['user_id'] = $publisherId;
        $_SESSION['role'] = 'publisher';
        self::assertTrue($service->approve($approvalId, 'Zatwierdzenie PHPUnit'));
        self::assertSame(
            'executed',
            $this->database->cell('SELECT status FROM financial_approvals WHERE id=:id', ['id' => $approvalId])
        );
        self::assertSame(
            (int)$target['points_balance'] + 3,
            (int)$this->database->cell(
                'SELECT points_balance FROM wallets WHERE id=:id',
                ['id' => $target['wallet_id']]
            )
        );
    }
}
