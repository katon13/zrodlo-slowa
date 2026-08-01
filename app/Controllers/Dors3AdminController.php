<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\RecoveryCodeService;
use App\Services\Dors3OperatorPresenter;
use App\Services\WebAuthnFoundationService;

final class Dors3AdminController extends BaseController
{
    public function index(): string
    {
        $adminId = $this->requireAdmin();
        $settings = $this->dors3Settings()->current();
        $credentials = $this->app->db->all(
            'SELECT public_id,display_name,credential_role,status,tested_at,last_used_at,created_at
             FROM webauthn_credentials WHERE user_id=:user ORDER BY created_at,id',
            ['user' => $adminId]
        );
        $sessions = $this->app->db->all(
            'SELECT id,last_activity FROM sessions
             WHERE user_id=:user AND last_activity>=:minimum
             ORDER BY last_activity DESC LIMIT 50',
            ['user' => $adminId, 'minimum' => time() - (int)$settings['admin_session_max_seconds']]
        );
        foreach ($sessions as &$session) {
            $session['public_id'] = substr(hash('sha256', (string)$session['id']), 0, 16);
            unset($session['id']);
        }
        unset($session);
        $events = $this->app->db->all(
            'SELECT event_id,occurred_at,action,resource_type,resource_id,result,reason,risk_level,authentication_level
             FROM security_events WHERE actor_id=:user ORDER BY occurred_at DESC,id DESC LIMIT 50',
            ['user' => $adminId]
        );
        $lastAlarm = $this->app->db->one(
            'SELECT occurred_at,action,reason,risk_level FROM security_events
             WHERE actor_id=:user AND risk_level IN (\'high\',\'critical\')
               AND result IN (\'failure\',\'blocked\',\'rejected\',\'warning\')
             ORDER BY occurred_at DESC,id DESC LIMIT 1',
            ['user' => $adminId]
        );
        $recovery = $this->recoveryCodes()->status($adminId);
        $gate = $this->dors3Settings()->requiredGate($adminId);
        $operatorCredentials = array_map(
            static fn(array $credential): array => $credential + [
                'role_label' => Dors3OperatorPresenter::credentialRoleLabel((string)($credential['credential_role'] ?? '')),
                'status_label' => Dors3OperatorPresenter::credentialStatusLabel((string)($credential['status'] ?? '')),
            ],
            $credentials
        );

        return $this->view('admin/dors3', [
            'title' => 'Bezpieczeństwo — 3DORS',
            'dors3' => $settings,
            'webauthn' => (new WebAuthnFoundationService(
                $this->app->db,
                is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
            ))->status(),
            'credentials' => $operatorCredentials,
            'sessions' => $sessions,
            'events' => array_map([Dors3OperatorPresenter::class, 'event'], $events),
            'last_alarm' => $lastAlarm !== null ? Dors3OperatorPresenter::event($lastAlarm) : null,
            'recovery' => $recovery,
            'required_gate' => $gate,
            'operator_readiness' => Dors3OperatorPresenter::readiness($gate),
            'operator_mode_label' => Dors3OperatorPresenter::modeLabel((string)($settings['mode'] ?? 'prepare')),
            'operator_confirmation_label' => Dors3OperatorPresenter::confirmationLabel((string)($settings['critical_step_up'] ?? 'password')),
            'new_recovery_codes' => $this->app->session->pullFlash('dors3_recovery_codes'),
            'new_recovery_batch' => $this->app->session->pullFlash('dors3_recovery_batch'),
        ]);
    }

    public function showUnlock(): string
    {
        $this->assertRawAdmin();
        return $this->view('admin/dors3_unlock', [
            'title' => 'Odblokowanie panelu 3DORS',
            'return_path' => $this->safeReturnPath((string)($_GET['return'] ?? '/admin')),
        ]);
    }

    public function unlock(): never
    {
        $adminId = $this->assertRawAdmin();
        $returnPath = $this->safeReturnPath((string)($_POST['return_path'] ?? '/admin'));
        try {
            $this->adminSessionPolicy()->unlock($adminId, (string)($_POST['password'] ?? ''));
            $this->app->session->flash('success', 'Sesja administracyjna została ponownie potwierdzona.');
            redirect($returnPath);
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeError(
                $error,
                'Nie udało się odblokować sesji administracyjnej.',
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
            $this->app->session->flash('success', 'Wygenerowano nowy zestaw 10 kodów. Zapisz je teraz — nie zostaną pokazane ponownie.');
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeError(
                $error,
                'Nie udało się wygenerować kodów odzyskiwania.',
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
                throw new \RuntimeException('Potwierdź, że kody zostały zapisane offline.');
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
            $this->app->session->flash('success', 'Zapisanie kodów odzyskiwania zostało potwierdzone.');
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeError(
                $error,
                'Nie udało się potwierdzić kodów odzyskiwania.',
                'dors3_recovery_confirm'
            ));
        }
        redirect('/admin/security/3dors');
    }

    private function recoveryCodes(): RecoveryCodeService
    {
        return new RecoveryCodeService($this->app->db, $this->securityEvents());
    }

    private function assertRawAdmin(): int
    {
        $adminId = $this->requireAuth();
        if ($this->app->session->role() !== 'admin') {
            http_response_code(403);
            echo $this->view('layouts/error', [
                'title' => 'Brak uprawnień',
                'message' => 'Odblokowanie 3DORS jest dostępne wyłącznie dla administratora.',
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
