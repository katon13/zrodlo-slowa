<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\FinancialService;

final class Dors3MobileFinancialActorTest extends DatabaseTestCase
{
    public function testVerifiedMobileActorDoesNotDependOnPhpSessionAndIsIdempotent(): void
    {
        $adminId = $this->database->insert(
            'INSERT INTO users(
                email,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,
                created_at,session_version
             ) VALUES(:email,:password,\'PHPUnit Mobile Financial Admin\',\'active\',0,0,1,1,NOW(),0)',
            [
                'email' => 'dors3-financial-' . bin2hex(random_bytes(6)) . '@phpunit.example',
                'password' => password_hash('not-used', PASSWORD_DEFAULT),
            ],
        );
        $this->database->query('INSERT INTO user_roles(user_id,role) VALUES(:user,\'admin\')', ['user' => $adminId]);
        $_SESSION = [];

        $financial = new FinancialService($this->database);
        $requestId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $payload = [
            'payout_id' => 441,
            'target_status' => 'approved',
            'dors3_mobile_approval_request_id' => $requestId,
        ];
        $firstId = $financial->requestApproval(
            'payout_status_update',
            12500,
            'PLN',
            0,
            $adminId,
            $payload,
            'Test aktora mobilnego',
            ['id' => $adminId, 'role' => 'admin'],
            'dors3_mobile',
            $requestId,
            'phpunit-mobile-financial',
        );
        $secondId = $financial->requestApproval(
            'payout_status_update',
            12500,
            'PLN',
            0,
            $adminId,
            $payload,
            'Test aktora mobilnego',
            ['id' => $adminId, 'role' => 'admin'],
            'dors3_mobile',
            $requestId,
            'phpunit-mobile-financial',
        );

        self::assertSame($firstId, $secondId);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM financial_approvals WHERE source=\'dors3_mobile\' AND external_request_id=:request',
            ['request' => $requestId],
        ));
        self::assertSame($adminId, (int)$this->database->cell(
            'SELECT requested_by FROM financial_approvals WHERE id=:id',
            ['id' => $firstId],
        ));
    }
}
