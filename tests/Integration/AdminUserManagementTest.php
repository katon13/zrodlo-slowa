<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\RoleService;
use App\Services\UserService;
use App\Services\AuthService;

final class AdminUserManagementTest extends DatabaseTestCase
{
    public function testChangingAccountTypePreservesEditorialRoles(): void
    {
        $userId = $this->testUserId();
        $this->database->query('DELETE FROM user_roles WHERE user_id=:id', ['id' => $userId]);
        foreach (['reader', 'moderator', 'proofreader'] as $role) {
            $this->database->query(
                'INSERT INTO user_roles(user_id, role) VALUES(:id, :role)',
                ['id' => $userId, 'role' => $role]
            );
        }

        (new UserService($this->database))->setPrimaryRole($userId, 'author');

        $roles = $this->database->all(
            'SELECT role FROM user_roles WHERE user_id=:id ORDER BY role',
            ['id' => $userId]
        );
        self::assertSame(
            ['author', 'moderator', 'proofreader'],
            array_column($roles, 'role')
        );
    }

    public function testDeletedStatusCannotBypassAnonymizationWorkflow(): void
    {
        $userId = $this->testUserId();
        $service = new UserService($this->database);

        $this->expectException(\InvalidArgumentException::class);
        $service->setStatus($userId, 'deleted');
    }

    public function testChangingStatusOfMissingUserFailsClearly(): void
    {
        $service = new UserService($this->database);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nie znaleziono użytkownika');
        $service->setStatus(PHP_INT_MAX, 'active');
    }

    public function testEditorialRolesRejectDeletedAndMissingAccounts(): void
    {
        $userId = $this->testUserId();
        $this->database->query('UPDATE users SET status=\'deleted\' WHERE id=:id', ['id' => $userId]);
        $service = new RoleService($this->database);

        try {
            $service->syncEditorialRoles($userId, ['moderator'], 1);
            self::fail('Zanonimizowane konto nie może otrzymać roli redakcyjnej.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('zanonimizowane', $error->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nie znaleziono użytkownika');
        $service->syncEditorialRoles(PHP_INT_MAX, ['moderator'], 1);
    }

    private function testUserId(): int
    {
        $id = (int)$this->database->cell(
            'SELECT u.id
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id=u.id AND ur.role=\'admin\'
             WHERE ur.user_id IS NULL AND u.status!=\'deleted\'
             ORDER BY u.id
             LIMIT 1'
        );
        if ($id <= 0) {
            $created = (new AuthService($this->database))->register([
                'email' => 'admin-user-fixture-' . bin2hex(random_bytes(6)) . '@phpunit.example',
                'phone' => '',
                'password' => 'Phpunit-Admin-User-2026!',
                'display_name' => 'Użytkownik testowy administracji',
                'role' => 'reader',
            ]);
            $id = (int)$created['id'];
        }
        self::assertGreaterThan(0, $id);
        return $id;
    }
}
