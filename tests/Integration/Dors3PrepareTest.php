<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\StructuredLoggerInterface;
use App\Core\RequestContext;
use App\Security\Dors3\ApprovalContext;
use App\Security\Dors3\ApprovalResponse;
use App\Services\Dors3SettingsService;
use App\Services\AdminSessionPolicy;
use App\Services\PasswordStepUpAuthorizer;
use App\Services\RecoveryCodeService;
use App\Services\SecurityEventService;
use App\Services\WebAuthnFoundationService;

final class Dors3PrepareTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::resetForTests();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit 3DORS';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'phpunit-dors3-request-0001';
    }

    public function testPrepareModeKeepsFidoDisabled(): void
    {
        $settings = (new Dors3SettingsService($this->database, $this->config()))->current();

        self::assertSame('prepare', $settings['mode']);
        self::assertFalse($settings['fido2_enabled']);
        self::assertFalse($settings['fido2_required']);
        self::assertSame('password', $settings['critical_step_up']);
        self::assertFalse((new WebAuthnFoundationService($this->database, $this->config()))->status()['enabled']);
    }

    public function testPasswordStepUpIsActionBoundAndOneTime(): void
    {
        [$adminId, $password] = $this->admin();
        $authorizer = $this->passwordAuthorizer();
        $context = new ApprovalContext(
            'wallet.adjust',
            $adminId,
            'wallet',
            '44',
            ['recipient_user_id' => 90, 'amount_minor' => 100, 'currency' => 'PLN'],
            ['available_minor' => 0],
            ['available_minor' => 100],
        );
        $request = $authorizer->begin($context);

        $result = $authorizer->verify(new ApprovalResponse($request, $password));

        self::assertTrue($result->approved);
        self::assertNotEmpty($this->database->cell(
            'SELECT consumed_at FROM security_step_up_authorizations WHERE public_id=:id',
            ['id' => $request->publicId]
        ));
        $this->expectException(\RuntimeException::class);
        $authorizer->verify(new ApprovalResponse($request, $password));
    }

    public function testChangedAmountInvalidatesPasswordStepUp(): void
    {
        [$adminId, $password] = $this->admin();
        $authorizer = $this->passwordAuthorizer();
        $request = $authorizer->begin(new ApprovalContext(
            'wallet.adjust',
            $adminId,
            'wallet',
            '44',
            ['amount_minor' => 100, 'currency' => 'PLN'],
        ));
        $changed = $request->withContext(new ApprovalContext(
            'wallet.adjust',
            $adminId,
            'wallet',
            '44',
            ['amount_minor' => 101, 'currency' => 'PLN'],
        ));

        try {
            $authorizer->verify(new ApprovalResponse($changed, $password));
            self::fail('Zmieniony fingerprint powinien zostać odrzucony.');
        } catch (\RuntimeException) {
            self::assertNotEmpty($this->database->cell(
                'SELECT invalidated_at FROM security_step_up_authorizations WHERE public_id=:id',
                ['id' => $request->publicId]
            ));
        }
    }

    public function testExpiredAuthorizationIsRejected(): void
    {
        [$adminId, $password] = $this->admin();
        $authorizer = $this->passwordAuthorizer();
        $request = $authorizer->begin(new ApprovalContext('role.change', $adminId, 'user', '50'));
        $this->database->query(
            'UPDATE security_step_up_authorizations SET expires_at=NOW() - INTERVAL \'1 second\' WHERE public_id=:id',
            ['id' => $request->publicId]
        );

        $this->expectException(\RuntimeException::class);
        $authorizer->verify(new ApprovalResponse($request, $password));
    }

    public function testPasswordStepUpIsBlockedAfterFiveInvalidPasswords(): void
    {
        [$adminId] = $this->admin();
        $authorizer = $this->passwordAuthorizer();
        $context = new ApprovalContext('settings.update', $adminId, 'settings_group', 'security');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $authorizer->verify(new ApprovalResponse($authorizer->begin($context), 'wrong-password'));
                self::fail('Błędne hasło step-up powinno zostać odrzucone.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('nieprawidłowe', $error->getMessage());
            }
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Zbyt wiele błędnych potwierdzeń');
        $authorizer->begin($context);
    }

    public function testSharedChallengeRejectsReplayAndBadOriginWithoutHardware(): void
    {
        [$adminId] = $this->admin();
        $config = $this->config();
        $config['mode'] = 'test';
        $config['fido2_enabled'] = true;
        $config['webauthn']['enabled'] = true;
        $appOne = new WebAuthnFoundationService($this->database, $config);
        $appTwo = new WebAuthnFoundationService($this->database, $config);
        $challenge = $appOne->beginChallenge($adminId, 'test');

        $consumed = $appTwo->consumeChallenge(
            $challenge['public_id'],
            $adminId,
            $challenge['challenge'],
            'http://localhost:8080',
            'localhost'
        );
        self::assertSame($adminId, (int)$consumed['user_id']);

        try {
            $appOne->consumeChallenge(
                $challenge['public_id'],
                $adminId,
                $challenge['challenge'],
                'http://localhost:8080',
                'localhost'
            );
            self::fail('Replay challenge powinien zostać odrzucony.');
        } catch (\RuntimeException) {
            // Oczekiwane odrzucenie jednorazowego challenge.
        }

        $second = $appOne->beginChallenge($adminId, 'test');
        $this->expectException(\RuntimeException::class);
        $appTwo->consumeChallenge(
            $second['public_id'],
            $adminId,
            $second['challenge'],
            'http://evil.example',
            'localhost'
        );
    }

    public function testRecoveryCodesAreHashedConfirmedAndSingleUse(): void
    {
        [$adminId] = $this->admin();
        $service = new RecoveryCodeService($this->database, $this->events());
        $generated = $service->generate($adminId);

        self::assertCount(10, $generated['codes']);
        self::assertSame(0, (int)$this->database->cell(
            'SELECT COUNT(*) FROM security_recovery_codes WHERE code_hash LIKE \'D3-%\''
        ));
        $service->confirmSaved($adminId, $generated['batch_public_id']);
        self::assertSame(10, $service->status($adminId)['confirmed']);

        $called = false;
        $service->consumeForRecovery(
            $adminId,
            $generated['codes'][0],
            static function ($db, array $row) use (&$called): void {
                $called = isset($row['public_id']);
            }
        );
        self::assertTrue($called);
        self::assertSame(0, $service->status($adminId)['active']);
    }

    public function testIdleAdminSessionLocksAndPasswordUnlocksIt(): void
    {
        [$adminId, $password] = $this->admin();
        $config = $this->config();
        $settings = new Dors3SettingsService($this->database, $config);
        $events = $this->events();
        $policy = new AdminSessionPolicy(
            new \App\Core\Session(),
            $settings,
            $events,
            new PasswordStepUpAuthorizer($this->database, $settings, $events),
        );
        $policy->start($adminId);
        $_SESSION['_dors3_admin_last_activity_at'] = time() - 901;

        try {
            $policy->assertAccess($adminId);
            self::fail('Nieaktywna sesja administratora powinna zostać zablokowana.');
        } catch (\App\Security\Dors3\AdminSessionLockedException) {
            self::assertTrue($policy->isLocked());
        }

        $policy->unlock($adminId, $password);

        self::assertFalse($policy->isLocked());
        $policy->assertAccess($adminId);
    }

    /** @return array{0:int,1:string} */
    private function admin(): array
    {
        $password = 'Phpunit-3DORS-Password-2026!';
        $email = 'dors3-' . bin2hex(random_bytes(6)) . '@phpunit.example';
        $hash = password_hash($password . env('PASSWORD_PEPPER', ''), PASSWORD_DEFAULT);
        $adminId = $this->database->insert(
            'INSERT INTO users(
                email,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,
                created_at,session_version
             ) VALUES(:email,:hash,\'PHPUnit 3DORS\',\'active\',0,0,0,0,NOW(),0)',
            ['email' => $email, 'hash' => $hash]
        );
        $this->database->query('INSERT INTO user_roles(user_id,role) VALUES(:user,\'admin\')', ['user' => $adminId]);
        $_SESSION['user_id'] = $adminId;
        $_SESSION['role'] = 'admin';
        $_SESSION['_authentication_context'] = [
            'method' => 'password',
            'factors' => ['password'],
            'authenticated_at' => time(),
            'strongly_verified_at' => null,
        ];
        return [$adminId, $password];
    }

    private function passwordAuthorizer(): PasswordStepUpAuthorizer
    {
        return new PasswordStepUpAuthorizer(
            $this->database,
            new Dors3SettingsService($this->database, $this->config()),
            $this->events(),
        );
    }

    private function events(): SecurityEventService
    {
        return new SecurityEventService(
            $this->database,
            new class implements StructuredLoggerInterface {
                public function log(string $level, string $operation, array $context): void {}
            }
        );
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'mode' => 'prepare',
            'fido2_enabled' => false,
            'fido2_required' => false,
            'critical_step_up' => 'password',
            'physical_approval' => 'disabled',
            'admin_idle_timeout_seconds' => 900,
            'admin_session_max_seconds' => 28800,
            'step_up_ttl_seconds' => 300,
            'webauthn' => [
                'enabled' => false,
                'rp_id' => 'localhost',
                'rp_name' => 'Źródło Słowa — 3DORS',
                'origin' => 'http://localhost:8080',
                'user_verification' => 'required',
                'challenge_ttl_seconds' => 300,
            ],
        ];
    }
}
