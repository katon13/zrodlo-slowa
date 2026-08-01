<?php
namespace App\Controllers;

use App\Services\AuthSecurityService;

final class AccountSecurityController extends BaseController
{
    public function show(): string
    {
        $userId = $this->requireAuth();
        $service = $this->security();
        return $this->view('account/security', [
            'title' => 'Bezpieczeństwo konta',
            'security' => $service->userSecurityStatus($userId),
            'secret' => $service->currentTwoFactorSecret($userId),
            'otpauth_uri' => $service->otpauthUri($userId),
        ]);
    }

    public function sendEmailVerification(): never
    {
        $userId = $this->requireAuth();
        try {
            $link = $this->security()
                ->queueEmailVerification($userId, (string)$this->app->config['app']['url']);
            $message = 'Wiadomość potwierdzająca e-mail została dodana do kolejki.';
            if (($this->app->config['app']['env'] ?? '') === 'local' && ($this->app->config['app']['debug'] ?? false)) {
                $message .= ' Lokalny link testowy: ' . $link;
            }
            $this->app->session->flash('success', $message);
        } catch (\Throwable $e) {
            error_log('Email verification queue failed: ' . $e->getMessage());
            $this->app->session->flash('error', 'Nie udało się przygotować wiadomości potwierdzającej.');
        }
        redirect(public_language_url(public_language(), '/account/security'));
    }

    public function verifyEmail(): never
    {
        try {
            $this->security()->verifyEmailToken((string)($_GET['token'] ?? ''));
            $this->app->session->flash('success', 'E-mail został potwierdzony.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się potwierdzić adresu e-mail.', 'email_verification'));
        }
        redirect(public_language_url(public_language(), '/account/security'));
    }

    public function start2fa(): never
    {
        $userId = $this->requireAuth();
        try {
            $this->security()->startTwoFactorSetup($userId);
            $this->app->session->flash('success', 'Wygenerowano sekret 2FA. Przepisz go do aplikacji i potwierdź kodem.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się rozpocząć konfiguracji 2FA.', '2fa_setup'));
        }
        redirect(public_language_url(public_language(), '/account/security'));
    }

    public function enable2fa(): never
    {
        $userId = $this->requireAuth();
        try {
            $this->security()->enableTwoFactor($userId, (string)($_POST['code'] ?? ''));
            $this->app->session->flash('success', '2FA zostało aktywowane.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się aktywować 2FA.', '2fa_enable'));
        }
        redirect(public_language_url(public_language(), '/account/security'));
    }

    private function security(): AuthSecurityService
    {
        return new AuthSecurityService(
            $this->app->db,
            $this->slowoSnajperConfig(),
            $this->app->rateLimiter,
            $this->app->queueSignals
        );
    }
}
