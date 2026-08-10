<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\EncryptionProviderInterface;
use App\Core\Database;
use App\Core\RequestContext;
use App\Security\Dors3\MobileOperationPolicy;
use App\Security\Dors3\MobileOperationReadiness;
use App\Security\Dors3\MobileProtocol;
use App\Security\Dors3\MobileSignatureVerifier;
use App\Security\Dors3\SecurityId;

final class Dors3MobileService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly Database $db,
        private readonly EncryptionProviderInterface $cipher,
        private readonly SecurityEventService $events,
        private readonly array $config,
        private readonly MobileSignatureVerifier $signatureVerifier = new MobileSignatureVerifier(),
    ) {}

    /** @return array<string,mixed> */
    public function startEnrollment(int $createdBy, int $userId, string $variant, string $password): array
    {
        $this->assertEnabled($variant);
        $this->hitRateLimit('enrollment.start', (string)$createdBy, 6, 300);

        $admin = $this->db->one('SELECT id,password_hash,status FROM users WHERE id=:id LIMIT 1', ['id' => $createdBy]);
        if (
            $admin === null
            || (string)$admin['status'] !== 'active'
            || !$this->hasRole($createdBy, 'admin')
            || !password_verify($password . env('PASSWORD_PEPPER', ''), (string)$admin['password_hash'])
        ) {
            throw new Dors3MobileException('reauthentication_failed', 'Ponowne uwierzytelnienie administratora nie powiodło się.', 403);
        }

        $user = $this->db->one(
            'SELECT id,email,login_name,display_name,status,can_write FROM users WHERE id=:id LIMIT 1',
            ['id' => $userId]
        );
        if ($user === null || (string)$user['status'] !== 'active') {
            throw new Dors3MobileException('user_not_eligible', 'Konto docelowe nie jest aktywne.', 422);
        }
        if ($variant === 'admin' && !$this->hasRole($userId, 'admin')) {
            throw new Dors3MobileException('variant_not_allowed', 'Konto nie ma roli administratora.', 403);
        }
        $agreementId = null;
        if ($variant === 'author') {
            $agreementId = (int)(new AuthorAgreementService($this->db))->requireActive($userId)['id'];
        }

        $pending = (int)$this->db->cell(
            'SELECT COUNT(*) FROM security_mobile_enrollments
             WHERE user_id=:user AND application_variant=:variant AND status IN (\'pending\',\'completed\') AND expires_at>NOW()',
            ['user' => $userId, 'variant' => $variant]
        );
        if ($pending >= (int)$this->mobileConfig()['max_pending_per_user']) {
            throw new Dors3MobileException('too_many_pending', 'Osiągnięto limit oczekujących rejestracji.', 429);
        }

        $publicId = SecurityId::uuid();
        $token = MobileProtocol::base64Url(32);
        $comparisonCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = time() + (int)$this->mobileConfig()['enrollment_ttl_seconds'];
        $organizationId = 'zrodlo-slowa';
        $browserSessionHash = $this->browserSessionHash();
        $role = $variant;

        $this->db->query(
            'INSERT INTO security_mobile_enrollments(
                public_id,user_id,organization_id,agreement_id,application_variant,token_hash,
                comparison_code_hash,comparison_code_ciphertext,browser_session_hash,expires_at,
                status,created_by,created_at
             ) VALUES(
                :public_id,:user_id,:organization_id,:agreement_id,:variant,:token_hash,
                :code_hash,:code_ciphertext,:browser_hash,:expires_at,\'pending\',:created_by,NOW()
             )',
            [
                'public_id' => $publicId,
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'agreement_id' => $agreementId,
                'variant' => $variant,
                'token_hash' => hash('sha256', $token),
                'code_hash' => hash('sha256', $comparisonCode),
                'code_ciphertext' => $this->cipher->encrypt($comparisonCode, 'dors3-mobile-comparison-code'),
                'browser_hash' => $browserSessionHash,
                'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
                'created_by' => $createdBy,
            ]
        );

        $account = trim((string)($user['login_name'] ?: $user['email']));
        $qrPayload = [
            'token' => $token,
            'enrollment_request_id' => $publicId,
            'service' => 'Źródło Słowa',
            'environment' => $this->environmentLabel(),
            'organization' => $organizationId,
            'user_display_name' => (string)$user['display_name'],
            'account' => $account,
            'role' => $role,
            'purpose' => 'enrollment',
            'application_variant' => $variant,
            'protocol_version' => MobileProtocol::PROTOCOL_VERSION,
            'expires_at' => $expiresAt,
        ];

        $this->event($createdBy, 'mobile.enrollment.started', 'success', 'medium', 'mobile_enrollment', $publicId, null, null, [
            'target_id' => $userId,
            'application_variant' => $variant,
        ]);

        return [
            'enrollment_request_id' => $publicId,
            'comparison_code' => $comparisonCode,
            'expires_at' => $expiresAt,
            'qr_payload' => $qrPayload,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function completeEnrollment(array $input): array
    {
        $variant = trim((string)($input['application_variant'] ?? ''));
        $this->assertEnabled($variant);
        $this->hitRateLimit('enrollment.complete', RequestContext::ipAddress() ?? 'unknown', 20, 300);
        $publicId = trim((string)($input['enrollment_request_id'] ?? ''));
        $token = (string)($input['token'] ?? '');
        $publicKey = trim((string)($input['public_key'] ?? ''));
        $algorithm = trim((string)($input['algorithm'] ?? ''));
        $securityLevel = strtoupper(trim((string)($input['security_level'] ?? '')));
        if ($publicId === '' || $token === '' || $publicKey === '') {
            throw new Dors3MobileException('invalid_enrollment', 'Niepełne dane rejestracji.');
        }
        if ($algorithm !== MobileProtocol::ALGORITHM || !in_array($securityLevel, ['STRONGBOX', 'TEE', 'SOFTWARE'], true)) {
            throw new Dors3MobileException('invalid_credential', 'Nieobsługiwany algorytm lub poziom ochrony.');
        }
        $this->assertEcPublicKey($publicKey);

        try {
            return $this->db->transaction(function (Database $db) use ($input, $variant, $publicId, $token, $publicKey, $algorithm, $securityLevel): array {
                $enrollment = $db->one(
                'SELECT * FROM security_mobile_enrollments WHERE public_id=:id FOR UPDATE',
                ['id' => $publicId]
            );
            if ($enrollment === null) {
                throw new Dors3MobileException('invalid_enrollment', 'Rejestracja nie istnieje.', 404);
            }
            if ((string)$enrollment['application_variant'] !== $variant) {
                throw new Dors3MobileException('variant_mismatch', 'Wariant aplikacji nie zgadza się z rejestracją.', 403);
            }
            if ((string)$enrollment['status'] !== 'pending' || !empty($enrollment['used_at'])) {
                throw new Dors3MobileException('enrollment_already_used', 'Rejestracja została już użyta.', 409);
            }
            if ($this->epoch((string)$enrollment['expires_at']) <= time()) {
                $db->query('UPDATE security_mobile_enrollments SET status=\'expired\' WHERE id=:id', ['id' => (int)$enrollment['id']]);
                throw new Dors3MobileException('enrollment_expired', 'Rejestracja wygasła.', 410);
            }
            if (!hash_equals((string)$enrollment['token_hash'], hash('sha256', $token))) {
                throw new Dors3MobileException('invalid_token', 'Token rejestracji jest nieprawidłowy.', 403);
            }

            $this->assertUserEligible(
                (int)$enrollment['user_id'],
                $variant,
                $enrollment['agreement_id'] !== null ? (int)$enrollment['agreement_id'] : null,
            );

            $devicePublicId = SecurityId::uuid();
            $credentialPublicId = SecurityId::uuid();
            $apiToken = MobileProtocol::base64Url(32);
            $apiTokenExpiresAt = time() + (int)$this->mobileConfig()['api_token_ttl_seconds'];
            $deviceId = $db->insert(
                'INSERT INTO security_mobile_devices(
                    public_id,user_id,organization_id,agreement_id,application_variant,display_name,
                    platform,app_version,device_model,os_version,security_level,status,registered_at,created_request_id
                 ) VALUES(
                    :public_id,:user_id,:organization_id,:agreement_id,:variant,:display_name,
                    \'android\',:app_version,:device_model,:os_version,:security_level,\'pending\',NOW(),:request_id
                 )',
                [
                    'public_id' => $devicePublicId,
                    'user_id' => (int)$enrollment['user_id'],
                    'organization_id' => (string)$enrollment['organization_id'],
                    'agreement_id' => $enrollment['agreement_id'],
                    'variant' => $variant,
                    'display_name' => mb_substr(trim((string)($input['device_model'] ?? 'Android')), 0, 120),
                    'app_version' => mb_substr(trim((string)($input['app_version'] ?? 'unknown')), 0, 32),
                    'device_model' => mb_substr(trim((string)($input['device_model'] ?? 'unknown')), 0, 160),
                    'os_version' => mb_substr(trim((string)($input['os_version'] ?? 'unknown')), 0, 80),
                    'security_level' => $securityLevel,
                    'request_id' => $publicId,
                ]
            );
            $db->insert(
                'INSERT INTO security_mobile_credentials(
                    public_id,device_id,public_key,algorithm,key_reference,security_level,status,
                    api_token_hash,api_token_expires_at,created_at
                 ) VALUES(
                    :public_id,:device_id,:public_key,:algorithm,:key_reference,:security_level,\'pending\',
                    :api_token_hash,:api_token_expires_at,NOW()
                 )',
                [
                    'public_id' => $credentialPublicId,
                    'device_id' => $deviceId,
                    'public_key' => $publicKey,
                    'algorithm' => $algorithm,
                    'key_reference' => 'android-keystore:' . $credentialPublicId,
                    'security_level' => $securityLevel,
                    'api_token_hash' => hash('sha256', $apiToken),
                    'api_token_expires_at' => gmdate('Y-m-d H:i:s', $apiTokenExpiresAt),
                ]
            );
            $db->query(
                'UPDATE security_mobile_enrollments
                 SET used_at=NOW(),device_completed_at=NOW(),status=\'completed\'
                 WHERE id=:id AND status=\'pending\'',
                ['id' => (int)$enrollment['id']]
            );
            $comparisonCode = $this->cipher->decrypt((string)$enrollment['comparison_code_ciphertext'], 'dors3-mobile-comparison-code');
            $this->event((int)$enrollment['user_id'], 'mobile.enrollment.completed', 'success', 'medium', 'mobile_device', $devicePublicId, null, null, [
                'application_variant' => $variant,
                'device_public_id' => $devicePublicId,
                'credential_public_id' => $credentialPublicId,
                'security_level' => $securityLevel,
            ], $credentialPublicId);
                return [
                    'device_public_id' => $devicePublicId,
                    'credential_public_id' => $credentialPublicId,
                    'comparison_code' => $comparisonCode,
                    'api_token' => $apiToken,
                    'api_token_expires_at' => $apiTokenExpiresAt,
                ];
            });
        } catch (Dors3MobileException $error) {
            if ($error->errorCode === 'enrollment_expired') {
                $this->db->query(
                    'UPDATE security_mobile_enrollments SET status=\'expired\' WHERE public_id=:id AND status=\'pending\'',
                    ['id' => $publicId]
                );
            }
            throw $error;
        }
    }

    public function confirmEnrollment(
        string $devicePublicId,
        string $credentialPublicId,
        string $apiToken,
        string $comparisonCode,
        bool $confirmed,
    ): void
    {
        $this->db->transaction(function (Database $db) use (
            $devicePublicId,
            $credentialPublicId,
            $apiToken,
            $comparisonCode,
            $confirmed,
        ): void {
            $device = $db->one(
                'SELECT d.*,e.id AS enrollment_id,e.status AS enrollment_status,
                        e.expires_at AS enrollment_expires_at,e.comparison_code_hash,
                        c.id AS credential_id,c.public_id AS credential_public_id,
                        c.status AS credential_status,c.api_token_hash,c.api_token_expires_at
                 FROM security_mobile_devices d
                 JOIN security_mobile_enrollments e ON e.public_id=d.created_request_id
                 JOIN security_mobile_credentials c ON c.device_id=d.id
                 WHERE d.public_id=:id FOR UPDATE OF d,e,c',
                ['id' => $devicePublicId]
            );
            if ($device === null) {
                throw new Dors3MobileException('device_not_found', 'Urządzenie nie istnieje.', 404);
            }
            $this->assertEnabled((string)$device['application_variant']);
            if ((string)$device['status'] !== 'pending' || (string)$device['enrollment_status'] !== 'completed') {
                throw new Dors3MobileException('enrollment_already_used', 'Rejestracja została już potwierdzona.', 409);
            }
            if (
                $credentialPublicId === ''
                || $apiToken === ''
                || !hash_equals((string)$device['credential_public_id'], $credentialPublicId)
                || !hash_equals((string)$device['api_token_hash'], hash('sha256', $apiToken))
            ) {
                throw new Dors3MobileException('credential_mismatch', 'Credential lub token urządzenia jest nieprawidłowy.', 403);
            }
            if (
                $this->epoch((string)$device['enrollment_expires_at']) <= time()
                || $this->epoch((string)$device['api_token_expires_at']) <= time()
            ) {
                throw new Dors3MobileException('enrollment_expired', 'Rejestracja lub token urządzenia wygasły.', 410);
            }
            if ($comparisonCode === '' || !hash_equals((string)$device['comparison_code_hash'], hash('sha256', $comparisonCode))) {
                throw new Dors3MobileException('comparison_code_mismatch', 'Kod porównawczy nie jest zgodny.', 403);
            }
            $this->assertUserEligible(
                (int)$device['user_id'],
                (string)$device['application_variant'],
                $device['agreement_id'] !== null ? (int)$device['agreement_id'] : null,
            );
            if ($confirmed) {
                throw new Dors3MobileException(
                    'panel_confirmation_required',
                    'Telefon nie może sam aktywować urządzenia. Aktywację zatwierdza administrator w powiązanej sesji panelu.',
                    409,
                );
            }
            $db->query('UPDATE security_mobile_devices SET status=\'revoked\',revoked_at=NOW(),revocation_reason=\'comparison_code_rejected_by_device\' WHERE id=:id', ['id' => (int)$device['id']]);
            $db->query('UPDATE security_mobile_credentials SET status=\'revoked\',revoked_at=NOW(),revocation_reason=\'comparison_code_rejected_by_device\' WHERE device_id=:device', ['device' => (int)$device['id']]);
            $db->query('UPDATE security_mobile_enrollments SET status=\'failed\',confirmed_at=NOW() WHERE id=:id', ['id' => (int)$device['enrollment_id']]);
            $this->event((int)$device['user_id'], 'mobile.enrollment.failed', 'rejected', 'high', 'mobile_device', $devicePublicId, null, null, [
                'application_variant' => (string)$device['application_variant'],
                'device_public_id' => $devicePublicId,
                'reason' => 'comparison_code_rejected_by_device',
            ]);
        });
    }

    public function approveEnrollment(int $adminId, string $enrollmentPublicId, string $comparisonCode): void
    {
        $this->hitRateLimit('enrollment.panel_approve', (string)$adminId, 10, 300);
        $this->db->transaction(function (Database $db) use ($adminId, $enrollmentPublicId, $comparisonCode): void {
            $enrollment = $db->one(
                'SELECT e.*,d.id AS device_id,d.public_id AS device_public_id,d.status AS device_status,
                        c.id AS credential_id,c.public_id AS credential_public_id,c.status AS credential_status
                 FROM security_mobile_enrollments e
                 JOIN security_mobile_devices d ON d.created_request_id=e.public_id
                 JOIN security_mobile_credentials c ON c.device_id=d.id
                 WHERE e.public_id=:id
                 FOR UPDATE OF e,d,c',
                ['id' => $enrollmentPublicId],
            );
            if (
                $enrollment === null
                || (int)$enrollment['created_by'] !== $adminId
                || !hash_equals((string)$enrollment['browser_session_hash'], $this->browserSessionHash())
            ) {
                throw new Dors3MobileException('enrollment_not_found', 'Rejestracja nie istnieje w tej sesji administratora.', 404);
            }
            $this->assertEnabled((string)$enrollment['application_variant']);
            if (
                (string)$enrollment['status'] !== 'completed'
                || (string)$enrollment['device_status'] !== 'pending'
                || (string)$enrollment['credential_status'] !== 'pending'
            ) {
                throw new Dors3MobileException('enrollment_not_ready', 'Telefon nie zakończył rejestracji albo enrollment został już rozstrzygnięty.', 409);
            }
            if ($this->epoch((string)$enrollment['expires_at']) <= time()) {
                $db->query('UPDATE security_mobile_enrollments SET status=\'expired\' WHERE id=:id', ['id' => (int)$enrollment['id']]);
                $db->query('UPDATE security_mobile_devices SET status=\'revoked\',revoked_at=NOW(),revocation_reason=\'enrollment_expired\' WHERE id=:id', ['id' => (int)$enrollment['device_id']]);
                $db->query('UPDATE security_mobile_credentials SET status=\'revoked\',revoked_at=NOW(),revocation_reason=\'enrollment_expired\' WHERE id=:id', ['id' => (int)$enrollment['credential_id']]);
                throw new Dors3MobileException('enrollment_expired', 'Rejestracja wygasła.', 410);
            }
            if (
                $comparisonCode === ''
                || !preg_match('/^\d{6}$/D', $comparisonCode)
                || !hash_equals((string)$enrollment['comparison_code_hash'], hash('sha256', $comparisonCode))
            ) {
                throw new Dors3MobileException('comparison_code_mismatch', 'Kod porównawczy nie jest zgodny.', 403);
            }
            $admin = $db->one('SELECT status FROM users WHERE id=:id FOR SHARE', ['id' => $adminId]);
            if ($admin === null || (string)$admin['status'] !== 'active' || !$this->hasRole($adminId, 'admin')) {
                throw new Dors3MobileException('admin_not_eligible', 'Administrator utracił uprawnienie do aktywacji urządzenia.', 403);
            }
            $this->assertUserEligible(
                (int)$enrollment['user_id'],
                (string)$enrollment['application_variant'],
                $enrollment['agreement_id'] !== null ? (int)$enrollment['agreement_id'] : null,
            );

            $db->query('UPDATE security_mobile_devices SET status=\'active\',activated_at=NOW() WHERE id=:id AND status=\'pending\'', ['id' => (int)$enrollment['device_id']]);
            $db->query('UPDATE security_mobile_credentials SET status=\'active\' WHERE id=:id AND status=\'pending\'', ['id' => (int)$enrollment['credential_id']]);
            $db->query(
                'UPDATE security_mobile_enrollments
                 SET status=\'confirmed\',confirmed_at=NOW(),panel_confirmed_by=:admin
                 WHERE id=:id AND status=\'completed\'',
                ['admin' => $adminId, 'id' => (int)$enrollment['id']],
            );
            $this->event($adminId, 'mobile.enrollment.panel_confirmed', 'success', 'high', 'mobile_device', (string)$enrollment['device_public_id'], null, null, [
                'application_variant' => (string)$enrollment['application_variant'],
                'target_user_id' => (int)$enrollment['user_id'],
                'device_public_id' => (string)$enrollment['device_public_id'],
                'credential_public_id' => (string)$enrollment['credential_public_id'],
                'enrollment_public_id' => $enrollmentPublicId,
                'browser_session_bound' => true,
            ], (string)$enrollment['credential_public_id']);
        });
    }

    /**
     * Backend sam wyznacza wariant aplikacji z typu operacji. Kontrolery nie
     * przekazują wariantu i nie mogą omyłkowo wysłać artykułu do Admin ani
     * wypłaty/roli/bezpieczeństwa do Author.
     *
     * @param array<string,string> $displayFields
     * @param array<string,mixed>|null $deferredOperation
     * @return array<string,mixed>
     */
    public function createOperationApprovalRequest(
        int $userId,
        string $actionType,
        ?string $resourceType,
        ?string $resourceId,
        array $displayFields,
        string $actionFingerprint,
        ?array $deferredOperation = null,
        ?int $issuedAtOverride = null,
    ): array {
        try {
            $variant = MobileOperationPolicy::requiredVariant($actionType);
        } catch (\DomainException $error) {
            throw new Dors3MobileException('operation_not_routed', $error->getMessage(), 422);
        }

        return $this->createApprovalRequest(
            $userId,
            $variant,
            'operation',
            $actionType,
            $resourceType,
            $resourceId,
            $displayFields,
            $actionFingerprint,
            $deferredOperation,
            $issuedAtOverride,
        );
    }

    /**
     * @param array<string,string> $displayFields
     * @param array<string,mixed>|null $deferredOperation
     * @return array<string,mixed>
     */
    public function createApprovalRequest(
        int $userId,
        string $variant,
        string $purpose,
        string $actionType,
        ?string $resourceType,
        ?string $resourceId,
        array $displayFields,
        ?string $actionFingerprint = null,
        ?array $deferredOperation = null,
        ?int $issuedAtOverride = null,
    ): array {
        $this->assertEnabled($variant);
        if (!in_array($purpose, ['login', 'operation'], true)) {
            throw new Dors3MobileException('invalid_purpose', 'Nieobsługiwany cel żądania.');
        }
        if ($purpose === 'operation') {
            $this->assertOperationAllowed($variant, $actionType);
            if ($actionFingerprint === null || preg_match('/^[a-f0-9]{64}$/D', $actionFingerprint) !== 1) {
                throw new Dors3MobileException('invalid_fingerprint', 'Operacja wymaga poprawnego action_fingerprint.');
            }
        }
        $this->assertUserEligible($userId, $variant);
        $this->assertOperationActorEligible($userId, $variant, $actionType);
        $this->hitRateLimit('approval.create.' . $variant, (string)$userId, 20, 60);
        $this->db->query(
            'UPDATE security_mobile_approval_requests SET status=\'expired\'
             WHERE user_id=:user AND status=\'pending\' AND expires_at<=NOW()',
            ['user' => $userId]
        );
        $pendingCount = (int)$this->db->cell(
            'SELECT COUNT(*) FROM security_mobile_approval_requests WHERE user_id=:user AND status=\'pending\' AND expires_at>NOW()',
            ['user' => $userId]
        );
        if ($pendingCount >= (int)$this->mobileConfig()['max_pending_per_user']) {
            throw new Dors3MobileException('too_many_pending', 'Osiągnięto limit równoległych żądań.', 429);
        }

        $device = $this->db->one(
            'SELECT d.id,d.public_id,c.id AS credential_id,c.public_id AS credential_public_id
             FROM security_mobile_devices d
             JOIN security_mobile_credentials c ON c.device_id=d.id AND c.status=\'active\'
             WHERE d.user_id=:user AND d.application_variant=:variant AND d.status=\'active\'
             ORDER BY d.last_used_at DESC NULLS LAST,d.activated_at DESC,d.id DESC LIMIT 1',
            ['user' => $userId, 'variant' => $variant]
        );
        if ($device === null) {
            throw new Dors3MobileException('active_device_required', 'Brak aktywnego urządzenia właściwego wariantu.', 409);
        }
        if ($purpose === 'operation') {
            $existing = $this->db->one(
                'SELECT r.public_id,r.action_fingerprint,r.expires_at,d.public_id AS device_public_id
                 FROM security_mobile_approval_requests r
                 JOIN security_mobile_devices d ON d.id=r.device_id
                 WHERE r.user_id=:user AND r.application_variant=:variant AND r.action_type=:action
                   AND COALESCE(r.resource_type,\'\')=COALESCE(:resource_type,\'\')
                   AND COALESCE(r.resource_id,\'\')=COALESCE(:resource_id,\'\')
                   AND r.status=\'pending\' AND r.expires_at>NOW()
                 ORDER BY r.created_at DESC,r.id DESC LIMIT 1',
                [
                    'user' => $userId,
                    'variant' => $variant,
                    'action' => $actionType,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                ]
            );
            if ($existing !== null) {
                if (!hash_equals((string)$existing['action_fingerprint'], (string)$actionFingerprint)) {
                    throw new Dors3MobileException(
                        'operation_changed_while_pending',
                        'Ta operacja oczekuje już na podpis, ale jej dane uległy zmianie.',
                        409
                    );
                }
                return [
                    'public_id' => (string)$existing['public_id'],
                    'expires_at' => strtotime((string)$existing['expires_at']) ?: time(),
                    'device_public_id' => (string)$existing['device_public_id'],
                    'application_variant' => $variant,
                    'launch_uri' => $this->approvalLaunchUri($variant, (string)$existing['public_id']),
                    'deduplicated' => true,
                ];
            }
        }
        $user = $this->db->one('SELECT email,login_name,display_name FROM users WHERE id=:id', ['id' => $userId]);
        if ($user === null) {
            throw new Dors3MobileException('user_not_found', 'Użytkownik nie istnieje.', 404);
        }
        $role = $variant;
        $publicId = SecurityId::uuid();
        $requestId = bin2hex(random_bytes(16));
        $challenge = MobileProtocol::base64Url(32);
        $nonce = MobileProtocol::base64Url(24);
        $issuedAt = $issuedAtOverride ?? time();
        if (abs(time() - $issuedAt) > 5) {
            throw new Dors3MobileException('invalid_issued_at', 'Czas utworzenia operacji jest nieprawidłowy.');
        }
        $expiresAt = $issuedAt + (int)$this->mobileConfig()['request_ttl_seconds'];
        $correlationId = trim((string)($_SERVER['HTTP_X_CORRELATION_ID'] ?? '')) ?: RequestContext::requestId();
        $browserHash = $this->browserSessionHash();
        $origin = rtrim((string)env('APP_URL', 'http://localhost:8080'), '/');
        $requestParams = [
            'public_id' => $publicId,
            'user_id' => $userId,
            'organization_id' => 'zrodlo-slowa',
            'role_context' => $role,
            'device_id' => (int)$device['id'],
            'credential_id' => (int)$device['credential_id'],
            'variant' => $variant,
            'purpose' => $purpose,
            'action_type' => $actionType,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'fingerprint' => $actionFingerprint,
            'display_payload' => $this->json($displayFields),
            'challenge_hash' => hash('sha256', $challenge),
            'challenge_ciphertext' => $this->cipher->encrypt($challenge, 'dors3-mobile-challenge'),
            'browser_hash' => $browserHash,
            'origin' => $origin,
            'environment' => $this->environmentLabel(),
            'protocol_version' => MobileProtocol::PROTOCOL_VERSION,
            'nonce_hash' => hash('sha256', $nonce),
            'nonce_ciphertext' => $this->cipher->encrypt($nonce, 'dors3-mobile-nonce'),
            'issued_at' => gmdate('Y-m-d H:i:s', $issuedAt),
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
            'request_id' => $requestId,
            'correlation_id' => mb_substr($correlationId, 0, 128),
        ];

        $id = $this->db->transaction(function (Database $db) use ($requestParams, $deferredOperation, $actionFingerprint): int {
            $id = $db->insert(
                'INSERT INTO security_mobile_approval_requests(
                public_id,user_id,organization_id,role_context,device_id,credential_id,application_variant,
                purpose,action_type,resource_type,resource_id,action_fingerprint,display_payload_json,
                challenge_hash,challenge_ciphertext,browser_session_hash,server_origin,environment,
                protocol_version,nonce_hash,nonce_ciphertext,status,issued_at,expires_at,request_id,
                correlation_id,created_at
             ) VALUES(
                :public_id,:user_id,:organization_id,:role_context,:device_id,:credential_id,:variant,
                :purpose,:action_type,:resource_type,:resource_id,:fingerprint,:display_payload,
                :challenge_hash,:challenge_ciphertext,:browser_hash,:origin,:environment,
                :protocol_version,:nonce_hash,:nonce_ciphertext,\'pending\',:issued_at,:expires_at,:request_id,
                :correlation_id,NOW()
                 )',
                $requestParams
            );
            if ($deferredOperation !== null) {
                $db->query(
                    'INSERT INTO security_mobile_deferred_operations(
                        approval_request_id,operation_payload_json,expected_fingerprint,status,created_at
                     ) VALUES(:request,:payload,:fingerprint,\'pending\',NOW())',
                    [
                        'request' => $id,
                        'payload' => $this->json($deferredOperation),
                        'fingerprint' => $actionFingerprint,
                    ]
                );
            }
            return $id;
        });
        $this->event($userId, 'mobile.request.created', 'success', $purpose === 'login' ? 'medium' : 'high', $resourceType, $resourceId, null, null, [
            'application_variant' => $variant,
            'device_public_id' => (string)$device['public_id'],
            'credential_public_id' => (string)$device['credential_public_id'],
            'approval_request_id' => $publicId,
            'action_type' => $actionType,
            'action_fingerprint' => $actionFingerprint,
        ], (string)$device['credential_public_id']);

        return [
            'public_id' => $publicId,
            'expires_at' => $expiresAt,
            'device_public_id' => (string)$device['public_id'],
            'application_variant' => $variant,
            'launch_uri' => $this->approvalLaunchUri($variant, $publicId),
        ];
    }

    /** @return array<string,mixed>|null */
    public function pendingRequest(string $devicePublicId, string $credentialPublicId, string $apiToken): ?array
    {
        $device = $this->authenticateDeviceApi($devicePublicId, $credentialPublicId, $apiToken, true);
        $this->assertEnabled((string)$device['application_variant']);
        $request = $this->db->one(
            'SELECT public_id FROM security_mobile_approval_requests
             WHERE device_id=:device AND status=\'pending\' AND expires_at>NOW()
             ORDER BY issued_at,id LIMIT 1',
            ['device' => (int)$device['id']]
        );
        return $request === null
            ? null
            : $this->requestDetails((string)$request['public_id'], $credentialPublicId, $apiToken);
    }

    /** @return array<string,mixed> */
    public function requestDetails(string $publicId, string $credentialPublicId, string $apiToken): array
    {
        $credential = $this->authenticateCredentialApi($credentialPublicId, $apiToken, true);
        $row = $this->db->one(
            'SELECT r.*,u.email,u.login_name,u.display_name,
                    d.public_id AS device_public_id,c.public_id AS credential_public_id,
                    EXTRACT(EPOCH FROM r.issued_at)::bigint AS issued_at_epoch,
                    EXTRACT(EPOCH FROM r.expires_at)::bigint AS expires_at_epoch
             FROM security_mobile_approval_requests r
             JOIN users u ON u.id=r.user_id
             LEFT JOIN security_mobile_devices d ON d.id=r.device_id
             LEFT JOIN security_mobile_credentials c ON c.id=r.credential_id
             WHERE r.public_id=:id LIMIT 1',
            ['id' => $publicId]
        );
        if ($row === null) {
            throw new Dors3MobileException('request_not_found', 'Żądanie nie istnieje.', 404);
        }
        if (
            !hash_equals((string)$row['credential_public_id'], $credentialPublicId)
            || (int)$row['device_id'] !== (int)$credential['id']
        ) {
            throw new Dors3MobileException('request_not_found', 'Żądanie nie istnieje.', 404);
        }
        if ((string)$row['status'] !== 'pending') {
            throw new Dors3MobileException('request_already_processed', 'Żądanie zostało już przetworzone.', 409);
        }
        if ((int)$row['expires_at_epoch'] <= time()) {
            $this->db->query('UPDATE security_mobile_approval_requests SET status=\'expired\' WHERE id=:id AND status=\'pending\'', ['id' => (int)$row['id']]);
            throw new Dors3MobileException('request_expired', 'Żądanie wygasło.', 410);
        }
        $this->assertEnabled((string)$row['application_variant']);
        return $this->detailsFromRow($row);
    }

    /**
     * @param array<string,mixed> $input
     * @param null|callable(Database,array<string,mixed>,array<string,mixed>):void $deferredExecutor
     * @return array{status:string,consumed_at:int}
     */
    public function decide(string $publicId, string $decision, array $input, ?callable $deferredExecutor = null): array
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new Dors3MobileException('invalid_decision', 'Nieobsługiwana decyzja.');
        }
        $devicePublicId = trim((string)($input['device_public_id'] ?? ''));
        $credentialPublicId = trim((string)($input['credential_public_id'] ?? ''));
        $algorithm = trim((string)($input['algorithm'] ?? ''));
        $signedPayload = (string)($input['signed_payload'] ?? '');
        $signature = (string)($input['signature'] ?? '');
        $ipAddress = RequestContext::ipAddress() ?? 'unknown';
        $this->hitRateLimit('approval.decide.ip', $ipAddress, 30, 60);
        $this->hitRateLimit('approval.decide.request', $publicId, 20, 60);
        if ($devicePublicId !== '') {
            $this->hitRateLimit('approval.decide.device', $devicePublicId, 30, 60);
        }

        try {
            return $this->db->transaction(function (Database $db) use (
                $publicId, $decision, $devicePublicId, $credentialPublicId, $algorithm,
                $signedPayload, $signature, $deferredExecutor
            ): array {
                $row = $db->one(
                    'SELECT r.*,u.email,u.login_name,u.display_name,
                            d.public_id AS device_public_id,d.status AS device_status,d.user_id AS device_user_id,
                            d.application_variant AS device_variant,d.agreement_id AS device_agreement_id,
                            c.public_id AS credential_public_id,c.status AS credential_status,c.public_key,c.algorithm AS credential_algorithm,
                            EXTRACT(EPOCH FROM r.issued_at)::bigint AS issued_at_epoch,
                            EXTRACT(EPOCH FROM r.expires_at)::bigint AS expires_at_epoch
                     FROM security_mobile_approval_requests r
                     JOIN users u ON u.id=r.user_id
                     JOIN security_mobile_devices d ON d.id=r.device_id
                     JOIN security_mobile_credentials c ON c.id=r.credential_id
                     WHERE r.public_id=:id FOR UPDATE OF r,d,c',
                    ['id' => $publicId]
                );
                if ($row === null) {
                    throw new Dors3MobileException('request_not_found', 'Żądanie nie istnieje.', 404);
                }
                $this->assertEnabled((string)$row['application_variant']);
                if ((string)$row['status'] !== 'pending') {
                    throw new Dors3MobileException('request_already_processed', 'Żądanie zostało już przetworzone.', 409);
                }
                if ((int)$row['expires_at_epoch'] <= time()) {
                    $db->query('UPDATE security_mobile_approval_requests SET status=\'expired\' WHERE id=:id', ['id' => (int)$row['id']]);
                    throw new Dors3MobileException('request_expired', 'Żądanie wygasło.', 410);
                }
                $this->assertDeviceActive($row);
                if (
                    !hash_equals((string)$row['device_public_id'], $devicePublicId)
                    || !hash_equals((string)$row['credential_public_id'], $credentialPublicId)
                    || (int)$row['device_user_id'] !== (int)$row['user_id']
                    || (string)$row['device_variant'] !== (string)$row['application_variant']
                    || (string)$row['credential_status'] !== 'active'
                    || !hash_equals((string)$row['credential_algorithm'], $algorithm)
                ) {
                    throw new Dors3MobileException('credential_mismatch', 'Urządzenie lub credential nie pasuje do żądania.', 403);
                }
                if ((string)$row['purpose'] === 'operation') {
                    $this->assertOperationAllowed((string)$row['application_variant'], (string)$row['action_type']);
                }
                $this->assertUserEligible(
                    (int)$row['user_id'],
                    (string)$row['application_variant'],
                    $row['device_agreement_id'] !== null ? (int)$row['device_agreement_id'] : null,
                );
                $this->assertOperationActorEligible(
                    (int)$row['user_id'],
                    (string)$row['application_variant'],
                    (string)$row['action_type'],
                );
                $protocol = $this->protocolRow($row);
                $canonical = MobileProtocol::canonicalPayload($protocol, $decision, $credentialPublicId);
                if (!hash_equals($canonical, $signedPayload)) {
                    throw new Dors3MobileException('signed_payload_mismatch', 'Podpisany payload nie odpowiada danym serwera.', 403);
                }
                if (!$this->signatureVerifier->verify((string)$row['public_key'], $canonical, $signature, $algorithm)) {
                    throw new Dors3MobileException('invalid_signature', 'Podpis kryptograficzny jest nieprawidłowy.', 403);
                }
                $this->assertCompanionPolicySatisfied($db, $row);

                $deferred = $db->one(
                    'SELECT * FROM security_mobile_deferred_operations WHERE approval_request_id=:request FOR UPDATE',
                    ['request' => (int)$row['id']]
                );
                if ($decision === 'approve' && $deferred !== null) {
                    if ((string)$deferred['status'] !== 'pending') {
                        throw new Dors3MobileException('request_already_processed', 'Operacja została już wykonana.', 409);
                    }
                    if (!hash_equals((string)$row['action_fingerprint'], (string)$deferred['expected_fingerprint'])) {
                        throw new Dors3MobileException('fingerprint_mismatch', 'Odcisk operacji zmienił się.', 409);
                    }
                    if ($deferredExecutor === null) {
                        throw new Dors3MobileException('executor_unavailable', 'Brak bezpiecznego wykonawcy operacji.', 503);
                    }
                    $payload = $this->decodeJson($deferred['operation_payload_json'] ?? null);
                    $deferredExecutor($db, $row, $payload);
                    $db->query(
                        'UPDATE security_mobile_deferred_operations SET status=\'executed\',executed_at=NOW(),failure_reason=NULL WHERE id=:id AND status=\'pending\'',
                        ['id' => (int)$deferred['id']]
                    );
                } elseif ($decision === 'reject' && $deferred !== null) {
                    $db->query('UPDATE security_mobile_deferred_operations SET status=\'cancelled\' WHERE id=:id AND status=\'pending\'', ['id' => (int)$deferred['id']]);
                }

                $now = time();
                $db->query(
                    $decision === 'approve'
                        ? 'UPDATE security_mobile_approval_requests SET status=\'consumed\',approved_at=NOW(),consumed_at=NOW() WHERE id=:id AND status=\'pending\''
                        : 'UPDATE security_mobile_approval_requests SET status=\'consumed\',rejected_at=NOW(),consumed_at=NOW() WHERE id=:id AND status=\'pending\'',
                    ['id' => (int)$row['id']]
                );
                $db->query(
                    'INSERT INTO security_mobile_signatures(
                        approval_request_id,device_id,credential_id,decision,signature,signed_payload_hash,
                        algorithm,verification_result,failure_reason,signed_at,verified_at,request_id
                     ) VALUES(:approval,:device,:credential,:decision,:signature,:payload_hash,
                        :algorithm,\'valid\',NULL,NOW(),NOW(),:request_id)',
                    [
                        'approval' => (int)$row['id'],
                        'device' => (int)$row['device_id'],
                        'credential' => (int)$row['credential_id'],
                        'decision' => $decision,
                        'signature' => $signature,
                        'payload_hash' => hash('sha256', $canonical),
                        'algorithm' => $algorithm,
                        'request_id' => RequestContext::requestId(),
                    ]
                );
                $db->query('UPDATE security_mobile_credentials SET last_signature_at=NOW() WHERE id=:id', ['id' => (int)$row['credential_id']]);
                $db->query('UPDATE security_mobile_devices SET last_used_at=NOW() WHERE id=:id', ['id' => (int)$row['device_id']]);
                $eventAction = $decision === 'approve' ? 'mobile.request.approved' : 'mobile.request.rejected';
                $this->event((int)$row['user_id'], $eventAction, $decision === 'approve' ? 'success' : 'rejected', 'high', (string)$row['resource_type'], (string)$row['resource_id'], null, null, [
                    'application_variant' => (string)$row['application_variant'],
                    'device_public_id' => $devicePublicId,
                    'credential_public_id' => $credentialPublicId,
                    'approval_request_id' => $publicId,
                    'decision' => $decision,
                    'action_type' => (string)$row['action_type'],
                    'action_fingerprint' => $row['action_fingerprint'],
                    'server_origin' => (string)$row['server_origin'],
                    'environment' => (string)$row['environment'],
                ], $credentialPublicId);
                return ['status' => $decision === 'approve' ? 'approved' : 'rejected', 'consumed_at' => $now];
            });
        } catch (Dors3MobileException $error) {
            if ($error->errorCode === 'request_expired') {
                $this->db->query(
                    'UPDATE security_mobile_approval_requests SET status=\'expired\' WHERE public_id=:id AND status=\'pending\'',
                    ['id' => $publicId]
                );
            }
            $this->event(null, 'mobile.signature.invalid', 'blocked', 'critical', 'mobile_approval', $publicId, null, null, [
                'application_variant' => null,
                'device_public_id' => $devicePublicId !== '' ? $devicePublicId : null,
                'credential_public_id' => $credentialPublicId !== '' ? $credentialPublicId : null,
                'approval_request_id' => $publicId,
                'decision' => $decision,
                'reason' => $error->errorCode,
            ], $credentialPublicId !== '' ? $credentialPublicId : null);
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function approvalStatus(string $publicId, bool $requireBrowserSession = true): array
    {
        $row = $this->db->one(
            'SELECT public_id,status,approved_at,rejected_at,consumed_at,expires_at,browser_session_hash,
                    EXTRACT(EPOCH FROM expires_at)::bigint AS expires_at_epoch,
                    EXTRACT(EPOCH FROM consumed_at)::bigint AS consumed_at_epoch
             FROM security_mobile_approval_requests WHERE public_id=:id LIMIT 1',
            ['id' => $publicId]
        );
        if ($row === null) {
            throw new Dors3MobileException('request_not_found', 'Żądanie nie istnieje.', 404);
        }
        if ($requireBrowserSession && !hash_equals((string)$row['browser_session_hash'], $this->browserSessionHash())) {
            throw new Dors3MobileException('request_not_found', 'Żądanie nie istnieje.', 404);
        }
        $status = (string)$row['status'];
        if ($status === 'pending' && (int)$row['expires_at_epoch'] <= time()) {
            $this->db->query('UPDATE security_mobile_approval_requests SET status=\'expired\' WHERE public_id=:id AND status=\'pending\'', ['id' => $publicId]);
            $status = 'expired';
        } elseif (!empty($row['approved_at'])) {
            $status = 'approved';
        } elseif (!empty($row['rejected_at'])) {
            $status = 'rejected';
        }
        return [
            'public_id' => (string)$row['public_id'],
            'status' => $status,
            'expires_at' => (int)$row['expires_at_epoch'],
            'consumed_at' => $row['consumed_at_epoch'] !== null ? (int)$row['consumed_at_epoch'] : null,
        ];
    }

    /** @return array<string,mixed> */
    public function deviceStatus(string $devicePublicId, string $credentialPublicId, string $apiToken): array
    {
        $device = $this->authenticateDeviceApi($devicePublicId, $credentialPublicId, $apiToken, false);
        return [
            'device_public_id' => (string)$device['public_id'],
            'application_variant' => (string)$device['application_variant'],
            'status' => (string)$device['status'],
            'last_used_at' => $device['last_used_at'],
        ];
    }

    public function heartbeat(string $devicePublicId, string $credentialPublicId, string $apiToken, string $variant): void
    {
        $this->assertEnabled($variant);
        $this->hitRateLimit('device.heartbeat', $devicePublicId, 30, 60);
        $device = $this->authenticateDeviceApi($devicePublicId, $credentialPublicId, $apiToken, true);
        if ((string)$device['application_variant'] !== $variant) {
            throw new Dors3MobileException('variant_mismatch', 'Wariant aplikacji nie pasuje do urządzenia.', 403);
        }
        $updated = $this->db->query(
            'UPDATE security_mobile_devices d SET last_used_at=NOW()
             FROM security_mobile_credentials c
             WHERE d.public_id=:device AND d.application_variant=:variant AND d.status=\'active\'
               AND c.device_id=d.id AND c.public_id=:credential AND c.status=\'active\'',
            ['device' => $devicePublicId, 'credential' => $credentialPublicId, 'variant' => $variant]
        )->rowCount();
        if ($updated !== 1) {
            throw new Dors3MobileException('credential_mismatch', 'Urządzenie lub credential nie jest aktywne.', 403);
        }
    }

    public function changeDeviceStatus(int $adminId, string $devicePublicId, string $status, string $reason): void
    {
        if (!in_array($status, ['active', 'suspended', 'lost', 'revoked'], true)) {
            throw new \InvalidArgumentException('Nieobsługiwany status urządzenia.');
        }
        $this->db->transaction(function (Database $db) use ($adminId, $devicePublicId, $status, $reason): void {
            $device = $db->one('SELECT * FROM security_mobile_devices WHERE public_id=:id FOR UPDATE', ['id' => $devicePublicId]);
            if ($device === null) {
                throw new Dors3MobileException('device_not_found', 'Urządzenie nie istnieje.', 404);
            }
            $currentStatus = (string)$device['status'];
            if (in_array($currentStatus, ['lost', 'revoked', 'expired'], true)) {
                throw new Dors3MobileException('terminal_device_state', 'Utraconego, unieważnionego lub wygasłego urządzenia nie można ponownie aktywować.', 409);
            }
            if ($status === 'active' && $currentStatus !== 'suspended') {
                throw new Dors3MobileException('invalid_device_transition', 'Wznowić można wyłącznie zawieszone urządzenie.', 409);
            }
            if ($status === 'suspended' && $currentStatus !== 'active') {
                throw new Dors3MobileException('invalid_device_transition', 'Zawiesić można wyłącznie aktywne urządzenie.', 409);
            }
            if (in_array($status, ['lost', 'revoked'], true) && !in_array($currentStatus, ['pending', 'active', 'suspended'], true)) {
                throw new Dors3MobileException('invalid_device_transition', 'Ta zmiana statusu urządzenia jest niedozwolona.', 409);
            }

            if ($status === 'active') {
                $db->query(
                    'UPDATE security_mobile_devices
                     SET status=\'active\',suspended_at=NULL,revoked_by=NULL,revocation_reason=NULL,last_used_at=NOW()
                     WHERE id=:id',
                    ['id' => (int)$device['id']],
                );
                $db->query(
                    'UPDATE security_mobile_credentials
                     SET status=\'active\',revocation_reason=NULL
                     WHERE device_id=:device AND status=\'suspended\'',
                    ['device' => (int)$device['id']],
                );
            } else {
                $timestampColumn = $status === 'suspended' ? 'suspended_at' : 'revoked_at';
            $db->query(
                "UPDATE security_mobile_devices SET status=:status,{$timestampColumn}=NOW(),revoked_by=:admin,revocation_reason=:reason WHERE id=:id",
                ['status' => $status, 'admin' => $adminId, 'reason' => mb_substr($reason, 0, 1000), 'id' => (int)$device['id']]
            );
            $credentialStatus = $status === 'suspended' ? 'suspended' : 'revoked';
            $db->query(
                $credentialStatus === 'revoked'
                    ? 'UPDATE security_mobile_credentials SET status=\'revoked\',revoked_at=NOW(),revocation_reason=:reason WHERE device_id=:device AND status IN (\'active\',\'pending\',\'suspended\')'
                    : 'UPDATE security_mobile_credentials SET status=\'suspended\',revocation_reason=:reason WHERE device_id=:device AND status IN (\'active\',\'pending\')',
                ['reason' => mb_substr($reason, 0, 1000), 'device' => (int)$device['id']]
            );
            }
            $db->query('UPDATE security_mobile_approval_requests SET status=\'cancelled\' WHERE device_id=:device AND status=\'pending\'', ['device' => (int)$device['id']]);
            $this->event($adminId, 'mobile.device.' . match ($status) {
                'active' => 'resumed',
                'suspended' => 'suspended',
                'lost' => 'lost',
                default => 'revoked',
            }, 'success', 'high', 'mobile_device', $devicePublicId, ['status' => $currentStatus], ['status' => $status], [
                'application_variant' => (string)$device['application_variant'],
                'device_public_id' => $devicePublicId,
                'reason' => $reason,
            ]);
        });
    }

    public function cancelEnrollment(int $adminId, string $enrollmentPublicId, string $reason): void
    {
        $this->db->transaction(function (Database $db) use ($adminId, $enrollmentPublicId, $reason): void {
            $enrollment = $db->one(
                'SELECT * FROM security_mobile_enrollments WHERE public_id=:id FOR UPDATE',
                ['id' => $enrollmentPublicId],
            );
            if ($enrollment === null) {
                throw new Dors3MobileException('enrollment_not_found', 'Rejestracja nie istnieje.', 404);
            }
            if (!in_array((string)$enrollment['status'], ['pending', 'completed'], true)) {
                throw new Dors3MobileException('enrollment_already_used', 'Tej rejestracji nie można już anulować.', 409);
            }
            $device = $db->one(
                'SELECT id,public_id,status FROM security_mobile_devices WHERE created_request_id=:request FOR UPDATE',
                ['request' => $enrollmentPublicId],
            );
            if ($device !== null) {
                $db->query(
                    'UPDATE security_mobile_devices
                     SET status=\'revoked\',revoked_at=NOW(),revoked_by=:admin,revocation_reason=:reason
                     WHERE id=:id AND status=\'pending\'',
                    ['admin' => $adminId, 'reason' => mb_substr($reason, 0, 1000), 'id' => (int)$device['id']],
                );
                $db->query(
                    'UPDATE security_mobile_credentials
                     SET status=\'revoked\',revoked_at=NOW(),revocation_reason=:reason
                     WHERE device_id=:device AND status=\'pending\'',
                    ['reason' => mb_substr($reason, 0, 1000), 'device' => (int)$device['id']],
                );
            }
            $db->query(
                'UPDATE security_mobile_enrollments SET status=\'cancelled\',confirmed_at=NOW() WHERE id=:id',
                ['id' => (int)$enrollment['id']],
            );
            $this->event(
                $adminId,
                'mobile.enrollment.cancelled',
                'success',
                'high',
                'mobile_enrollment',
                $enrollmentPublicId,
                ['status' => (string)$enrollment['status']],
                ['status' => 'cancelled'],
                [
                    'application_variant' => (string)$enrollment['application_variant'],
                    'device_public_id' => $device !== null ? (string)$device['public_id'] : null,
                    'reason' => $reason,
                ],
            );
        });
    }

    /** @return list<array<string,mixed>> */
    public function devices(int $limit = 100): array
    {
        return $this->db->all(
            'SELECT d.*,u.email,u.display_name AS owner_name,c.public_id AS credential_public_id,c.last_signature_at,
                    c.status AS credential_status,c.attestation_verified,c.attestation_verified_at
             FROM security_mobile_devices d
             JOIN users u ON u.id=d.user_id
             LEFT JOIN security_mobile_credentials c ON c.device_id=d.id
             ORDER BY d.registered_at DESC,d.id DESC LIMIT ' . max(1, min(250, $limit))
        );
    }

    /** @return list<array<string,mixed>> */
    public function pendingApprovals(int $limit = 100): array
    {
        return $this->db->all(
            'SELECT r.*,u.display_name AS owner_name,d.public_id AS device_public_id,
                    EXTRACT(EPOCH FROM r.expires_at)::bigint-EXTRACT(EPOCH FROM NOW())::bigint AS ttl_seconds,
                    p.policy,p.enforced
             FROM security_mobile_approval_requests r
             JOIN users u ON u.id=r.user_id
             LEFT JOIN security_mobile_devices d ON d.id=r.device_id
             LEFT JOIN security_mobile_operation_policies p ON p.action_type=r.action_type
             WHERE r.status=\'pending\' AND r.expires_at>NOW()
             ORDER BY r.expires_at,r.id LIMIT ' . max(1, min(250, $limit))
        );
    }

    /** @return list<array<string,mixed>> */
    public function pendingEnrollments(int $limit = 100): array
    {
        return $this->db->all(
            'SELECT e.*,u.display_name AS owner_name,u.email,
                    d.public_id AS device_public_id,d.display_name AS device_name,
                    EXTRACT(EPOCH FROM e.expires_at)::bigint-EXTRACT(EPOCH FROM NOW())::bigint AS ttl_seconds
             FROM security_mobile_enrollments e
             JOIN users u ON u.id=e.user_id
             LEFT JOIN security_mobile_devices d ON d.created_request_id=e.public_id
             WHERE e.status IN (\'pending\',\'completed\') AND e.expires_at>NOW()
             ORDER BY e.expires_at,e.id LIMIT ' . max(1, min(250, $limit)),
        );
    }

    /** @return list<array<string,mixed>> */
    public function recentDecisions(int $limit = 100): array
    {
        return $this->db->all(
            'SELECT r.*,u.display_name AS owner_name,d.public_id AS device_public_id,c.public_id AS credential_public_id
             FROM security_mobile_approval_requests r
             JOIN users u ON u.id=r.user_id
             LEFT JOIN security_mobile_devices d ON d.id=r.device_id
             LEFT JOIN security_mobile_credentials c ON c.id=r.credential_id
             WHERE r.approved_at IS NOT NULL OR r.rejected_at IS NOT NULL OR r.status IN (\'expired\',\'cancelled\')
             ORDER BY COALESCE(r.consumed_at,r.expires_at) DESC,r.id DESC LIMIT ' . max(1, min(250, $limit))
        );
    }

    /** @return list<array<string,mixed>> */
    public function policies(): array
    {
        $rows = $this->db->all('SELECT * FROM security_mobile_operation_policies ORDER BY application_variant,action_type');
        foreach ($rows as &$row) {
            $row['ready'] = MobileOperationReadiness::isReady((string)$row['action_type']);
            $row['readiness_description'] = MobileOperationReadiness::description((string)$row['action_type']);
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function detailsFromRow(array $row): array
    {
        $displayFields = $this->decodeJson($row['display_payload_json'] ?? null);
        $initiatingDevice = null;
        foreach ([
            Dors3UiText::get('fields.initiating_device', [], 'pl'),
            Dors3UiText::get('fields.initiating_device', [], 'en'),
            'Urządzenie inicjujące',
        ] as $initiatingDeviceKey) {
            if (isset($displayFields[$initiatingDeviceKey])) {
                $initiatingDevice = mb_substr((string)$displayFields[$initiatingDeviceKey], 0, 160);
                break;
            }
        }
        return [
            'request_id' => (string)$row['request_id'],
            'public_id' => (string)$row['public_id'],
            'purpose' => (string)$row['purpose'],
            'service' => 'Źródło Słowa',
            'environment' => (string)$row['environment'],
            'account' => trim((string)($row['login_name'] ?: $row['email'])),
            'person' => (string)$row['display_name'],
            'role' => (string)$row['role_context'],
            'organization' => (string)$row['organization_id'],
            'initiating_device' => $initiatingDevice,
            'action_type' => (string)$row['action_type'],
            'display_fields' => $displayFields,
            'challenge' => $this->cipher->decrypt((string)$row['challenge_ciphertext'], 'dors3-mobile-challenge'),
            'action_fingerprint' => $row['action_fingerprint'] !== null ? (string)$row['action_fingerprint'] : null,
            'browser_session_hash' => (string)$row['browser_session_hash'],
            'issued_at' => (int)$row['issued_at_epoch'],
            'expires_at' => (int)$row['expires_at_epoch'],
            'nonce' => $this->cipher->decrypt((string)$row['nonce_ciphertext'], 'dors3-mobile-nonce'),
            'server_origin' => (string)$row['server_origin'],
            'protocol_version' => (int)$row['protocol_version'],
            'application_variant' => (string)$row['application_variant'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function protocolRow(array $row): array
    {
        return [
            'purpose' => (string)$row['purpose'],
            'request_id' => (string)$row['request_id'],
            'challenge' => $this->cipher->decrypt((string)$row['challenge_ciphertext'], 'dors3-mobile-challenge'),
            'account' => trim((string)($row['login_name'] ?: $row['email'])),
            'organization_id' => (string)$row['organization_id'],
            'role_context' => (string)$row['role_context'],
            'server_origin' => (string)$row['server_origin'],
            'environment' => (string)$row['environment'],
            'browser_session_hash' => (string)$row['browser_session_hash'],
            'action_fingerprint' => $row['action_fingerprint'] !== null ? (string)$row['action_fingerprint'] : '',
            'issued_at_epoch' => (int)$row['issued_at_epoch'],
            'expires_at_epoch' => (int)$row['expires_at_epoch'],
            'nonce' => $this->cipher->decrypt((string)$row['nonce_ciphertext'], 'dors3-mobile-nonce'),
        ];
    }

    /** @param array<string,mixed> $device */
    private function assertDeviceActive(array $device): void
    {
        $status = (string)($device['device_status'] ?? $device['status'] ?? '');
        if ($status === 'active') {
            return;
        }
        $code = match ($status) {
            'suspended' => 'device_suspended',
            'lost' => 'device_lost',
            'revoked' => 'device_revoked',
            default => 'device_not_active',
        };
        throw new Dors3MobileException($code, 'Urządzenie nie jest aktywne.', 403);
    }

    private function assertUserEligible(int $userId, string $variant, ?int $expectedAgreementId = null): void
    {
        $user = $this->db->one('SELECT status,can_write FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if ($user === null || (string)$user['status'] !== 'active') {
            throw new Dors3MobileException('user_not_eligible', 'Konto nie jest aktywne.', 403);
        }
        $role = $variant === 'admin' ? 'admin' : 'author';
        if (!$this->hasRole($userId, $role) || ($variant === 'author' && (int)$user['can_write'] !== 1)) {
            throw new Dors3MobileException('variant_not_allowed', 'Konto nie jest uprawnione do tego wariantu aplikacji.', 403);
        }
        if ($variant === 'author') {
            (new AuthorAgreementService($this->db))->requireActive($userId, $expectedAgreementId);
        }
    }

    private function assertOperationActorEligible(int $userId, string $variant, string $actionType): void
    {
        // Uprawnienia aktora do wariantu są sprawdzane w assertUserEligible().
        // Operacje wypłat należą wyłącznie do 3DORS Admin; payout_enabled autora
        // nie może ograniczać jego pracy redakcyjnej w 3DORS Author.
    }

    /** @return array<string,mixed> */
    private function authenticateDeviceApi(
        string $devicePublicId,
        string $credentialPublicId,
        string $apiToken,
        bool $requireActive,
    ): array {
        $credential = $this->authenticateCredentialApi($credentialPublicId, $apiToken, $requireActive);
        if (!hash_equals((string)$credential['public_id'], $devicePublicId)) {
            throw new Dors3MobileException('credential_mismatch', 'Credential nie należy do wskazanego urządzenia.', 403);
        }
        return $credential;
    }

    /** @return array<string,mixed> */
    private function authenticateCredentialApi(string $credentialPublicId, string $apiToken, bool $requireActive): array
    {
        if ($credentialPublicId === '' || $apiToken === '' || strlen($apiToken) > 256) {
            throw new Dors3MobileException('device_auth_required', 'Wymagane jest uwierzytelnienie urządzenia.', 401);
        }
        $row = $this->db->one(
            'SELECT d.*,c.id AS credential_id,c.public_id AS credential_public_id,
                    c.status AS credential_status,c.api_token_hash,c.api_token_expires_at
             FROM security_mobile_credentials c
             JOIN security_mobile_devices d ON d.id=c.device_id
             WHERE c.public_id=:credential LIMIT 1',
            ['credential' => $credentialPublicId],
        );
        if (
            $row === null
            || empty($row['api_token_hash'])
            || !hash_equals((string)$row['api_token_hash'], hash('sha256', $apiToken))
        ) {
            throw new Dors3MobileException('device_auth_invalid', 'Uwierzytelnienie urządzenia jest nieprawidłowe.', 401);
        }
        if ($this->epoch((string)$row['api_token_expires_at']) <= time()) {
            throw new Dors3MobileException('device_auth_expired', 'Token urządzenia wygasł. Zarejestruj urządzenie ponownie.', 401);
        }
        $this->assertEnabled((string)$row['application_variant']);
        if ($requireActive) {
            $this->assertDeviceActive(['status' => (string)$row['status']]);
            if ((string)$row['credential_status'] !== 'active') {
                throw new Dors3MobileException('credential_not_active', 'Credential urządzenia nie jest aktywny.', 403);
            }
            $this->assertUserEligible(
                (int)$row['user_id'],
                (string)$row['application_variant'],
                $row['agreement_id'] !== null ? (int)$row['agreement_id'] : null,
            );
        }
        $this->db->query(
            'UPDATE security_mobile_credentials SET api_token_last_used_at=NOW() WHERE id=:id',
            ['id' => (int)$row['credential_id']],
        );
        return $row;
    }

    private function hasRole(int $userId, string $role): bool
    {
        return (int)$this->db->cell(
            'SELECT COUNT(*) FROM user_roles WHERE user_id=:user AND role=:role',
            ['user' => $userId, 'role' => $role]
        ) > 0;
    }

    private function assertEcPublicKey(string $base64): void
    {
        $der = base64_decode($base64, true);
        if (!is_string($der) || strlen($der) < 64 || strlen($der) > 2048) {
            throw new Dors3MobileException('invalid_public_key', 'Klucz publiczny ma nieprawidłowy format.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        $details = $key !== false ? openssl_pkey_get_details($key) : false;
        $curve = is_array($details) ? (string)($details['ec']['curve_name'] ?? '') : '';
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || !in_array($curve, ['prime256v1', 'secp256r1'], true)) {
            throw new Dors3MobileException('invalid_public_key', 'Wymagany jest klucz EC secp256r1.');
        }
    }

    /** @param array<string,mixed> $request */
    private function assertCompanionPolicySatisfied(Database $db, array $request): void
    {
        if ((string)$request['purpose'] !== 'operation') {
            return;
        }
        $policy = $db->one(
            'SELECT policy,enforced FROM security_mobile_operation_policies WHERE action_type=:action LIMIT 1',
            ['action' => (string)$request['action_type']]
        );
        if ($policy === null || (int)$policy['enforced'] !== 1) {
            return;
        }
        $policyName = (string)$policy['policy'];
        if ($policyName === 'fido2') {
            throw new Dors3MobileException('fido2_required', 'Ta operacja wymaga wyłącznie klucza USB FIDO2.', 409);
        }
        if ($policyName !== 'mobile_and_fido2') {
            return;
        }
        $authorization = $db->one(
            'SELECT id FROM security_step_up_authorizations
             WHERE user_id=:user AND action_fingerprint=:fingerprint AND method=\'fido2\'
               AND consumed_at IS NULL AND invalidated_at IS NULL AND expires_at>NOW()
             ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE',
            ['user' => (int)$request['user_id'], 'fingerprint' => (string)$request['action_fingerprint']]
        );
        if ($authorization === null) {
            throw new Dors3MobileException('fido2_required', 'Polityka wymaga także niezależnego klucza USB FIDO2.', 409);
        }
        $updated = $db->query(
            'UPDATE security_step_up_authorizations SET consumed_at=NOW() WHERE id=:id AND consumed_at IS NULL',
            ['id' => (int)$authorization['id']]
        )->rowCount();
        if ($updated !== 1) {
            throw new Dors3MobileException('fido2_replay', 'Autoryzacja FIDO2 została już zużyta.', 409);
        }
    }

    private function hitRateLimit(string $scope, string $identity, int $maximum, int $windowSeconds): void
    {
        $now = time();
        $bucketStart = $now - ($now % $windowSeconds);
        $key = hash('sha256', $scope . '|' . $identity);
        $row = $this->db->one(
            'INSERT INTO security_mobile_rate_limits(limit_key,bucket_started_at,attempt_count,expires_at)
             VALUES(:key,:bucket,1,:expires)
             ON CONFLICT(limit_key,bucket_started_at)
             DO UPDATE SET attempt_count=security_mobile_rate_limits.attempt_count+1
             RETURNING attempt_count',
            [
                'key' => $key,
                'bucket' => gmdate('Y-m-d H:i:s', $bucketStart),
                'expires' => gmdate('Y-m-d H:i:s', $bucketStart + $windowSeconds * 2),
            ]
        );
        if ((int)($row['attempt_count'] ?? 0) > $maximum) {
            throw new Dors3MobileException('rate_limited', 'Przekroczono limit żądań.', 429);
        }
    }

    private function assertEnabled(string $variant): void
    {
        if (!in_array($variant, ['admin', 'author'], true)) {
            throw new Dors3MobileException('invalid_variant', 'Nieobsługiwany wariant aplikacji 3DORS.', 422);
        }
        $mobile = $this->mobileConfig();
        $variantEnabled = $variant === 'admin' ? (bool)$mobile['admin_app_enabled'] : (bool)$mobile['author_app_enabled'];
        if (!(bool)$mobile['enabled'] || (string)$mobile['mode'] === 'disabled' || !$variantEnabled) {
            throw new Dors3MobileException('feature_disabled', '3DORS Mobile pozostaje wyłączone.', 404);
        }
    }

    private function assertOperationAllowed(string $variant, string $actionType): void
    {
        if (!MobileOperationPolicy::allows($variant, $actionType)) {
            throw new Dors3MobileException('variant_mismatch', 'Wariant aplikacji nie może zatwierdzić tej operacji.', 403);
        }
        if (!MobileOperationReadiness::isReady($actionType)) {
            throw new Dors3MobileException(
                'operation_not_ready',
                'Operacja nie ma jeszcze kompletnego i przetestowanego wykonawcy 3DORS Mobile.',
                503,
            );
        }
    }

    /** @return array<string,mixed> */
    private function mobileConfig(): array
    {
        $mobile = $this->config['mobile'] ?? null;
        if (!is_array($mobile)) {
            throw new Dors3MobileException('feature_disabled', 'Brak konfiguracji 3DORS Mobile.', 404);
        }
        return $mobile;
    }

    private function browserSessionHash(): string
    {
        $session = session_id();
        return hash('sha256', $session !== '' ? $session : 'no-browser-session|' . RequestContext::requestId());
    }

    private function environmentLabel(): string
    {
        return match (strtolower((string)env('APP_ENV', 'local'))) {
            'production', 'prod' => 'PRODUKCJA',
            'test', 'testing', 'stage', 'staging' => 'TESTOWE',
            default => 'LOKALNE',
        };
    }

    private function approvalLaunchUri(string $variant, string $requestPublicId): string
    {
        if ($this->environmentLabel() !== 'PRODUKCJA') {
            return MobileOperationPolicy::debugLaunchUri($variant, $requestPublicId);
        }

        $key = $variant === 'admin' ? 'admin_app_link_base_url' : 'author_app_link_base_url';
        $baseUrl = rtrim(trim((string)($this->mobileConfig()[$key] ?? '')), '/');
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (
            !str_starts_with($baseUrl, 'https://')
            || !is_string($host)
            || $host === ''
            || str_contains(strtolower($baseUrl), 'change_me')
            || str_contains(strtolower($baseUrl), 'przyklad-domeny')
        ) {
            throw new Dors3MobileException(
                'app_link_not_configured',
                'Produkcyjny App Link właściwego wariantu 3DORS nie jest skonfigurowany.',
                503,
            );
        }
        return $baseUrl . '/' . rawurlencode($requestPublicId);
    }

    private function epoch(string $timestamp): int
    {
        $epoch = strtotime($timestamp . ' UTC');
        return $epoch === false ? 0 : $epoch;
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @param array<string,mixed> $metadata
     */
    private function event(
        ?int $actorId,
        string $action,
        string $result,
        string $risk,
        ?string $resourceType,
        ?string $resourceId,
        ?array $before,
        ?array $after,
        array $metadata,
        ?string $credentialPublicId = null,
    ): void {
        $this->events->record(
            $actorId,
            $action,
            $result,
            $risk,
            $resourceType !== '' ? $resourceType : null,
            $resourceId !== '' ? $resourceId : null,
            $before,
            $after,
            isset($metadata['reason']) && is_string($metadata['reason']) ? $metadata['reason'] : null,
            $credentialPublicId,
            $metadata
        );
    }
}
