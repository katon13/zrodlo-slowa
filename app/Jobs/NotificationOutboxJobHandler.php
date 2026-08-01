<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\BackgroundJobHandlerInterface;
use App\Contracts\ValkeyClientInterface;
use App\Core\Database;
use App\Services\ActivityUiHelper;

final class NotificationOutboxJobHandler implements BackgroundJobHandlerInterface
{
    private const ALLOWED_TYPES = ['article_sale_income', 'article_support_income'];

    public function __construct(
        private readonly Database $db,
        private readonly ?ValkeyClientInterface $valkey = null,
    ) {}

    public function supports(string $jobType): bool
    {
        return $jobType === 'notifications.activity';
    }

    public function handle(array $job): array
    {
        $payload = json_decode((string)$job['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        $eventKey = trim((string)($payload['event_key'] ?? ''));
        $recipientId = (int)($payload['recipient_user_id'] ?? 0);
        $activityType = (string)($payload['activity_type'] ?? '');
        $referenceId = (int)($payload['reference_id'] ?? 0);
        if (
            $eventKey === '' || strlen($eventKey) > 190 || $recipientId <= 0 || $referenceId <= 0
            || !in_array($activityType, self::ALLOWED_TYPES, true)
        ) {
            throw new NonRetryableJobException('Nieprawidłowe zdarzenie outbox powiadomienia.');
        }
        $keys = ActivityUiHelper::keysFor($activityType);
        $params = [
            'user' => $recipientId,
            'type' => $activityType,
            'amount' => max(0, (int)($payload['amount_minor'] ?? 0)),
            'points' => max(0, (int)($payload['points_amount'] ?? 0)),
            'message' => $keys['message_key'],
            'title_key' => $keys['title_key'],
            'message_key' => $keys['message_key'],
            'description_key' => $keys['description_key'],
            'reference_type' => 'article',
            'reference_id' => $referenceId,
            'event_key' => $eventKey,
        ];
        if ($this->db->isPostgres()) {
            $this->db->query(
                'INSERT INTO activity_bonus_notifications(
                    user_id,activity_type,amount_minor,points_amount,message,title_key,message_key,
                    description_key,reference_type,reference_id,source_event_key,created_at
                 ) VALUES(
                    :user,:type,:amount,:points,:message,:title_key,:message_key,
                    :description_key,:reference_type,:reference_id,:event_key,NOW()
                 ) ON CONFLICT DO NOTHING',
                $params
            );
        } else {
            $this->db->query(
                'INSERT IGNORE INTO activity_bonus_notifications(
                    user_id,activity_type,amount_minor,points_amount,message,title_key,message_key,
                    description_key,reference_type,reference_id,source_event_key,created_at
                 ) VALUES(
                    :user,:type,:amount,:points,:message,:title_key,:message_key,
                    :description_key,:reference_type,:reference_id,:event_key,NOW())',
                $params
            );
        }
        $notificationId = (int)$this->db->cell(
            'SELECT id FROM activity_bonus_notifications WHERE source_event_key=:event_key LIMIT 1',
            ['event_key' => $eventKey]
        );
        if ($notificationId <= 0) {
            throw new \RuntimeException('Nie udało się zmaterializować powiadomienia outbox.');
        }
        try {
            $this->valkey?->set($this->hintKey($recipientId), (string)$notificationId, 604800);
        } catch (\Throwable $error) {
            error_log('Nie udało się zapisać podpowiedzi powiadomienia w Valkey: ' . $error->getMessage());
        }
        return ['notification_id' => $notificationId, 'event_key' => $eventKey];
    }

    public static function hintKey(int $userId): string
    {
        return 'earnings-notification-hint:user:' . $userId;
    }
}
