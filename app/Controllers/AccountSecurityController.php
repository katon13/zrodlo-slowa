<?php
namespace App\Controllers;

use App\Services\AuthSecurityService;

final class AccountSecurityController extends BaseController
{
    public function show(): string
    {
        $userId = $this->requireAuth();
        // Dla administratora nie utrzymujemy drugiego, konkurencyjnego panelu.
        // Widok osobisty pozostaje dla czytelnika/autora, którzy nie mają dostępu
        // do administracyjnego centrum bezpieczeństwa.
        if ($this->app->session->role() === 'admin') {
            redirect('/admin/security/3dors');
        }
        $service = $this->security();
        return $this->view('account/security', [
            'title' => t('ui.account.security.bezpieczenstwo_konta_2'),
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
            $message = t('controller.accountsecurity.wiadomosc_potwierdzajaca_e_mail_zostaa_dodana_do_kolejki');
            if (($this->app->config['app']['env'] ?? '') === 'local' && ($this->app->config['app']['debug'] ?? false)) {
                $message .= ' Lokalny link testowy: ' . $link;
            }
            $this->app->session->flash('success', $message);
        } catch (\Throwable $e) {
            error_log('Email verification queue failed: ' . $e->getMessage());
            $this->app->session->flash('error', t('controller.accountsecurity.nie_udao_sie_przygotowac_wiadomosci_potwierdzajacej'));
        }
        redirect(public_language_url(public_language(), '/account/security'));
    }

    public function verifyEmail(): never
    {
        try {
            $this->security()->verifyEmailToken((string)($_GET['token'] ?? ''));
            $this->app->session->flash('success', t('controller.accountsecurity.e_mail_zosta_potwierdzony'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.accountsecurity.nie_udao_sie_potwierdzic_adresu_e_mail'), 'email_verification'));
        }
        redirect(public_language_url(public_language(), '/account/security'));
    }

    public function start2fa(): never
    {
        $userId = $this->requireAuth();
        try {
            $this->security()->startTwoFactorSetup($userId);
            $this->app->session->flash('success', t('controller.accountsecurity.wygenerowano_sekret_2fa_przepisz_go_do_aplikacji_i_potw_fe380dd4'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.accountsecurity.nie_udao_sie_rozpoczac_konfiguracji_2fa'), '2fa_setup'));
        }
        redirect(public_language_url(public_language(), '/account/security'));
    }

    public function enable2fa(): never
    {
        $userId = $this->requireAuth();
        try {
            $this->security()->enableTwoFactor($userId, (string)($_POST['code'] ?? ''));
            $this->app->session->flash('success', t('controller.accountsecurity.2fa_zostao_aktywowane'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.accountsecurity.nie_udao_sie_aktywowac_2fa'), '2fa_enable'));
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
