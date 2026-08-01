<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AuthService;

final class AuthLoginNameTest extends DatabaseTestCase
{
    public function testUserCanAuthenticateWithLoginNameOrEmail(): void
    {
        $password = 'Phpunit-Login-Name-2026!';
        $email = 'login-name-' . bin2hex(random_bytes(6)) . '@phpunit.example';
        $loginName = 'login_' . bin2hex(random_bytes(6));
        $auth = new AuthService($this->database);
        $created = $auth->register([
            'email' => $email,
            'phone' => '',
            'password' => $password,
            'display_name' => 'Test loginu użytkownika',
            'role' => 'reader',
        ]);
        $this->database->query(
            'UPDATE users SET login_name=:login WHERE id=:id',
            ['login' => $loginName, 'id' => (int)$created['id']]
        );

        self::assertSame((int)$created['id'], (int)($auth->attempt($loginName, $password)['id'] ?? 0));
        self::assertSame((int)$created['id'], (int)($auth->attempt(strtoupper($loginName), $password)['id'] ?? 0));
        self::assertSame((int)$created['id'], (int)($auth->attempt($email, $password)['id'] ?? 0));
        self::assertNull($auth->attempt($loginName, 'nieprawidlowe-haslo'));
    }
}
