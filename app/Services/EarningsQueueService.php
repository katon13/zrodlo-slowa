<?php
declare(strict_types=1);

namespace App\Services;

final class EarningsQueueService
{
    public const QUEUE = 'earnings.critical';

    private BusinessClock $businessClock;

    public function __construct(
        private readonly DurableJobQueue $queue,
        ?BusinessClock $businessClock = null,
    ) {
        $this->businessClock = $businessClock ?? BusinessClock::fromEnvironment();
    }

    /** @return array<string,mixed> */
    public function queueTalentAward(
        int $userId,
        string $activityType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $context = [],
    ): array {
        if ($userId <= 0 || preg_match('/^[a-z0-9_]{1,80}$/D', $activityType) !== 1) {
            throw new \InvalidArgumentException('Nieprawidłowe dane naliczenia aktywności.');
        }
        $reference = $referenceType !== null && $referenceId !== null && $referenceId > 0
            ? $referenceType . ':' . $referenceId
            : (($context['presence_verified'] ?? false) === true ? 'presence-day:' : 'day:')
                . $this->businessClock->dayKey();
        return $this->queue->enqueue(
            self::QUEUE,
            'earnings.talent_award',
            [
                'user_id' => $userId,
                'activity_type' => $activityType,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ] + $context,
            "talent:{$userId}:{$activityType}:{$reference}",
            priority: 100,
            maxAttempts: 8,
            allowPayloadMismatchOnDuplicate: true,
        );
    }

    /** @return array<string,mixed> */
    public function queueSurveyReward(int $responseId, array $context = []): array
    {
        if ($responseId <= 0) {
            throw new \InvalidArgumentException('Nieprawidłowy identyfikator odpowiedzi ankietowej.');
        }
        return $this->queue->enqueue(
            self::QUEUE,
            'earnings.survey_reward',
            ['response_id' => $responseId] + $context,
            'survey-response:' . $responseId,
            priority: 110,
            maxAttempts: 8,
            allowPayloadMismatchOnDuplicate: true,
        );
    }
}
