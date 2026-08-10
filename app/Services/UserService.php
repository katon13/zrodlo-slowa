<?php
namespace App\Services;

use App\Contracts\QueueSignalInterface;
use App\Core\Database;

final class UserService
{
    public function __construct(
        private readonly Database $db,
        private readonly ?QueueSignalInterface $queueSignals = null,
    ) {}

    public function listUsers(int $limit = 50, int $offset = 0, ?string $status = null): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $where = 'u.status != \'deleted\'';
        $params = [];
        if ($status !== null && in_array($status, ['pending_author','active','blocked'], true)) {
            $where .= ' AND u.status=:status';
            $params['status'] = $status;
        }
        $rolesAggregate = $this->rolesAggregate(', ');
        return $this->db->all('
            SELECT
                u.*,
                ' . $rolesAggregate . ' AS roles,
                w.id AS wallet_id,
                w.points_balance,
                w.available_minor,
                w.slowo_available_minor,
                w.main_available_minor
            FROM users u
            LEFT JOIN wallets w ON w.user_id=u.id
            WHERE ' . $where . '
            ORDER BY CASE WHEN u.status=\'pending_author\' THEN 0 ELSE 1 END, u.created_at DESC, u.id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
    }

    public function setStatus(int $userId, string $status): void
    {
        if (!in_array($status, ['pending_author','active','blocked'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy status użytkownika.');
        }
        $user = $this->db->one('SELECT status FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if (!$user) {
            throw new \RuntimeException('Nie znaleziono użytkownika.');
        }
        if ((string)$user['status'] === 'deleted') {
            throw new \RuntimeException('Nie można zmieniać statusu konta, które zostało zanonimizowane.');
        }
        $this->db->query(
            'UPDATE users
             SET status=:status,session_version=session_version+1,updated_at=NOW()
             WHERE id=:id',
            ['id' => $userId, 'status' => $status]
        );
    }

    public function setPrimaryRole(int $userId, string $role): void
    {
        if (!in_array($role, ['reader','commentator','author','admin'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy typ konta. Role redakcyjne nadaje się w panelu „Role i uprawnienia”.');
        }
        $user = $this->db->one('SELECT status FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if (!$user) {
            throw new \RuntimeException('Nie znaleziono użytkownika.');
        }
        if ((string)$user['status'] === 'deleted') {
            throw new \RuntimeException('Nie można zmieniać roli konta, które zostało zanonimizowane.');
        }
        $this->db->transaction(function(Database $db) use ($userId, $role) {
            if ($role === 'commentator') {
                $this->ensureWallet($userId);
            }
            // Typ konta i stanowiska redakcyjne to dwie niezależne warstwy.
            // Zmiana reader/commentator/author/admin nie może odbierać ról moderatora,
            // redaktora, wydawcy, korektora ani księgowego.
            $db->query('DELETE FROM user_roles WHERE user_id=:id AND role IN (\'reader\',\'commentator\',\'author\',\'admin\')', ['id'=>$userId]);
            $db->query('INSERT INTO user_roles(user_id,role) VALUES(:id,:role)', ['id'=>$userId,'role'=>$role]);
            if ($role === 'commentator') {
                $db->query(
                    'UPDATE users
                     SET status=\'active\',can_write=0,talent_enabled=1,wallet_enabled=1,payout_enabled=0,
                         session_version=session_version+1,permissions_updated_at=NOW(),updated_at=NOW()
                     WHERE id=:id',
                    ['id' => $userId]
                );
            } else {
                $db->query(
                    'UPDATE users SET session_version=session_version+1,updated_at=NOW() WHERE id=:id',
                    ['id' => $userId]
                );
            }
        });
    }

    public function pendingAuthorsCount(): int
    {
        return (int)$this->db->cell('SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id=u.id AND ur.role=\'author\' WHERE u.status=\'pending_author\'');
    }

    public function approveAuthor(int $userId): void
    {
        $user = $this->db->one('SELECT u.id, u.status, u.email, u.display_name FROM users u JOIN user_roles ur ON ur.user_id=u.id AND ur.role=\'author\' WHERE u.id=:id AND u.status != \'deleted\' LIMIT 1', ['id' => $userId]);
        if (!$user) {
            throw new \RuntimeException('Nie znaleziono autora do zatwierdzenia (albo konto jest usunięte).');
        }

        $this->db->transaction(function(Database $db) use ($user): void {
            $db->query(
                'UPDATE users
                 SET status=\'active\',can_write=1,session_version=session_version+1,
                     updated_at=NOW(),permissions_updated_at=NOW()
                 WHERE id=:id',
                ['id' => (int)$user['id']]
            );
            $this->queuePermissionMail((int)$user['id'], (string)$user['email'], 'Aktywowano możliwość pisania', 'Twoje konto autora zostało zatwierdzone. Możesz już dodawać i edytować teksty w panelu autora.');
        });
    }

    public function updateOperationalPermissions(int $userId, array $flags): array
    {
        $user = $this->db->one('SELECT * FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if (!$user) {
            throw new \RuntimeException('Nie znaleziono użytkownika.');
        }

        if ((string)($user['status'] ?? '') === 'deleted') {
            throw new \RuntimeException('Nie można zmieniać uprawnień konta, które zostało zanonimizowane.');
        }

        $allowed = ['can_write', 'talent_enabled', 'wallet_enabled', 'payout_enabled'];
        $desired = [];
        foreach ($allowed as $key) {
            $raw = $flags[$key] ?? '0';
            $desired[$key] = in_array((string)$raw, ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
        }

        $isCommentator = $this->db->one(
            'SELECT 1 FROM user_roles WHERE user_id=:id AND role=\'commentator\' LIMIT 1',
            ['id' => $userId]
        ) !== null;
        if ($isCommentator) {
            $desired['can_write'] = 0;
            $desired['payout_enabled'] = 0;
        }

        if ($desired['wallet_enabled'] === 0) {
            $desired['payout_enabled'] = 0;
        } elseif ($desired['payout_enabled'] === 1) {
            $desired['wallet_enabled'] = 1;
        }

        if ($desired['talent_enabled'] === 1 || $desired['wallet_enabled'] === 1 || $desired['payout_enabled'] === 1) {
            // Obecna baza trzyma Talent i PLN w tabeli wallets.
            // Portfel jest tworzony dopiero przy ręcznej zgodzie Talent/Wallet/Payout.
            $this->ensureWallet($userId);
        }

        $changed = [];
        foreach ($desired as $key => $value) {
            if ((int)($user[$key] ?? 0) !== $value) {
                $changed[$key] = ['from' => (int)($user[$key] ?? 0), 'to' => $value];
            }
        }
        if ($changed === []) {
            return [];
        }

        $this->db->transaction(function(Database $db) use ($userId, $desired, $changed, $user): void {
            $statusSql = '';
            if ($desired['can_write'] === 1 && (string)($user['status'] ?? '') === 'pending_author') {
                $statusSql = ', status=\'active\'';
            }

            $db->query('
                UPDATE users
                SET
                    can_write=:can_write,
                    talent_enabled=:talent_enabled,
                    wallet_enabled=:wallet_enabled,
                    payout_enabled=:payout_enabled,
                    session_version=session_version+1,
                    updated_at=NOW(),
                    permissions_updated_at=NOW()
                    ' . $statusSql . '
                WHERE id=:id
            ', [
                'id' => $userId,
                'can_write' => $desired['can_write'],
                'talent_enabled' => $desired['talent_enabled'],
                'wallet_enabled' => $desired['wallet_enabled'],
                'payout_enabled' => $desired['payout_enabled'],
            ]);

            $mailMap = [
                'can_write' => ['Aktywowano możliwość pisania', 'Redakcja aktywowała możliwość pisania i edycji tekstów w ŹRÓDLE SŁOWA.'],
                'talent_enabled' => ['Aktywowano Talent', 'Redakcja aktywowała zbieranie punktów Talent dla Twojego konta.'],
                'wallet_enabled' => ['Aktywowano konto rozliczeniowe', 'Administracja aktywowała konto rozliczeniowe w ŹRÓDLE SŁOWA.'],
                'payout_enabled' => ['Aktywowano możliwość wypłat', 'Administracja aktywowała możliwość składania wniosków o wypłatę.'],
            ];

            foreach ($changed as $key => $change) {
                if ($change['to'] === 1) {
                    [$subject, $body] = $mailMap[$key];
                    $this->queuePermissionMail($userId, (string)$user['email'], $subject, $body);
                }
            }
        });

        return $changed;
    }

    public function findUserStatus(int $userId): ?array
    {
        $rolesAggregate = $this->rolesAggregate(',');
        return $this->db->one('
            SELECT
                u.id,
                u.status,
                u.can_write,
                u.talent_enabled,
                u.wallet_enabled,
                u.payout_enabled,
                u.article_submit_blocked_until,
                u.article_submit_block_reason,
                u.article_submit_blocked_by,
                ' . $rolesAggregate . ' AS roles
            FROM users u
            WHERE u.id=:id
            LIMIT 1
        ', ['id' => $userId]);
    }

    public function assertPayoutAccountEligible(int $userId): void
    {
        $account = $this->db->one(
            'SELECT u.payout_enabled,
                    EXISTS(
                        SELECT 1 FROM user_roles ur
                        WHERE ur.user_id=u.id AND ur.role=\'commentator\'
                    ) AS is_commentator
             FROM users u
             WHERE u.id=:id
             LIMIT 1',
            ['id' => $userId]
        );
        if ($account === null) {
            throw new \RuntimeException('Nie znaleziono konta użytkownika.');
        }
        if ((bool)$account['is_commentator']) {
            throw new \RuntimeException('Konto komentatora nie obsługuje wypłat pieniężnych.');
        }
        if ((int)$account['payout_enabled'] !== 1) {
            throw new \RuntimeException('Wypłaty nie są aktywne dla tego konta. Wymagana jest ręczna zgoda administracji.');
        }
    }



    public function authorSubmitBlockInfo(int $userId): array
    {
        $row = $this->db->one('
            SELECT
                article_submit_blocked_until,
                article_submit_block_reason,
                article_submit_blocked_by,
                CASE
                    WHEN article_submit_blocked_until IS NOT NULL AND article_submit_blocked_until > NOW() THEN 1
                    ELSE 0
                END AS is_blocked
            FROM users
            WHERE id=:id
            LIMIT 1
        ', ['id' => $userId]);

        if (!$row) {
            return [
                'is_blocked' => false,
                'blocked_until' => null,
                'reason' => null,
                'blocked_by' => null,
            ];
        }

        return [
            'is_blocked' => (int)($row['is_blocked'] ?? 0) === 1,
            'blocked_until' => $row['article_submit_blocked_until'] ?? null,
            'reason' => $row['article_submit_block_reason'] ?? null,
            'blocked_by' => $row['article_submit_blocked_by'] ?? null,
        ];
    }

    public function setAuthorSubmitBlock(int $authorId, ?int $blockedBy, string $duration, string $customReason = ''): array
    {
        $rolesAggregate = $this->rolesAggregate(',');
        $user = $this->db->one('
            SELECT u.id, u.status, u.display_name, ' . $rolesAggregate . ' AS roles
            FROM users u
            WHERE u.id=:id AND u.status != \'deleted\'
            LIMIT 1
        ', ['id' => $authorId]);

        if (!$user) {
            throw new \RuntimeException('Nie znaleziono użytkownika.');
        }

        $roles = array_filter(explode(',', (string)($user['roles'] ?? '')));
        if (!in_array('author', $roles, true)) {
            throw new \RuntimeException('Blokadę wysyłania tekstów można ustawić tylko autorowi.');
        }

        if ($duration === 'clear') {
            $this->db->query('
                UPDATE users
                SET article_submit_blocked_until=NULL,
                    article_submit_block_reason=NULL,
                    article_submit_blocked_by=NULL,
                    updated_at=NOW()
                WHERE id=:id
            ', ['id' => $authorId]);

            return ['action' => 'clear', 'until' => null];
        }

        $map = [
            '24h' => ['+24 hours', 'Blokada wysyłania tekstów na 24 godziny.'],
            '7d' => ['+7 days', 'Blokada wysyłania tekstów na 7 dni.'],
            '30d' => ['+30 days', 'Blokada wysyłania tekstów na 30 dni.'],
        ];

        if (!isset($map[$duration])) {
            throw new \InvalidArgumentException('Nieprawidłowy czas blokady.');
        }

        [$modifier, $defaultReason] = $map[$duration];
        $customReason = trim($customReason);
        $reason = $customReason !== '' ? mb_substr($customReason, 0, 500) : $defaultReason;
        $until = date('Y-m-d H:i:s', strtotime($modifier));

        $this->db->query('
            UPDATE users
            SET article_submit_blocked_until=:until_at,
                article_submit_block_reason=:reason,
                article_submit_blocked_by=:blocked_by,
                updated_at=NOW()
            WHERE id=:id
        ', [
            'id' => $authorId,
            'until_at' => $until,
            'reason' => $reason,
            'blocked_by' => $blockedBy,
        ]);

        return ['action' => $duration, 'until' => $until, 'reason' => $reason];
    }


    public function operationalPermissions(int $userId): array
    {
        $row = $this->db->one('SELECT can_write,talent_enabled,wallet_enabled,payout_enabled,display_currency,interface_language,avatar_path,avatar_updated_at FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if (!$row) {
            return ['can_write'=>0, 'talent_enabled'=>0, 'wallet_enabled'=>0, 'payout_enabled'=>0, 'display_currency' => 'AUTO', 'interface_language' => null, 'avatar_path' => null, 'avatar_updated_at' => null];
        }
        return [
            'can_write' => (int)($row['can_write'] ?? 0),
            'talent_enabled' => (int)($row['talent_enabled'] ?? 0),
            'wallet_enabled' => (int)($row['wallet_enabled'] ?? 0),
            'payout_enabled' => (int)($row['payout_enabled'] ?? 0),
            'display_currency' => $row['display_currency'] ?? 'AUTO',
            'interface_language' => $row['interface_language'] ?? null,
            'avatar_path' => $row['avatar_path'] ?? null,
            'avatar_updated_at' => $row['avatar_updated_at'] ?? null,
        ];
    }

    public function updateSettings(int $userId, array $settings): void
    {
        $user = $this->db->one('SELECT id FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if (!$user) {
            throw new \RuntimeException('Nie znaleziono użytkownika.');
        }

        $displayCurrency = strtoupper($settings['display_currency'] ?? 'AUTO');
        if (!in_array($displayCurrency, ['AUTO', 'PLN', 'EUR', 'GBP'], true)) {
            $displayCurrency = 'AUTO';
        }

        $interfaceLanguage = $settings['interface_language'] ?? null;
        if ($interfaceLanguage !== null) {
            $interfaceLanguage = strtolower(substr(trim($interfaceLanguage), 0, 5));
        }

        $this->db->query('UPDATE users SET display_currency=:display_currency, interface_language=:interface_language, updated_at=NOW() WHERE id=:id', [
            'id' => $userId,
            'display_currency' => $displayCurrency,
            'interface_language' => $interfaceLanguage
        ]);
    }

    private function ensureWallet(int $userId): void
    {
        $wallet = $this->db->one('SELECT id FROM wallets WHERE user_id=:id LIMIT 1', ['id' => $userId]);
        if (!$wallet) {
            $this->db->query('INSERT INTO wallets(user_id,main_available_minor,main_reserved_minor,slowo_available_minor,slowo_reserved_minor,available_minor,pending_minor,reserved_minor,points_balance,currency,created_at) VALUES(:id,0,0,0,0,0,0,0,0,\'PLN\',NOW())', ['id' => $userId]);
        }
    }

    private function queuePermissionMail(int $userId, string $email, string $subject, string $body): void
    {
        try {
            (new MailService($this->db, $this->queueSignals))->queue($userId, $email, $subject . ' — Źródło Słowa', $body);
        } catch (\Throwable) {
            // Mail queue nie może blokować decyzji administracyjnej.
        }
    }

    public function requestPasswordReset(string $email): ?string
    {
        $email = strtolower(trim($email));
        $user = $this->db->one('SELECT id,email FROM users WHERE email=:email AND status IN (\'active\',\'pending_author\') LIMIT 1', ['email' => $email]);
        if (!$user) return null;
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $this->db->transaction(function (Database $db) use ($user, $hash, $token): void {
            $db->query('UPDATE password_resets SET used_at=NOW() WHERE user_id=:user AND used_at IS NULL', [
                'user' => $user['id'],
            ]);
            $db->query('INSERT INTO password_resets(user_id,token_hash,expires_at,created_at) VALUES(:user,:hash,' . $db->nowPlus(2, 'hour') . ',NOW())', [
                'user' => $user['id'],
                'hash' => $hash,
            ]);
            (new MailService($db, $this->queueSignals))->queue(
                (int)$user['id'],
                (string)$user['email'],
                'Reset hasła — Źródło Słowa',
                'Link resetu: ' . rtrim((string)env('APP_URL', ''), '/') . '/password/reset?token=' . urlencode($token)
            );
        });
        return $token;
    }

    public function resetPassword(string $token, string $password): void
    {
        if (strlen($password) < 12) throw new \InvalidArgumentException('Hasło musi mieć minimum 12 znaków.');
        if (strlen($password) > 4096) throw new \InvalidArgumentException('Hasło jest zbyt długie.');
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[^A-Za-z]/', $password)) {
            throw new \InvalidArgumentException('Hasło musi zawierać litery oraz co najmniej jeden inny znak.');
        }
        $hash = hash('sha256', $token);
        $this->db->transaction(function(Database $db) use ($hash, $password): void {
            $row = $db->one('SELECT * FROM password_resets WHERE token_hash=:hash AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1 FOR UPDATE', [
                'hash' => $hash,
            ]);
            if (!$row) throw new \RuntimeException('Token resetu jest nieprawidłowy albo wygasł.');
            $db->query('UPDATE users SET password_hash=:hash, force_password_change=0, session_version=session_version+1, updated_at=NOW() WHERE id=:id', [
                'id'=>$row['user_id'], 'hash'=>password_hash($password . env('PASSWORD_PEPPER', ''), PASSWORD_DEFAULT)
            ]);
            $db->query('UPDATE password_resets SET used_at=NOW() WHERE user_id=:user AND used_at IS NULL', [
                'user' => $row['user_id'],
            ]);
        });
    }
    public function updateAvatar(int $userId, string $avatarPath): void
    {
        $this->db->query('UPDATE users SET avatar_path=:path, avatar_updated_at=NOW(), updated_at=NOW() WHERE id=:id', [
            'id' => $userId,
            'path' => $avatarPath
        ]);
    }

    public function updateAvatarIfCurrent(int $userId, string $avatarPath, ?string $expectedPath): bool
    {
        $statement = $this->db->query(
            'UPDATE users
             SET avatar_path=:path, avatar_updated_at=NOW(), updated_at=NOW()
             WHERE id=:id
               AND ((:expected_is_null=1 AND avatar_path IS NULL) OR avatar_path=:expected_path)',
            [
                'id' => $userId,
                'path' => $avatarPath,
                'expected_is_null' => $expectedPath === null ? 1 : 0,
                'expected_path' => $expectedPath ?? '',
            ]
        );
        return $statement->rowCount() === 1;
    }

    private function rolesAggregate(string $separator): string
    {
        $separator = str_replace("'", "''", $separator);
        return $this->db->isPostgres()
            ? "(SELECT STRING_AGG(ur.role, '{$separator}' ORDER BY ur.role)
                FROM user_roles ur WHERE ur.user_id=u.id)"
            : "(SELECT GROUP_CONCAT(ur.role ORDER BY ur.role SEPARATOR '{$separator}')
                FROM user_roles ur WHERE ur.user_id=u.id)";
    }
}
