<?php
namespace App\Services;

final class GoogleOAuthService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private array $config;
    private OidcTokenVerifier $verifier;

    public function __construct(?OidcTokenVerifier $verifier = null)
    {
        $allConfig = require __DIR__ . '/../../config/oauth.php';
        $this->config = $allConfig['google'];
        $this->verifier = $verifier ?? new OidcTokenVerifier();
    }

    public function getAuthUrl(string $state, string $nonce): string
    {
        $this->assertConfigured();
        $params = [
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => $this->config['scopes'],
            'state' => $state,
            'nonce' => $nonce,
            'access_type' => 'offline',
            'prompt' => 'select_account',
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function getProfile(string $code, string $expectedNonce): array
    {
        $this->assertConfigured();
        $data = $this->postToken([
            'code' => $code,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);
        if (empty($data['id_token']) || !is_string($data['id_token'])) {
            throw new \RuntimeException('Google nie zwrócił tokenu tożsamości.');
        }

        $claims = $this->verifier->verify(
            $data['id_token'],
            self::JWKS_URL,
            ['accounts.google.com', 'https://accounts.google.com'],
            (string)$this->config['client_id'],
            $expectedNonce
        );

        $picture = isset($claims['picture']) && filter_var($claims['picture'], FILTER_VALIDATE_URL)
            && str_starts_with(strtolower((string)$claims['picture']), 'https://')
            ? (string)$claims['picture']
            : null;

        return [
            'provider' => 'google',
            'sub' => (string)$claims['sub'],
            'email' => isset($claims['email']) ? strtolower(trim((string)$claims['email'])) : null,
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name' => isset($claims['name']) ? mb_substr(trim((string)$claims['name']), 0, 190) : null,
            'picture' => $picture,
        ];
    }

    private function postToken(array $parameters): array
    {
        $curl = curl_init(self::TOKEN_URL);
        if ($curl === false) {
            throw new \RuntimeException('Nie udało się rozpocząć połączenia z Google.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($response) || $response === '') {
            throw new \RuntimeException('Brak odpowiedzi Google' . ($curlError !== '' ? ': ' . $curlError : '.'));
        }
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if ($status !== 200 || !is_array($data)) {
            error_log('Google token endpoint rejected request with HTTP ' . $status);
            throw new \RuntimeException('Google odrzucił wymianę kodu logowania.');
        }
        return $data;
    }

    private function assertConfigured(): void
    {
        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (trim((string)($this->config[$key] ?? '')) === '') {
                throw new \RuntimeException("Brak konfiguracji Google OAuth: $key.");
            }
        }
        if (!filter_var($this->config['redirect_uri'], FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Nieprawidłowy redirect_uri Google OAuth.');
        }
    }
}
