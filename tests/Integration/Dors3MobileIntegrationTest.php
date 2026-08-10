<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\EncryptionProviderInterface;
use App\Contracts\StructuredLoggerInterface;
use App\Core\RequestContext;
use App\Security\Dors3\MobileProtocol;
use App\Services\Dors3MobileException;
use App\Services\Dors3MobileService;
use App\Services\Dors3MobileOperationExecutor;
use App\Services\Dors3OperationFingerprintService;
use App\Services\SecurityEventService;
use App\Services\SafetyFundService;

final class Dors3MobileIntegrationTest extends DatabaseTestCase
{
    private Dors3MobileService $service;
    private int $adminId;
    private string $password;
    private \OpenSSLAsymmetricKey $privateKey;
    /** @var array{device_public_id:string,credential_public_id:string,comparison_code:string,api_token:string} */
    private array $credential;

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::resetForTests();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit 3DORS Mobile';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'phpunit-dors3-mobile-request';
        [$this->adminId, $this->password] = $this->createAdmin();
        $_SESSION['user_id'] = $this->adminId;
        $_SESSION['role'] = 'admin';
        $this->service = new Dors3MobileService(
            $this->database,
            new class implements EncryptionProviderInterface {
                public function encrypt(string $plainText, string $purpose = 'application'): string { return $plainText; }
                public function decrypt(string $encoded, string $purpose = 'application'): string { return $encoded; }
            },
            new SecurityEventService(
                $this->database,
                new class implements StructuredLoggerInterface {
                    public function log(string $level, string $operation, array $context): void {}
                }
            ),
            $this->config(),
        );
        [$this->privateKey, $publicKey] = $this->ecKeyPair();
        $enrollment = $this->service->startEnrollment($this->adminId, $this->adminId, 'admin', $this->password);
        $this->credential = $this->service->completeEnrollment([
            'enrollment_request_id' => $enrollment['enrollment_request_id'],
            'token' => $enrollment['qr_payload']['token'],
            'public_key' => $publicKey,
            'algorithm' => MobileProtocol::ALGORITHM,
            'security_level' => 'TEE',
            'device_model' => 'PHPUnit Android',
            'os_version' => 'Android test',
            'app_version' => '1.0-test',
            'application_variant' => 'admin',
        ]);
        self::assertSame($enrollment['comparison_code'], $this->credential['comparison_code']);
        $this->service->approveEnrollment(
            $this->adminId,
            (string)$enrollment['enrollment_request_id'],
            $this->credential['comparison_code'],
        );
    }

    public function testPhoneCannotSelfActivateEnrollment(): void
    {
        $enrollment = $this->service->startEnrollment($this->adminId, $this->adminId, 'admin', $this->password);
        [, $publicKey] = $this->ecKeyPair();
        $credential = $this->service->completeEnrollment([
            'enrollment_request_id' => $enrollment['enrollment_request_id'],
            'token' => $enrollment['qr_payload']['token'],
            'public_key' => $publicKey,
            'algorithm' => MobileProtocol::ALGORITHM,
            'security_level' => 'TEE',
            'device_model' => 'Untrusted phone',
            'os_version' => 'Android test',
            'app_version' => '1.0-test',
            'application_variant' => 'admin',
        ]);

        try {
            $this->service->confirmEnrollment(
                $credential['device_public_id'],
                $credential['credential_public_id'],
                $credential['api_token'],
                $credential['comparison_code'],
                true,
            );
            self::fail('Telefon nie może sam aktywować enrollmentu.');
        } catch (Dors3MobileException $error) {
            self::assertSame('panel_confirmation_required', $error->errorCode);
        }
        self::assertSame('pending', (string)$this->database->cell(
            'SELECT status FROM security_mobile_devices WHERE public_id=:id',
            ['id' => $credential['device_public_id']],
        ));
    }

    public function testReaderWithoutJournalistRoleCannotEnrollInAuthorApp(): void
    {
        $readerId = $this->database->insert(
            'INSERT INTO users(email,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,created_at,session_version)
             VALUES(:email,:password,\'PHPUnit Reader\',\'active\',0,0,0,0,NOW(),0)',
            ['email' => 'reader-' . bin2hex(random_bytes(6)) . '@phpunit.example', 'password' => password_hash('unused', PASSWORD_DEFAULT)],
        );
        try {
            $this->service->startEnrollment($this->adminId, $readerId, 'author', $this->password);
            self::fail('Czytelnik nie może korzystać z 3DORS Author.');
        } catch (Dors3MobileException $error) {
            self::assertSame('agreement_inactive', $error->errorCode);
        }
    }

    public function testDecisionRateLimitCannotBeBypassedWithRandomDeviceIdentifiers(): void
    {
        $lastCode = null;
        for ($attempt = 0; $attempt < 31; $attempt++) {
            try {
                $this->service->decide(
                    sprintf('00000000-0000-4000-8000-%012d', $attempt),
                    'approve',
                    [
                        'device_public_id' => sprintf('10000000-0000-4000-8000-%012d', $attempt),
                        'credential_public_id' => 'fake',
                        'algorithm' => MobileProtocol::ALGORITHM,
                        'signed_payload' => 'fake',
                        'signature' => 'fake',
                    ],
                );
            } catch (Dors3MobileException $error) {
                $lastCode = $error->errorCode;
            }
        }
        self::assertSame('rate_limited', $lastCode);
    }

    public function testEnrollmentApprovalRejectionAndReplayProtection(): void
    {
        $request = $this->loginRequest();
        $result = $this->service->decide(
            $request['public_id'],
            'approve',
            $this->signedDecision($request['public_id'], 'approve')
        );
        self::assertSame('approved', $result['status']);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM security_mobile_signatures s
             JOIN security_mobile_approval_requests r ON r.id=s.approval_request_id
             WHERE r.public_id=:id AND s.verification_result=\'valid\'',
            ['id' => $request['public_id']]
        ));

        try {
            $this->service->decide(
                $request['public_id'],
                'approve',
                $this->signedDecision($request['public_id'], 'approve')
            );
            self::fail('Powtórne użycie podpisu powinno zostać odrzucone.');
        } catch (Dors3MobileException $error) {
            self::assertSame('request_already_processed', $error->errorCode);
        }

        $rejected = $this->loginRequest();
        self::assertSame('rejected', $this->service->decide(
            $rejected['public_id'],
            'reject',
            $this->signedDecision($rejected['public_id'], 'reject')
        )['status']);
        self::assertSame('rejected', $this->service->approvalStatus($rejected['public_id'])['status']);
    }

    public function testTamperAndTtlAreRejectedWithoutConsumingValidOperation(): void
    {
        $tampered = $this->loginRequest();
        $input = $this->signedDecision($tampered['public_id'], 'approve');
        $input['signed_payload'] .= "\ntampered=true";
        try {
            $this->service->decide($tampered['public_id'], 'approve', $input);
            self::fail('Zmiana payloadu po podpisaniu powinna zostać odrzucona.');
        } catch (Dors3MobileException $error) {
            self::assertSame('signed_payload_mismatch', $error->errorCode);
        }
        self::assertSame('pending', (string)$this->database->cell(
            'SELECT status FROM security_mobile_approval_requests WHERE public_id=:id',
            ['id' => $tampered['public_id']]
        ));

        $expired = $this->loginRequest();
        $expiredInput = $this->signedDecision($expired['public_id'], 'approve');
        $this->database->query(
            'UPDATE security_mobile_approval_requests SET expires_at=NOW() - INTERVAL \'1 second\' WHERE public_id=:id',
            ['id' => $expired['public_id']]
        );
        try {
            $this->service->decide($expired['public_id'], 'approve', $expiredInput);
            self::fail('Wygasłe żądanie powinno zostać odrzucone.');
        } catch (Dors3MobileException $error) {
            self::assertSame('request_expired', $error->errorCode);
        }
        self::assertSame('expired', (string)$this->database->cell(
            'SELECT status FROM security_mobile_approval_requests WHERE public_id=:id',
            ['id' => $expired['public_id']]
        ));
    }

    public function testWrongVariantSuspensionAndDuplicateOperationAreBlocked(): void
    {
        try {
            $this->service->createApprovalRequest(
                $this->adminId,
                'admin',
                'operation',
                'article.submit',
                'article',
                '10',
                ['Tytuł' => 'Nieprawidłowy wariant'],
                str_repeat('a', 64),
            );
            self::fail('Aplikacja Admin nie może akceptować operacji autora.');
        } catch (Dors3MobileException $error) {
            self::assertSame('variant_mismatch', $error->errorCode);
        }

        $first = $this->service->createOperationApprovalRequest(
            $this->adminId,
            'payout.approve',
            'payout',
            '44',
            ['Kwota' => '10,00 PLN'],
            str_repeat('b', 64),
        );
        $duplicate = $this->service->createOperationApprovalRequest(
            $this->adminId,
            'payout.approve',
            'payout',
            '44',
            ['Kwota' => '10,00 PLN'],
            str_repeat('b', 64),
        );
        self::assertSame($first['public_id'], $duplicate['public_id']);
        self::assertTrue($duplicate['deduplicated']);
        self::assertSame('admin', $duplicate['application_variant']);
        self::assertStringStartsWith('dors3-admin-dev://approve/', $duplicate['launch_uri']);

        $this->service->changeDeviceStatus(
            $this->adminId,
            $this->credential['device_public_id'],
            'suspended',
            'PHPUnit suspension'
        );
        self::assertSame('cancelled', (string)$this->database->cell(
            'SELECT status FROM security_mobile_approval_requests WHERE public_id=:id',
            ['id' => $first['public_id']]
        ));
        self::assertSame('suspended', $this->service->deviceStatus(
            $this->credential['device_public_id'],
            $this->credential['credential_public_id'],
            $this->credential['api_token'],
        )['status']);
    }

    public function testOpaqueIdentifiersDoNotAuthorizeDeviceApi(): void
    {
        $request = $this->loginRequest();
        try {
            $this->service->requestDetails(
                $request['public_id'],
                $this->credential['credential_public_id'],
                'invalid-token-that-is-long-enough-for-the-test',
            );
            self::fail('Publiczne identyfikatory nie mogą wystarczyć do odczytu żądania.');
        } catch (Dors3MobileException $error) {
            self::assertSame('device_auth_invalid', $error->errorCode);
        }

        $details = $this->service->requestDetails(
            $request['public_id'],
            $this->credential['credential_public_id'],
            $this->credential['api_token'],
        );
        self::assertSame($request['public_id'], $details['public_id']);
    }

    public function testSignedPayoutApprovalExecutesMakerCheckerWithVerifiedMobileActor(): void
    {
        $recipientId = $this->database->insert(
            'INSERT INTO users(email,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,created_at,session_version)
             VALUES(:email,:password,\'PHPUnit Recipient\',\'active\',0,0,1,1,NOW(),0)',
            ['email' => 'recipient-' . bin2hex(random_bytes(6)) . '@phpunit.example', 'password' => password_hash('unused', PASSWORD_DEFAULT)],
        );
        $payoutId = $this->database->insert(
            'INSERT INTO payouts(user_id,amount_minor,currency,status,note,requested_at,updated_at)
             VALUES(:user,12500,\'PLN\',\'requested\',\'PHPUnit\',NOW(),NOW())',
            ['user' => $recipientId],
        );
        $issuedAt = time();
        $fingerprint = (new Dors3OperationFingerprintService($this->database))->payoutStatus($payoutId, 'approved', $issuedAt);
        $request = $this->service->createOperationApprovalRequest(
            $this->adminId,
            'payout.approve',
            'payout',
            (string)$payoutId,
            $fingerprint['display_fields'],
            $fingerprint['fingerprint'],
            ['payout_id' => $payoutId, 'target_status' => 'approved', 'admin_id' => $this->adminId, 'admin_note' => 'E2E'],
            $issuedAt,
        );

        $result = $this->service->decide(
            $request['public_id'],
            'approve',
            $this->signedDecision($request['public_id'], 'approve'),
            static fn($db, $approval, $payload) => (new Dors3MobileOperationExecutor())->execute($db, $approval, $payload),
        );

        self::assertSame('approved', $result['status']);
        $approval = $this->database->one(
            'SELECT source,external_request_id,requested_by,requested_role,user_id,amount
             FROM financial_approvals WHERE external_request_id=:id',
            ['id' => $request['public_id']],
        );
        self::assertNotNull($approval);
        self::assertSame('dors3_mobile', (string)$approval['source']);
        self::assertSame($this->adminId, (int)$approval['requested_by']);
        self::assertSame('admin', (string)$approval['requested_role']);
        self::assertSame($recipientId, (int)$approval['user_id']);
    }

    public function testSignedRevenueSplitChangeActivatesNewPolicyAndRejectedChangeDoesNothing(): void
    {
        $issuedAt = time();
        $fingerprint = (new Dors3OperationFingerprintService($this->database))->revenueSplitPolicy(
            $this->adminId,
            4500,
            3500,
            2000,
            $issuedAt,
        );
        self::assertStringContainsString('45,00%', implode(' ', $fingerprint['display_fields']));
        $request = $this->service->createOperationApprovalRequest(
            $this->adminId,
            'financial_settings.change',
            'revenue_split_policy',
            'active',
            $fingerprint['display_fields'],
            $fingerprint['fingerprint'],
            [
                'admin_id' => $this->adminId,
                'author_basis_points' => 4500,
                'platform_basis_points' => 3500,
                'safety_fund_basis_points' => 2000,
            ],
            $issuedAt,
        );
        self::assertSame('admin', $request['application_variant']);
        $approved = $this->service->decide(
            $request['public_id'],
            'approve',
            $this->signedDecision($request['public_id'], 'approve'),
            static fn($db, $approval, $payload) => (new Dors3MobileOperationExecutor())->execute($db, $approval, $payload),
        );
        self::assertSame('approved', $approved['status']);
        $active = (new SafetyFundService($this->database))->currentPolicy();
        self::assertSame(4500, (int)$active['author_basis_points']);
        self::assertSame(3500, (int)$active['platform_basis_points']);
        self::assertSame(2000, (int)$active['safety_fund_basis_points']);
        self::assertSame($request['public_id'], (string)$active['approval_request_public_id']);

        $rejectedIssuedAt = time() + 1;
        $rejectedFingerprint = (new Dors3OperationFingerprintService($this->database))->revenueSplitPolicy(
            $this->adminId,
            4000,
            4000,
            2000,
            $rejectedIssuedAt,
        );
        $rejectedRequest = $this->service->createOperationApprovalRequest(
            $this->adminId,
            'financial_settings.change',
            'revenue_split_policy',
            'active',
            $rejectedFingerprint['display_fields'],
            $rejectedFingerprint['fingerprint'],
            [
                'admin_id' => $this->adminId,
                'author_basis_points' => 4000,
                'platform_basis_points' => 4000,
                'safety_fund_basis_points' => 2000,
            ],
            $rejectedIssuedAt,
        );
        self::assertSame('rejected', $this->service->decide(
            $rejectedRequest['public_id'],
            'reject',
            $this->signedDecision($rejectedRequest['public_id'], 'reject'),
            static fn($db, $approval, $payload) => (new Dors3MobileOperationExecutor())->execute($db, $approval, $payload),
        )['status']);
        self::assertSame(4500, (int)(new SafetyFundService($this->database))->currentPolicy()['author_basis_points']);
    }

    public function testSignedSafetyFundDisbursementUsesAdminAppAndExistingLedger(): void
    {
        $safetyFund = new SafetyFundService($this->database);
        $fundId = $safetyFund->fundUserId();
        $this->database->query(
            'INSERT INTO wallets(
                user_id,main_available_minor,main_reserved_minor,slowo_available_minor,slowo_reserved_minor,
                available_minor,pending_minor,reserved_minor,points_balance,currency,created_at
             ) VALUES(:user,5000,0,0,0,5000,0,0,0,\'PLN\',NOW())
             ON CONFLICT (user_id) DO UPDATE SET main_available_minor=5000,available_minor=5000',
            ['user' => $fundId],
        );
        $publicId = $this->uuid();
        $issuedAt = time();
        $fingerprint = (new Dors3OperationFingerprintService($this->database))->safetyFundDisbursement(
            $this->adminId,
            $publicId,
            1250,
            'materials_protection',
            'Zabezpieczenie materiałów źródłowych autora.',
            'MATERIAL-PHPUNIT-1',
            $issuedAt,
        );
        $request = $this->service->createOperationApprovalRequest(
            $this->adminId,
            'safety_fund.disbursement',
            'safety_fund_disbursement',
            $publicId,
            $fingerprint['display_fields'],
            $fingerprint['fingerprint'],
            [
                'admin_id' => $this->adminId,
                'public_id' => $publicId,
                'amount_minor' => 1250,
                'category' => 'materials_protection',
                'description' => 'Zabezpieczenie materiałów źródłowych autora.',
                'evidence_reference' => 'MATERIAL-PHPUNIT-1',
            ],
            $issuedAt,
        );
        self::assertSame('admin', $request['application_variant']);
        self::assertStringStartsWith('dors3-admin-dev://approve/', $request['launch_uri']);

        $result = $this->service->decide(
            $request['public_id'],
            'approve',
            $this->signedDecision($request['public_id'], 'approve'),
            static fn($db, $approval, $payload) => (new Dors3MobileOperationExecutor())->execute($db, $approval, $payload),
        );
        self::assertSame('approved', $result['status']);
        self::assertSame(3750, $safetyFund->balanceMinor());
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM safety_fund_disbursements
             WHERE public_id=:public AND approval_request_public_id=:request AND amount_minor=1250',
            ['public' => $publicId, 'request' => $request['public_id']],
        ));
    }

    public function testPublisherRoleIsRecheckedAfterSignatureRequestAndBeforeExecution(): void
    {
        $authorId = $this->database->insert(
            'INSERT INTO users(email,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,created_at,session_version)
             VALUES(:email,:password,\'PHPUnit Journalist\',\'active\',1,0,1,1,NOW(),0)',
            ['email' => 'journalist-' . bin2hex(random_bytes(6)) . '@phpunit.example', 'password' => password_hash('unused', PASSWORD_DEFAULT)],
        );
        $this->database->query('INSERT INTO user_roles(user_id,role) VALUES(:id,\'author\'),(:id,\'publisher\')', ['id' => $authorId]);
        $this->database->query(
            'INSERT INTO author_agreements(public_id,user_id,organization_id,status,valid_from,terms_version,created_by,created_at,updated_at)
             VALUES(:public,:user,\'zrodlo-slowa\',\'active\',NOW()-INTERVAL \'1 minute\',\'phpunit-v1\',:admin,NOW(),NOW())',
            ['public' => $this->uuid(), 'user' => $authorId, 'admin' => $this->adminId],
        );
        [$authorPrivateKey, $authorPublicKey] = $this->ecKeyPair();
        $enrollment = $this->service->startEnrollment($this->adminId, $authorId, 'author', $this->password);
        $authorCredential = $this->service->completeEnrollment([
            'enrollment_request_id' => $enrollment['enrollment_request_id'],
            'token' => $enrollment['qr_payload']['token'],
            'public_key' => $authorPublicKey,
            'algorithm' => MobileProtocol::ALGORITHM,
            'security_level' => 'TEE',
            'device_model' => 'PHPUnit Journalist Phone',
            'os_version' => 'Android test',
            'app_version' => '1.0-test',
            'application_variant' => 'author',
        ]);
        $this->service->approveEnrollment($this->adminId, $enrollment['enrollment_request_id'], $authorCredential['comparison_code']);
        $articleId = $this->database->insert(
            'INSERT INTO articles(author_id,title,slug,lead,body,status,access_mode,created_at,updated_at,source_language)
             VALUES(:author,\'Publikacja E2E\',:slug,\'Lead\',:body,\'approved\',\'free\',NOW(),NOW(),\'pl\')',
            ['author' => $authorId, 'slug' => 'publikacja-e2e-' . bin2hex(random_bytes(4)), 'body' => str_repeat('Treść testowa. ', 80)],
        );
        $issuedAt = time();
        $fingerprint = (new Dors3OperationFingerprintService($this->database))->articlePublish($articleId, $authorId, $issuedAt);
        $request = $this->service->createOperationApprovalRequest(
            $authorId,
            'article.publish',
            'article',
            (string)$articleId,
            $fingerprint['display_fields'],
            $fingerprint['fingerprint'],
            ['article_id' => $articleId, 'author_id' => $authorId],
            $issuedAt,
        );
        self::assertSame('author', $request['application_variant']);
        self::assertStringStartsWith('dors3-author-dev://approve/', $request['launch_uri']);
        $this->database->query('DELETE FROM user_roles WHERE user_id=:id AND role IN (\'publisher\',\'chief_editor\')', ['id' => $authorId]);

        try {
            $this->service->decide(
                $request['public_id'],
                'approve',
                $this->signedDecisionUsing($request['public_id'], 'approve', $authorCredential, $authorPrivateKey),
                static fn($db, $approval, $payload) => (new Dors3MobileOperationExecutor())->execute($db, $approval, $payload),
            );
            self::fail('Cofnięta rola wydawcy musi zablokować publikację po podpisie.');
        } catch (Dors3MobileException $error) {
            self::assertSame('publisher_role_revoked', $error->errorCode);
        }
        self::assertSame('approved', (string)$this->database->cell('SELECT status FROM articles WHERE id=:id', ['id' => $articleId]));
        self::assertSame('pending', (string)$this->database->cell('SELECT status FROM security_mobile_approval_requests WHERE public_id=:id', ['id' => $request['public_id']]));
    }

    /** @return array<string,mixed> */
    private function loginRequest(): array
    {
        return $this->service->createApprovalRequest(
            $this->adminId,
            'admin',
            'login',
            'auth.login',
            'user',
            (string)$this->adminId,
            ['Operacja' => 'Logowanie testowe'],
        );
    }

    /** @return array<string,string> */
    private function signedDecision(string $publicId, string $decision): array
    {
        return $this->signedDecisionUsing($publicId, $decision, $this->credential, $this->privateKey);
    }

    /** @param array{device_public_id:string,credential_public_id:string,comparison_code:string,api_token:string} $credential @return array<string,string> */
    private function signedDecisionUsing(string $publicId, string $decision, array $credential, \OpenSSLAsymmetricKey $privateKey): array
    {
        $details = $this->service->requestDetails(
            $publicId,
            $credential['credential_public_id'],
            $credential['api_token'],
        );
        $canonical = MobileProtocol::canonicalPayload([
            'purpose' => $details['purpose'],
            'request_id' => $details['request_id'],
            'challenge' => $details['challenge'],
            'account' => $details['account'],
            'organization_id' => $details['organization'],
            'role_context' => $details['role'],
            'server_origin' => $details['server_origin'],
            'environment' => $details['environment'],
            'browser_session_hash' => $details['browser_session_hash'],
            'action_fingerprint' => $details['action_fingerprint'] ?? '',
            'issued_at_epoch' => $details['issued_at'],
            'expires_at_epoch' => $details['expires_at'],
            'nonce' => $details['nonce'],
        ], $decision, $credential['credential_public_id']);
        self::assertTrue(openssl_sign($canonical, $signature, $privateKey, OPENSSL_ALGO_SHA256));

        return [
            'device_public_id' => $credential['device_public_id'],
            'credential_public_id' => $credential['credential_public_id'],
            'algorithm' => MobileProtocol::ALGORITHM,
            'signed_payload' => $canonical,
            'signature' => base64_encode($signature),
        ];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @return array{0:int,1:string} */
    private function createAdmin(): array
    {
        $password = 'PHPUnit-Mobile-Password-2026!';
        $id = $this->database->insert(
            'INSERT INTO users(
                email,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,
                created_at,session_version
             ) VALUES(:email,:password,\'PHPUnit Mobile Admin\',\'active\',0,0,0,0,NOW(),0)',
            [
                'email' => 'dors3-mobile-' . bin2hex(random_bytes(6)) . '@phpunit.example',
                'password' => password_hash($password . env('PASSWORD_PEPPER', ''), PASSWORD_DEFAULT),
            ]
        );
        $this->database->query('INSERT INTO user_roles(user_id,role) VALUES(:user,\'admin\')', ['user' => $id]);
        return [$id, $password];
    }

    /** @return array{0:\OpenSSLAsymmetricKey,1:string} */
    private function ecKeyPair(): array
    {
        $opensslConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (is_file($opensslConfig)) {
            putenv('OPENSSL_CONF=' . $opensslConfig);
        }
        $options = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ];
        if (is_file($opensslConfig)) {
            $options['config'] = $opensslConfig;
        }
        $key = openssl_pkey_new($options);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', (string)$details['key']) ?: '', true);
        self::assertIsString($der);
        return [$key, base64_encode($der)];
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        return [
            'mode' => 'test',
            'mobile' => [
                'enabled' => true,
                'mode' => 'test',
                'admin_app_enabled' => true,
                'author_app_enabled' => true,
                'article_submit_approval' => true,
                'article_publish_approval' => true,
                'payout_approval' => true,
                'admin_critical_approval' => true,
                'enrollment_ttl_seconds' => 300,
                'request_ttl_seconds' => 60,
                'api_token_ttl_seconds' => 2592000,
                'max_pending_per_user' => 10,
            ],
        ];
    }
}
