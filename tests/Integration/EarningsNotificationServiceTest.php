<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\DurableJobQueue;
use App\Services\EarningsNotificationService;

final class EarningsNotificationServiceTest extends DatabaseTestCase
{
    public function testCursorAcknowledgementAndJobOwnership(): void
    {
        $userId = $this->user('notification-owner');
        $otherUserId = $this->user('notification-other');
        $firstId = $this->notification($userId, 'share_bonus');
        $secondId = $this->notification($userId, 'share_bonus');
        $service = new EarningsNotificationService($this->database);

        $rows = $service->pendingAfter($userId, 0, 10);
        self::assertSame([$firstId, $secondId], array_map('intval', array_column($rows, 'id')));
        self::assertSame(1, $service->acknowledge($userId, [$firstId]));
        self::assertSame([$secondId], array_map(
            'intval',
            array_column($service->pendingAfter($userId, 0, 10), 'id')
        ));
        self::assertSame(0, $service->acknowledge($otherUserId, [$secondId]));

        $job = (new DurableJobQueue($this->database))->enqueue(
            'earnings.critical',
            'earnings.talent_award',
            ['user_id' => $userId, 'activity_type' => 'share_bonus'],
            'notification-owner-job-' . bin2hex(random_bytes(5)),
        );
        self::assertNotNull($service->jobForUser((string)$job['public_id'], $userId));
        self::assertNull($service->jobForUser((string)$job['public_id'], $otherUserId));
    }

    private function user(string $prefix): int
    {
        return $this->database->insert(
            'INSERT INTO users(email,password_hash,display_name,status,created_at)
             VALUES(:email,:hash,:name,\'active\',NOW())',
            [
                'email' => $prefix . '-' . bin2hex(random_bytes(5)) . '@phpunit.example',
                'hash' => password_hash('PHPUnit-Notification-2026!', PASSWORD_DEFAULT),
                'name' => $prefix,
            ]
        );
    }

    private function notification(int $userId, string $type): int
    {
        return $this->database->insert(
            'INSERT INTO activity_bonus_notifications(
                user_id,activity_type,amount_minor,points_amount,message,created_at
             ) VALUES(:user,:type,0,1,:message,NOW())',
            ['user' => $userId, 'type' => $type, 'message' => 'bonus.message.' . $type]
        );
    }
}
