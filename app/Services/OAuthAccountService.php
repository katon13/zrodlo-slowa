<?php
namespace App\Services;

use App\Core\Database;

final class OAuthAccountService
{
    public function __construct(private readonly Database $db) {}

    public function findByProvider(string $provider, string $providerUserId): ?array
    {
        return $this->db->one('
            SELECT * FROM user_oauth_accounts 
            WHERE provider = :provider AND provider_user_id = :provider_user_id 
            LIMIT 1
        ', [
            'provider' => $provider,
            'provider_user_id' => $providerUserId
        ]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->one('
            SELECT * FROM users WHERE email = :email LIMIT 1
        ', ['email' => strtolower(trim($email))]);
    }

    public function linkAccount(int $userId, array $profile): void
    {
        $this->assertProfile($profile);
        $this->db->transaction(function (Database $db) use ($userId, $profile): void {
            $user = $db->one('SELECT id,status FROM users WHERE id=:id FOR UPDATE', ['id' => $userId]);
            if (!$user || !in_array((string)$user['status'], ['active', 'pending_author'], true)) {
                throw new \RuntimeException('Nie można połączyć dostawcy z niedostępnym kontem.');
            }
            $existing = $db->one(
                'SELECT id,user_id FROM user_oauth_accounts WHERE provider=:provider AND provider_user_id=:subject FOR UPDATE',
                ['provider' => $profile['provider'], 'subject' => $profile['sub']]
            );
            if ($existing) {
                if ((int)$existing['user_id'] !== $userId) {
                    throw new \RuntimeException('To konto dostawcy jest już połączone z innym użytkownikiem.');
                }
                return;
            }
            $this->insertAccount($db, $userId, $profile);
        });
    }

    public function updateLastLogin(int $oauthAccountId): void
    {
        $this->db->transaction(function (Database $db) use ($oauthAccountId): void {
            $oauth = $db->one('SELECT user_id FROM user_oauth_accounts WHERE id=:id FOR UPDATE', ['id' => $oauthAccountId]);
            if (!$oauth) {
                return;
            }
            $db->query('UPDATE user_oauth_accounts SET last_login_at=NOW(),updated_at=NOW() WHERE id=:id', ['id' => $oauthAccountId]);
            $db->query('UPDATE users SET last_login_at=NOW(),updated_at=NOW() WHERE id=:id', ['id' => $oauth['user_id']]);
        });
    }

    public function createUserWithAccount(array $profile): array
    {
        $this->assertProfile($profile);
        if (empty($profile['email']) || empty($profile['email_verified'])) {
            throw new \RuntimeException('Nowe konto OAuth wymaga potwierdzonego adresu e-mail.');
        }

        $userId = $this->db->transaction(function (Database $db) use ($profile): int {
            $email = strtolower(trim((string)$profile['email']));
            if ($db->one('SELECT id FROM users WHERE email=:email FOR UPDATE', ['email' => $email])) {
                throw new \RuntimeException('Konto o tym adresie e-mail już istnieje.');
            }
            $name = trim((string)($profile['name'] ?? ''));
            if ($name === '') {
                $name = explode('@', $email)[0];
            }
            $password = bin2hex(random_bytes(32));
            $userId = $db->insert(
                'INSERT INTO users(
                    email,password_hash,display_name,status,can_write,talent_enabled,
                    wallet_enabled,payout_enabled,created_at,email_verified_at,avatar_path
                 ) VALUES (
                    :email,:hash,:name,\'active\',0,1,1,0,NOW(),NOW(),NULL
                 )',
                [
                    'email' => $email,
                    'hash' => password_hash($password . env('PASSWORD_PEPPER', ''), PASSWORD_DEFAULT),
                    'name' => mb_substr($name, 0, 190),
                ]
            );
            $db->query('INSERT INTO user_roles(user_id,role) VALUES(:user,\'reader\')', ['user' => $userId]);
            $this->insertAccount($db, $userId, $profile);
            return $userId;
        });

        $user = (new AuthService($this->db))->findActiveById($userId);
        if (!$user) {
            throw new \RuntimeException('Nie udało się odczytać utworzonego konta OAuth.');
        }
        return $user;
    }

    public function userForLogin(int $userId): ?array
    {
        return (new AuthService($this->db))->findActiveById($userId);
    }

    private function insertAccount(Database $db, int $userId, array $profile): void
    {
        $db->query(
            'INSERT INTO user_oauth_accounts (
                user_id,provider,provider_user_id,provider_email,provider_email_verified,
                provider_name,provider_avatar_url,linked_at,last_login_at,created_at
             ) VALUES (
                :user,:provider,:subject,:email,:verified,:name,:avatar,NOW(),NOW(),NOW()
             )',
            [
                'user' => $userId,
                'provider' => $profile['provider'],
                'subject' => $profile['sub'],
                'email' => $profile['email'] ?? null,
                'verified' => !empty($profile['email_verified']) ? 1 : 0,
                'name' => isset($profile['name']) ? mb_substr((string)$profile['name'], 0, 255) : null,
                'avatar' => $profile['picture'] ?? null,
            ]
        );
    }

    private function assertProfile(array $profile): void
    {
        if (!in_array((string)($profile['provider'] ?? ''), ['google', 'apple'], true)) {
            throw new \InvalidArgumentException('Nieobsługiwany dostawca OAuth.');
        }
        if (trim((string)($profile['sub'] ?? '')) === '') {
            throw new \InvalidArgumentException('Brak identyfikatora konta OAuth.');
        }
        if (!empty($profile['email']) && !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Dostawca OAuth zwrócił nieprawidłowy e-mail.');
        }
    }
}
