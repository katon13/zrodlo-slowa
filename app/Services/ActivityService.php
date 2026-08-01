<?php
namespace App\Services;

use App\Core\Database;

final class ActivityService
{
    private const ALLOWED = [
        'link_click_bonus' => 'kliknięcie w link',
        'like_bonus' => 'łapkę w górę',
        'share_bonus' => 'udostępnienie',
        'bug_report_bonus' => 'zgłoszenie błędu',
        'comment_bonus' => 'komentarz',
        'newsletter_open_reward' => 'otworzenie maila od redakcji',
    ];

    public function __construct(private readonly Database $db, private readonly TalentService $talent) {}

    public function allowedTypes(): array
    {
        return self::ALLOWED;
    }

    public function record(int $userId, string $activityType, ?string $referenceType = null, ?int $referenceId = null, string $note = ''): ?array
    {
        if (!array_key_exists($activityType, self::ALLOWED)) {
            throw new \InvalidArgumentException('Nieobsługiwany typ aktywności.');
        }

        return $this->db->transaction(function (Database $db) use ($userId, $activityType, $referenceType, $referenceId, $note): ?array {
            $eventId = $db->insert('INSERT INTO user_activity_events(user_id, activity_type, reference_type, reference_id, note, ip_hash, user_agent, created_at) VALUES(:user,:type,:ref_type,:ref_id,:note,:ip,:ua,NOW())', [
                'user' => $userId,
                'type' => $activityType,
                'ref_type' => $referenceType,
                'ref_id' => $referenceId,
                'note' => mb_substr($note, 0, 1000, 'UTF-8'),
                'ip' => $this->hashIp((string)($_SERVER['REMOTE_ADDR'] ?? '')),
                'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8'),
            ]);

            $award = $this->talent->queueAward($userId, $activityType, $referenceType ?: 'user_activity_event', $referenceId ?: $eventId);
            return $award ? ['event_id' => $eventId, 'award' => $award] : null;
        });
    }

    private function hashIp(string $ip): ?string
    {
        return $ip === '' ? null : hash('sha256', 'zrodlo-slowa:' . $ip);
    }
}
