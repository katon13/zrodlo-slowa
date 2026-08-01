<?php
namespace App\Services;

use App\Core\Database;

final class AuthService
{
    private const ROLE_PRIORITY = [
        'admin',
        'publisher',
        'chief_editor',
        'editor',
        'proofreader',
        'accountant',
        'author',
        'reader',
    ];

    public function __construct(private readonly Database $db) {}

    public function register(array $data): array
    {
        $data['email'] = strtolower(trim((string)($data['email'] ?? '')));
        $data['display_name'] = trim((string)($data['display_name'] ?? ''));
        $password = (string)($data['password'] ?? '');
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Nieprawidłowy e-mail.');
        }
        if ($data['display_name'] === '' || mb_strlen($data['display_name']) > 160) {
            throw new \InvalidArgumentException('Nazwa użytkownika jest wymagana i może mieć najwyżej 160 znaków.');
        }
        if (
            strlen($password) < 12
            || strlen($password) > 4096
            || preg_match('/[A-Za-z]/', $password) !== 1
            || preg_match('/[^A-Za-z]/', $password) !== 1
        ) {
            throw new \InvalidArgumentException('Hasło musi mieć co najmniej 12 znaków, litery oraz znaki innego typu.');
        }

        return $this->db->transaction(function(Database $db) use ($data) {
            $role = in_array($data['role'] ?? 'reader', ['author','reader','admin'], true) ? $data['role'] : 'reader';
            $status = $role === 'author' ? 'pending_author' : 'active';

            $id = $db->insert('
                INSERT INTO users(email, phone, password_hash, display_name, status, can_write, talent_enabled, wallet_enabled, payout_enabled, created_at)
                VALUES(:email,:phone,:hash,:name,:status,0,1,1,0,NOW())
            ', [
                'email' => $data['email'],
                'phone' => $data['phone'] ?: null,
                'hash' => password_hash($data['password'] . env('PASSWORD_PEPPER', ''), PASSWORD_DEFAULT),
                'name' => $data['display_name'],
                'status' => $status,
            ]);

            $db->query('INSERT INTO user_roles(user_id, role) VALUES(:user_id,:role)', ['user_id'=>$id, 'role'=>$role]);

            // Etap 2: każdy użytkownik od startu może dostawać podstawowe bonusy aktywności.
            // Wypłaty nadal wymagają osobnej decyzji administracji.
            return ['id' => $id, 'role' => $role];
        });
    }

    public function attempt(string $identifier, string $password): ?array
    {
        static $dummyHash = null;
        $dummyHash ??= password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        $identifier = strtolower(trim($identifier));
        $user = $this->db->one(
            'SELECT * FROM users
             WHERE LOWER(email)=:email_identifier OR LOWER(COALESCE(login_name,\'\'))=:login_identifier
             ORDER BY CASE WHEN LOWER(COALESCE(login_name,\'\'))=:priority_identifier THEN 0 ELSE 1 END
             LIMIT 1',
            [
                'email_identifier' => $identifier,
                'login_identifier' => $identifier,
                'priority_identifier' => $identifier,
            ]
        );
        $hash = (string)($user['password_hash'] ?? $dummyHash);
        $validPassword = password_verify($password . env('PASSWORD_PEPPER', ''), $hash);
        if (!$user || !$validPassword) return null;
        $status = (string)($user['status'] ?? '');
        if (!in_array($status, ['active', 'pending_author'], true)) return null;
        return $this->withPrimaryRole($user);
    }

    public function findActiveById(int $userId): ?array
    {
        $user = $this->db->one('SELECT * FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if (!$user || !in_array((string)$user['status'], ['active', 'pending_author'], true)) {
            return null;
        }
        return $this->withPrimaryRole($user);
    }

    public function rolesForUser(int $userId): array
    {
        $rows = $this->db->all('SELECT role FROM user_roles WHERE user_id=:id', ['id' => $userId]);
        return array_values(array_unique(array_map(
            static fn(array $row): string => (string)$row['role'],
            $rows
        )));
    }

    private function withPrimaryRole(array $user): array
    {
        $roles = $this->rolesForUser((int)$user['id']);
        $primaryRole = 'reader';
        foreach (self::ROLE_PRIORITY as $candidate) {
            if (in_array($candidate, $roles, true)) {
                $primaryRole = $candidate;
                break;
            }
        }
        $user['role'] = $primaryRole;
        $user['roles'] = $roles;
        return $user;
    }
}
