<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\BackgroundJobHandlerInterface;
use App\Contracts\ValkeyClientInterface;
use App\Core\Database;
use App\Core\SlowoSnajperConfig;
use App\Services\ActivityUiHelper;
use App\Services\BusinessClock;
use App\Services\FinancialService;
use App\Services\LedgerService;
use App\Services\TalentService;
use App\Services\FraudGuardService;

final class EarningsJobHandler implements BackgroundJobHandlerInterface
{
    private SlowoSnajperConfig $config;

    public function __construct(
        private readonly Database $db,
        ?SlowoSnajperConfig $config = null,
        private readonly ?ValkeyClientInterface $valkey = null,
    )
    {
        $this->config = $config ?? SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2));
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, ['earnings.talent_award', 'earnings.survey_reward'], true);
    }

    public function handle(array $job): array
    {
        $payload = json_decode((string)$job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $result = match ((string)$job['job_type']) {
            'earnings.talent_award' => $this->talentAward($payload + [
                'job_idempotency_key' => (string)($job['idempotency_key'] ?? ''),
                'job_public_id' => (string)($job['public_id'] ?? ''),
                'job_created_at' => (string)($job['created_at'] ?? ''),
            ]),
            'earnings.survey_reward' => $this->surveyReward($payload + [
                'job_idempotency_key' => (string)($job['idempotency_key'] ?? ''),
                'job_public_id' => (string)($job['public_id'] ?? ''),
                'job_created_at' => (string)($job['created_at'] ?? ''),
            ]),
            default => throw new NonRetryableJobException('Nieobsługiwany typ naliczenia.'),
        };
        $notificationId = (int)($result['notification_id'] ?? 0);
        $userId = (int)($result['user_id'] ?? $payload['user_id'] ?? 0);
        if ($notificationId > 0 && $userId > 0) {
            try {
                $this->valkey?->set(NotificationOutboxJobHandler::hintKey($userId), (string)$notificationId, 604800);
            } catch (\Throwable $error) {
                error_log('Nie udało się zapisać podpowiedzi bonusu w Valkey: ' . $error->getMessage());
            }
        }
        return $result;
    }

    private function talentAward(array $payload): array
    {
        $userId = (int)($payload['user_id'] ?? 0);
        $activityType = (string)($payload['activity_type'] ?? '');
        if (!$this->config->earningsWorkerEnabled()) {
            return $this->decision($userId, $activityType, 'rejected', 'earnings_disabled');
        }
        if ($this->config->earningsRequiresPresence($activityType) && !$this->validPresenceProof($payload)) {
            return $this->decision($userId, $activityType, 'not_eligible', 'not_present');
        }

        return (new TalentService(
            $this->db,
            new LedgerService($this->db, new FinancialService($this->db)),
            businessClock: null,
            fraudGuard: new FraudGuardService($this->db, $this->config),
        ))->award(
            $userId,
            $activityType,
            isset($payload['reference_type']) ? (string)$payload['reference_type'] : null,
            isset($payload['reference_id']) ? (int)$payload['reference_id'] : null,
            $payload,
        );
    }

    /** @param array<string,mixed> $payload */
    private function validPresenceProof(array $payload): bool
    {
        if (
            ($payload['presence_verified'] ?? false) !== true
            || ($payload['visibility_state'] ?? null) !== 'visible'
        ) {
            return false;
        }
        try {
            $observedAt = new \DateTimeImmutable((string)($payload['observed_at'] ?? ''));
            $createdAt = new \DateTimeImmutable((string)($payload['job_created_at'] ?? ''));
        } catch (\Throwable) {
            return false;
        }
        if (abs($createdAt->getTimestamp() - $observedAt->getTimestamp()) > 30) {
            return false;
        }
        return hash_equals(
            BusinessClock::fromEnvironment()->dayKey($observedAt),
            (string)($payload['interval_key'] ?? '')
        );
    }

    /** @return array<string,mixed> */
    private function decision(int $userId, string $activityType, string $decision, string $reason): array
    {
        return [
            'decision' => $decision,
            'reason' => $reason,
            'awarded' => false,
            'duplicate' => false,
            'user_id' => $userId,
            'activity_type' => $activityType,
            'points' => 0,
            'amount_minor' => 0,
            'transaction_id' => null,
            'notification_id' => null,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function surveyReward(array $payload): array
    {
        $responseId = (int)($payload['response_id'] ?? 0);
        if ($responseId <= 0) {
            throw new NonRetryableJobException('Brak identyfikatora odpowiedzi ankietowej.');
        }
        return $this->db->transaction(function (Database $db) use ($responseId, $payload): array {
            $response = $db->one(
                'SELECT r.*,s.id AS source_survey_id
                 FROM survey_responses r
                 JOIN surveys s ON s.id=r.survey_id
                 WHERE r.id=:id FOR UPDATE',
                ['id' => $responseId]
            );
            if ($response === null) {
                throw new NonRetryableJobException('Odpowiedź ankietowa nie istnieje.');
            }
            if ((string)$response['reward_status'] === 'paid') {
                return [
                    'awarded' => true,
                    'duplicate' => true,
                    'transaction_id' => $response['wallet_transaction_id'] !== null
                        ? (int)$response['wallet_transaction_id']
                        : null,
                ];
            }
            if ((string)$response['reward_status'] !== 'pending') {
                throw new NonRetryableJobException('Nagroda ankietowa nie jest w stanie oczekującym.');
            }

            $userId = (int)$response['user_id'];
            $surveyId = (int)$response['survey_id'];
            $rewardMinor = max(0, (int)$response['reward_amount_minor']);
            $keys = ActivityUiHelper::keysFor('survey_reward');
            $ledger = new LedgerService($db, new FinancialService($db));
            $transactionId = $rewardMinor > 0
                ? $ledger->post($userId, 'survey_reward', $rewardMinor, 0, $keys['message_key'], [
                    'source_module' => 'system',
                    'account_type' => 'slowo',
                    'title_key' => $keys['title_key'],
                    'message_key' => $keys['message_key'],
                    'description_key' => $keys['description_key'],
                    'ref_type' => 'survey_response',
                    'ref_id' => $responseId,
                    'idempotency_key' => 'survey-response:' . $responseId . ':money',
                    'meta' => [
                        'survey_id' => $surveyId,
                        'survey_response_id' => $responseId,
                        'reward_amount_minor' => $rewardMinor,
                    ],
                ])
                : null;

            // The survey owns the PLN snapshot. Talent independently owns TT and
            // may legitimately decide 0 TT when its survey rule is inactive.
            $talentResult = (new TalentService(
                $db,
                $ledger,
                businessClock: null,
                fraudGuard: new FraudGuardService($db, $this->config),
            ))->award(
                $userId,
                'survey_reward',
                'survey_response',
                $responseId,
                $payload + [
                    'survey_id' => $surveyId,
                    'survey_response_id' => $responseId,
                    'survey_reward_amount_minor' => $rewardMinor,
                ],
            );
            $db->query(
                'UPDATE survey_responses SET wallet_transaction_id=:tx,reward_status=\'paid\' WHERE id=:id',
                ['tx' => $transactionId, 'id' => $responseId]
            );
            $notificationId = null;
            if ($rewardMinor > 0) {
                $notificationId = $db->insert('INSERT INTO activity_bonus_notifications(
                        user_id,activity_type,amount_minor,points_amount,message,title_key,message_key,
                        description_key,reference_type,reference_id,created_at
                     ) VALUES(:user,\'survey_reward\',:amount,0,:message,:title_key,:message_key,
                        :description_key,\'survey_response\',:response,NOW())', [
                    'user' => $userId,
                    'amount' => $rewardMinor,
                    'message' => $keys['message_key'],
                    'title_key' => $keys['title_key'],
                    'message_key' => $keys['message_key'],
                    'description_key' => $keys['description_key'],
                    'response' => $responseId,
                ]);
            }
            return [
                'awarded' => true,
                'duplicate' => false,
                'transaction_id' => $transactionId,
                'notification_id' => $notificationId ?? ($talentResult['notification_id'] ?? null),
                'user_id' => $userId,
                'amount_minor' => $rewardMinor,
                'points' => (int)($talentResult['points'] ?? 0),
                'talent_decision' => (string)($talentResult['decision'] ?? 'unknown'),
            ];
        });
    }
}
