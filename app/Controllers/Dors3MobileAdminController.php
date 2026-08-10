<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Dors3MobileException;
use App\Services\Dors3MobileService;
use App\Services\Dors3UiText;
use App\Services\SecretCipher;

final class Dors3MobileAdminController extends BaseController
{
    public function startEnrollment(): string
    {
        $adminId = $this->requireAdmin();
        try {
            $result = $this->service()->startEnrollment(
                $adminId,
                (int)($_POST['user_id'] ?? $adminId),
                trim((string)($_POST['application_variant'] ?? 'admin')),
                (string)($_POST['current_password'] ?? ''),
            );
            if ($this->wantsJson()) {
                return $this->jsonResponse($result);
            }
            $this->app->session->flash('dors3_mobile_enrollment', $result);
            $this->app->session->flash('success', Dors3UiText::get('messages.enrollment_started'));
        } catch (\Throwable $error) {
            if ($this->wantsJson()) {
                return $this->errorResponse($error);
            }
            $this->app->session->flash('error', $error instanceof Dors3MobileException
                ? $this->localizedMobileError($error)
                : Dors3UiText::get('messages.enrollment_start_failed'));
        }
        redirect('/admin/security/3dors');
    }

    public function suspend(): never
    {
        $this->changeStatus('suspended');
    }

    public function revoke(): never
    {
        $this->changeStatus('revoked');
    }

    public function resume(): never
    {
        $this->changeStatus('active');
    }

    public function markLost(): never
    {
        $this->changeStatus('lost');
    }

    public function cancelEnrollment(): never
    {
        $adminId = $this->requireAdmin();
        $enrollmentPublicId = trim((string)($_GET['enrollment_public_id'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? t('controller.dors3mobileadmin.anulowanie_przez_administratora')));
        try {
            $this->authorizeCriticalOperation(
                $adminId,
                'mobile.enrollment.cancel',
                'mobile_enrollment',
                $enrollmentPublicId,
                ['reason' => $reason],
            );
            $this->service()->cancelEnrollment($adminId, $enrollmentPublicId, $reason);
            $this->app->session->flash('success', Dors3UiText::get('messages.enrollment_cancelled'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $error instanceof Dors3MobileException
                ? $this->localizedMobileError($error)
                : Dors3UiText::get('messages.enrollment_cancel_failed'));
        }
        redirect('/admin/security/3dors');
    }

    public function approveEnrollment(): never
    {
        $adminId = $this->requireAdmin();
        $enrollmentPublicId = trim((string)($_GET['enrollment_public_id'] ?? ''));
        $comparisonCode = trim((string)($_POST['comparison_code'] ?? ''));
        try {
            $this->authorizeCriticalOperation(
                $adminId,
                'mobile.enrollment.approve',
                'mobile_enrollment',
                $enrollmentPublicId,
                ['comparison_code_verified' => true],
            );
            $this->service()->approveEnrollment($adminId, $enrollmentPublicId, $comparisonCode);
            $this->app->session->flash('success', Dors3UiText::get('messages.enrollment_approved'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $error instanceof Dors3MobileException
                ? $this->localizedMobileError($error)
                : Dors3UiText::get('messages.enrollment_approve_failed'));
        }
        redirect('/admin/security/3dors');
    }

    private function changeStatus(string $status): never
    {
        $adminId = $this->requireAdmin();
        $devicePublicId = trim((string)($_GET['device_public_id'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? t('controller.dors3mobileadmin.decyzja_administratora')));
        try {
            $this->authorizeCriticalOperation(
                $adminId,
                'mobile.device.' . $status,
                'mobile_device',
                $devicePublicId,
                ['status' => $status, 'reason' => $reason],
            );
            $this->service()->changeDeviceStatus($adminId, $devicePublicId, $status, $reason);
            $this->app->session->flash('success', Dors3UiText::get('messages.device_status_changed'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $error instanceof Dors3MobileException
                ? $this->localizedMobileError($error)
                : Dors3UiText::get('messages.device_status_change_failed'));
        }
        redirect('/admin/security/3dors');
    }

    /** @param array<string,mixed> $data */
    private function jsonResponse(array $data, int $status = 200): string
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function errorResponse(\Throwable $error): string
    {
        $status = $error instanceof Dors3MobileException ? $error->httpStatus : 500;
        $code = $error instanceof Dors3MobileException ? $error->errorCode : 'internal_error';
        $message = $error instanceof Dors3MobileException
            ? $this->localizedMobileError($error)
            : Dors3UiText::get('messages.operation_failed');
        return $this->jsonResponse(['error' => $code, 'message' => $message], $status);
    }

    private function localizedMobileError(Dors3MobileException $error): string
    {
        if ($error->errorCode === 'rate_limited' || $error->httpStatus === 429) {
            return Dors3UiText::get('messages.rate_limited');
        }
        if (str_contains($error->errorCode, 'expired') || $error->httpStatus === 410) {
            return Dors3UiText::get('messages.expired');
        }
        if ($error->httpStatus === 404) {
            return Dors3UiText::get('messages.not_found');
        }
        if ($error->httpStatus === 409) {
            return Dors3UiText::get('messages.conflict');
        }
        if ($error->httpStatus === 401 || $error->httpStatus === 403) {
            return Dors3UiText::get('messages.not_authorized');
        }
        return Dors3UiText::get('messages.invalid_request');
    }

    private function wantsJson(): bool
    {
        return str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json') || $this->isAjax();
    }

    private function service(): Dors3MobileService
    {
        return new Dors3MobileService(
            $this->app->db,
            SecretCipher::fromEnvironment(),
            $this->securityEvents(),
            is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
        );
    }
}
