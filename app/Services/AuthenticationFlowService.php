<?php
namespace App\Services;

use App\Contracts\RateLimiterInterface;
use App\Core\Database;
use App\Core\Session;
use App\Core\SlowoSnajperConfig;
use App\Security\Authentication\AuthenticationContext;

final class AuthenticationFlowService
{
    private const MAX_2FA_ATTEMPTS = 5;

    private AuthSecurityService $security;
    private AuthService $auth;

    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
        SlowoSnajperConfig $config,
        ?RateLimiterInterface $rateLimiter = null,
    ) {
        $this->security = new AuthSecurityService($db, $config, $rateLimiter);
        $this->auth = new AuthService($db);
        $this->config = $config;
    }

    private readonly SlowoSnajperConfig $config;

    public function begin(array $user, string $method): array
    {
        $method = $this->normalizeMethod($method);
        $userId = (int)($user['id'] ?? 0);
        $email = (string)($user['email'] ?? '');
        if ($userId <= 0 || !in_array((string)($user['status'] ?? ''), ['active', 'pending_author'], true)) {
            throw new \RuntimeException('Konto jest niedostępne.');
        }

        $this->security->recordLoginEvent($userId, $email, $method . '_ok');
        if ($this->security->shouldChallengeLogin($userId)) {
            $this->session->set('_pending_2fa_login', [
                'user_id' => $userId,
                'email' => $email,
                'issued_at' => time(),
                'attempts' => 0,
                'auth_method' => $method,
            ]);
            return ['status' => 'challenge', 'destination' => '/login/2fa'];
        }

        return $this->finish($user, $method, false);
    }

    public function completeTwoFactor(string $code): array
    {
        $pending = $this->session->get('_pending_2fa_login');
        if (!$this->validPendingChallenge($pending)) {
            $this->session->remove('_pending_2fa_login');
            throw new \RuntimeException('Sesja 2FA wygasła. Zaloguj się ponownie.');
        }

        $userId = (int)$pending['user_id'];
        $email = (string)($pending['email'] ?? '');
        $attempts = (int)($pending['attempts'] ?? 0);
        if (!$this->security->verifyLoginTwoFactor($userId, trim($code))) {
            $attempts++;
            $this->security->recordLoginEvent($userId, $email, '2fa_failed');
            if ($attempts >= self::MAX_2FA_ATTEMPTS) {
                $this->session->remove('_pending_2fa_login');
                $this->security->recordLoginEvent($userId, $email, '2fa_locked');
                throw new \RuntimeException('Przekroczono limit prób 2FA. Zaloguj się ponownie.');
            }
            $pending['attempts'] = $attempts;
            $this->session->set('_pending_2fa_login', $pending);
            throw new \InvalidArgumentException('Kod 2FA jest nieprawidłowy.');
        }

        $user = $this->auth->findActiveById($userId);
        if (!$user) {
            $this->session->remove('_pending_2fa_login');
            throw new \RuntimeException('Konto dla sesji 2FA jest niedostępne.');
        }

        $this->session->remove('_pending_2fa_login');
        $this->security->recordLoginEvent($userId, (string)$user['email'], '2fa_ok');
        return $this->finish($user, (string)($pending['auth_method'] ?? 'password'), true);
    }

    public function validPendingChallenge(mixed $pending = null): bool
    {
        $pending ??= $this->session->get('_pending_2fa_login');
        if (!is_array($pending) || empty($pending['user_id']) || empty($pending['issued_at'])) {
            return false;
        }
        return (int)$pending['issued_at'] + $this->pendingTtlSeconds() >= time()
            && (int)($pending['attempts'] ?? 0) < self::MAX_2FA_ATTEMPTS;
    }

    private function finish(array $user, string $method, bool $secondFactorVerified): array
    {
        $userId = (int)$user['id'];
        $role = (string)($user['role'] ?? 'reader');
        $language = trim((string)($user['interface_language'] ?? ''));
        $this->session->login(
            $userId,
            $role,
            $language !== '' ? $language : null,
            (int)($user['session_version'] ?? 0)
        );
        $now = time();
        $factors = [$method];
        if ($secondFactorVerified) {
            $factors[] = 'totp';
        }
        $this->session->setAuthenticationContext(new AuthenticationContext(
            $method,
            $factors,
            $now,
            $secondFactorVerified ? $now : null
        ));
        $this->security->markLoginSuccess($userId);

        $missing = [];
        $destination = match ($role) {
            'admin' => '/admin',
            'commentator' => '/opinie',
            default => '/author',
        };
        if ($this->security->userHasHighRole($userId)) {
            $status = $this->security->userSecurityStatus($userId);
            if (($status['ready_for_high_roles'] ?? false) !== true) {
                $missing = array_values($status['missing'] ?? ['zabezpieczenia konta']);
                $destination = '/account/security';
            }
        }

        return [
            'status' => 'authenticated',
            'destination' => $destination,
            'security_missing' => $missing,
            'method' => $method,
        ];
    }

    private function normalizeMethod(string $method): string
    {
        return in_array($method, ['password', 'google', 'apple'], true) ? $method : 'password';
    }

    private function pendingTtlSeconds(): int
    {
        $all = $this->config->all();
        $ttl = (int)($all['auth']['login_2fa_pending_ttl_seconds'] ?? 600);
        return max(60, min(1800, $ttl));
    }
}
