<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Security\Dors3\MobileEnrollmentQrCode;
use App\Services\RecoveryCodeService;
use App\Services\Dors3OperatorPresenter;
use App\Services\WebAuthnFoundationService;
use App\Services\Dors3MobileService;
use App\Services\Dors3UiText;
use App\Services\AuthorAgreementService;
use App\Services\SecretCipher;
use App\Services\AdminSecurityPanelService;
use App\Services\AuthSecurityService;

final class Dors3AdminController extends BaseController
{
    public function index(): string
    {
        $adminId = $this->requireAdmin();
        $settings = $this->dors3Settings()->current();
        $panelData = (new AdminSecurityPanelService($this->app->db))->configurationSnapshot($adminId);
        $recovery = $this->recoveryCodes()->status($adminId);
        $gate = $this->dors3Settings()->requiredGate($adminId);
        $operatorCredentials = array_map(
            static fn(array $credential): array => $credential + [
                'role_label' => Dors3OperatorPresenter::credentialRoleLabel((string)($credential['credential_role'] ?? '')),
                'status_label' => Dors3OperatorPresenter::credentialStatusLabel((string)($credential['status'] ?? '')),
            ],
            $panelData['credentials']
        );
        $mobileDevices = [];
        $mobilePending = [];
        $mobileEnrollments = [];
        $mobileAdminCandidates = [];
        $mobileAuthorCandidates = [];
        $mobileUserQuery = mb_substr(trim((string)($_GET['mobile_user_query'] ?? '')), 0, 120);
        if ($this->app->db->tableExists('security_mobile_devices')) {
            $mobile = new Dors3MobileService(
                $this->app->db,
                SecretCipher::fromEnvironment(),
                $this->securityEvents(),
                is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
            );
            $mobileDevices = $mobile->devices();
            $mobilePending = $mobile->pendingApprovals();
            $mobileEnrollments = $mobile->pendingEnrollments();
            if ($this->app->db->tableExists('author_agreements')) {
                $agreements = new AuthorAgreementService($this->app->db);
                $mobileAdminCandidates = $agreements->searchEligible('admin', $mobileUserQuery);
                $mobileAuthorCandidates = $agreements->searchEligible('author', $mobileUserQuery);
            }
        }
        $newMobileEnrollment = $this->app->session->pullFlash('dors3_mobile_enrollment');
        if (is_array($newMobileEnrollment) && is_array($newMobileEnrollment['qr_payload'] ?? null)) {
            try {
                $newMobileEnrollment['qr_data_uri'] = MobileEnrollmentQrCode::dataUri($newMobileEnrollment['qr_payload']);
            } catch (\Throwable $error) {
                error_log('[dors3_mobile_enrollment_qr] generation failed: ' . $error::class);
            }
        }
        $authSecurity = $this->authSecurity();
        $accountSecurity = $authSecurity->userSecurityStatus($adminId);
        $accountSecuritySecret = $authSecurity->currentTwoFactorSecret($adminId);

        return $this->view('admin/dors3', [
            'title' => Dors3UiText::get('admin_messages.panel_title'),
            'dors3' => $settings,
            'webauthn' => (new WebAuthnFoundationService(
                $this->app->db,
                is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
            ))->status(),
            'credentials' => $operatorCredentials,
            'role_summary' => $panelData['role_summary'],
            'recovery' => $recovery,
            'required_gate' => $gate,
            'operator_readiness' => Dors3OperatorPresenter::readiness($gate),
            'operator_mode_label' => Dors3OperatorPresenter::modeLabel((string)($settings['mode'] ?? 'prepare')),
            'operator_confirmation_label' => Dors3OperatorPresenter::confirmationLabel((string)($settings['critical_step_up'] ?? 'password')),
            'new_recovery_codes' => $this->app->session->pullFlash('dors3_recovery_codes'),
            'new_recovery_batch' => $this->app->session->pullFlash('dors3_recovery_batch'),
            'mobile_devices' => $mobileDevices,
            'mobile_pending' => $mobilePending,
            'mobile_enrollments' => $mobileEnrollments,
            'mobile_admin_candidates' => $mobileAdminCandidates,
            'mobile_author_candidates' => $mobileAuthorCandidates,
            'mobile_user_query' => $mobileUserQuery,
            'mobile_config' => is_array($this->app->config['dors3']['mobile'] ?? null)
                ? $this->app->config['dors3']['mobile']
                : [],
            'new_mobile_enrollment' => $newMobileEnrollment,
            'account_security' => $accountSecurity,
            'account_security_secret' => $accountSecuritySecret,
            'account_security_otpauth_uri' => $authSecurity->otpauthUri($adminId),
        ]);
    }

    public function showUnlock(): string
    {
        $this->assertRawAdmin();
        return $this->view('admin/dors3_unlock', [
            'title' => Dors3UiText::get('admin_messages.unlock_page_title'),
            'return_path' => $this->safeReturnPath((string)($_GET['return'] ?? '/admin')),
        ]);
    }

    public function unlock(): never
    {
        $adminId = $this->assertRawAdmin();
        $returnPath = $this->safeReturnPath((string)($_POST['return_path'] ?? '/admin'));
        try {
            $this->adminSessionPolicy()->unlock($adminId, (string)($_POST['password'] ?? ''));
            $this->app->session->flash('success', Dors3UiText::get('admin_messages.unlock_success'));
            redirect($returnPath);
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeError(
                $error,
                Dors3UiText::get('admin_messages.unlock_failed'),
                'dors3_session_unlock'
            ));
            redirect('/admin/security/unlock?return=' . urlencode($returnPath));
        }
    }

    public function generateRecoveryCodes(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $before = $this->recoveryCodes()->status($adminId);
            $this->authorizeCriticalOperation(
                $adminId,
                'recovery_codes.generate',
                'user',
                (string)$adminId,
                ['count' => 10, 'replaces_existing' => true],
                $before,
                ['active' => 10, 'confirmed' => 0],
            );
            $generated = $this->recoveryCodes()->generate($adminId);
            $this->app->session->flash('dors3_recovery_codes', $generated['codes']);
            $this->app->session->flash('dors3_recovery_batch', $generated['batch_public_id']);
            $this->app->session->flash('success', Dors3UiText::get('admin_messages.recovery_generated'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeError(
                $error,
                Dors3UiText::get('admin_messages.recovery_generate_failed'),
                'dors3_recovery_generate'
            ));
        }
        redirect('/admin/security/3dors');
    }

    public function confirmRecoveryCodes(): never
    {
        $adminId = $this->requireAdmin();
        $batch = trim((string)($_POST['batch_public_id'] ?? ''));
        try {
            if (($_POST['saved_confirmation'] ?? '') !== 'yes') {
                throw new \RuntimeException(Dors3UiText::get('admin_messages.recovery_saved_required'));
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'recovery_codes.confirm',
                'recovery_code_batch',
                $batch,
                ['saved_offline' => true],
                ['confirmed' => false],
                ['confirmed' => true],
            );
            $this->recoveryCodes()->confirmSaved($adminId, $batch);
            $this->app->session->flash('success', Dors3UiText::get('admin_messages.recovery_confirmed'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeError(
                $error,
                Dors3UiText::get('admin_messages.recovery_confirm_failed'),
                'dors3_recovery_confirm'
            ));
        }
        redirect('/admin/security/3dors');
    }

    private function recoveryCodes(): RecoveryCodeService
    {
        return new RecoveryCodeService($this->app->db, $this->securityEvents());
    }

    private function authSecurity(): AuthSecurityService
    {
        return new AuthSecurityService(
            $this->app->db,
            $this->slowoSnajperConfig(),
            $this->app->rateLimiter,
            $this->app->queueSignals,
        );
    }

    private function assertRawAdmin(): int
    {
        $adminId = $this->requireAuth();
        if ($this->app->session->role() !== 'admin') {
            http_response_code(403);
            echo $this->view('layouts/error', [
                'title' => Dors3UiText::get('admin_messages.forbidden_title'),
                'message' => Dors3UiText::get('admin_messages.forbidden_message'),
            ]);
            exit;
        }
        return $adminId;
    }

    private function safeReturnPath(string $path): string
    {
        $path = trim($path);
        if (!str_starts_with($path, '/admin') || str_starts_with($path, '//') || str_contains($path, "\r") || str_contains($path, "\n")) {
            return '/admin';
        }
        return $path;
    }
}
