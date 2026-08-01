<?php
namespace App\Services;

use Firebase\JWT\JWT;

final class AppleOAuthService
{
    private const TOKEN_URL = 'https://appleid.apple.com/auth/token';
    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';

    private array $config;
    private OidcTokenVerifier $verifier;

    public function __construct(?OidcTokenVerifier $verifier = null)
    {
        $allConfig = require __DIR__ . '/../../config/oauth.php';
        $this->config = $allConfig['apple'];
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
            'response_mode' => 'form_post',
        ];
        return 'https://appleid.apple.com/auth/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function getProfile(string $code, string $expectedNonce, ?string $userJson = null): array
    {
        $this->assertConfigured();
        $data = $this->postToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config['redirect_uri'],
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->generateClientSecret(),
        ]);
        if (empty($data['id_token']) || !is_string($data['id_token'])) {
            throw new \RuntimeException('Apple nie zwrócił tokenu tożsamości.');
        }

        $claims = $this->verifier->verify(
            $data['id_token'],
            self::JWKS_URL,
            ['https://appleid.apple.com'],
            (string)$this->config['client_id'],
            $expectedNonce
        );
        $name = null;
        if (is_string($userJson) && $userJson !== '') {
            $userData = json_decode($userJson, true);
            if (is_array($userData) && is_array($userData['name'] ?? null)) {
                $name = trim(
                    (string)($userData['name']['firstName'] ?? '')
                    . ' '
                    . (string)($userData['name']['lastName'] ?? '')
                );
                $name = $name !== '' ? mb_substr($name, 0, 190) : null;
            }
        }

        return [
            'provider' => 'apple',
            'sub' => (string)$claims['sub'],
            'email' => isset($claims['email']) ? strtolower(trim((string)$claims['email'])) : null,
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name' => $name,
            'picture' => null,
        ];
    }

    private function generateClientSecret(): string
    {
        if (!class_exists(JWT::class)) {
            throw new \RuntimeException('Brak biblioteki firebase/php-jwt. Uruchom composer install.');
        }
        $privateKeyPath = (string)$this->config['private_key_path'];
        if (!$this->isAbsolutePath($privateKeyPath)) {
            $privateKeyPath = dirname(__DIR__, 2) . '/' . ltrim($privateKeyPath, '/\\');
        }
        $realPath = realpath($privateKeyPath);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new \RuntimeException('Klucz prywatny Apple jest niedostępny.');
        }
        $privateKey = file_get_contents($realPath);
        if (!is_string($privateKey) || openssl_pkey_get_private($privateKey) === false) {
            throw new \RuntimeException('Klucz prywatny Apple ma nieprawidłowy format.');
        }

        $now = time();
        return JWT::encode([
            'iss' => (string)$this->config['team_id'],
            'iat' => $now,
            'exp' => $now + 3600,
            'aud' => 'https://appleid.apple.com',
            'sub' => (string)$this->config['client_id'],
        ], $privateKey, 'ES256', (string)$this->config['key_id']);
    }

    private function postToken(array $parameters): array
    {
        $curl = curl_init(self::TOKEN_URL);
        if ($curl === false) {
            throw new \RuntimeException('Nie udało się rozpocząć połączenia z Apple.');
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
            throw new \RuntimeException('Brak odpowiedzi Apple' . ($curlError !== '' ? ': ' . $curlError : '.'));
        }
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if ($status !== 200 || !is_array($data)) {
            error_log('Apple token endpoint rejected request with HTTP ' . $status);
            throw new \RuntimeException('Apple odrzucił wymianę kodu logowania.');
        }
        return $data;
    }

    private function assertConfigured(): void
    {
        foreach (['client_id', 'team_id', 'key_id', 'private_key_path', 'redirect_uri'] as $key) {
            if (trim((string)($this->config[$key] ?? '')) === '') {
                throw new \RuntimeException("Brak konfiguracji Apple OAuth: $key.");
            }
        }
        if (!filter_var($this->config['redirect_uri'], FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Nieprawidłowy redirect_uri Apple OAuth.');
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/');
    }
}
