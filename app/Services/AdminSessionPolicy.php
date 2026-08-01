<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Session;
use App\Security\Dors3\AdminSessionExpiredException;
use App\Security\Dors3\AdminSessionLockedException;
use App\Security\Dors3\ApprovalContext;
use App\Security\Dors3\ApprovalResponse;

final class AdminSessionPolicy
{
    private const STARTED_AT = '_dors3_admin_started_at';
    private const LAST_ACTIVITY_AT = '_dors3_admin_last_activity_at';
    private const LOCKED_AT = '_dors3_admin_locked_at';

    public function __construct(
        private readonly Session $session,
        private readonly Dors3SettingsService $settings,
        private readonly SecurityEventService $events,
        private readonly PasswordStepUpAuthorizer $passwordAuthorizer,
    ) {}

    public function start(int $adminId): void
    {
        $now = time();
        $this->session->set(self::STARTED_AT, $now);
        $this->session->set(self::LAST_ACTIVITY_AT, $now);
        $this->session->remove(self::LOCKED_AT);
        $this->events->record(
            $adminId,
            'security.admin_session.started',
            'success',
            'medium',
            'session',
            session_id() !== '' ? hash('sha256', session_id()) : 'unknown'
        );
    }

    public function assertAccess(int $adminId): void
    {
        $settings = $this->settings->current();
        $now = time();
        $startedAt = (int)$this->session->get(self::STARTED_AT, 0);
        $lastActivityAt = (int)$this->session->get(self::LAST_ACTIVITY_AT, 0);
        if ($startedAt <= 0 || $lastActivityAt <= 0) {
            $this->start($adminId);
            return;
        }

        if ($startedAt + (int)$settings['admin_session_max_seconds'] < $now) {
            $this->events->record(
                $adminId,
                'security.admin_session.max_expired',
                'blocked',
                'high',
                'session',
                session_id() !== '' ? hash('sha256', session_id()) : 'unknown',
                null,
                null,
                'maximum_session_age_exceeded'
            );
            $this->session->logout();
            throw new AdminSessionExpiredException('Maksymalny czas sesji administracyjnej wygasł. Zaloguj się ponownie.');
        }

        $lockedAt = (int)$this->session->get(self::LOCKED_AT, 0);
        if ($lockedAt > 0 || $lastActivityAt + (int)$settings['admin_idle_timeout_seconds'] < $now) {
            if ($lockedAt <= 0) {
                $this->session->set(self::LOCKED_AT, $now);
                $this->events->record(
                    $adminId,
                    'security.admin_session.idle_locked',
                    'blocked',
                    'medium',
                    'session',
                    session_id() !== '' ? hash('sha256', session_id()) : 'unknown',
                    null,
                    null,
                    'idle_timeout_exceeded'
                );
            }
            throw new AdminSessionLockedException('Panel administracyjny został zablokowany po bezczynności.');
        }

        $this->session->set(self::LAST_ACTIVITY_AT, $now);
    }

    public function unlock(int $adminId, string $password): void
    {
        $context = new ApprovalContext(
            'admin_session.unlock',
            $adminId,
            'session',
            session_id() !== '' ? hash('sha256', session_id()) : 'unknown',
            ['locked_at' => (int)$this->session->get(self::LOCKED_AT, 0)]
        );
        $request = $this->passwordAuthorizer->begin($context);
        $result = $this->passwordAuthorizer->verify(new ApprovalResponse($request, $password));
        if (!$result->approved) {
            throw new \RuntimeException('Nie udało się odblokować sesji administracyjnej.');
        }
        $this->session->remove(self::LOCKED_AT);
        $this->session->set(self::LAST_ACTIVITY_AT, time());
        $this->events->record(
            $adminId,
            'security.admin_session.unlocked',
            'success',
            'medium',
            'session',
            session_id() !== '' ? hash('sha256', session_id()) : 'unknown'
        );
    }

    public function isLocked(): bool
    {
        return (int)$this->session->get(self::LOCKED_AT, 0) > 0;
    }
}
