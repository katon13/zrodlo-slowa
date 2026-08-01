<?php
namespace App\Controllers;

use App\Services\AuthService;
use App\Services\AuthSecurityService;
use App\Services\AuthenticationFlowService;
use App\Services\UserService;

final class AuthController extends BaseController
{
    public function showRegister(): string { return $this->view('auth/register', ['title' => t('auth.register.title')]); }
    public function showLogin(): string { return $this->view('auth/login', ['title' => t('auth.login.title')]); }
    public function showForgot(): string { return $this->view('auth/forgot', ['title' => t('auth.forgot.title')]); }
    public function showReset(): string { return $this->view('auth/reset', ['title' => t('auth.reset.title'), 'token' => $_GET['token'] ?? '']); }

    public function showTwoFactorChallenge(): string
    {
        $pending = $_SESSION['_pending_2fa_login'] ?? null;
        if (!$this->authenticationFlow()->validPendingChallenge($pending)) {
            unset($_SESSION['_pending_2fa_login']);
            $this->app->session->flash('error', 'Sesja 2FA wygasła. Zaloguj się ponownie.');
            redirect(public_language_url(public_language(), '/login'));
        }

        return $this->view('auth/two_factor_challenge', [
            'title' => 'Kod 2FA',
            'email' => (string)($pending['email'] ?? ''),
            'issued_at' => (int)($pending['issued_at'] ?? time()),
        ]);
    }

    public function register(): never
    {
        try {
            $service = new AuthService($this->app->db);
            $user = $service->register([
                'display_name' => trim($_POST['display_name'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'phone' => trim($_POST['phone'] ?? ''),
                'password' => (string)($_POST['password'] ?? ''),
                'role' => 'author',
            ]);
            
            $this->app->session->login($user['id'], $user['role']);

            // Etap 2: bonus live za rejestrację po założeniu sesji.
            $talent = $this->talentService();
            $talent->queueAward((int)$user['id'], 'registration_bonus');
            
            $this->app->session->flash('success', 'Konto autora zostało utworzone i czeka na akceptację redakcji. Po zatwierdzeniu uzyskasz dostęp do dodawania tekstów.');
            redirect(public_language_url(public_language(), '/author'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się utworzyć konta.', 'auth_register'));
            redirect(public_language_url(public_language(), '/register'));
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
                $this->safeError($error, 'Zbyt wiele prób lub logowanie jest chwilowo niedostępne. Spróbuj później.', 'auth_login_guard')
            );
            redirect(public_language_url(public_language(), '/login'));
        }
        $user = $service->attempt($identifier, (string)($_POST['password'] ?? ''));

        if (!$user) {
            $security->recordLoginEvent(null, $identifier, 'password_failed');
            $this->dors3LoginSecurity()->recordFailure($identifier);
            $this->app->session->flash('error', 'Nieprawidłowy login lub hasło.');
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
            $this->app->session->flash('error', $this->safeError($error, 'Nie udało się zakończyć logowania 2FA.', 'auth_2fa'));
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
            $this->app->session->flash('success', 'Token resetu wygenerowany: ' . $token);
        } else {
            $this->app->session->flash('success', 'Jeżeli konto istnieje, wiadomość resetująca została zapisana do kolejki maili.');
        }
        redirect(public_language_url(public_language(), '/password/forgot'));
    }

    public function reset(): never
    {
        try {
            (new UserService($this->app->db))->resetPassword((string)($_POST['token'] ?? ''), (string)($_POST['password'] ?? ''));
            $this->app->session->flash('success', 'Hasło zostało zmienione.');
            redirect(public_language_url(public_language(), '/login'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się zmienić hasła. Link mógł wygasnąć.', 'password_reset'));
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
            $this->app->session->flash('success', 'To konto wymaga kodu 2FA.');
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
                'Uzupełnij zabezpieczenia konta wymagane dla wysokiej roli: ' . implode(', ', $missing) . '.'
            );
        }
        redirect(public_language_url(public_language(), (string)($result['destination'] ?? '/')));
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
