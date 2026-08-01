<?php
namespace App\Services;

use App\Contracts\QueueSignalInterface;
use App\Contracts\RateLimiterInterface;
use App\Core\Database;
use App\Core\SlowoSnajperConfig;

final class AuthSecurityService
{
    private const HIGH_ROLES = ['admin', 'chief_editor', 'editor', 'publisher', 'proofreader', 'accountant'];

    public function __construct(
        private readonly Database $db,
        private readonly SlowoSnajperConfig $config,
        private readonly ?RateLimiterInterface $rateLimiter = null,
        private readonly ?QueueSignalInterface $queueSignals = null,
    ) {}

    public function highRoles(): array
    {
        return self::HIGH_ROLES;
    }

    public function userSecurityStatus(int $userId): array
    {
        $user = $this->db->one('
            SELECT id,email,display_name,status,email_verified_at,two_factor_enabled,two_factor_secret,auth_security_level,force_2fa_setup,force_password_change,last_login_at,last_login_ip_hash
            FROM users
            WHERE id=:id
            LIMIT 1
        ', ['id' => $userId]);

        if (!$user) {
            return [
                'exists' => false,
                'email_verified' => false,
                'two_factor_enabled' => false,
                'ready_for_high_roles' => false,
                'missing' => ['konto'],
            ];
        }

        $emailVerified = !empty($user['email_verified_at']);
        $twoFactorEnabled = (int)($user['two_factor_enabled'] ?? 0) === 1;
        $isSystemAdmin = $this->isSystemAdmin($userId);

        if (!$this->enforceHighRoleSecurity()) {
            return [
                'exists' => true,
                'user' => $user,
                'email_verified' => $emailVerified,
                'two_factor_enabled' => $twoFactorEnabled,
                'requires_verified_email' => false,
                'requires_2fa' => false,
                'ready_for_high_roles' => true,
                'missing' => [],
                'is_system_admin' => $isSystemAdmin,
            ];
        }
        $requiresEmail = $this->requiresVerifiedEmail();
        $requires2fa = $this->requires2fa();
        $missing = [];
        if ($requiresEmail && !$emailVerified) {
            $missing[] = 'potwierdzony e-mail';
        }
        if ($requires2fa && !$twoFactorEnabled) {
            $missing[] = '2FA';
        }

        return [
            'exists' => true,
            'user' => $user,
            'email_verified' => $emailVerified,
            'two_factor_enabled' => $twoFactorEnabled,
            'requires_verified_email' => $requiresEmail,
            'requires_2fa' => $requires2fa,
            'ready_for_high_roles' => $missing === [],
            'missing' => $missing,
            'is_system_admin' => $isSystemAdmin,
        ];
    }

    public function canUseHighRole(int $userId): bool
    {
        return (bool)$this->userSecurityStatus($userId)['ready_for_high_roles'];
    }

    public function assertHighRoleReady(int $userId, string $contextLabel = 'wysokiej roli'): void
    {
        $status = $this->userSecurityStatus($userId);
        if (($status['ready_for_high_roles'] ?? false) === true) {
            return;
        }
        $missing = implode(', ', $status['missing'] ?? ['zabezpieczenia']);
        throw new \RuntimeException('Dostęp do ' . $contextLabel . ' jest zablokowany przez SNAJPERA SŁOWA. Brakuje: ' . $missing . '. Wejdź w /account/security i dokończ zabezpieczenia konta.');
    }

    public function markHighRoleRequired(int $userId, int $adminId, array $roles): void
    {
        $high = array_values(array_intersect(self::HIGH_ROLES, $roles));
        if ($high === []) {
            return;
        }
        $this->db->query('
            UPDATE users
            SET auth_security_level=\'high\',
                force_2fa_setup=1,
                updated_at=NOW()
            WHERE id=:id
        ', ['id' => $userId]);
        $this->audit($adminId, 'auth_security_high_role_required', ['user_id' => $userId, 'roles' => $high]);
    }

    public function createEmailVerificationToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $this->db->transaction(function (Database $db) use ($userId, $hash): void {
            $db->query('UPDATE email_verification_tokens SET used_at=NOW() WHERE user_id=:user AND used_at IS NULL', [
                'user' => $userId,
            ]);
            $db->query('INSERT INTO email_verification_tokens(user_id,token_hash,expires_at,created_at) VALUES(:user,:hash,' . $db->nowPlus(24, 'hour') . ',NOW())', [
                'user' => $userId,
                'hash' => $hash,
            ]);
        });
        return $token;
    }

    public function queueEmailVerification(int $userId, string $baseUrl = ''): string
    {
        $user = $this->db->one('SELECT id,email FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if (!$user) {
            throw new \RuntimeException('Nie znaleziono użytkownika.');
        }
        $token = $this->createEmailVerificationToken($userId);
        $link = rtrim($baseUrl, '/') . '/email/verify?token=' . urlencode($token);
        (new MailService($this->db, $this->queueSignals))->queue($userId, (string)$user['email'], 'Potwierdź e-mail — Źródło Słowa', "Potwierdź adres e-mail klikając link: {$link}");
        return $link;
    }

    public function verifyEmailToken(string $token): void
    {
        $hash = hash('sha256', $token);
        $this->db->transaction(function(Database $db) use ($hash): void {
            $row = $db->one('SELECT * FROM email_verification_tokens WHERE token_hash=:hash AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1 FOR UPDATE', [
                'hash' => $hash,
            ]);
            if (!$row) {
                throw new \RuntimeException('Link potwierdzenia e-mail jest nieprawidłowy albo wygasł.');
            }
            $db->query('UPDATE users SET email_verified_at=COALESCE(email_verified_at,NOW()), updated_at=NOW() WHERE id=:id', ['id' => (int)$row['user_id']]);
            $db->query('UPDATE email_verification_tokens SET used_at=NOW() WHERE user_id=:user AND used_at IS NULL', [
                'user' => (int)$row['user_id'],
            ]);
        });
    }

    public function startTwoFactorSetup(int $userId): string
    {
        $secret = $this->base32Secret(20);
        $encrypted = SecretCipher::fromEnvironment()->encrypt($secret);
        $this->db->query('UPDATE users SET two_factor_secret=:secret, two_factor_enabled=0, force_2fa_setup=1, updated_at=NOW() WHERE id=:id', [
            'id' => $userId,
            'secret' => $encrypted,
        ]);
        return $secret;
    }

    public function currentTwoFactorSecret(int $userId): ?string
    {
        $secret = $this->db->cell('SELECT two_factor_secret FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if (!$secret) {
            return null;
        }
        $secret = (string)$secret;
        if (str_starts_with($secret, 'v1:')) {
            return SecretCipher::fromEnvironment()->decrypt($secret);
        }

        // Jednorazowa, bezpieczna migracja historycznego sekretu przy pierwszym użyciu.
        $encrypted = SecretCipher::fromEnvironment()->encrypt($secret);
        $this->db->query(
            'UPDATE users SET two_factor_secret=:encrypted,updated_at=NOW()
             WHERE id=:id AND two_factor_secret=:legacy',
            ['encrypted' => $encrypted, 'id' => $userId, 'legacy' => $secret]
        );
        return $secret;
    }

    public function enableTwoFactor(int $userId, string $code): void
    {
        $secret = $this->currentTwoFactorSecret($userId);
        if (!$secret) {
            throw new \RuntimeException('Najpierw wygeneruj sekret 2FA.');
        }
        if (!$this->verifyTotp($secret, $code)) {
            throw new \RuntimeException('Kod 2FA jest nieprawidłowy.');
        }
        $this->db->query('UPDATE users SET two_factor_enabled=1, force_2fa_setup=0, updated_at=NOW() WHERE id=:id', ['id' => $userId]);
    }

    public function disableTwoFactorByAdmin(int $userId, int $adminId): void
    {
        $this->db->query(
            'UPDATE users
             SET two_factor_enabled=0,two_factor_secret=NULL,force_2fa_setup=1,
                 session_version=session_version+1,updated_at=NOW()
             WHERE id=:id',
            ['id' => $userId]
        );
        $this->audit($adminId, 'auth_security_2fa_disabled_by_admin', ['user_id' => $userId]);
    }

    public function otpauthUri(int $userId, string $issuer = 'Źródło Słowa'): string
    {
        $user = $this->db->one('SELECT email FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        $secret = $this->currentTwoFactorSecret($userId);
        if (!$user || $secret === null) {
            return '';
        }
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . (string)$user['email'])
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }


    public function userHasHighRole(int $userId): bool
    {
        try {
            $placeholders = implode(',', array_fill(0, count(self::HIGH_ROLES), '?'));
            $params = array_merge([$userId], self::HIGH_ROLES);
            $count = (int)$this->db->cell('SELECT COUNT(*) FROM user_roles WHERE user_id=? AND role IN (' . $placeholders . ')', $params);
            return $count > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function shouldChallengeLogin(int $userId): bool
    {
        if (!$this->loginTwoFactorChallengeEnabled()) {
            return false;
        }
        if (!$this->enforceHighRoleSecurity() || !$this->requires2fa()) {
            return false;
        }
        if (!$this->userHasHighRole($userId)) {
            return false;
        }
        $status = $this->userSecurityStatus($userId);
        return (bool)($status['two_factor_enabled'] ?? false)
            && !empty($status['user']['two_factor_secret'] ?? null);
    }

    public function assertLoginAllowed(string $email): void
    {
        $email = strtolower(trim($email));
        $windowMinutes = 15;
        $ipHash = $this->hashValue((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($this->rateLimiter?->available()) {
            $emailLimited = $email !== ''
                && $this->rateLimiter->tooManyAttempts($this->loginEmailRateKey($email), 5);
            $ipLimited = $ipHash !== null
                && $this->rateLimiter->tooManyAttempts($this->loginIpRateKey($ipHash), 20);
            if ($emailLimited || $ipLimited) {
                $this->recordLoginEvent(null, $email, 'login_throttled');
                throw new \RuntimeException('Zbyt wiele prób logowania. Spróbuj ponownie za kilkanaście minut.');
            }
            return;
        }

        $emailFailures = $email !== '' ? (int)$this->db->cell(
            'SELECT COUNT(*) FROM auth_login_events
             WHERE email=:email AND result=\'password_failed\'
               AND created_at >= ' . $this->db->nowMinus($windowMinutes, 'minute'),
            ['email' => $email]
        ) : 0;
        $ipFailures = $ipHash !== null ? (int)$this->db->cell(
            'SELECT COUNT(*) FROM auth_login_events
             WHERE ip_hash=:ip AND result=\'password_failed\'
               AND created_at >= ' . $this->db->nowMinus($windowMinutes, 'minute'),
            ['ip' => $ipHash]
        ) : 0;

        if ($emailFailures >= 5 || $ipFailures >= 20) {
            $this->recordLoginEvent(null, $email, 'login_throttled');
            throw new \RuntimeException('Zbyt wiele prób logowania. Spróbuj ponownie za kilkanaście minut.');
        }
    }

    public function assertPasswordResetAllowed(string $email): void
    {
        $email = strtolower(trim($email));
        $ipHash = $this->hashValue((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($this->rateLimiter?->available()) {
            $emailLimited = $email !== ''
                && $this->rateLimiter->tooManyAttempts($this->resetEmailRateKey($email), 3);
            $ipLimited = $ipHash !== null
                && $this->rateLimiter->tooManyAttempts($this->resetIpRateKey($ipHash), 10);
            if ($emailLimited || $ipLimited) {
                $this->recordLoginEvent(null, $email, 'reset_throttled');
                throw new \RuntimeException('Limit żądań resetu hasła został przekroczony.');
            }
            return;
        }

        $byEmail = $email !== '' ? (int)$this->db->cell(
            'SELECT COUNT(*) FROM auth_login_events
             WHERE email=:email AND result=\'reset_requested\'
               AND created_at >= ' . $this->db->nowMinus(30, 'minute'),
            ['email' => $email]
        ) : 0;
        $byIp = $ipHash !== null ? (int)$this->db->cell(
            'SELECT COUNT(*) FROM auth_login_events
             WHERE ip_hash=:ip AND result=\'reset_requested\'
               AND created_at >= ' . $this->db->nowMinus(30, 'minute'),
            ['ip' => $ipHash]
        ) : 0;
        if ($byEmail >= 3 || $byIp >= 10) {
            $this->recordLoginEvent(null, $email, 'reset_throttled');
            throw new \RuntimeException('Limit żądań resetu hasła został przekroczony.');
        }
    }

    public function verifyLoginTwoFactor(int $userId, string $code): bool
    {
        $row = $this->db->one('SELECT two_factor_enabled,two_factor_secret FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if (!$row || (int)($row['two_factor_enabled'] ?? 0) !== 1 || empty($row['two_factor_secret'])) {
            return false;
        }
        $secret = (string)$row['two_factor_secret'];
        if (str_starts_with($secret, 'v1:')) {
            $secret = SecretCipher::fromEnvironment()->decrypt($secret);
        }
        return $this->verifyTotp($secret, $code);
    }

    public function markLoginSuccess(int $userId): void
    {
        try {
            $this->db->query('UPDATE users SET last_login_at=NOW(), last_login_ip_hash=:ip, updated_at=NOW() WHERE id=:id', [
                'id' => $userId,
                'ip' => $this->hashValue((string)($_SERVER['REMOTE_ADDR'] ?? '')),
            ]);
        } catch (\Throwable) {}
    }

    public function recordLoginEvent(?int $userId, string $email, string $result): void
    {
        $this->updateRateLimits($email, $result);
        if (!$this->loginEventLoggingEnabled()) {
            return;
        }
        try {
            $this->db->query('INSERT INTO auth_login_events(user_id,email,result,ip_hash,user_agent_hash,created_at) VALUES(:user,:email,:result,:ip,:ua,NOW())', [
                'user' => $userId,
                'email' => $email !== '' ? $email : null,
                'result' => $result,
                'ip' => $this->hashValue((string)($_SERVER['REMOTE_ADDR'] ?? '')),
                'ua' => $this->hashValue((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
            ]);
        } catch (\Throwable) {}
    }

    private function updateRateLimits(string $email, string $result): void
    {
        if (!$this->rateLimiter?->available()) {
            return;
        }
        $email = strtolower(trim($email));
        $ipHash = $this->hashValue((string)($_SERVER['REMOTE_ADDR'] ?? ''));

        if ($result === 'password_failed') {
            if ($email !== '') {
                $this->rateLimiter->hit($this->loginEmailRateKey($email), 900);
            }
            if ($ipHash !== null) {
                $this->rateLimiter->hit($this->loginIpRateKey($ipHash), 900);
            }
            return;
        }
        if ($result === 'reset_requested') {
            if ($email !== '') {
                $this->rateLimiter->hit($this->resetEmailRateKey($email), 1800);
            }
            if ($ipHash !== null) {
                $this->rateLimiter->hit($this->resetIpRateKey($ipHash), 1800);
            }
            return;
        }
        if (in_array($result, ['password_ok', 'google_ok', 'apple_ok'], true) && $email !== '') {
            $this->rateLimiter->clear($this->loginEmailRateKey($email));
        }
    }

    private function loginEmailRateKey(string $email): string
    {
        return 'auth:login:email:' . hash('sha256', $email);
    }

    private function loginIpRateKey(string $ipHash): string
    {
        return 'auth:login:ip:' . $ipHash;
    }

    private function resetEmailRateKey(string $email): string
    {
        return 'auth:reset:email:' . hash('sha256', $email);
    }

    private function resetIpRateKey(string $ipHash): string
    {
        return 'auth:reset:ip:' . $ipHash;
    }

    private function isSystemAdmin(int $userId): bool
    {
        try {
            return (int)$this->db->cell('SELECT COUNT(*) FROM user_roles WHERE user_id=:id AND role=\'admin\'', ['id' => $userId]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function enforceHighRoleSecurity(): bool
    {
        $all = $this->config->all();
        return (bool)($all['roles']['stage3_enforce_high_role_security'] ?? true);
    }

    private function requiresVerifiedEmail(): bool
    {
        $all = $this->config->all();
        return (bool)($all['roles']['higher_roles_require_verified_email'] ?? true);
    }

    private function requires2fa(): bool
    {
        $all = $this->config->all();
        return (bool)($all['roles']['higher_roles_require_2fa'] ?? true);
    }

    private function audit(?int $userId, string $action, array $payload = []): void
    {
        try {
            $this->db->query('INSERT INTO admin_audit_logs(user_id,action,payload,created_at) VALUES(:user,:action,:payload,NOW())', [
                'user' => $userId,
                'action' => $action,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable) {}
    }


    private function loginTwoFactorChallengeEnabled(): bool
    {
        $all = $this->config->all();
        return (bool)($all['auth']['login_2fa_challenge_enabled'] ?? true);
    }

    private function loginEventLoggingEnabled(): bool
    {
        $all = $this->config->all();
        return (bool)($all['auth']['log_login_events'] ?? true);
    }

    private function hashValue(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        return hash('sha256', env('APP_KEY', 'zrodlo-slowa-local') . '|' . $value);
    }

    private function base32Secret(int $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(random_bytes($bytes)) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $secret .= $alphabet[bindec($chunk)];
        }
        return $secret;
    }

    private function verifyTotp(string $secret, string $code): bool
    {
        $code = preg_replace('/\D+/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }
        $timeSlice = (int)floor(time() / 30);
        for ($i = -1; $i <= 1; $i++) {
            if (hash_equals($this->totp($secret, $timeSlice + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    private function totp(string $secret, int $timeSlice): string
    {
        $key = $this->base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr(bindec($byte));
            }
        }
        return $binary;
    }
}
