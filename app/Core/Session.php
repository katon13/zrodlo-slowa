<?php
namespace App\Core;

use App\Security\Authentication\AuthenticationContext;

final class Session
{
    public function userId(): ?int { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
    public function role(): ?string { return $_SESSION['role'] ?? null; }
    public function language(): ?string { return $_SESSION['interface_language'] ?? null; }
    public function authenticationContext(): ?AuthenticationContext
    {
        $value = $_SESSION['_authentication_context'] ?? null;
        return is_array($value) ? AuthenticationContext::fromArray($value) : null;
    }
    public function setAuthenticationContext(AuthenticationContext $context): void
    {
        $_SESSION['_authentication_context'] = $context->toArray();
    }
    public function hasRecentStrongAuthentication(int $maxAgeSeconds = 600): bool
    {
        return $this->authenticationContext()?->satisfiesStepUp($maxAgeSeconds) ?? false;
    }
    public function set(string $key, mixed $value): void { $_SESSION[$key] = $value; }
    public function get(string $key, mixed $default = null): mixed { return $_SESSION[$key] ?? $default; }
    public function remove(string $key): void { unset($_SESSION[$key]); }
    public function login(int $userId, string $role, ?string $lang = null, int $sessionVersion = 0): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['role'] = $role;
        $_SESSION['_session_version'] = $sessionVersion;
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        if ($lang) {
            $_SESSION['interface_language'] = $lang;
        }
    }
    public function logout(): void {
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $params = session_get_cookie_params();
        session_destroy();
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool)$params['secure'],
                'httponly' => (bool)$params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }
    }
    public function resetAnonymous(): void {
        $this->logout();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    public function flash(string $key, mixed $value): void { $_SESSION['_flash'][$key] = $value; }
    public function pullFlash(string $key): mixed { $v = $_SESSION['_flash'][$key] ?? null; unset($_SESSION['_flash'][$key]); return $v; }
}
