<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\RequestContext;
use App\Security\Dors3\SecurityId;

/**
 * Ograniczone recovery WWW. Autoryzacja z tej klasy nigdy nie jest sesją administratora.
 */
final class AdminWebRecoveryService
{
    private const OPERATION = 'admin.security.recovery';
    private const CAPABILITY_TTL_SECONDS = 900;

    public function __construct(
        private readonly Database $db,
        private readonly RecoveryCodeService $codes,
        private readonly SecurityEventService $events,
        private readonly AuthSecurityService $authSecurity,
        private readonly MailService $mail,
    ) {}

    /** @return array{capability_public_id:string,admin_id:int,display_name:string,expires_at:string} */
    public function begin(string $identifier, string $password, string $recoveryCode, string $sessionBinding): array
    {
        $identifier = strtolower(mb_substr(trim($identifier), 0, 255));
        $sessionBinding = $this->assertSessionBinding($sessionBinding);
        $this->assertAttemptLimit(null);
        $this->authSecurity->assertLoginAllowed($identifier);

        $user = (new AuthService($this->db))->attempt($identifier, $password);
        $adminId = is_array($user) ? (int)($user['id'] ?? 0) : 0;
        $roles = is_array($user) && is_array($user['roles'] ?? null) ? $user['roles'] : [];
        if ($adminId <= 0 || !in_array('admin', $roles, true)) {
            $this->authSecurity->recordLoginEvent($adminId > 0 ? $adminId : null, $identifier, 'password_failed');
            $this->recordFailure($adminId > 0 ? $adminId : null, 'invalid_primary_credentials');
            throw new \RuntimeException(Dors3UiText::get('recovery_web.invalid_credentials'));
        }
        $this->assertAttemptLimit($adminId);

        $capabilityPublicId = SecurityId::uuid();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::CAPABILITY_TTL_SECONDS);
        $mobileReset = [];
        $webauthnRevoked = 0;
        $webauthnChallengesInvalidated = 0;
        $sessionsEnded = 0;
        $stepUpsInvalidated = 0;

        try {
            $this->codes->consumeForRecovery(
                $adminId,
                $recoveryCode,
                function (Database $db, array $usedCode) use (
                    $adminId,
                    $sessionBinding,
                    $capabilityPublicId,
                    $expiresAt,
                    &$mobileReset,
                    &$webauthnRevoked,
                    &$webauthnChallengesInvalidated,
                    &$sessionsEnded,
                    &$stepUpsInvalidated,
                ): void {
                    $reason = 'limited_web_recovery:' . $capabilityPublicId;
                    $webauthnRevoked = $db->query(
                        'UPDATE webauthn_credentials
                         SET status=\'revoked\',revoked_at=NOW(),revoked_by=:admin,
                             revocation_reason=:reason,updated_at=NOW()
                         WHERE user_id=:admin AND status<>\'revoked\'',
                        ['admin' => $adminId, 'reason' => $reason]
                    )->rowCount();
                    $webauthnChallengesInvalidated = $db->query(
                        'UPDATE webauthn_challenges
                         SET used_at=NOW()
                         WHERE user_id=:admin AND used_at IS NULL',
                        ['admin' => $adminId]
                    )->rowCount();
                    $sessionsEnded = $db->query(
                        'DELETE FROM sessions WHERE user_id=:admin',
                        ['admin' => $adminId]
                    )->rowCount();
                    $mobileReset = (new AdminMobileSecurityResetService($db))->revokeAll($adminId, $reason);
                    $stepUpsInvalidated = $db->query(
                        'UPDATE security_step_up_authorizations
                         SET invalidated_at=NOW()
                         WHERE user_id=:admin AND consumed_at IS NULL AND invalidated_at IS NULL',
                        ['admin' => $adminId]
                    )->rowCount();
                    $db->query(
                        'UPDATE users
                         SET two_factor_enabled=0,two_factor_secret=NULL,force_2fa_setup=1,
                             session_version=session_version+1,updated_at=NOW()
                         WHERE id=:admin',
                        ['admin' => $adminId]
                    );

                    $context = [
                        'scope' => 'security_replacement_only',
                        'session_binding' => $sessionBinding,
                        'used_recovery_code_public_id' => (string)$usedCode['public_id'],
                        'mobile_admin_reset' => $mobileReset,
                        'webauthn_credentials_revoked' => $webauthnRevoked,
                        'webauthn_challenges_invalidated' => $webauthnChallengesInvalidated,
                        'sessions_ended' => $sessionsEnded,
                        'step_up_authorizations_invalidated' => $stepUpsInvalidated,
                    ];
                    $contextJson = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    $db->query(
                        'INSERT INTO security_step_up_authorizations(
                            public_id,user_id,operation,action_fingerprint,method,context,
                            request_id,expires_at,created_at
                         ) VALUES(
                            :public_id,:user_id,:operation,:fingerprint,\'recovery\',CAST(:context AS jsonb),
                            :request_id,:expires_at,NOW()
                         )',
                        [
                            'public_id' => $capabilityPublicId,
                            'user_id' => $adminId,
                            'operation' => self::OPERATION,
                            'fingerprint' => hash('sha256', self::OPERATION . '|' . $adminId . '|' . $capabilityPublicId . '|' . $expiresAt),
                            'context' => $contextJson,
                            'request_id' => RequestContext::requestId(),
                            'expires_at' => $expiresAt,
                        ]
                    );
                    $this->events->record(
                        $adminId,
                        'security.recovery.web.started',
                        'success',
                        'critical',
                        'user',
                        (string)$adminId,
                        null,
                        ['scope' => 'security_replacement_only'],
                        null,
                        null,
                        [
                            'capability_public_id' => $capabilityPublicId,
                            'mobile_admin_reset' => $mobileReset,
                            'webauthn_credentials_revoked' => $webauthnRevoked,
                            'webauthn_challenges_invalidated' => $webauthnChallengesInvalidated,
                            'sessions_ended' => $sessionsEnded,
                            'step_up_authorizations_invalidated' => $stepUpsInvalidated,
                            'ordinary_admin_session_created' => false,
                        ]
                    );
                }
            );
        } catch (\Throwable $error) {
            $this->recordFailure($adminId, 'recovery_code_rejected', ['error_type' => $error::class]);
            throw new \RuntimeException(Dors3UiText::get('recovery_web.invalid_credentials'), 0, $error);
        }

        $this->queueNotification(
            $adminId,
            (string)$user['email'],
            Dors3UiText::get('recovery_web.mail_started_subject'),
            Dors3UiText::get('recovery_web.mail_started_body'),
            'admin-web-recovery-start:' . $capabilityPublicId,
        );

        return [
            'capability_public_id' => $capabilityPublicId,
            'admin_id' => $adminId,
            'display_name' => (string)($user['display_name'] ?? $user['login_name'] ?? $user['email']),
            'expires_at' => $expiresAt,
        ];
    }

    /** @return array<string,mixed> */
    public function state(string $capabilityPublicId, string $sessionBinding): array
    {
        $capability = $this->capability($capabilityPublicId, $sessionBinding);
        $adminId = (int)$capability['user_id'];
        return [
            'capability' => $capability,
            'admin' => $this->db->one(
                'SELECT id,email,login_name,display_name FROM users WHERE id=:id LIMIT 1',
                ['id' => $adminId]
            ),
            'devices' => $this->db->all(
                'SELECT d.public_id,d.display_name,d.platform,d.app_version,d.status,d.registered_at,d.activated_at,
                        c.public_id AS credential_public_id,c.status AS credential_status
                 FROM security_mobile_devices d
                 LEFT JOIN security_mobile_credentials c ON c.device_id=d.id
                 WHERE d.user_id=:admin AND d.application_variant=\'admin\'
                 ORDER BY d.registered_at DESC,d.id DESC LIMIT 25',
                ['admin' => $adminId]
            ),
            'pending_enrollments' => $this->db->all(
                'SELECT e.public_id,e.status,e.expires_at,d.display_name AS device_name,d.public_id AS device_public_id
                 FROM security_mobile_enrollments e
                 LEFT JOIN security_mobile_devices d ON d.created_request_id=e.public_id
                 WHERE e.user_id=:admin AND e.application_variant=\'admin\'
                   AND e.status IN (\'pending\',\'completed\') AND e.expires_at>NOW()
                 ORDER BY e.created_at DESC,e.id DESC',
                ['admin' => $adminId]
            ),
            'recovery_codes' => $this->codes->status($adminId),
        ];
    }

    /** @return array{batch_public_id:string,codes:list<string>} */
    public function generateRecoveryCodes(string $capabilityPublicId, string $sessionBinding): array
    {
        $capability = $this->capability($capabilityPublicId, $sessionBinding);
        return $this->codes->generate((int)$capability['user_id']);
    }

    public function confirmRecoveryCodes(string $capabilityPublicId, string $sessionBinding, string $batchPublicId): void
    {
        $capability = $this->capability($capabilityPublicId, $sessionBinding);
        $this->codes->confirmSaved((int)$capability['user_id'], $batchPublicId);
    }

    /** @return array{admin_id:int,email:string} */
    public function finish(string $capabilityPublicId, string $sessionBinding): array
    {
        $result = $this->db->transaction(function (Database $db) use ($capabilityPublicId, $sessionBinding): array {
            $capability = $this->capability($capabilityPublicId, $sessionBinding, true);
            $adminId = (int)$capability['user_id'];
            $codes = $this->codes->status($adminId);
            if ((int)$codes['active'] !== 10 || (int)$codes['confirmed'] !== 10) {
                throw new \RuntimeException(Dors3UiText::get('recovery_web.finish_requires_codes'));
            }
            $activeDevices = (int)$db->cell(
                'SELECT COUNT(DISTINCT d.id)
                 FROM security_mobile_devices d
                 JOIN security_mobile_credentials c ON c.device_id=d.id
                 WHERE d.user_id=:admin AND d.application_variant=\'admin\' AND d.status=\'active\'
                   AND c.status=\'active\' AND c.api_token_hash IS NOT NULL AND c.api_token_expires_at>NOW()',
                ['admin' => $adminId]
            );
            if ($activeDevices < 1) {
                throw new \RuntimeException(Dors3UiText::get('recovery_web.finish_requires_device'));
            }
            $updated = $db->query(
                'UPDATE security_step_up_authorizations
                 SET consumed_at=NOW()
                 WHERE id=:id AND consumed_at IS NULL AND invalidated_at IS NULL AND expires_at>NOW()',
                ['id' => (int)$capability['id']]
            )->rowCount();
            if ($updated !== 1) {
                throw new \RuntimeException(Dors3UiText::get('recovery_web.expired'));
            }
            $db->query('DELETE FROM sessions WHERE user_id=:admin', ['admin' => $adminId]);
            $db->query(
                'UPDATE users SET session_version=session_version+1,updated_at=NOW() WHERE id=:admin',
                ['admin' => $adminId]
            );
            $db->query(
                'UPDATE security_step_up_authorizations
                 SET invalidated_at=NOW()
                 WHERE user_id=:admin AND id<>:current AND consumed_at IS NULL AND invalidated_at IS NULL',
                ['admin' => $adminId, 'current' => (int)$capability['id']]
            );
            $this->events->record(
                $adminId,
                'security.recovery.web.completed',
                'success',
                'critical',
                'user',
                (string)$adminId,
                null,
                ['active_admin_devices' => $activeDevices, 'confirmed_recovery_codes' => 10],
                null,
                null,
                ['ordinary_admin_session_created' => false]
            );
            $admin = $db->one('SELECT email FROM users WHERE id=:admin LIMIT 1', ['admin' => $adminId]);
            return ['admin_id' => $adminId, 'email' => (string)($admin['email'] ?? '')];
        });

        $this->queueNotification(
            $result['admin_id'],
            $result['email'],
            Dors3UiText::get('recovery_web.mail_finished_subject'),
            Dors3UiText::get('recovery_web.mail_finished_body'),
            'admin-web-recovery-finish:' . $capabilityPublicId,
        );
        return $result;
    }

    /** @return array<string,mixed> */
    public function assertCapability(string $capabilityPublicId, string $sessionBinding): array
    {
        return $this->capability($capabilityPublicId, $sessionBinding);
    }

    public function assertOwnAdminEnrollment(
        string $capabilityPublicId,
        string $sessionBinding,
        string $enrollmentPublicId,
    ): void {
        $capability = $this->capability($capabilityPublicId, $sessionBinding);
        $owned = (int)$this->db->cell(
            'SELECT COUNT(*) FROM security_mobile_enrollments
             WHERE public_id=:enrollment AND user_id=:admin AND created_by=:admin
               AND application_variant=\'admin\' AND status IN (\'pending\',\'completed\')',
            ['enrollment' => trim($enrollmentPublicId), 'admin' => (int)$capability['user_id']]
        );
        if ($owned !== 1) {
            throw new \RuntimeException(Dors3UiText::get('recovery_web.operation_failed'));
        }
    }

    /** @return array<string,mixed> */
    private function capability(string $capabilityPublicId, string $sessionBinding, bool $forUpdate = false): array
    {
        $capabilityPublicId = trim($capabilityPublicId);
        $sessionBinding = $this->assertSessionBinding($sessionBinding);
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $row = $this->db->one(
            'SELECT a.* FROM security_step_up_authorizations a
             JOIN users u ON u.id=a.user_id
             WHERE a.public_id=:public_id AND a.operation=:operation AND a.method=\'recovery\'
               AND a.consumed_at IS NULL AND a.invalidated_at IS NULL AND a.expires_at>NOW()
               AND u.status=\'active\'
               AND EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role=\'admin\')
             LIMIT 1' . $suffix,
            ['public_id' => $capabilityPublicId, 'operation' => self::OPERATION]
        );
        if ($row === null) {
            throw new \RuntimeException(Dors3UiText::get('recovery_web.expired'));
        }
        $context = is_array($row['context'] ?? null)
            ? $row['context']
            : json_decode((string)($row['context'] ?? ''), true);
        if (!is_array($context)
            || (string)($context['scope'] ?? '') !== 'security_replacement_only'
            || !hash_equals((string)($context['session_binding'] ?? ''), $sessionBinding)
        ) {
            throw new \RuntimeException(Dors3UiText::get('recovery_web.expired'));
        }
        $row['context'] = $context;
        return $row;
    }

    private function assertAttemptLimit(?int $adminId): void
    {
        $ip = RequestContext::ipAddress();
        $conditions = [];
        $params = [];
        if ($adminId !== null && $adminId > 0) {
            $conditions[] = 'actor_id=:actor';
            $params['actor'] = $adminId;
        }
        if ($ip !== null) {
            $conditions[] = 'ip=:ip';
            $params['ip'] = $ip;
        }
        if ($conditions === []) {
            return;
        }
        $failures = (int)$this->db->cell(
            'SELECT COUNT(*) FROM security_events
             WHERE action=\'security.recovery.web.failed\' AND occurred_at>=' . $this->db->nowMinus(15, 'minute') . '
               AND (' . implode(' OR ', $conditions) . ')',
            $params
        );
        if ($failures >= ($adminId !== null ? 5 : 20)) {
            throw new \RuntimeException(Dors3UiText::get('recovery_web.rate_limited'));
        }
    }

    /** @param array<string,mixed> $metadata */
    private function recordFailure(?int $adminId, string $reason, array $metadata = []): void
    {
        try {
            $this->events->record(
                $adminId,
                'security.recovery.web.failed',
                'failure',
                'critical',
                'user',
                $adminId !== null ? (string)$adminId : null,
                null,
                null,
                $reason,
                null,
                $metadata
            );
        } catch (\Throwable $error) {
            error_log('Nie udało się zapisać nieudanej próby recovery WWW: ' . $error->getMessage());
        }
    }

    private function queueNotification(int $adminId, string $email, string $subject, string $body, string $idempotencyKey): void
    {
        try {
            $this->mail->queue($adminId, $email, $subject, $body, 5, $idempotencyKey);
        } catch (\Throwable $error) {
            error_log('Nie udało się zakolejkować powiadomienia recovery WWW: ' . $error->getMessage());
            try {
                $this->events->record(
                    $adminId,
                    'security.recovery.web.notification_failed',
                    'warning',
                    'high',
                    'user',
                    (string)$adminId,
                    null,
                    null,
                    'mail_queue_unavailable',
                    null,
                    ['error_type' => $error::class]
                );
            } catch (\Throwable) {
            }
        }
    }

    private function assertSessionBinding(string $sessionBinding): string
    {
        $sessionBinding = trim($sessionBinding);
        if (preg_match('/^[a-f0-9]{64}$/D', $sessionBinding) !== 1) {
            throw new \RuntimeException(Dors3UiText::get('recovery_web.expired'));
        }
        return $sessionBinding;
    }
}
