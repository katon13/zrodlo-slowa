<?php
namespace App\Controllers;

use App\Services\AppleOAuthService;
use App\Services\AuthenticationFlowService;
use App\Services\GoogleOAuthService;
use App\Services\OidcTokenVerifier;
use App\Services\OAuthAccountService;

final class OAuthController extends BaseController
{
    private const REQUEST_TTL_SECONDS = 600;

    public function googleRedirect(): never
    {
        $config = $this->oauthConfig();
        if (!($config['google']['enabled'] ?? false)) {
            $this->app->session->flash('error', 'Logowanie Google jest wyłączone.');
            redirect(public_language_url(public_language(), '/login'));
        }

        [$state, $nonce] = $this->createRequest('google');
        redirect((new GoogleOAuthService($this->oidcVerifier()))->getAuthUrl($state, $nonce));
    }

    public function googleCallback(): never
    {
        try {
            $nonce = $this->consumeRequest('google', $_GET['state'] ?? null);
            if (!empty($_GET['error'])) {
                throw new \RuntimeException('Dostawca anulował albo odrzucił logowanie.');
            }
            $code = is_string($_GET['code'] ?? null) ? trim($_GET['code']) : '';
            if ($code === '') {
                throw new \RuntimeException('Google nie zwrócił kodu autoryzacyjnego.');
            }
            $profile = (new GoogleOAuthService($this->oidcVerifier()))->getProfile($code, $nonce);
            $this->handleOAuthLogin($profile);
        } catch (\Throwable $error) {
            error_log('OAuth Google [' . bin2hex(random_bytes(4)) . ']: ' . $error->getMessage());
            $this->app->session->flash('error', 'Nie udało się zalogować przez Google.');
            redirect(public_language_url(public_language(), '/login'));
        }
    }

    public function appleRedirect(): never
    {
        $config = $this->oauthConfig();
        if (!($config['apple']['enabled'] ?? false)) {
            $this->app->session->flash('error', 'Logowanie Apple jest wyłączone.');
            redirect(public_language_url(public_language(), '/login'));
        }

        [$state, $nonce] = $this->createRequest('apple');
        redirect((new AppleOAuthService($this->oidcVerifier()))->getAuthUrl($state, $nonce));
    }

    public function appleCallback(): never
    {
        try {
            $nonce = $this->consumeRequest('apple', $_POST['state'] ?? null);
            if (!empty($_POST['error'])) {
                throw new \RuntimeException('Dostawca anulował albo odrzucił logowanie.');
            }
            $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
            if ($code === '') {
                throw new \RuntimeException('Apple nie zwrócił kodu autoryzacyjnego.');
            }
            $userJson = is_string($_POST['user'] ?? null) ? $_POST['user'] : null;
            if ($userJson !== null && strlen($userJson) > 10000) {
                throw new \RuntimeException('Dane profilu Apple są zbyt duże.');
            }
            $profile = (new AppleOAuthService($this->oidcVerifier()))->getProfile($code, $nonce, $userJson);
            $this->handleOAuthLogin($profile);
        } catch (\Throwable $error) {
            error_log('OAuth Apple [' . bin2hex(random_bytes(4)) . ']: ' . $error->getMessage());
            $this->app->session->flash('error', 'Nie udało się zalogować przez Apple.');
            redirect(public_language_url(public_language(), '/login'));
        }
    }

    private function handleOAuthLogin(array $profile): never
    {
        $accounts = new OAuthAccountService($this->app->db);
        $oauthAccount = $accounts->findByProvider((string)$profile['provider'], (string)$profile['sub']);

        if ($oauthAccount) {
            $user = $accounts->userForLogin((int)$oauthAccount['user_id']);
            if (!$user) {
                throw new \RuntimeException('Konto połączone z OAuth jest niedostępne.');
            }
            $accounts->updateLastLogin((int)$oauthAccount['id']);
            $this->applyAuthenticationResult(
                $this->authenticationFlow()->begin($user, (string)$profile['provider'])
            );
        }

        if (!empty($profile['email']) && !empty($profile['email_verified'])) {
            $localUser = $accounts->findByEmail((string)$profile['email']);
            if ($localUser) {
                $accounts->linkAccount((int)$localUser['id'], $profile);
                $linked = $accounts->findByProvider((string)$profile['provider'], (string)$profile['sub']);
                $user = $accounts->userForLogin((int)$localUser['id']);
                if (!$user || !$linked) {
                    throw new \RuntimeException('Nie udało się połączyć konta OAuth.');
                }
                $accounts->updateLastLogin((int)$linked['id']);
                $this->app->session->flash('success', 'Konto zostało bezpiecznie połączone z dostawcą logowania.');
                $this->applyAuthenticationResult(
                    $this->authenticationFlow()->begin($user, (string)$profile['provider'])
                );
            }
        }

        $user = $accounts->createUserWithAccount($profile);
        $linked = $accounts->findByProvider((string)$profile['provider'], (string)$profile['sub']);
        if ($linked) {
            $accounts->updateLastLogin((int)$linked['id']);
        }
        $this->applyAuthenticationResult(
            $this->authenticationFlow()->begin($user, (string)$profile['provider'])
        );
    }

    private function createRequest(string $provider): array
    {
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $this->app->session->set('oauth_' . $provider . '_request', [
            'state_hash' => hash('sha256', $state),
            'nonce' => $nonce,
            'issued_at' => time(),
        ]);
        return [$state, $nonce];
    }

    private function consumeRequest(string $provider, mixed $state): string
    {
        $key = 'oauth_' . $provider . '_request';
        $request = $this->app->session->get($key);
        $this->app->session->remove($key);
        if (!is_array($request) || !is_string($state) || $state === '') {
            throw new \RuntimeException('Brak sesji logowania OAuth.');
        }
        if ((int)($request['issued_at'] ?? 0) + self::REQUEST_TTL_SECONDS < time()) {
            throw new \RuntimeException('Sesja logowania OAuth wygasła.');
        }
        $expectedHash = (string)($request['state_hash'] ?? '');
        if ($expectedHash === '' || !hash_equals($expectedHash, hash('sha256', $state))) {
            throw new \RuntimeException('Nieprawidłowy parametr state OAuth.');
        }
        $nonce = (string)($request['nonce'] ?? '');
        if ($nonce === '') {
            throw new \RuntimeException('Brak nonce OAuth.');
        }
        return $nonce;
    }

    private function applyAuthenticationResult(array $result): never
    {
        if (($result['status'] ?? '') === 'challenge') {
            $this->app->session->flash('success', 'To konto wymaga kodu 2FA.');
            redirect(public_language_url(public_language(), '/login/2fa'));
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

    private function oauthConfig(): array
    {
        return require __DIR__ . '/../../config/oauth.php';
    }

    private function oidcVerifier(): OidcTokenVerifier
    {
        return new OidcTokenVerifier('', 3600, 86400, $this->app->cache);
    }
}
