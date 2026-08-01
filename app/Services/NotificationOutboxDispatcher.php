<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\QueueSignalInterface;
use App\Core\Database;
use App\Core\RequestContext;
use App\Core\SlowoSnajperConfig;

final class NotificationOutboxDispatcher
{
    public const QUEUE = 'earnings.notifications';

    public function __construct(
        private readonly Database $db,
        private readonly DurableJobQueue $queue,
        private readonly QueueSignalInterface $signals,
        private readonly SlowoSnajperConfig $config,
    ) {}

    /** @return array<string,mixed> */
    public function articleSale(
        int $authorId,
        int $buyerId,
        int $articleId,
        int $purchaseId,
        int $amountMinor,
    ): array {
        return $this->enqueue(
            'article-sale:' . $purchaseId,
            $authorId,
            $buyerId,
            'article_sale_income',
            $amountMinor,
            $articleId,
            ['purchase_id' => $purchaseId],
        );
    }

    /** @return array<string,mixed> */
    public function articleSupport(
        int $authorId,
        int $readerId,
        int $articleId,
        int $paymentId,
        int $amountMinor,
    ): array {
        return $this->enqueue(
            'article-support:' . $paymentId,
            $authorId,
            $readerId,
            'article_support_income',
            $amountMinor,
            $articleId,
            ['payment_id' => $paymentId],
        );
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function enqueue(
        string $eventKey,
        int $recipientId,
        int $actorId,
        string $activityType,
        int $amountMinor,
        int $articleId,
        array $extra,
    ): array {
        if ($recipientId <= 0 || $actorId <= 0 || $articleId <= 0 || $amountMinor < 0) {
            throw new \InvalidArgumentException('Nieprawidłowe dane zdarzenia powiadomienia finansowego.');
        }
        $job = $this->queue->enqueue(
            self::QUEUE,
            'notifications.activity',
            [
                'event_key' => $eventKey,
                'recipient_user_id' => $recipientId,
                'actor_user_id' => $actorId,
                'activity_type' => $activityType,
                'amount_minor' => $amountMinor,
                'points_amount' => 0,
                'reference_type' => 'article',
                'reference_id' => $articleId,
                'request_id' => RequestContext::requestId(),
            ] + $extra,
            'outbox:' . $eventKey,
            priority: 50,
            maxAttempts: 8,
            actorUserId: $actorId,
            requestId: RequestContext::requestId(),
        );
        if ($this->config->earningsWakeOnEvent()) {
            $this->db->afterCommit(function () use ($job): void {
                $this->signals->notify(self::QUEUE, (string)$job['public_id']);
            });
        }
        return $job;
    }
}
