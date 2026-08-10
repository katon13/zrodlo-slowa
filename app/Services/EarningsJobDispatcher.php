<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\QueueSignalInterface;
use App\Core\Database;
use App\Core\RequestContext;
use App\Core\SlowoSnajperConfig;

final class EarningsJobDispatcher
{
    private EarningsQueueService $earningsQueue;

    public function __construct(
        private readonly Database $db,
        DurableJobQueue $queue,
        private readonly QueueSignalInterface $signals,
        private readonly SlowoSnajperConfig $config,
        ?BusinessClock $businessClock = null,
    ) {
        $this->earningsQueue = new EarningsQueueService($queue, $businessClock);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function queueTalentAward(
        int $userId,
        string $activityType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $context = [],
    ): array {
        if (!$this->config->earningsWorkerEnabled()) {
            return [
                'queued' => false,
                'reason' => 'earnings_disabled',
                'activity_type' => $activityType,
            ];
        }

        $job = $this->earningsQueue->queueTalentAward(
            $userId,
            $activityType,
            $referenceType,
            $referenceId,
            $this->context($userId, $context),
        );
        $job['queued'] = true;
        $job['signal_scheduled'] = $this->scheduleSignal((string)$job['public_id']);
        return $job;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function queueSurveyReward(int $responseId, int $userId, array $context = []): array
    {
        if (!$this->config->earningsWorkerEnabled()) {
            return ['queued' => false, 'reason' => 'earnings_disabled'];
        }
        $job = $this->earningsQueue->queueSurveyReward(
            $responseId,
            $this->context($userId, $context),
        );
        $job['queued'] = true;
        $job['signal_scheduled'] = $this->scheduleSignal((string)$job['public_id']);
        return $job;
    }

    private function scheduleSignal(string $publicId): bool
    {
        if (!$this->config->earningsWakeOnEvent()) {
            return false;
        }
        return $this->db->afterCommit(function () use ($publicId): void {
            $this->signals->notify(EarningsQueueService::QUEUE, $publicId);
        });
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function context(int $actorId, array $context): array
    {
        $allowed = array_intersect_key($context, array_flip([
            'observed_at',
            'presence_verified',
            'interval_key',
            'visibility_state',
            'proof_verified',
            'visible_seconds',
            'progress_percent',
            'proof_type',
            'referral_party',
            'response_rule_qualified',
            'response_points_amount',
            'response_source_article_id',
            'response_published_by',
            'response_published_at',
            'bug_report_qualified',
            'bug_report_points',
        ]));
        return $allowed + [
            'actor_id' => $actorId,
            'request_id' => RequestContext::requestId(),
        ];
    }
}
