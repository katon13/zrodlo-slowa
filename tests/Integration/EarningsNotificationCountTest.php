<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\EarningsNotificationService;
use App\Services\AuthService;

final class EarningsNotificationCountTest extends DatabaseTestCase
{
    public function testUnreadCountAndSingleAndAllAcknowledgementUseOneSource(): void
    {
        $service = new EarningsNotificationService($this->database);
        $userId = $this->ordinaryUserId();
        self::assertSame(0, $service->unreadCount($userId));

        $firstId = $this->notification($userId, 1);
        self::assertSame(1, $service->unreadCount($userId));

        $this->notification($userId, 2);
        $this->notification($userId, 3);
        self::assertSame(3, $service->unreadCount($userId));

        self::assertSame(1, $service->acknowledge($userId, [$firstId]));
        self::assertSame(2, $service->unreadCount($userId));

        self::assertSame(2, $service->acknowledgeAll($userId));
        self::assertSame(0, $service->unreadCount($userId));
    }

    public function testBackendReturnsExactCountAboveBadgeDisplayLimit(): void
    {
        $service = new EarningsNotificationService($this->database);
        $userId = $this->ordinaryUserId();
        for ($index = 1; $index <= 100; $index++) {
            $this->notification($userId, $index);
        }
        self::assertSame(100, $service->unreadCount($userId));
        self::assertCount(10, $service->pendingAfter($userId, 0, 10));
    }

    private function notification(int $userId, int $referenceId): int
    {
        return $this->database->insert(
            'INSERT INTO activity_bonus_notifications(
                user_id,activity_type,amount_minor,points_amount,message,
                reference_type,reference_id,created_at
             ) VALUES(:user,\'day_visit_bonus\',0,1,\'Test notification\',\'phpunit\',:reference,NOW())',
            ['user' => $userId, 'reference' => $referenceId]
        );
    }

    private function ordinaryUserId(): int
    {
        $id = (int)$this->database->cell(
            "SELECT u.id FROM users u
             WHERE u.status='active'
               AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role='admin')
             ORDER BY u.id LIMIT 1"
        );
        if ($id <= 0) {
            $created = (new AuthService($this->database))->register([
                'email' => 'notification-user-' . bin2hex(random_bytes(6)) . '@phpunit.example',
                'phone' => '',
                'password' => 'Phpunit-Notification-User-2026!',
                'display_name' => 'Użytkownik powiadomień PHPUnit',
                'role' => 'reader',
            ]);
            $id = (int)$created['id'];
        }
        self::assertGreaterThan(0, $id);
        return $id;
    }
}
