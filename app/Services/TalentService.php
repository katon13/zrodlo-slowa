<?php
namespace App\Services;

use App\Core\Database;

final class TalentService
{
    private BusinessClock $businessClock;

    public function __construct(
        private readonly Database $db,
        private readonly LedgerService $ledger,
        private readonly ?EarningsJobDispatcher $earningsDispatcher = null,
        ?BusinessClock $businessClock = null,
        private readonly ?FraudGuardService $fraudGuard = null,
    ) {
        $this->businessClock = $businessClock ?? BusinessClock::fromEnvironment();
    }

    public function queueAward(int $userId, string $activityType, ?string $referenceType = null, ?int $referenceId = null): array
    {
        $dispatcher = $this->earningsDispatcher ?? new EarningsJobDispatcher(
            $this->db,
            new DurableJobQueue($this->db),
            new \App\Infrastructure\Valkey\NullQueueSignal(),
            \App\Core\SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2)),
            $this->businessClock,
        );
        return $dispatcher->queueTalentAward($userId, $activityType, $referenceType, $referenceId);
    }

    /**
     * Nalicz bonus za aktywność użytkownika.
     * Etap 2: bonus może dawać punkty Talent i realne grosze w portfelu,
     * a wynik zawiera komunikat live „Zarobiłeś za...”.
     */
    /** @return array<string,mixed> */
    public function award(int $userId, string $activityType, ?string $referenceType = null, ?int $referenceId = null, array $context = []): array
    {
        return $this->db->transaction(function(Database $db) use ($userId, $activityType, $referenceType, $referenceId, $context): array {
            $operationKey = $this->operationKey($userId, $activityType, $referenceType, $referenceId, $context);
            if ($operationKey !== null) {
                $committed = $db->one(
                    'SELECT * FROM activity_reward_logs WHERE operation_key=:operation_key LIMIT 1',
                    ['operation_key' => $operationKey]
                );
                if ($committed !== null) {
                    return $this->decision($userId, $activityType, 'duplicate', [
                        'log_id' => (int)$committed['id'],
                        'transaction_id' => isset($committed['wallet_transaction_id']) ? (int)$committed['wallet_transaction_id'] : null,
                        'money_transaction_id' => isset($committed['money_wallet_transaction_id']) ? (int)$committed['money_wallet_transaction_id'] : null,
                        'points' => (int)($committed['points_amount'] ?? 0),
                        'amount_minor' => (int)($committed['amount_minor'] ?? 0),
                    ]);
                }
            }

            $user = $db->one('SELECT id,status,talent_enabled,wallet_enabled FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
            if (!$user || !in_array((string)$user['status'], ['active', 'pending_author'], true)) {
                return $this->decision($userId, $activityType, 'user_inactive');
            }

            $rule = $db->one('SELECT * FROM activity_reward_rules WHERE activity_type=:type', ['type' => $activityType]);
            if (!$rule) {
                return $this->decision($userId, $activityType, 'missing_rule');
            }
            if ((int)($rule['is_active'] ?? 0) !== 1) {
                return $this->decision($userId, $activityType, 'inactive_rule');
            }

            $points = (int)($rule['points_amount'] ?? 0);
            $amountMinor = (int)($rule['amount_minor'] ?? 0);
            if ($points <= 0 && $amountMinor <= 0) {
                return $this->decision($userId, $activityType, 'zero_value');
            }

            if ($points > 0 && (int)($user['talent_enabled'] ?? 0) !== 1) {
                return $this->decision($userId, $activityType, 'talent_disabled');
            }
            if ((int)($user['wallet_enabled'] ?? 0) !== 1) {
                return $this->decision($userId, $activityType, 'wallet_disabled');
            }

            if ($this->fraudGuard !== null) {
                $guard = $this->fraudGuard->inspectEarning($userId, $activityType, $context);
                if (($guard['allowed'] ?? false) !== true) {
                    return $this->decision($userId, $activityType, 'antifraud_hold', [
                        'risk_score' => (int)($guard['risk_score'] ?? 0),
                        'risk_status' => (string)($guard['status'] ?? 'suspect'),
                    ]);
                }
            }

            // Serializuje kontrolę limitu i duplikatu tylko dla tego portfela.
            $this->ledger->walletForUser($userId);
            $this->ledger->lockWalletsForUsers([$userId]);

            if ((int)($rule['daily_limit'] ?? 0) > 0) {
                $day = $this->businessClock->dayBoundsUtc();
                $count = (int)$db->cell('SELECT COUNT(*) FROM activity_reward_logs
                    WHERE user_id=:user AND activity_type=:type
                      AND awarded_at>=:day_start AND awarded_at<:day_end', [
                    'user' => $userId,
                    'type' => $activityType,
                    'day_start' => $day['start'],
                    'day_end' => $day['end'],
                ]);
                if ($count >= (int)$rule['daily_limit']) {
                    return $this->decision($userId, $activityType, 'daily_limit');
                }
            }

            if ($referenceType && $referenceId) {
                $exists = $db->one('SELECT id FROM activity_reward_logs WHERE user_id=:user AND activity_type=:type AND reference_type=:rt AND reference_id=:ri LIMIT 1', [
                    'user' => $userId,
                    'type' => $activityType,
                    'rt' => $referenceType,
                    'ri' => $referenceId,
                ]);
                if ($exists) {
                    return $this->decision($userId, $activityType, 'duplicate', [
                        'log_id' => (int)$exists['id'],
                    ]);
                }
            }

            $keys = ActivityUiHelper::keysFor($activityType);
            $message = $keys['message_key'];
            $transactionType = $this->walletTransactionType($activityType);
            $ledgerContext = [
                'title_key' => $keys['title_key'],
                'message_key' => $keys['message_key'],
                'description_key' => $keys['description_key'],
                'source_module' => 'system',
                'ref_type' => $referenceType,
                'ref_id' => $referenceId,
                'meta' => [
                    'activity_type' => $activityType,
                    'title_key' => $keys['title_key'],
                    'message_key' => $keys['message_key'],
                    'description_key' => $keys['description_key'],
                    'amount_minor' => $amountMinor,
                    'points' => $points,
                    'job_public_id' => $context['job_public_id'] ?? null,
                ],
            ];
            $pointsTransactionId = $points > 0
                ? $this->ledger->post($userId, $transactionType, 0, $points, $message, $ledgerContext + [
                    'account_type' => 'points',
                    'idempotency_key' => $operationKey !== null ? $operationKey . ':points' : null,
                ])
                : null;
            $moneyTransactionId = $amountMinor > 0
                ? $this->ledger->post($userId, $transactionType, $amountMinor, 0, $message, $ledgerContext + [
                    'account_type' => 'slowo',
                    'idempotency_key' => $operationKey !== null ? $operationKey . ':money' : null,
                ])
                : null;
            $transactionId = $pointsTransactionId ?? $moneyTransactionId;

            $logId = $db->insert('INSERT INTO activity_reward_logs (user_id, activity_type, reference_type, reference_id, points_amount, amount_minor, wallet_transaction_id, money_wallet_transaction_id, operation_key, live_message, title_key, message_key, description_key, awarded_at) VALUES (:user, :type, :rt, :ri, :points, :amount, :tx, :money_tx, :operation_key, :message, :title_key, :message_key, :description_key, NOW())', [
                'user' => $userId,
                'type' => $activityType,
                'rt' => $referenceType,
                'ri' => $referenceId,
                'points' => $points,
                'amount' => $amountMinor,
                'tx' => $transactionId,
                'money_tx' => $moneyTransactionId,
                'operation_key' => $operationKey,
                'message' => $message,
                'title_key' => $keys['title_key'],
                'message_key' => $keys['message_key'],
                'description_key' => $keys['description_key'],
            ]);

            $notificationId = $db->insert('INSERT INTO activity_bonus_notifications(user_id, activity_type, amount_minor, points_amount, message, title_key, message_key, description_key, reference_type, reference_id, created_at) VALUES(:user,:type,:amount,:points,:message,:title_key,:message_key,:description_key,:rt,:ri,NOW())', [
                'user' => $userId,
                'type' => $activityType,
                'amount' => $amountMinor,
                'points' => $points,
                'message' => $message,
                'title_key' => $keys['title_key'],
                'message_key' => $keys['message_key'],
                'description_key' => $keys['description_key'],
                'rt' => $referenceType,
                'ri' => $referenceId,
            ]);

            if ($referenceType === 'campaign_event' && $referenceId !== null && $referenceId > 0) {
                $db->query(
                    'UPDATE campaign_events SET is_rewarded=1,reward_minor=:amount WHERE id=:id',
                    ['amount' => $amountMinor, 'id' => $referenceId]
                );
            }

            return [
                'decision' => 'awarded',
                'reason' => 'eligible',
                'awarded' => true,
                'duplicate' => false,
                'user_id' => $userId,
                'log_id' => $logId,
                'notification_id' => $notificationId,
                'transaction_id' => $transactionId,
                'points_transaction_id' => $pointsTransactionId,
                'money_transaction_id' => $moneyTransactionId,
                'activity_type' => $activityType,
                'amount_minor' => $amountMinor,
                'points' => $points,
                'message' => $message,
            ];
        });
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    private function decision(int $userId, string $activityType, string $reason, array $details = []): array
    {
        return array_replace([
            'decision' => $reason,
            'reason' => $reason,
            'awarded' => false,
            'duplicate' => $reason === 'duplicate',
            'user_id' => $userId,
            'activity_type' => $activityType,
            'points' => 0,
            'amount_minor' => 0,
            'transaction_id' => null,
            'notification_id' => null,
        ], $details);
    }

    /** @param array<string,mixed> $context */
    private function operationKey(
        int $userId,
        string $activityType,
        ?string $referenceType,
        ?int $referenceId,
        array $context,
    ): ?string {
        $jobKey = trim((string)($context['job_idempotency_key'] ?? ''));
        if ($jobKey !== '') {
            return 'earning:' . hash('sha256', $jobKey);
        }
        if ($referenceType !== null && $referenceType !== '' && $referenceId !== null && $referenceId > 0) {
            return 'earning:' . hash('sha256', implode(':', [
                (string)$userId,
                $activityType,
                $referenceType,
                (string)$referenceId,
            ]));
        }
        return null;
    }

    public function recentNotifications(int $userId, int $limit = 10): array
    {
        return $this->db->all('SELECT * FROM activity_bonus_notifications WHERE user_id=:user ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit, ['user' => $userId]);
    }

    public function unseenNotifications(int $userId, int $limit = 5): array
    {
        return $this->db->all('SELECT * FROM activity_bonus_notifications WHERE user_id=:user AND seen_at IS NULL ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit, ['user' => $userId]);
    }

    public function markNotificationsSeen(int $userId): void
    {
        $this->db->query('UPDATE activity_bonus_notifications SET seen_at=NOW() WHERE user_id=:user AND seen_at IS NULL', ['user' => $userId]);
    }

    public function getRules(): array
    {
        return $this->db->all('SELECT * FROM activity_reward_rules ORDER BY id ASC');
    }

    public function updateRule(string $type, int $pointsAmount, int $amountMinor, int $limit, bool $active): void
    {
        if (!preg_match('/^[a-z0-9_]{1,80}$/', $type)) {
            throw new \InvalidArgumentException('Nieprawidłowy typ reguły Talentu.');
        }
        if (!$this->db->one('SELECT id FROM activity_reward_rules WHERE activity_type=:type LIMIT 1', ['type' => $type])) {
            throw new \RuntimeException('Nie znaleziono reguły Talentu: ' . $type);
        }
        if ($pointsAmount < 0 || $pointsAmount > 1000000) {
            throw new \InvalidArgumentException('Liczba punktów reguły jest poza zakresem.');
        }
        if ($amountMinor < 0 || $amountMinor > 100000000) {
            throw new \InvalidArgumentException('Kwota reguły jest poza zakresem.');
        }
        if ($limit < 0 || $limit > 100000) {
            throw new \InvalidArgumentException('Limit dzienny reguły jest poza zakresem.');
        }

        $statement = $this->db->query('UPDATE activity_reward_rules SET points_amount=:points, amount_minor=:amount, daily_limit=:l, is_active=:s, updated_at=NOW() WHERE activity_type=:t', [
            'points' => $pointsAmount,
            'amount' => $amountMinor,
            'l' => $limit,
            's' => $active ? 1 : 0,
            't' => $type,
        ]);
        if ($statement->rowCount() > 1) {
            throw new \RuntimeException('Zmieniono nieprawidłową liczbę reguł Talentu.');
        }
    }

    private function walletTransactionType(string $activityType): string
    {
        $map = [
            'registration_bonus' => 'registration_bonus',
            'login_bonus' => 'login_bonus',
            'day_visit_bonus' => 'day_visit_bonus',
            'article_read_bonus' => 'article_read_bonus',
            'comment_bonus' => 'comment_bonus',
            'poll_bonus' => 'survey_reward',
            'survey_reward' => 'survey_reward',
            'link_click_bonus' => 'link_click_bonus',
            'like_bonus' => 'like_bonus',
            'share_bonus' => 'share_bonus',
            'bug_report_bonus' => 'bug_report_bonus',
            'sponsored_article_read_bonus' => 'sponsored_article_read_bonus',
            'ad_view_reward' => 'ad_view_reward',
            'ad_click_reward' => 'ad_click_reward',
            'newsletter_open_reward' => 'newsletter_open_reward',
            'ppv_reward' => 'ppv_reward',
            'live_event_reward' => 'live_event_reward',
        ];
        return $map[$activityType] ?? 'activity_bonus';
    }
}
