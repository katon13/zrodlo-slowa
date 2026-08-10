<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\StructuredLoggerInterface;
use App\Core\RequestContext;
use App\Core\SlowoSnajperConfig;
use App\Services\AdminWebRecoveryService;
use App\Services\AdminRecoveryService;
use App\Services\AuthSecurityService;
use App\Services\MailService;
use App\Services\RecoveryCodeService;
use App\Services\SecurityEventService;

final class AdminWebRecoveryIntegrationTest extends DatabaseTestCase
{
    public function testLimitedRecoveryRevokesOnlyAdminSecurityAndNeverCreatesAdminSession(): void
    {
        RequestContext::resetForTests();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit limited recovery';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'phpunit-admin-web-recovery';
        $password = 'PHPUnit-Recovery-Password-2026!';
        $adminId = $this->createAdmin($password);
        $adminDevice = $this->createMobileCredential($adminId, 'admin', 'active');
        $authorDevice = $this->createMobileCredential($adminId, 'author', 'active');
        $this->createPendingAdminState($adminId, $adminDevice['device_id'], $adminDevice['credential_id']);
        $this->database->query(
            'INSERT INTO webauthn_credentials(
                public_id,user_id,credential_id,public_key,display_name,credential_role,status,created_by
             ) VALUES(:public_id,:user,:credential_id,:public_key,\'PHPUnit key\',\'primary\',\'active\',:created_by)',
            [
                'public_id' => $this->uuid(),
                'user' => $adminId,
                'credential_id' => 'phpunit-webauthn-' . bin2hex(random_bytes(8)),
                'public_key' => 'phpunit-public-key',
                'created_by' => $adminId,
            ]
        );
        $webAuthnChallengeId = $this->createWebAuthnChallenge($adminId);
        $this->database->query(
            'INSERT INTO sessions(id,user_id,payload,last_activity) VALUES(:id,:user,:payload,:activity)',
            ['id' => 'phpunit-recovery-session', 'user' => $adminId, 'payload' => '{}', 'activity' => time()]
        );

        $events = new SecurityEventService(
            $this->database,
            new class implements StructuredLoggerInterface {
                public function log(string $level, string $operation, array $context): void {}
            }
        );
        $codes = new RecoveryCodeService($this->database, $events);
        $originalCodes = $codes->generate($adminId);
        $codes->confirmSaved($adminId, $originalCodes['batch_public_id']);
        $service = new AdminWebRecoveryService(
            $this->database,
            $codes,
            $events,
            new AuthSecurityService(
                $this->database,
                SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2)),
            ),
            new MailService($this->database),
        );

        $binding = hash('sha256', 'phpunit-browser-session');
        $started = $service->begin(
            'phpunit-recovery-admin@example.test',
            $password,
            $originalCodes['codes'][0],
            $binding,
        );

        self::assertSame($adminId, $started['admin_id']);
        self::assertSame(0, (int)$this->database->cell('SELECT COUNT(*) FROM sessions WHERE user_id=:id', ['id' => $adminId]));
        self::assertSame(5, (int)$this->database->cell('SELECT session_version FROM users WHERE id=:id', ['id' => $adminId]));
        self::assertSame(0, (int)$this->database->cell('SELECT two_factor_enabled FROM users WHERE id=:id', ['id' => $adminId]));
        self::assertSame('revoked', (string)$this->database->cell(
            'SELECT status FROM security_mobile_devices WHERE id=:id',
            ['id' => $adminDevice['device_id']]
        ));
        self::assertNotNull($this->database->cell(
            'SELECT used_at FROM webauthn_challenges WHERE id=:id',
            ['id' => $webAuthnChallengeId]
        ));
        self::assertNull($this->database->cell(
            'SELECT api_token_hash FROM security_mobile_credentials WHERE id=:id',
            ['id' => $adminDevice['credential_id']]
        ));
        self::assertSame('active', (string)$this->database->cell(
            'SELECT status FROM security_mobile_devices WHERE id=:id',
            ['id' => $authorDevice['device_id']]
        ));
        self::assertNotNull($this->database->cell(
            'SELECT api_token_hash FROM security_mobile_credentials WHERE id=:id',
            ['id' => $authorDevice['credential_id']]
        ));
        self::assertSame('revoked', (string)$this->database->cell(
            'SELECT status FROM webauthn_credentials WHERE user_id=:id',
            ['id' => $adminId]
        ));
        self::assertSame('cancelled', (string)$this->database->cell(
            'SELECT status FROM security_mobile_enrollments WHERE user_id=:id AND application_variant=\'admin\'',
            ['id' => $adminId]
        ));
        self::assertSame('cancelled', (string)$this->database->cell(
            'SELECT status FROM security_mobile_approval_requests WHERE user_id=:id AND application_variant=\'admin\'',
            ['id' => $adminId]
        ));
        self::assertSame('cancelled', (string)$this->database->cell(
            'SELECT status FROM security_mobile_deferred_operations LIMIT 1'
        ));
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM security_recovery_codes WHERE user_id=:id AND used_at IS NOT NULL',
            ['id' => $adminId]
        ));
        self::assertSame(9, (int)$this->database->cell(
            'SELECT COUNT(*) FROM security_recovery_codes WHERE user_id=:id AND revoked_at IS NOT NULL',
            ['id' => $adminId]
        ));
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM security_step_up_authorizations
             WHERE public_id=:id AND method=\'recovery\' AND operation=\'admin.security.recovery\'
               AND consumed_at IS NULL AND invalidated_at IS NULL',
            ['id' => $started['capability_public_id']]
        ));
        self::assertArrayNotHasKey('user_id', $_SESSION);

        $newCodes = $service->generateRecoveryCodes($started['capability_public_id'], $binding);
        $service->confirmRecoveryCodes($started['capability_public_id'], $binding, $newCodes['batch_public_id']);
        $freshDevice = $this->createMobileCredential($adminId, 'admin', 'active');
        self::assertNotSame($adminDevice['device_id'], $freshDevice['device_id']);
        $service->finish($started['capability_public_id'], $binding);

        self::assertSame(6, (int)$this->database->cell('SELECT session_version FROM users WHERE id=:id', ['id' => $adminId]));
        self::assertNotNull($this->database->cell(
            'SELECT consumed_at FROM security_step_up_authorizations WHERE public_id=:id',
            ['id' => $started['capability_public_id']]
        ));
        self::assertSame(10, (int)$codes->status($adminId)['confirmed']);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM security_events WHERE actor_id=:id AND action=\'security.recovery.web.completed\'',
            ['id' => $adminId]
        ));
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testFullCliRecoveryIncludesMobileAdminButPreservesAuthorHistory(): void
    {
        RequestContext::resetForTests();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit CLI recovery';
        $password = 'PHPUnit-Recovery-Password-2026!';
        $adminId = $this->createAdmin($password);
        $adminDevice = $this->createMobileCredential($adminId, 'admin', 'active');
        $authorDevice = $this->createMobileCredential($adminId, 'author', 'active');
        $webAuthnChallengeId = $this->createWebAuthnChallenge($adminId);
        $events = new SecurityEventService(
            $this->database,
            new class implements StructuredLoggerInterface {
                public function log(string $level, string $operation, array $context): void {}
            }
        );
        $codes = new RecoveryCodeService($this->database, $events);
        $batch = $codes->generate($adminId);
        $codes->confirmSaved($adminId, $batch['batch_public_id']);
        $reportDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zrodlo-slowa-recovery-' . bin2hex(random_bytes(8));

        try {
            $service = new AdminRecoveryService($this->database, $codes, $events, $reportDirectory);
            $report = $service->recover(
                $adminId,
                $batch['codes'][0],
                'ODZYSKUJE ADMINA ' . $adminId . ' I UNIEWAZNIAM KLUCZE 3DORS',
                'Kontrolowany test pełnego lokalnego odzyskiwania administratora.',
            );

            self::assertSame(1, (int)$report['mobile_admin_reset']['devices_revoked']);
            self::assertSame(1, (int)$report['mobile_admin_reset']['credentials_revoked']);
            self::assertSame(1, (int)$report['mobile_admin_reset']['tokens_revoked']);
            self::assertSame(1, (int)$report['webauthn_challenges_invalidated']);
            self::assertSame('revoked', (string)$this->database->cell(
                'SELECT status FROM security_mobile_devices WHERE id=:id',
                ['id' => $adminDevice['device_id']]
            ));
            self::assertSame('active', (string)$this->database->cell(
                'SELECT status FROM security_mobile_devices WHERE id=:id',
                ['id' => $authorDevice['device_id']]
            ));
            self::assertNotNull($this->database->cell(
                'SELECT used_at FROM webauthn_challenges WHERE id=:id',
                ['id' => $webAuthnChallengeId]
            ));
            self::assertFileExists($reportDirectory . DIRECTORY_SEPARATOR . $report['report_id'] . '.json');
            self::assertSame(5, (int)$this->database->cell(
                'SELECT session_version FROM users WHERE id=:id',
                ['id' => $adminId]
            ));
        } finally {
            if (is_dir($reportDirectory)) {
                foreach (glob($reportDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $reportFile) {
                    @unlink($reportFile);
                }
                @rmdir($reportDirectory);
            }
        }
    }

    private function createAdmin(string $password): int
    {
        $id = $this->database->insert(
            'INSERT INTO users(
                email,login_name,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,
                two_factor_enabled,two_factor_secret,force_2fa_setup,created_at,session_version
             ) VALUES(
                \'phpunit-recovery-admin@example.test\',\'phpunit-recovery-admin\',:password,\'PHPUnit Recovery Admin\',
                \'active\',1,0,1,0,1,\'PHPUNIT-TOTP\',0,NOW(),4
             )',
            ['password' => password_hash($password . env('PASSWORD_PEPPER', ''), PASSWORD_DEFAULT)]
        );
        $this->database->query('INSERT INTO user_roles(user_id,role) VALUES(:id,\'admin\'),(:id,\'author\')', ['id' => $id]);
        return $id;
    }

    /** @return array{device_id:int,credential_id:int} */
    private function createMobileCredential(int $userId, string $variant, string $status): array
    {
        $deviceId = $this->database->insert(
            'INSERT INTO security_mobile_devices(
                public_id,user_id,organization_id,application_variant,display_name,app_version,device_model,
                os_version,security_level,status,activated_at,created_request_id
             ) VALUES(
                :public_id,:user,\'zrodlo-slowa\',:variant,:display_name,\'1.0-test\',\'PHPUnit phone\',
                \'Android test\',\'TEE\',:status,CASE WHEN :active_status=\'active\' THEN NOW() ELSE NULL END,:request_id
             )',
            [
                'public_id' => $this->uuid(),
                'user' => $userId,
                'variant' => $variant,
                'display_name' => 'PHPUnit ' . $variant,
                'status' => $status,
                'active_status' => $status,
                'request_id' => $this->uuid(),
            ]
        );
        $credentialId = $this->database->insert(
            'INSERT INTO security_mobile_credentials(
                public_id,device_id,public_key,algorithm,key_reference,security_level,status,
                api_token_hash,api_token_expires_at
             ) VALUES(
                :public_id,:device,\'phpunit-public-key\',\'SHA256withECDSA\',:key_reference,\'TEE\',:status,
                :token_hash,NOW()+INTERVAL \'30 days\'
             )',
            [
                'public_id' => $this->uuid(),
                'device' => $deviceId,
                'key_reference' => 'phpunit-key-' . bin2hex(random_bytes(8)),
                'status' => $status,
                'token_hash' => hash('sha256', random_bytes(32)),
            ]
        );
        return ['device_id' => $deviceId, 'credential_id' => $credentialId];
    }

    private function createPendingAdminState(int $adminId, int $deviceId, int $credentialId): void
    {
        $this->database->query(
            'INSERT INTO security_mobile_enrollments(
                public_id,user_id,organization_id,application_variant,token_hash,comparison_code_hash,
                comparison_code_ciphertext,browser_session_hash,expires_at,status,created_by
             ) VALUES(
                :public_id,:user,\'zrodlo-slowa\',\'admin\',:token_hash,:code_hash,\'ciphertext\',:browser_hash,
                NOW()+INTERVAL \'5 minutes\',\'pending\',:created_by
             )',
            [
                'public_id' => $this->uuid(),
                'user' => $adminId,
                'token_hash' => hash('sha256', random_bytes(32)),
                'code_hash' => hash('sha256', '123456'),
                'browser_hash' => hash('sha256', 'browser'),
                'created_by' => $adminId,
            ]
        );
        $approvalId = $this->database->insert(
            'INSERT INTO security_mobile_approval_requests(
                public_id,user_id,organization_id,role_context,device_id,credential_id,application_variant,purpose,
                action_type,resource_type,resource_id,action_fingerprint,display_payload_json,challenge_hash,
                challenge_ciphertext,browser_session_hash,server_origin,environment,nonce_hash,nonce_ciphertext,
                status,issued_at,expires_at,request_id,correlation_id
             ) VALUES(
                :public_id,:user,\'zrodlo-slowa\',\'admin\',:device,:credential,\'admin\',\'operation\',
                \'role.change\',\'user\',:resource_id,:fingerprint,\'{}\'::jsonb,:challenge_hash,
                \'challenge\',:browser_hash,\'https://example.test\',\'TESTOWE\',:nonce_hash,\'nonce\',
                \'pending\',NOW(),NOW()+INTERVAL \'5 minutes\',:request_id,:correlation_id
             )',
            [
                'public_id' => $this->uuid(),
                'user' => $adminId,
                'device' => $deviceId,
                'credential' => $credentialId,
                'resource_id' => (string)$adminId,
                'fingerprint' => hash('sha256', 'operation'),
                'challenge_hash' => hash('sha256', random_bytes(32)),
                'browser_hash' => hash('sha256', 'browser'),
                'nonce_hash' => hash('sha256', random_bytes(32)),
                'request_id' => 'phpunit-recovery-request-' . bin2hex(random_bytes(4)),
                'correlation_id' => 'phpunit-recovery-correlation-' . bin2hex(random_bytes(4)),
            ]
        );
        $this->database->query(
            'INSERT INTO security_mobile_deferred_operations(
                approval_request_id,operation_payload_json,expected_fingerprint,status
             ) VALUES(:approval,\'{}\'::jsonb,:fingerprint,\'pending\')',
            ['approval' => $approvalId, 'fingerprint' => hash('sha256', 'operation')]
        );
    }

    private function createWebAuthnChallenge(int $adminId): int
    {
        return $this->database->insert(
            'INSERT INTO webauthn_challenges(
                public_id,user_id,purpose,challenge_hash,rp_id,origin,request_id,expires_at
             ) VALUES(
                :public_id,:user,\'authentication\',:challenge_hash,\'example.test\',\'https://example.test\',
                :request_id,NOW()+INTERVAL \'5 minutes\'
             )',
            [
                'public_id' => $this->uuid(),
                'user' => $adminId,
                'challenge_hash' => hash('sha256', random_bytes(32)),
                'request_id' => 'phpunit-webauthn-' . bin2hex(random_bytes(4)),
            ]
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
