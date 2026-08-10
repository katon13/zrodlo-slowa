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

            $isResponsePublication = $activityType === 'response_publication_bonus';
            $rule = $db->one('SELECT * FROM activity_reward_rules WHERE activity_type=:type', ['type' => $activityType]);
            if (!$rule && !$isResponsePublication) {
                return $this->decision($userId, $activityType, 'missing_rule');
            }
            if (!$isResponsePublication && (int)($rule['is_active'] ?? 0) !== 1) {
                return $this->decision($userId, $activityType, 'inactive_rule');
            }

            if ($isResponsePublication) {
                $responseSnapshot = $this->responsePublicationSnapshot($userId, $referenceType, $referenceId, $context);
                if (!$responseSnapshot['qualified']) {
                    return $this->decision($userId, $activityType, 'snapshot_ineligible');
                }
                $points = $responseSnapshot['points'];
                $amountMinor = 0;
            } else {
                $points = $activityType === AppReferralService::ACTIVITY_TYPE
                    ? $this->appReferralSnapshotPoints($userId, $referenceType, $referenceId, $context)
                    : (int)($rule['points_amount'] ?? 0);
                $amountMinor = in_array($activityType, [AppReferralService::ACTIVITY_TYPE, 'survey_reward'], true)
                    ? 0
                    : (int)($rule['amount_minor'] ?? 0);
            }
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

            $dailyLimit = ($isResponsePublication || $activityType === 'survey_reward')
                ? 0
                : (int)($rule['daily_limit'] ?? 0);
            if ($dailyLimit > 0) {
                $day = $this->businessClock->dayBoundsUtc();
                $count = (int)$db->cell('SELECT COUNT(*) FROM activity_reward_logs
                    WHERE user_id=:user AND activity_type=:type
                      AND awarded_at>=:day_start AND awarded_at<:day_end', [
                    'user' => $userId,
                    'type' => $activityType,
                    'day_start' => $day['start'],
                    'day_end' => $day['end'],
                ]);
                if ($count >= $dailyLimit) {
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

            if ($activityType === AppReferralService::ACTIVITY_TYPE
                && $referenceType === AppReferralService::REFERENCE_TYPE
                && $referenceId !== null
            ) {
                $awardedParties = (int)$db->cell(
                    'SELECT COUNT(DISTINCT user_id) FROM activity_reward_logs
                     WHERE activity_type=:type AND reference_type=:reference_type AND reference_id=:reference_id',
                    [
                        'type' => AppReferralService::ACTIVITY_TYPE,
                        'reference_type' => AppReferralService::REFERENCE_TYPE,
                        'reference_id' => $referenceId,
                    ]
                );
                if ($awardedParties >= 2) {
                    $db->query(
                        "UPDATE app_referral_invitations
                         SET status='rewarded',rewarded_at=COALESCE(rewarded_at,NOW()),updated_at=NOW()
                         WHERE id=:id AND status='reward_queued'",
                        ['id' => $referenceId]
                    );
                }
            }

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

    /** @param array<string,mixed> $context */
    private function appReferralSnapshotPoints(
        int $userId,
        ?string $referenceType,
        ?int $referenceId,
        array $context,
    ): int {
        if ($referenceType !== AppReferralService::REFERENCE_TYPE || $referenceId === null || $referenceId <= 0) {
            throw new \RuntimeException('Bonus polecenia wymaga referencji do zaproszenia.');
        }
        $invitation = $this->db->one(
            'SELECT inviter_user_id,invitee_user_id,reward_points,status,
                    inviter_reward_job_public_id,invitee_reward_job_public_id
             FROM app_referral_invitations WHERE id=:id FOR UPDATE',
            ['id' => $referenceId]
        );
        if ($invitation === null || !in_array((string)$invitation['status'], ['reward_queued', 'rewarded'], true)) {
            throw new \RuntimeException('Zaproszenie nie jest gotowe do naliczenia nagrody.');
        }
        $expectedJobId = null;
        if ((int)$invitation['inviter_user_id'] === $userId) {
            $expectedJobId = (string)$invitation['inviter_reward_job_public_id'];
        } elseif ((int)$invitation['invitee_user_id'] === $userId) {
            $expectedJobId = (string)$invitation['invitee_reward_job_public_id'];
        }
        $jobId = (string)($context['job_public_id'] ?? '');
        if ($expectedJobId === null || $expectedJobId === '' || $jobId === '' || !hash_equals($expectedJobId, $jobId)) {
            throw new \RuntimeException('Zadanie nagrody nie odpowiada stronie zapisanej w zaproszeniu.');
        }
        $points = (int)$invitation['reward_points'];
        if ($points <= 0 || $points > 1_000_000) {
            throw new \RuntimeException('Zaproszenie zawiera nieprawidłową wartość nagrody.');
        }
        return $points;
    }

    /**
     * Wartość i kwalifikacja tej nagrody pochodzą wyłącznie ze snapshotu utworzonego
     * atomowo przy pierwszej publikacji. Bieżąca konfiguracja administratora nie jest
     * źródłem prawdy dla już opublikowanej polemiki.
     *
     * @param array<string,mixed> $context
     * @return array{qualified:bool,points:int}
     */
    private function responsePublicationSnapshot(
        int $userId,
        ?string $referenceType,
        ?int $referenceId,
        array $context,
    ): array {
        if ($referenceType !== 'response_publication' || $referenceId === null || $referenceId <= 0) {
            throw new \RuntimeException('Nagroda za polemikę wymaga referencji do opublikowanej odpowiedzi.');
        }
        if (!array_key_exists('response_rule_qualified', $context) || !array_key_exists('response_points_amount', $context)) {
            throw new \RuntimeException('Zadanie polemiki nie zawiera snapshotu kwalifikacji i TT.');
        }

        $article = $this->db->one(
            'SELECT id,author_id,status,response_to_article_id,response_reward_qualified,
                    response_reward_points,response_reward_job_public_id
             FROM articles WHERE id=:id FOR UPDATE',
            ['id' => $referenceId]
        );
        if (
            $article === null
            || (int)$article['author_id'] !== $userId
            || (string)$article['status'] !== 'published'
            || empty($article['response_to_article_id'])
        ) {
            throw new \RuntimeException('Odpowiedź nie spełnia zapisanego kontraktu publikacji.');
        }

        $qualified = ($context['response_rule_qualified'] ?? null) === true;
        $points = (int)($context['response_points_amount'] ?? -1);
        $jobPublicId = trim((string)($context['job_public_id'] ?? ''));
        $storedJobPublicId = trim((string)($article['response_reward_job_public_id'] ?? ''));
        $storedQualified = (bool)($article['response_reward_qualified'] ?? false);
        $storedPoints = (int)($article['response_reward_points'] ?? -1);

        if (
            $jobPublicId === ''
            || $storedJobPublicId === ''
            || !hash_equals($storedJobPublicId, $jobPublicId)
            || $storedQualified !== $qualified
            || $storedPoints !== $points
            || $points < 0
            || $points > 1_000_000
        ) {
            throw new \RuntimeException('Snapshot zadania polemiki nie odpowiada snapshotowi publikacji.');
        }
        if (($qualified && $points <= 0) || (!$qualified && $points !== 0)) {
            throw new \RuntimeException('Snapshot nagrody za polemikę ma nieprawidłową wartość.');
        }

        return ['qualified' => $qualified, 'points' => $points];
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

    public function updateRule(string $type, int $pointsAmount, int $amountMinor, int $limit, bool $active, int $submissionDepositPoints = 0): void
    {
        if (!preg_match('/^[a-z0-9_]{1,80}$/', $type)) {
            throw new \InvalidArgumentException('Nieprawidłowy typ reguły Talentu.');
        }
        if ($type === AppReferralService::ACTIVITY_TYPE) {
            throw new \RuntimeException('Bonus polecenia jest kontrolowany wyłącznie przez ustawienia promocji.');
        }
        $verifiedActivationTypes = [
            'registration_bonus',
            'day_visit_bonus',
            'article_read_bonus',
            'response_publication_bonus',
            'survey_reward',
        ];
        if ($active && !in_array($type, $verifiedActivationTypes, true)) {
            throw new \RuntimeException('Ta reguła nie ma jeszcze wiarygodnego punktu wyzwolenia i nie może zostać aktywowana.');
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
        if ($submissionDepositPoints < 0 || $submissionDepositPoints > 1000000) {
            throw new \InvalidArgumentException('Kaucja za wysłanie polemiki jest poza zakresem.');
        }
        if ($type === 'response_publication_bonus') {
            if ($amountMinor !== 0 || $limit !== 0) {
                throw new \InvalidArgumentException('Opublikowana odpowiedź może przyznawać wyłącznie TT i nie ma limitu dziennego.');
            }
            $amountMinor = 0;
            $limit = 0;
        } else {
            $submissionDepositPoints = 0;
        }
        if ($type === 'survey_reward') {
            if ($amountMinor !== 0 || $limit !== 0) {
                throw new \InvalidArgumentException('Reguła ankiety może określać wyłącznie TT. Kwotę PLN i limit odpowiedzi kontroluje konkretna ankieta.');
            }
            $amountMinor = 0;
            $limit = 0;
        }

        $statement = $this->db->query('UPDATE activity_reward_rules SET points_amount=:points, submission_deposit_points=:deposit, amount_minor=:amount, daily_limit=:l, is_active=:s, updated_at=NOW() WHERE activity_type=:t', [
            'points' => $pointsAmount,
            'deposit' => $submissionDepositPoints,
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
            'response_publication_bonus' => 'response_publication_bonus',
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
            'app_referral_bonus' => 'app_referral_bonus',
        ];
        return $map[$activityType] ?? 'activity_bonus';
    }
}
