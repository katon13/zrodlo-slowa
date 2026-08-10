<?php
declare(strict_types=1);

namespace App\Controllers;

/** Kanoniczny, pozbawiony treści HTML stan sesji dla aplikacji Źródło Słowa Mobile. */
final class MobileSessionController extends BaseController
{
    public function show(): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header_remove('Set-Cookie');

        $userId = $this->app->session->userId();
        if ($userId === null) {
            $this->anonymous();
        }

        $user = $this->app->db->one(
            'SELECT id,status,can_write,wallet_enabled,payout_enabled,session_version
             FROM users WHERE id=:id LIMIT 1',
            ['id' => $userId]
        );
        if (
            $user === null
            || !in_array((string)$user['status'], ['active', 'pending_author'], true)
            || (int)$user['session_version'] !== (int)$this->app->session->get('_session_version', -1)
        ) {
            $this->anonymous();
        }

        $sessionId = session_id();
        $lastActivity = $this->lastActivity($sessionId, $userId);
        if ($lastActivity === null) {
            $this->anonymous();
        }
        $sessionTtl = max(300, (int)($this->app->config['valkey']['session_ttl_seconds'] ?? 86400));
        $sessionExpiresAt = $lastActivity + $sessionTtl;
        if ($sessionExpiresAt <= time()) {
            $this->anonymous();
        }
        $generation = $this->generation($sessionId, (int)$user['session_version']);

        $roles = array_values(array_unique(array_map(
            static fn(array $row): string => (string)$row['role'],
            $this->app->db->all('SELECT role FROM user_roles WHERE user_id=:id ORDER BY role', ['id' => $userId])
        )));

        $this->json([
            'ok' => true,
            'authenticated' => true,
            'session' => [
                'generation' => $generation,
                'version' => (int)$user['session_version'],
                'session_expires_at' => $sessionExpiresAt,
            ],
            'user' => [
                'id' => $userId,
                'primary_role' => (string)($this->app->session->role() ?? 'reader'),
                'roles' => $roles,
                'can_write' => (int)$user['can_write'] === 1,
                'wallet_enabled' => (int)$user['wallet_enabled'] === 1,
                'payout_enabled' => (int)$user['payout_enabled'] === 1,
            ],
        ]);
    }

    private function generation(string $sessionId, int $sessionVersion): string
    {
        $key = (string)env('APP_KEY', '');
        if ($key === '') {
            $key = (string)env('PASSWORD_PEPPER', '');
        }
        $message = 'mobile-session|' . $sessionId . '|' . $sessionVersion;
        $digest = $key !== '' ? hash_hmac('sha256', $message, $key) : hash('sha256', $message);
        return substr($digest, 0, 32);
    }

    private function lastActivity(string $sessionId, int $userId): ?int
    {
        if ($sessionId === '' || preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $sessionId) !== 1) {
            return null;
        }
        $driver = (string)($this->app->config['valkey']['session_driver'] ?? 'file');
        if ($driver === 'valkey') {
            $value = $this->app->db->cell(
                'SELECT last_activity FROM sessions WHERE id=:id AND user_id=:user LIMIT 1',
                ['id' => $sessionId, 'user' => $userId]
            );
            return is_numeric($value) ? (int)$value : null;
        }

        $savePath = session_save_path();
        if (str_contains($savePath, ';')) {
            $savePath = (string)substr($savePath, strrpos($savePath, ';') + 1);
        }
        $directory = realpath($savePath);
        $file = $directory !== false ? realpath($directory . DIRECTORY_SEPARATOR . 'sess_' . $sessionId) : false;
        if ($directory === false || $file === false || strcasecmp(dirname($file), $directory) !== 0) {
            return null;
        }
        $modifiedAt = @filemtime($file);
        return is_int($modifiedAt) ? $modifiedAt : null;
    }

    private function anonymous(): never
    {
        $this->json([
            'ok' => true,
            'authenticated' => false,
            'session' => null,
            'user' => null,
        ]);
    }
}
