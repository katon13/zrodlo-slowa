<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class EarningsNotificationService
{
    public function __construct(private readonly Database $db) {}

    public function unreadCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        return (int)$this->db->cell(
            'SELECT COUNT(*) FROM activity_bonus_notifications WHERE user_id=:user AND seen_at IS NULL',
            ['user' => $userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function pendingAfter(int $userId, int $afterId, int $limit = 5): array
    {
        $limit = max(1, min(10, $limit));
        return $this->db->all(
            'SELECT * FROM activity_bonus_notifications
             WHERE user_id=:user AND seen_at IS NULL AND id>:after_id
             ORDER BY id ASC LIMIT ' . $limit,
            ['user' => $userId, 'after_id' => max(0, $afterId)]
        );
    }

    /** @param list<int> $ids */
    public function acknowledge(int $userId, array $ids): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        if ($userId <= 0 || $ids === [] || count($ids) > 20) {
            return 0;
        }
        $params = ['user' => $userId];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = 'notification_' . $index;
            $params[$key] = $id;
            $placeholders[] = ':' . $key;
        }
        return $this->db->query(
            'UPDATE activity_bonus_notifications SET seen_at=NOW()
             WHERE user_id=:user AND seen_at IS NULL AND id IN (' . implode(',', $placeholders) . ')',
            $params
        )->rowCount();
    }

    public function acknowledgeAll(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        return $this->db->query(
            'UPDATE activity_bonus_notifications SET seen_at=NOW()
             WHERE user_id=:user AND seen_at IS NULL',
            ['user' => $userId]
        )->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function jobForUser(string $publicId, int $userId): ?array
    {
        $job = $this->db->one(
            'SELECT public_id,status,payload_json,result_json,actor_user_id,created_at,completed_at
             FROM background_jobs
             WHERE queue_name=:queue AND public_id=:public_id LIMIT 1',
            ['queue' => EarningsQueueService::QUEUE, 'public_id' => $publicId]
        );
        if ($job === null) {
            return null;
        }
        $payload = json_decode((string)$job['payload_json'], true);
        $ownerId = is_array($payload) ? (int)($payload['user_id'] ?? $payload['actor_id'] ?? 0) : 0;
        $actorId = (int)($job['actor_user_id'] ?? 0);
        return $userId > 0 && ($ownerId === $userId || $actorId === $userId) ? $job : null;
    }
}
