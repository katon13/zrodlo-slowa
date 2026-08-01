<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\RequestContext;

final class Dors3LoginSecurityService
{
    private const FAILURE_LIMIT = 5;
    private const WINDOW_SECONDS = 900;

    public function __construct(
        private readonly Database $db,
        private readonly SecurityEventService $events,
    ) {}

    public function assertAllowed(string $identifier): void
    {
        $admin = $this->adminByIdentifier($identifier);
        if ($admin === null) {
            return;
        }
        $lock = $this->db->one(
            'SELECT locked_until,series_count FROM security_admin_login_locks WHERE user_id=:user',
            ['user' => (int)$admin['id']]
        );
        if ($lock === null || empty($lock['locked_until']) || strtotime((string)$lock['locked_until'] . ' UTC') <= time()) {
            return;
        }
        $this->events->record(
            (int)$admin['id'],
            'security.login.blocked',
            'blocked',
            'high',
            'user',
            (string)$admin['id'],
            null,
            null,
            'admin_login_lock_active',
            null,
            ['locked_until' => (string)$lock['locked_until'], 'series_count' => (int)$lock['series_count']]
        );
        throw new \RuntimeException('Logowanie administracyjne jest czasowo zablokowane po błędnych próbach.');
    }

    public function recordFailure(string $identifier): void
    {
        $admin = $this->adminByIdentifier($identifier);
        if ($admin === null) {
            return;
        }
        $userId = (int)$admin['id'];
        $outcome = $this->db->transaction(function (Database $db) use ($userId): array {
            $row = $db->one(
                'SELECT * FROM security_admin_login_locks WHERE user_id=:user FOR UPDATE',
                ['user' => $userId]
            );
            if ($row === null) {
                $db->query(
                    'INSERT INTO security_admin_login_locks(user_id,failure_count,series_count,window_started_at,updated_at)
                     VALUES(:user,0,0,NOW(),NOW())',
                    ['user' => $userId]
                );
                $row = [
                    'failure_count' => 0,
                    'series_count' => 0,
                    'window_started_at' => gmdate('Y-m-d H:i:s'),
                    'locked_until' => null,
                ];
            }

            $failureCount = (int)$row['failure_count'];
            $seriesCount = (int)$row['series_count'];
            $windowStarted = !empty($row['window_started_at'])
                ? strtotime((string)$row['window_started_at'] . ' UTC')
                : false;
            $currentlyLocked = !empty($row['locked_until'])
                && strtotime((string)$row['locked_until'] . ' UTC') > time();
            if ($currentlyLocked) {
                return ['locked' => true, 'failure_count' => $failureCount, 'series_count' => $seriesCount];
            }
            if ($windowStarted === false || $windowStarted + self::WINDOW_SECONDS < time()) {
                $failureCount = 0;
                $db->query(
                    'UPDATE security_admin_login_locks SET window_started_at=NOW(),locked_until=NULL WHERE user_id=:user',
                    ['user' => $userId]
                );
            }

            $failureCount++;
            $lockedUntil = null;
            if ($failureCount >= self::FAILURE_LIMIT) {
                $lockSeconds = min(self::WINDOW_SECONDS * (2 ** min($seriesCount, 4)), 21600);
                $seriesCount++;
                $failureCount = 0;
                $lockedUntil = time() + $lockSeconds;
            }
            $db->query(
                'UPDATE security_admin_login_locks
                 SET failure_count=:failures,series_count=:series,locked_until=:locked_until,updated_at=NOW()
                 WHERE user_id=:user',
                [
                    'user' => $userId,
                    'failures' => $failureCount,
                    'series' => $seriesCount,
                    'locked_until' => $lockedUntil !== null ? gmdate('Y-m-d H:i:s', $lockedUntil) : null,
                ]
            );
            return [
                'locked' => $lockedUntil !== null,
                'locked_until' => $lockedUntil,
                'failure_count' => $failureCount,
                'series_count' => $seriesCount,
            ];
        });

        $this->events->record(
            $userId,
            !empty($outcome['locked']) ? 'security.login.locked' : 'security.login.password_failed',
            !empty($outcome['locked']) ? 'blocked' : 'failure',
            !empty($outcome['locked']) ? 'high' : 'medium',
            'user',
            (string)$userId,
            null,
            null,
            !empty($outcome['locked']) ? 'five_failed_attempts' : 'invalid_password',
            null,
            $outcome
        );
    }

    public function recordSuccess(int $userId): void
    {
        if (!$this->isAdmin($userId)) {
            return;
        }
        $ip = RequestContext::ipAddress();
        $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $knownIp = $ip !== null && (int)$this->db->cell(
            'SELECT COUNT(*) FROM security_events
             WHERE actor_id=:user AND action=\'security.login.success\' AND ip=:ip',
            ['user' => $userId, 'ip' => $ip]
        ) > 0;
        $knownDevice = $userAgent !== '' && (int)$this->db->cell(
            'SELECT COUNT(*) FROM security_events
             WHERE actor_id=:user AND action=\'security.login.success\' AND user_agent=:user_agent',
            ['user' => $userId, 'user_agent' => mb_substr($userAgent, 0, 2048)]
        ) > 0;

        $this->db->query('DELETE FROM security_admin_login_locks WHERE user_id=:user', ['user' => $userId]);
        $this->events->record(
            $userId,
            'security.login.success',
            'success',
            (!$knownIp || !$knownDevice) ? 'medium' : 'low',
            'user',
            (string)$userId,
            null,
            null,
            null,
            null,
            [
                'method' => 'password',
                'new_ip' => !$knownIp,
                'new_device' => !$knownDevice,
            ]
        );
        if (!$knownIp || !$knownDevice) {
            $this->events->record(
                $userId,
                'security.login.new_context',
                'warning',
                'medium',
                'user',
                (string)$userId,
                null,
                null,
                !$knownIp && !$knownDevice ? 'new_ip_and_device' : (!$knownIp ? 'new_ip' : 'new_device')
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function adminByIdentifier(string $identifier): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }
        return $this->db->one(
            'SELECT u.id,u.email,u.login_name
             FROM users u
             JOIN user_roles ur ON ur.user_id=u.id AND ur.role=\'admin\'
             WHERE u.status=\'active\'
               AND (LOWER(u.email)=:identifier OR LOWER(COALESCE(u.login_name,\'\'))=:identifier)
             LIMIT 1',
            ['identifier' => $identifier]
        );
    }

    private function isAdmin(int $userId): bool
    {
        return (int)$this->db->cell(
            'SELECT COUNT(*) FROM user_roles WHERE user_id=:user AND role=\'admin\'',
            ['user' => $userId]
        ) > 0;
    }
}
