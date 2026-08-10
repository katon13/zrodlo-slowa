<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\QueueSignalInterface;
use App\Core\Database;
use App\Core\RequestContext;

final class AppReferralService
{
    public const PROMOTION_CODE = 'app_referral';
    public const ACTIVITY_TYPE = 'app_referral_bonus';
    public const REFERENCE_TYPE = 'app_referral_invitation';
    public const PRIVATE_ELIGIBILITY_REJECTION = 4091;

    private const ACTIVE_STATUSES = ['mail_queued', 'sent', 'link_opened', 'installed', 'registered'];
    private const SUCCESS_STATUSES = ['reward_queued', 'rewarded'];
    private const REGISTRATION_NONCE_TTL_SECONDS = 900;

    public function __construct(
        private readonly Database $db,
        private readonly MailService $mail,
        private readonly EarningsJobDispatcher $earnings,
        private readonly ?QueueSignalInterface $queueSignals = null,
    ) {
        if (!$this->db->isPostgres()) {
            throw new \RuntimeException(t('referral.error.postgres_only'));
        }
    }

    /** @return array<string,mixed> */
    public function createInvitation(int $inviterUserId, string $email): array
    {
        $language = public_language();
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            throw new \InvalidArgumentException(t('referral.error.invalid_email'));
        }

        $this->hitRateLimit('referral.invite.user', (string)$inviterUserId, 6, 3600);
        $this->hitRateLimit('referral.invite.ip', RequestContext::ipAddress() ?? 'unknown', 20, 3600);

        return $this->db->transaction(function (Database $db) use ($inviterUserId, $email, $language): array {
            $inviter = $db->one(
                'SELECT id,email,status,talent_enabled,wallet_enabled FROM users WHERE id=:id FOR UPDATE',
                ['id' => $inviterUserId]
            );
            if ($inviter === null || !in_array((string)$inviter['status'], ['active', 'pending_author'], true)) {
                throw new \RuntimeException(t('referral.error.active_account_required'));
            }
            if ((int)$inviter['talent_enabled'] !== 1 || (int)$inviter['wallet_enabled'] !== 1) {
                throw new \RuntimeException(t('referral.error.talent_wallet_required'));
            }
            if (hash_equals(strtolower((string)$inviter['email']), $email)) {
                throw new \InvalidArgumentException(t('referral.error.own_email'));
            }

            $this->synchronizeTerminalStates($inviterUserId);
            $promotion = $this->currentPromotion(true);
            if ($promotion === null) {
                throw new \RuntimeException(t('referral.error.promotion_inactive'));
            }

            $successful = $this->countForInviter($inviterUserId, self::SUCCESS_STATUSES);
            if ($successful >= (int)$promotion['successful_referral_limit']) {
                throw new \RuntimeException(t('referral.error.success_limit_reached'));
            }
            $active = $this->countForInviter($inviterUserId, self::ACTIVE_STATUSES);
            if ($active >= (int)$promotion['active_invitation_limit']) {
                throw new \RuntimeException(str_replace(
                    '{limit}',
                    (string)(int)$promotion['active_invitation_limit'],
                    t('referral.error.active_limit_reached'),
                ));
            }

            $emailConflict = $db->one(
                "SELECT id FROM app_referral_invitations
                 WHERE LOWER(invited_email)=:email
                   AND status IN ('mail_queued','sent','link_opened','installed','registered','reward_queued','rewarded')
                 LIMIT 1 FOR UPDATE",
                ['email' => $email]
            );
            if ($emailConflict !== null) {
                throw new \RuntimeException(
                    t('referral.error.email_ineligible'),
                    self::PRIVATE_ELIGIBILITY_REJECTION,
                );
            }

            $existingAccount = $db->one(
                "SELECT id FROM users WHERE LOWER(email)=:email AND status<>'deleted' LIMIT 1",
                ['email' => $email]
            );
            if ($existingAccount !== null) {
                throw new \RuntimeException(
                    t('referral.error.email_ineligible'),
                    self::PRIVATE_ELIGIBILITY_REJECTION,
                );
            }

            $token = $this->newToken();
            $publicId = $this->uuidV4();
            $expiresAt = gmdate('Y-m-d H:i:s', time() + ((int)$promotion['invitation_valid_days'] * 86400));
            $invitationId = $db->insert(
                'INSERT INTO app_referral_invitations(
                    public_id,promotion_id,inviter_user_id,invited_email,reward_points,token_hash,
                    status,created_ip_hash,created_user_agent_hash,expires_at,created_at,updated_at
                 ) VALUES(
                    :public_id,:promotion,:inviter,:email,:reward,:token_hash,\'mail_queued\',
                    :ip_hash,:ua_hash,:expires_at,NOW(),NOW()
                 )',
                [
                    'public_id' => $publicId,
                    'promotion' => (int)$promotion['id'],
                    'inviter' => $inviterUserId,
                    'email' => $email,
                    'reward' => (int)$promotion['reward_points'],
                    'token_hash' => $this->hashToken($token),
                    'ip_hash' => $this->hashPrivate(RequestContext::ipAddress()),
                    'ua_hash' => $this->hashPrivate((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
                    'expires_at' => $expiresAt,
                ]
            );

            $link = ApplicationUrl::absolute('/app/referral/' . rawurlencode($token));
            $points = (int)$promotion['reward_points'];
            $subject = str_replace('{points}', (string)$points, t('referral.mail.subject', $language));
            $body = strtr(t('referral.mail.body', $language), [
                '{points}' => (string)$points,
                '{expires_at}' => $expiresAt,
                '{link}' => $link,
            ]);
            $mailId = $this->mail->queue(
                null,
                $email,
                $subject,
                $body,
                5,
                'app-referral:' . $publicId,
            );
            $db->query(
                'UPDATE app_referral_invitations SET mail_queue_id=:mail,updated_at=NOW() WHERE id=:id',
                ['mail' => $mailId, 'id' => $invitationId]
            );
            $db->afterCommit(function () use ($mailId): void {
                try {
                    $this->queueSignals?->notify('email.transactional', (string)$mailId);
                } catch (\Throwable $error) {
                    error_log('Nie udało się wysłać sygnału kolejki zaproszenia: ' . $error->getMessage());
                }
            });

            return [
                'id' => $invitationId,
                'public_id' => $publicId,
                'email' => $this->maskEmail($email),
                'reward_points' => $points,
                'status' => 'mail_queued',
                'expires_at' => $expiresAt,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function userOverview(int $userId): array
    {
        $this->synchronizeTerminalStates($userId);
        $promotion = $this->currentPromotion(false);
        $rows = $this->db->all(
            'SELECT i.id,i.public_id,i.invited_email,i.reward_points,i.status,i.expires_at,
                    i.mail_sent_at,i.link_opened_at,i.installed_at,i.registered_at,
                    i.first_session_at,i.reward_queued_at,i.rewarded_at,i.created_at,
                    m.status AS mail_status,m.error AS mail_error
             FROM app_referral_invitations i
             LEFT JOIN mail_queue m ON m.id=i.mail_queue_id
             WHERE i.inviter_user_id=:user
             ORDER BY i.created_at DESC,i.id DESC LIMIT 30',
            ['user' => $userId]
        );
        foreach ($rows as &$row) {
            $row['invited_email'] = $this->maskEmail((string)$row['invited_email']);
            $row['reward_points'] = (int)$row['reward_points'];
            $row['mail_error'] = (string)$row['mail_status'] === 'dead_letter'
                ? t('referral.mail.failed')
                : null;
        }
        unset($row);

        $activeLimit = (int)($promotion['active_invitation_limit'] ?? 3);
        $successLimit = (int)($promotion['successful_referral_limit'] ?? 3);
        $activeCount = $this->countForInviter($userId, self::ACTIVE_STATUSES);
        $successCount = $this->countForInviter($userId, self::SUCCESS_STATUSES);
        $poolExhausted = $activeCount >= $activeLimit || $successCount >= $successLimit;

        return [
            'promotion' => $poolExhausted ? null : $promotion,
            'active_count' => $activeCount,
            'active_limit' => $activeLimit,
            'successful_count' => $successCount,
            'successful_limit' => $successLimit,
            'pool_exhausted' => $poolExhausted,
            'can_invite' => !$poolExhausted && $promotion !== null && $activeCount < $activeLimit,
            'invitations' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    public function openInvitation(string $token): array
    {
        $this->assertToken($token);
        $this->hitRateLimit('referral.open.ip', RequestContext::ipAddress() ?? 'unknown', 60, 3600);
        return $this->db->transaction(function (Database $db) use ($token): array {
            $invitation = $this->invitationByToken($token, true);
            $this->assertInvitationUsable($invitation);
            if (in_array((string)$invitation['status'], ['mail_queued', 'sent'], true)) {
                $db->query(
                    "UPDATE app_referral_invitations
                     SET status='link_opened',link_opened_at=COALESCE(link_opened_at,NOW()),updated_at=NOW()
                     WHERE id=:id",
                    ['id' => (int)$invitation['id']]
                );
                $invitation['status'] = 'link_opened';
            }
            return $this->publicInvitation($invitation);
        });
    }

    /** @return array<string,mixed> */
    public function recordInstallation(string $token, string $deviceId): array
    {
        $this->assertToken($token);
        $deviceHash = $this->deviceHash($deviceId);
        $this->hitRateLimit('referral.install.ip', RequestContext::ipAddress() ?? 'unknown', 30, 3600);
        $this->hitRateLimit('referral.install.token', $this->hashToken($token), 8, 3600);

        return $this->db->transaction(function (Database $db) use ($token, $deviceHash): array {
            $invitation = $this->invitationByToken($token, true);
            $this->assertInvitationUsable($invitation);
            if ($invitation['device_hash'] !== null && !hash_equals((string)$invitation['device_hash'], $deviceHash)) {
                throw new \RuntimeException(t('referral.error.assigned_to_other_device'));
            }
            $otherDevice = $db->one(
                'SELECT id FROM app_referral_invitations WHERE device_hash=:device AND id<>:id LIMIT 1',
                ['device' => $deviceHash, 'id' => (int)$invitation['id']]
            );
            if ($otherDevice !== null) {
                throw new \RuntimeException(t('referral.error.device_used_other_invitation'));
            }
            if ($invitation['installed_at'] === null) {
                $db->query(
                    "UPDATE app_referral_invitations
                     SET device_hash=:device,status='installed',
                         link_opened_at=COALESCE(link_opened_at,NOW()),installed_at=NOW(),updated_at=NOW()
                     WHERE id=:id",
                    ['device' => $deviceHash, 'id' => (int)$invitation['id']]
                );
            }
            return ['ok' => true, 'installed' => true, 'invitation_public_id' => (string)$invitation['public_id']];
        });
    }

    /** @return array<string,mixed> */
    public function createRegistrationNonce(string $token, string $deviceId): array
    {
        $this->assertToken($token);
        $deviceHash = $this->deviceHash($deviceId);
        $this->hitRateLimit('referral.registration_nonce.token', $this->hashToken($token), 8, 3600);

        return $this->db->transaction(function (Database $db) use ($token, $deviceHash): array {
            $invitation = $this->invitationByToken($token, true);
            $this->assertInvitationUsable($invitation);
            if ($invitation['installed_at'] === null || $invitation['device_hash'] === null) {
                throw new \RuntimeException(t('referral.error.installation_required'));
            }
            if (!hash_equals((string)$invitation['device_hash'], $deviceHash)) {
                throw new \RuntimeException(t('referral.error.registration_device_mismatch'));
            }
            if ((string)$invitation['status'] !== 'installed' || $invitation['invitee_user_id'] !== null) {
                throw new \RuntimeException(t('referral.error.not_waiting_for_registration'));
            }

            $nonce = $this->newToken();
            $expiresAt = gmdate('Y-m-d H:i:s', time() + self::REGISTRATION_NONCE_TTL_SECONDS);
            $db->query(
                'UPDATE app_referral_invitations
                 SET registration_nonce_hash=:nonce,registration_nonce_expires_at=:expires,
                     registration_nonce_used_at=NULL,updated_at=NOW()
                 WHERE id=:id',
                [
                    'nonce' => $this->hashRegistrationNonce($nonce),
                    'expires' => $expiresAt,
                    'id' => (int)$invitation['id'],
                ]
            );

            return [
                'ok' => true,
                'registration_nonce' => $nonce,
                'expires_at' => $expiresAt,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function registrationContext(string $nonce, bool $lock = false): array
    {
        $this->assertRegistrationNonce($nonce);
        $invitation = $this->db->one(
            'SELECT id,public_id,inviter_user_id,invitee_user_id,invited_email,status,
                    installed_at,device_hash,registration_nonce_expires_at,registration_nonce_used_at
             FROM app_referral_invitations
             WHERE registration_nonce_hash=:nonce LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            ['nonce' => $this->hashRegistrationNonce($nonce)]
        );
        if (
            $invitation === null
            || (string)$invitation['status'] !== 'installed'
            || $invitation['installed_at'] === null
            || $invitation['device_hash'] === null
            || $invitation['invitee_user_id'] !== null
            || $invitation['registration_nonce_used_at'] !== null
            || $invitation['registration_nonce_expires_at'] === null
            || (bool)$this->db->cell(
                'SELECT CAST(:expires AS timestamp without time zone) <= NOW()',
                ['expires' => (string)$invitation['registration_nonce_expires_at']]
            )
        ) {
            throw new \RuntimeException(t('referral.error.registration_session_invalid'));
        }
        return $invitation;
    }

    public function consumeRegistrationNonce(string $nonce, int $inviteeUserId, string $email): void
    {
        $this->db->transaction(function () use ($nonce, $inviteeUserId, $email): void {
            $invitation = $this->registrationContext($nonce, true);
            $email = strtolower(trim($email));
            if (!hash_equals(strtolower((string)$invitation['invited_email']), $email)) {
                throw new \RuntimeException(t('referral.error.registration_email_mismatch'));
            }
            if ((int)$invitation['inviter_user_id'] === $inviteeUserId) {
                throw new \RuntimeException(t('referral.error.own_invitation'));
            }

            $invitee = $this->db->one(
                'SELECT id,email,created_at FROM users WHERE id=:id FOR UPDATE',
                ['id' => $inviteeUserId]
            );
            if ($invitee === null || !hash_equals(strtolower((string)$invitee['email']), $email)) {
                throw new \RuntimeException(t('referral.error.new_account_not_found'));
            }
            if (strtotime((string)$invitee['created_at']) < strtotime((string)$invitation['installed_at'])) {
                throw new \RuntimeException(t('referral.error.account_must_follow_install'));
            }
            $existingAccount = $this->db->one(
                'SELECT id FROM app_referral_invitations WHERE invitee_user_id=:user AND id<>:id LIMIT 1',
                ['user' => $inviteeUserId, 'id' => (int)$invitation['id']]
            );
            if ($existingAccount !== null) {
                throw new \RuntimeException(t('referral.error.account_used_other_invitation'));
            }

            $updated = $this->db->query(
                "UPDATE app_referral_invitations
                 SET invitee_user_id=:invitee,status='registered',registered_at=NOW(),
                     registration_nonce_used_at=NOW(),updated_at=NOW()
                 WHERE id=:id AND registration_nonce_used_at IS NULL",
                ['invitee' => $inviteeUserId, 'id' => (int)$invitation['id']]
            );
            if ($updated->rowCount() !== 1) {
                throw new \RuntimeException(t('referral.error.registration_session_used'));
            }
        });
    }

    /** @return array<string,mixed> */
    public function completeFirstSession(string $token, string $deviceId, int $inviteeUserId): array
    {
        $this->assertToken($token);
        $deviceHash = $this->deviceHash($deviceId);
        $this->hitRateLimit('referral.complete.user', (string)$inviteeUserId, 8, 3600);

        return $this->db->transaction(function (Database $db) use ($token, $deviceHash, $inviteeUserId): array {
            $invitation = $this->invitationByToken($token, true);
            if (in_array((string)$invitation['status'], self::SUCCESS_STATUSES, true)) {
                if ((int)$invitation['invitee_user_id'] !== $inviteeUserId
                    || !hash_equals((string)$invitation['device_hash'], $deviceHash)) {
                    throw new \RuntimeException(t('referral.error.used_by_other_account_or_device'));
                }
                return ['ok' => true, 'completed' => true, 'duplicate' => true];
            }
            $this->assertInvitationUsable($invitation);
            if ($invitation['installed_at'] === null || $invitation['device_hash'] === null) {
                throw new \RuntimeException(t('referral.error.installation_required'));
            }
            if (!hash_equals((string)$invitation['device_hash'], $deviceHash)) {
                throw new \RuntimeException(t('referral.error.first_session_device_mismatch'));
            }
            if (
                (string)$invitation['status'] !== 'registered'
                || (int)$invitation['invitee_user_id'] !== $inviteeUserId
                || $invitation['registered_at'] === null
                || $invitation['registration_nonce_used_at'] === null
            ) {
                throw new \RuntimeException(t('referral.error.account_not_registered_from_install'));
            }
            if ((int)$invitation['inviter_user_id'] === $inviteeUserId) {
                throw new \RuntimeException(t('referral.error.own_invitation'));
            }

            $lockIds = [(int)$invitation['inviter_user_id'], $inviteeUserId];
            sort($lockIds, SORT_NUMERIC);
            $users = $db->all(
                'SELECT id,email,status,talent_enabled,wallet_enabled,created_at
                 FROM users WHERE id IN (?,?) ORDER BY id FOR UPDATE',
                $lockIds
            );
            $byId = [];
            foreach ($users as $user) {
                $byId[(int)$user['id']] = $user;
            }
            $invitee = $byId[$inviteeUserId] ?? null;
            if ($invitee === null || !in_array((string)$invitee['status'], ['active', 'pending_author'], true)) {
                throw new \RuntimeException(t('referral.error.first_session_inactive_account'));
            }
            if ((int)$invitee['talent_enabled'] !== 1 || (int)$invitee['wallet_enabled'] !== 1) {
                throw new \RuntimeException(t('referral.error.talent_wallet_required'));
            }
            if (!hash_equals(strtolower((string)$invitation['invited_email']), strtolower((string)$invitee['email']))) {
                throw new \RuntimeException(t('referral.error.registration_email_mismatch'));
            }
            if (strtotime((string)$invitee['created_at']) < strtotime((string)$invitation['installed_at'])) {
                throw new \RuntimeException(t('referral.error.account_must_follow_install'));
            }
            $existingAccount = $db->one(
                'SELECT id FROM app_referral_invitations WHERE invitee_user_id=:user AND id<>:id LIMIT 1',
                ['user' => $inviteeUserId, 'id' => (int)$invitation['id']]
            );
            if ($existingAccount !== null) {
                throw new \RuntimeException(t('referral.error.account_used_other_invitation'));
            }

            $successful = $this->countForInviter((int)$invitation['inviter_user_id'], self::SUCCESS_STATUSES);
            if ($successful >= (int)$invitation['successful_referral_limit']) {
                throw new \RuntimeException(t('referral.error.success_limit_reached'));
            }

            $db->query(
                "UPDATE app_referral_invitations
                 SET first_session_at=COALESCE(first_session_at,NOW()),updated_at=NOW()
                 WHERE id=:id",
                ['id' => (int)$invitation['id']]
            );

            $inviterJob = $this->earnings->queueTalentAward(
                (int)$invitation['inviter_user_id'],
                self::ACTIVITY_TYPE,
                self::REFERENCE_TYPE,
                (int)$invitation['id'],
            );
            if (($inviterJob['queued'] ?? false) !== true) {
                throw new \RuntimeException(t('referral.error.inviter_reward_queue_failed'));
            }
            $inviteeJob = $this->earnings->queueTalentAward(
                $inviteeUserId,
                self::ACTIVITY_TYPE,
                self::REFERENCE_TYPE,
                (int)$invitation['id'],
            );
            if (($inviteeJob['queued'] ?? false) !== true) {
                throw new \RuntimeException(t('referral.error.invitee_reward_queue_failed'));
            }

            $db->query(
                "UPDATE app_referral_invitations
                 SET status='reward_queued',inviter_reward_job_public_id=:inviter_job,
                     invitee_reward_job_public_id=:invitee_job,reward_queued_at=NOW(),updated_at=NOW()
                 WHERE id=:id",
                [
                    'inviter_job' => (string)$inviterJob['public_id'],
                    'invitee_job' => (string)$inviteeJob['public_id'],
                    'id' => (int)$invitation['id'],
                ]
            );

            return [
                'ok' => true,
                'completed' => true,
                'duplicate' => false,
                'reward_points' => (int)$invitation['reward_points'],
                'inviter_job_public_id' => (string)$inviterJob['public_id'],
                'invitee_job_public_id' => (string)$inviteeJob['public_id'],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function adminOverview(): array
    {
        $this->synchronizeTerminalStates(null);
        $promotion = $this->latestPromotion();
        $counts = [];
        foreach ($this->db->all(
            'SELECT status,COUNT(*) AS total FROM app_referral_invitations GROUP BY status ORDER BY status'
        ) as $row) {
            $counts[(string)$row['status']] = (int)$row['total'];
        }
        $recent = $this->db->all(
            'SELECT i.*,m.status AS mail_status,m.error AS mail_error,
                    inviter.email AS inviter_email,invitee.email AS invitee_email
             FROM app_referral_invitations i
             LEFT JOIN mail_queue m ON m.id=i.mail_queue_id
             JOIN users inviter ON inviter.id=i.inviter_user_id
             LEFT JOIN users invitee ON invitee.id=i.invitee_user_id
             ORDER BY i.created_at DESC,i.id DESC LIMIT 50'
        );
        return ['promotion' => $promotion, 'status_counts' => $counts, 'recent_invitations' => $recent];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function updatePromotion(int $adminId, array $input): array
    {
        $reward = (int)($input['reward_points'] ?? 0);
        $activeLimit = (int)($input['active_invitation_limit'] ?? 0);
        $successLimit = (int)($input['successful_referral_limit'] ?? 0);
        $validDays = (int)($input['invitation_valid_days'] ?? 0);
        if ($reward < 1 || $reward > 1_000_000) {
            throw new \InvalidArgumentException(t('referral.error.reward_range'));
        }
        if ($activeLimit < 1 || $activeLimit > 100 || $successLimit < 1 || $successLimit > 100) {
            throw new \InvalidArgumentException(t('referral.error.limits_range'));
        }
        if ($validDays < 1 || $validDays > 365) {
            throw new \InvalidArgumentException(t('referral.error.validity_range'));
        }
        $startsAt = $this->normalizeDateTime((string)($input['starts_at'] ?? ''));
        $endsAt = $this->normalizeDateTime((string)($input['ends_at'] ?? ''), true);
        if ($endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
            throw new \InvalidArgumentException(t('referral.error.invalid_promotion_dates'));
        }

        $this->db->query(
            'UPDATE talent_promotions
             SET reward_points=:reward,active_invitation_limit=:active_limit,
                 successful_referral_limit=:success_limit,invitation_valid_days=:valid_days,
                 is_promoted=CASE WHEN :promoted=1 THEN TRUE ELSE FALSE END,
                 starts_at=:starts_at,ends_at=:ends_at,
                 updated_by_admin_id=:admin,updated_at=NOW()
             WHERE code=:code',
            [
                'reward' => $reward,
                'active_limit' => $activeLimit,
                'success_limit' => $successLimit,
                'valid_days' => $validDays,
                'promoted' => !empty($input['is_promoted']) ? 1 : 0,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'admin' => $adminId,
                'code' => self::PROMOTION_CODE,
            ]
        );
        return $this->latestPromotion() ?? throw new \RuntimeException(t('referral.error.promotion_not_found'));
    }

    /** @return array<string,mixed>|null */
    public function currentPromotion(bool $lock = false): ?array
    {
        $row = $this->db->one(
            "SELECT * FROM talent_promotions
             WHERE code=:code AND is_promoted=TRUE AND starts_at<=NOW()
               AND (ends_at IS NULL OR ends_at>NOW())
             LIMIT 1" . ($lock ? ' FOR UPDATE' : ''),
            ['code' => self::PROMOTION_CODE]
        );
        return $row !== null ? $this->normalizePromotion($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function latestPromotion(): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM talent_promotions WHERE code=:code LIMIT 1',
            ['code' => self::PROMOTION_CODE]
        );
        return $row !== null ? $this->normalizePromotion($row) : null;
    }

    private function synchronizeTerminalStates(?int $inviterUserId): void
    {
        $where = $inviterUserId !== null ? ' AND i.inviter_user_id=:inviter' : '';
        $params = $inviterUserId !== null ? ['inviter' => $inviterUserId] : [];
        $this->db->query(
            "UPDATE app_referral_invitations i
             SET status='expired',updated_at=NOW()
             WHERE i.expires_at<=NOW()
               AND i.status IN ('mail_queued','sent','link_opened','installed','registered'){$where}",
            $params
        );
        $this->db->query(
            "UPDATE app_referral_invitations i
             SET status='mail_dead_letter',updated_at=NOW()
             FROM mail_queue m
             WHERE m.id=i.mail_queue_id AND m.status='dead_letter'
               AND i.status IN ('mail_queued','sent','link_opened'){$where}",
            $params
        );
        $this->db->query(
            "UPDATE app_referral_invitations i
             SET status='sent',mail_sent_at=COALESCE(i.mail_sent_at,m.sent_at),updated_at=NOW()
             FROM mail_queue m
             WHERE m.id=i.mail_queue_id AND m.status='sent' AND i.status='mail_queued'{$where}",
            $params
        );
    }

    /** @param list<string> $statuses */
    private function countForInviter(int $userId, array $statuses): int
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        return (int)$this->db->cell(
            "SELECT COUNT(*) FROM app_referral_invitations WHERE inviter_user_id=? AND status IN ({$placeholders})",
            [$userId, ...$statuses]
        );
    }

    /** @return array<string,mixed> */
    private function invitationByToken(string $token, bool $lock): array
    {
        $row = $this->db->one(
            'SELECT i.*,p.successful_referral_limit,p.active_invitation_limit,p.is_promoted
             FROM app_referral_invitations i
             JOIN talent_promotions p ON p.id=i.promotion_id
             WHERE i.token_hash=:token LIMIT 1' . ($lock ? ' FOR UPDATE OF i' : ''),
            ['token' => $this->hashToken($token)]
        );
        if ($row === null) {
            throw new \RuntimeException(t('referral.error.invitation_invalid'));
        }
        return $row;
    }

    /** @param array<string,mixed> $invitation */
    private function assertInvitationUsable(array $invitation): void
    {
        if (strtotime((string)$invitation['expires_at']) <= time()) {
            $this->db->query(
                "UPDATE app_referral_invitations SET status='expired',updated_at=NOW() WHERE id=:id",
                ['id' => (int)$invitation['id']]
            );
            throw new \RuntimeException(t('referral.error.invitation_expired'));
        }
        if (in_array((string)$invitation['status'], ['mail_dead_letter', 'cancelled', 'expired'], true)) {
            throw new \RuntimeException(t('referral.error.invitation_inactive'));
        }
    }

    /** @param array<string,mixed> $invitation @return array<string,mixed> */
    private function publicInvitation(array $invitation): array
    {
        return [
            'public_id' => (string)$invitation['public_id'],
            'invited_email' => $this->maskEmail((string)$invitation['invited_email']),
            'reward_points' => (int)$invitation['reward_points'],
            'status' => (string)$invitation['status'],
            'expires_at' => (string)$invitation['expires_at'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizePromotion(array $row): array
    {
        $row['reward_points'] = (int)$row['reward_points'];
        $row['active_invitation_limit'] = (int)$row['active_invitation_limit'];
        $row['successful_referral_limit'] = (int)$row['successful_referral_limit'];
        $row['invitation_valid_days'] = (int)$row['invitation_valid_days'];
        $row['is_promoted'] = (bool)$row['is_promoted'];
        return $row;
    }

    private function hitRateLimit(string $scope, string $identity, int $maximum, int $windowSeconds): void
    {
        $now = time();
        $bucketStart = $now - ($now % $windowSeconds);
        $row = $this->db->one(
            'INSERT INTO security_mobile_rate_limits(limit_key,bucket_started_at,attempt_count,expires_at)
             VALUES(:key,:bucket,1,:expires)
             ON CONFLICT(limit_key,bucket_started_at)
             DO UPDATE SET attempt_count=security_mobile_rate_limits.attempt_count+1
             RETURNING attempt_count',
            [
                'key' => hash('sha256', $scope . '|' . $identity),
                'bucket' => gmdate('Y-m-d H:i:s', $bucketStart),
                'expires' => gmdate('Y-m-d H:i:s', $bucketStart + ($windowSeconds * 2)),
            ]
        );
        if ((int)($row['attempt_count'] ?? 0) > $maximum) {
            throw new \RuntimeException(t('referral.error.rate_limit'));
        }
    }

    private function deviceHash(string $deviceId): string
    {
        $deviceId = trim($deviceId);
        if (preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $deviceId) !== 1) {
            throw new \InvalidArgumentException(t('referral.error.invalid_device_id'));
        }
        return hash_hmac('sha256', 'app-referral-device|' . $deviceId, $this->secret());
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', 'app-referral-token|' . $token, $this->secret());
    }

    private function hashRegistrationNonce(string $nonce): string
    {
        return hash_hmac('sha256', 'app-referral-registration|' . $nonce, $this->secret());
    }

    private function hashPrivate(?string $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : hash_hmac('sha256', 'app-referral-private|' . $value, $this->secret());
    }

    private function secret(): string
    {
        $secret = (string)\env('APP_KEY', '');
        if (strlen($secret) < 16) {
            $secret = (string)\env('PASSWORD_PEPPER', '');
        }
        if (strlen($secret) < 16) {
            throw new \RuntimeException(t('referral.error.application_key_missing'));
        }
        return $secret;
    }

    private function assertToken(string $token): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new \InvalidArgumentException(t('referral.error.invalid_token'));
        }
    }

    private function assertRegistrationNonce(string $nonce): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $nonce) !== 1) {
            throw new \InvalidArgumentException(t('referral.error.invalid_registration_nonce'));
        }
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
        return $visible . str_repeat('•', max(2, min(8, mb_strlen($local) - mb_strlen($visible)))) . '@' . $domain;
    }

    private function normalizeDateTime(string $value, bool $nullable = false): ?string
    {
        $value = trim($value);
        if ($value === '' && $nullable) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('Europe/Warsaw'));
        } catch (\Throwable) {
            throw new \InvalidArgumentException(t('referral.error.invalid_promotion_datetime'));
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
