<?php
namespace App\Controllers;

use App\Services\AuthService;
use App\Services\AuthSecurityService;
use App\Services\AuthenticationFlowService;
use App\Services\Dors3UiText;
use App\Services\UserService;

final class AuthController extends BaseController
{
    public function showRegister(): string
    {
        $registrationNonce = trim((string)($_GET['refn'] ?? ''));
        $referralEmail = '';
        if ($registrationNonce !== '') {
            try {
                $context = $this->appReferralService()->registrationContext($registrationNonce);
                $referralEmail = (string)$context['invited_email'];
            } catch (\Throwable $error) {
                http_response_code(410);
                return $this->view('layouts/error', [
                    'title' => t('controller.auth.rejestracja_z_aplikacji_wygasa'),
                    'message' => $this->safeError(
                        $error,
                        t('controller.auth.ta_sesja_rejestracji_jest_nieprawidowa_albo_wygasa_otwo_cd331297'),
                        'referral_registration_context',
                    ),
                ]);
            }
        }
        return $this->view('auth/register', [
            'title' => t('auth.register.title'),
            'registration_nonce' => $registrationNonce,
            'referral_email' => $referralEmail,
        ]);
    }
    public function showLogin(): string { return $this->view('auth/login', ['title' => t('auth.login.title')]); }
    public function showForgot(): string { return $this->view('auth/forgot', ['title' => t('auth.forgot.title')]); }
    public function showReset(): string { return $this->view('auth/reset', ['title' => t('auth.reset.title'), 'token' => $_GET['token'] ?? '']); }

    public function showTwoFactorChallenge(): string
    {
        $pending = $_SESSION['_pending_2fa_login'] ?? null;
        if (!$this->authenticationFlow()->validPendingChallenge($pending)) {
            unset($_SESSION['_pending_2fa_login']);
            $this->app->session->flash('error', t('controller.auth.sesja_2fa_wygasa_zaloguj_sie_ponownie'));
            redirect(public_language_url(public_language(), '/login'));
        }

        return $this->view('auth/two_factor_challenge', [
            'title' => t('ui.auth.two_factor_challenge.kod_2fa'),
            'email' => (string)($pending['email'] ?? ''),
            'issued_at' => (int)($pending['issued_at'] ?? time()),
        ]);
    }

    public function showMobileChallenge(): string
    {
        $pending = $this->app->session->get('_pending_dors3_mobile_login');
        if (!is_array($pending) || (int)($pending['expires_at'] ?? 0) <= time()) {
            $this->app->session->remove('_pending_dors3_mobile_login');
            $this->app->session->logout();
            $this->app->session->flash('error', Dors3UiText::get('messages.mobile_login_expired'));
            redirect(public_language_url(public_language(), '/login'));
        }
        return $this->view('auth/dors3_mobile_challenge', [
            'title' => t('controller.auth.potwierdzenie_3dors_mobile'),
            'approval_request_id' => (string)$pending['public_id'],
            'expires_at' => (int)$pending['expires_at'],
            'application_variant' => (string)$pending['application_variant'],
        ]);
    }

    public function completeMobileChallenge(): never
    {
        $pending = $this->app->session->get('_pending_dors3_mobile_login');
        if (!is_array($pending) || (int)($pending['expires_at'] ?? 0) <= time()) {
            $this->app->session->remove('_pending_dors3_mobile_login');
            $this->app->session->logout();
            redirect(public_language_url(public_language(), '/login'));
        }
        try {
            $status = $this->dors3Mobile()->approvalStatus((string)$pending['public_id']);
            if ((string)$status['status'] !== 'approved') {
                throw new \RuntimeException(t('controller.auth.decyzja_mobilna_nie_zostaa_zatwierdzona'));
            }
            $context = $this->app->session->authenticationContext();
            $now = time();
            $factors = $context instanceof \App\Security\Authentication\AuthenticationContext
                ? $context->factors
                : ['password'];
            $factors[] = 'mobile_' . (string)$pending['application_variant'];
            $this->app->session->setAuthenticationContext(new \App\Security\Authentication\AuthenticationContext(
                $context instanceof \App\Security\Authentication\AuthenticationContext ? $context->method : 'password',
                array_values(array_unique($factors)),
                $context instanceof \App\Security\Authentication\AuthenticationContext ? $context->authenticatedAt : $now,
                $now,
            ));
            $destination = (string)($pending['destination'] ?? '/');
            $this->app->session->remove('_pending_dors3_mobile_login');
            $this->securityEvents()->record(
                $this->app->session->userId(),
                'mobile.login.completed',
                'success',
                'medium',
                'mobile_approval',
                (string)$pending['public_id'],
                null,
                null,
                null,
                null,
                ['application_variant' => (string)$pending['application_variant']]
            );
            redirect(public_language_url(public_language(), $destination));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeError($error, Dors3UiText::get('messages.mobile_login_finish_failed'), 'dors3_mobile_login'));
            redirect(public_language_url(public_language(), '/login/3dors-mobile'));
        }
    }

    public function register(): never
    {
        $registrationNonce = trim((string)($_POST['registration_nonce'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $registrationNonce) !== 1) {
            $registrationNonce = '';
        }
        try {
            $service = new AuthService($this->app->db);
            $registrationData = [
                'display_name' => trim((string)($_POST['display_name'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')),
                'phone' => trim((string)($_POST['phone'] ?? '')),
                'password' => (string)($_POST['password'] ?? ''),
                'role' => 'author',
            ];
            $talent = $this->talentService();
            $user = $this->app->db->transaction(function () use ($service, $talent, $registrationData, $registrationNonce): array {
                if ($registrationNonce !== '') {
                    $context = $this->appReferralService()->registrationContext($registrationNonce, true);
                    if (!hash_equals(
                        strtolower((string)$context['invited_email']),
                        strtolower((string)$registrationData['email'])
                    )) {
                        throw new \RuntimeException(t('controller.auth.adres_e_mail_rejestracji_nie_zgadza_sie_z_zaproszeniem'));
                    }
                }
                $registeredUser = $service->registerWithTalentEntitlement($registrationData, $talent);
                if ($registrationNonce !== '') {
                    $this->appReferralService()->consumeRegistrationNonce(
                        $registrationNonce,
                        (int)$registeredUser['id'],
                        (string)$registrationData['email'],
                    );
                }
                return $registeredUser;
            });
            
            $this->app->session->login($user['id'], $user['role']);

            $this->app->session->flash('success', t('controller.auth.konto_autora_zostao_utworzone_i_czeka_na_akceptacje_red_d6ec98f4'));
            redirect(public_language_url(public_language(), '/author'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.auth.nie_udao_sie_utworzyc_konta'), 'auth_register'));
            $path = '/register' . ($registrationNonce !== '' ? '?refn=' . rawurlencode($registrationNonce) : '');
            redirect(public_language_url(public_language(), $path));
        }
    }

    public function login(): never
    {
        $this->app->session->remove('_pending_2fa_login');

        $identifier = trim((string)($_POST['login'] ?? $_POST['email'] ?? ''));
        $service = new AuthService($this->app->db);
        $security = new AuthSecurityService(
            $this->app->db,
            $this->slowoSnajperConfig(),
            $this->app->rateLimiter,
            $this->app->queueSignals
        );
        try {
            $this->dors3LoginSecurity()->assertAllowed($identifier);
            $security->assertLoginAllowed($identifier);
        } catch (\Throwable $error) {
            $this->app->session->flash(
                'error',
                $this->safeError($error, t('controller.auth.zbyt_wiele_prob_lub_logowanie_jest_chwilowo_niedostepne_4da14f2e'), 'auth_login_guard')
            );
            redirect(public_language_url(public_language(), '/login'));
        }
        $user = $service->attempt($identifier, (string)($_POST['password'] ?? ''));

        if (!$user) {
            $security->recordLoginEvent(null, $identifier, 'password_failed');
            $this->dors3LoginSecurity()->recordFailure($identifier);
            $this->app->session->flash('error', t('controller.auth.nieprawidowy_login_lub_haso'));
            redirect(public_language_url(public_language(), '/login'));
        }

        $this->applyAuthenticationResult($this->authenticationFlow()->begin($user, 'password'));
    }

    public function verifyTwoFactorChallenge(): never
    {
        try {
            $result = $this->authenticationFlow()->completeTwoFactor((string)($_POST['code'] ?? ''));
            $this->applyAuthenticationResult($result);
        } catch (\InvalidArgumentException $error) {
            $this->app->session->flash('error', $error->getMessage());
            redirect(public_language_url(public_language(), '/login/2fa'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeError($error, t('controller.auth.nie_udao_sie_zakonczyc_logowania_2fa'), 'auth_2fa'));
            redirect(public_language_url(public_language(), '/login'));
        }
    }

    public function forgot(): never
    {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $security = new AuthSecurityService(
            $this->app->db,
            $this->slowoSnajperConfig(),
            $this->app->rateLimiter,
            $this->app->queueSignals
        );
        $token = null;
        try {
            $security->assertPasswordResetAllowed($email);
            $token = (new UserService($this->app->db, $this->app->queueSignals))->requestPasswordReset($email);
            $security->recordLoginEvent(null, $email, 'reset_requested');
        } catch (\Throwable $error) {
            error_log('Password reset request rejected: ' . $error->getMessage());
        }
        if ($token && env('APP_ENV', 'local') !== 'production') {
            $this->app->session->flash('success', t('controller.auth.token_resetu_wygenerowany') . $token);
        } else {
            $this->app->session->flash('success', t('controller.auth.jezeli_konto_istnieje_wiadomosc_resetujaca_zostaa_zapis_16312b4b'));
        }
        redirect(public_language_url(public_language(), '/password/forgot'));
    }

    public function reset(): never
    {
        try {
            (new UserService($this->app->db))->resetPassword((string)($_POST['token'] ?? ''), (string)($_POST['password'] ?? ''));
            $this->app->session->flash('success', t('controller.auth.haso_zostao_zmienione'));
            redirect(public_language_url(public_language(), '/login'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.auth.nie_udao_sie_zmienic_hasa_link_mog_wygasnac'), 'password_reset'));
            redirect(public_language_url(public_language(), '/password/reset?token=' . urlencode((string)($_POST['token'] ?? ''))));
        }
    }

    public function logout(): never
    {
        unset($_SESSION['_pending_2fa_login']);
        $userId = $this->app->session->userId();
        if ($userId !== null) {
            $this->earningsPresenceService()->clear($userId);
            if ($this->app->session->role() === 'admin') {
                try {
                    $this->securityEvents()->record(
                        $userId,
                        'security.admin_session.ended',
                        'success',
                        'low',
                        'session',
                        session_id() !== '' ? hash('sha256', session_id()) : 'unknown',
                        null,
                        null,
                        'explicit_logout'
                    );
                } catch (\Throwable) {
                    // Logout must remain available even if the audit store is unavailable.
                }
            }
        }
        $this->app->session->logout();
        redirect(public_language_url(public_language(), '/'));
    }

    private function applyAuthenticationResult(array $result): never
    {
        if (($result['status'] ?? '') === 'challenge') {
            $this->app->session->flash('success', t('controller.auth.to_konto_wymaga_kodu_2fa'));
            redirect(public_language_url(public_language(), '/login/2fa'));
        }
        $userId = $this->app->session->userId();
        if ($userId !== null && $this->app->session->role() === 'admin') {
            $this->dors3LoginSecurity()->recordSuccess($userId);
            $this->adminSessionPolicy()->start($userId);
        }
        $missing = (array)($result['security_missing'] ?? []);
        if ($missing !== []) {
            $this->app->session->flash(
                'error',
                t('controller.auth.uzupenij_zabezpieczenia_konta_wymagane_dla_wysokiej_roli') . implode(', ', $missing) . '.'
            );
        }
        if ($userId !== null && $this->beginMobileLoginIfConfigured($userId, (string)($result['destination'] ?? '/'))) {
            redirect(public_language_url(public_language(), '/login/3dors-mobile'));
        }
        redirect(public_language_url(public_language(), (string)($result['destination'] ?? '/')));
    }

    private function beginMobileLoginIfConfigured(int $userId, string $destination): bool
    {
        $mobile = $this->app->config['dors3']['mobile'] ?? null;
        $role = (string)$this->app->session->role();
        $variant = $role === 'admin' ? 'admin' : ($role === 'author' ? 'author' : '');
        if (
            !is_array($mobile)
            || $variant === ''
        ) {
            return false;
        }
        try {
            if (!\App\Security\Dors3\MobileApprovalConfiguration::isVariantEnabled($mobile, $variant)) {
                return false;
            }
            $hasDevice = (int)$this->app->db->cell(
                'SELECT COUNT(*) FROM security_mobile_devices WHERE user_id=:user AND application_variant=:variant AND status=\'active\'',
                ['user' => $userId, 'variant' => $variant]
            ) > 0;
            if (!$hasDevice) {
                if ((string)$mobile['mode'] === 'required') {
                    throw new \RuntimeException(t('controller.auth.tryb_required_wymaga_aktywnego_urzadzenia_3dors_mobile'));
                }
                return false;
            }
            $request = $this->dors3Mobile()->createApprovalRequest(
                $userId,
                $variant,
                'login',
                'auth.login',
                'user',
                (string)$userId,
                [
                    Dors3UiText::get('fields.operation') => Dors3UiText::option('operations', 'auth.login'),
                    Dors3UiText::get('fields.account') => (string)$userId,
                    Dors3UiText::get('fields.initiating_device') => mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Browser')), 0, 120),
                ],
            );
        } catch (\Throwable $error) {
            if ((string)$mobile['mode'] === 'required') {
                $this->app->session->logout();
                throw new \RuntimeException(t('controller.auth.wymagane_potwierdzenie_3dors_mobile_jest_obecnie_niedostepne'), 0, $error);
            }
            error_log('[dors3_mobile_login] test mode unavailable: ' . $error::class);
            return false;
        }
        $this->app->session->set('_pending_dors3_mobile_login', [
            'public_id' => (string)$request['public_id'],
            'expires_at' => (int)$request['expires_at'],
            'application_variant' => $variant,
            'destination' => $destination,
        ]);
        return true;
    }

    private function dors3Mobile(): \App\Services\Dors3MobileService
    {
        return new \App\Services\Dors3MobileService(
            $this->app->db,
            \App\Services\SecretCipher::fromEnvironment(),
            $this->securityEvents(),
            is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
        );
    }

    private function authenticationFlow(): AuthenticationFlowService
    {
        return new AuthenticationFlowService(
            $this->app->db,
            $this->app->session,
            $this->slowoSnajperConfig(),
            $this->app->rateLimiter
        );
    }

    private function dors3LoginSecurity(): \App\Services\Dors3LoginSecurityService
    {
        return new \App\Services\Dors3LoginSecurityService(
            $this->app->db,
            $this->securityEvents(),
        );
    }
}
