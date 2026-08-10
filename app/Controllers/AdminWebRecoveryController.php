<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AdminWebRecoveryService;
use App\Services\AuthSecurityService;
use App\Services\Dors3MobileException;
use App\Services\Dors3MobileService;
use App\Services\Dors3UiText;
use App\Services\MailService;
use App\Services\RecoveryCodeService;
use App\Services\SecretCipher;

final class AdminWebRecoveryController extends BaseController
{
    private const SESSION_KEY = '_admin_recovery_capability';

    public function show(): string
    {
        if ($this->app->session->userId() !== null) {
            redirect('/admin/security/3dors');
        }

        $state = null;
        $capability = $this->capabilityPublicId();
        if ($capability !== '') {
            try {
                $state = $this->recovery()->state($capability, $this->sessionBinding());
            } catch (\Throwable) {
                $this->app->session->remove(self::SESSION_KEY);
                $this->app->session->flash('error', Dors3UiText::get('recovery_web.expired'));
            }
        }

        $enrollment = $this->app->session->pullFlash('admin_recovery_enrollment');
        $codes = $this->app->session->pullFlash('admin_recovery_codes');
        return $this->view('auth/admin_recovery', [
            'title' => Dors3UiText::get('recovery_web.page_title'),
            'state' => $state,
            'enrollment' => is_array($enrollment) ? $enrollment : null,
            'recovery_codes' => is_array($codes) ? $codes : null,
        ]);
    }

    public function start(): never
    {
        if ($this->app->session->userId() !== null) {
            redirect('/admin/security/3dors');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        try {
            $result = $this->recovery()->begin(
                (string)($_POST['identifier'] ?? ''),
                (string)($_POST['password'] ?? ''),
                (string)($_POST['recovery_code'] ?? ''),
                $this->sessionBinding(),
            );
            $this->app->session->set(self::SESSION_KEY, (string)$result['capability_public_id']);
            $this->app->session->flash('success', Dors3UiText::get('recovery_web.started'));
        } catch (\Throwable $error) {
            $this->app->session->remove(self::SESSION_KEY);
            $this->app->session->flash('error', $this->safeRecoveryMessage($error));
        }
        redirect('/security/recovery');
    }

    public function startEnrollment(): never
    {
        try {
            $capability = $this->requireCapability();
            $result = $this->mobile()->startEnrollment(
                (int)$capability['user_id'],
                (int)$capability['user_id'],
                'admin',
                (string)($_POST['current_password'] ?? ''),
            );
            $this->app->session->flash('admin_recovery_enrollment', $result);
            $this->app->session->flash('success', Dors3UiText::get('recovery_web.enrollment_started'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeRecoveryMessage($error));
        }
        redirect('/security/recovery');
    }

    public function approveEnrollment(): never
    {
        try {
            $capability = $this->requireCapability();
            $enrollmentPublicId = trim((string)($_GET['enrollment_public_id'] ?? ''));
            $this->recovery()->assertOwnAdminEnrollment(
                $this->capabilityPublicId(),
                $this->sessionBinding(),
                $enrollmentPublicId,
            );
            $this->mobile()->approveEnrollment(
                (int)$capability['user_id'],
                $enrollmentPublicId,
                trim((string)($_POST['comparison_code'] ?? '')),
            );
            $this->app->session->flash('success', Dors3UiText::get('recovery_web.enrollment_approved'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeRecoveryMessage($error));
        }
        redirect('/security/recovery');
    }

    public function cancelEnrollment(): never
    {
        try {
            $capability = $this->requireCapability();
            $enrollmentPublicId = trim((string)($_GET['enrollment_public_id'] ?? ''));
            $this->recovery()->assertOwnAdminEnrollment(
                $this->capabilityPublicId(),
                $this->sessionBinding(),
                $enrollmentPublicId,
            );
            $this->mobile()->cancelEnrollment(
                (int)$capability['user_id'],
                $enrollmentPublicId,
                'limited_web_recovery_cancelled',
            );
            $this->app->session->flash('success', Dors3UiText::get('recovery_web.enrollment_cancelled'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeRecoveryMessage($error));
        }
        redirect('/security/recovery');
    }

    public function generateCodes(): never
    {
        try {
            $result = $this->recovery()->generateRecoveryCodes(
                $this->capabilityPublicId(),
                $this->sessionBinding(),
            );
            $this->app->session->flash('admin_recovery_codes', $result);
            $this->app->session->flash('success', Dors3UiText::get('recovery_web.codes_generated'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeRecoveryMessage($error));
        }
        redirect('/security/recovery');
    }

    public function confirmCodes(): never
    {
        try {
            if ((string)($_POST['codes_saved'] ?? '') !== '1') {
                throw new \RuntimeException(Dors3UiText::get('recovery_web.codes_confirmation_required'));
            }
            $this->recovery()->confirmRecoveryCodes(
                $this->capabilityPublicId(),
                $this->sessionBinding(),
                trim((string)($_POST['batch_public_id'] ?? '')),
            );
            $this->app->session->flash('success', Dors3UiText::get('recovery_web.codes_confirmed'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeRecoveryMessage($error));
        }
        redirect('/security/recovery');
    }

    public function finish(): never
    {
        try {
            $this->recovery()->finish($this->capabilityPublicId(), $this->sessionBinding());
            $this->app->session->resetAnonymous();
            $this->app->session->flash('success', Dors3UiText::get('recovery_web.finished'));
            redirect('/login');
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeRecoveryMessage($error));
            redirect('/security/recovery');
        }
    }

    /** @return array<string,mixed> */
    private function requireCapability(): array
    {
        return $this->recovery()->assertCapability($this->capabilityPublicId(), $this->sessionBinding());
    }

    private function recovery(): AdminWebRecoveryService
    {
        $events = $this->securityEvents();
        return new AdminWebRecoveryService(
            $this->app->db,
            new RecoveryCodeService($this->app->db, $events),
            $events,
            new AuthSecurityService(
                $this->app->db,
                $this->slowoSnajperConfig(),
                $this->app->rateLimiter,
                $this->app->queueSignals,
            ),
            new MailService($this->app->db, $this->app->queueSignals),
        );
    }

    private function mobile(): Dors3MobileService
    {
        return new Dors3MobileService(
            $this->app->db,
            SecretCipher::fromEnvironment(),
            $this->securityEvents(),
            is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
        );
    }

    private function capabilityPublicId(): string
    {
        return trim((string)$this->app->session->get(self::SESSION_KEY, ''));
    }

    private function sessionBinding(): string
    {
        $sessionId = session_id();
        if ($sessionId === '') {
            throw new \RuntimeException(Dors3UiText::get('recovery_web.expired'));
        }
        $key = (string)env('APP_KEY', '');
        if ($key === '') {
            $key = (string)env('PASSWORD_PEPPER', '');
        }
        return $key !== ''
            ? hash_hmac('sha256', 'admin-web-recovery|' . $sessionId, $key)
            : hash('sha256', 'admin-web-recovery|' . $sessionId);
    }

    private function safeRecoveryMessage(\Throwable $error): string
    {
        if ($error instanceof Dors3MobileException) {
            return match ($error->errorCode) {
                'reauthentication_failed' => Dors3UiText::get('recovery_web.password_invalid'),
                'too_many_pending', 'rate_limited' => Dors3UiText::get('recovery_web.rate_limited'),
                default => Dors3UiText::get('recovery_web.operation_failed'),
            };
        }
        $message = trim($error->getMessage());
        $allowed = [
            Dors3UiText::get('recovery_web.invalid_credentials'),
            Dors3UiText::get('recovery_web.expired'),
            Dors3UiText::get('recovery_web.rate_limited'),
            Dors3UiText::get('recovery_web.codes_confirmation_required'),
            Dors3UiText::get('recovery_web.finish_requires_codes'),
            Dors3UiText::get('recovery_web.finish_requires_device'),
        ];
        return in_array($message, $allowed, true)
            ? $message
            : Dors3UiText::get('recovery_web.operation_failed');
    }
}
