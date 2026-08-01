<?php
namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

final class OidcTokenVerifier
{
    public function __construct(
        private readonly string $cacheDirectory = '',
        private readonly int $cacheTtlSeconds = 3600,
        private readonly int $staleTtlSeconds = 86400,
        private readonly ?CacheService $sharedCache = null,
    ) {}

    public function verify(
        string $idToken,
        string $jwksUrl,
        array $allowedIssuers,
        string $audience,
        string $expectedNonce
    ): array {
        if (!class_exists(JWT::class) || !class_exists(JWK::class)) {
            throw new \RuntimeException('Brak biblioteki firebase/php-jwt. Uruchom composer install.');
        }
        if ($idToken === '' || $audience === '' || $expectedNonce === '') {
            throw new \InvalidArgumentException('Brak wymaganych danych do weryfikacji OIDC.');
        }

        $jwks = $this->jwks($jwksUrl);
        $keys = JWK::parseKeySet($jwks, 'RS256');
        if ($keys === []) {
            throw new \RuntimeException('Dostawca OIDC nie udostępnił prawidłowych kluczy.');
        }

        JWT::$leeway = 60;
        $claims = (array)JWT::decode($idToken, $keys);
        $issuer = (string)($claims['iss'] ?? '');
        if (!in_array($issuer, $allowedIssuers, true)) {
            throw new \RuntimeException('Token OIDC ma nieprawidłowego wydawcę.');
        }

        $audiences = is_array($claims['aud'] ?? null)
            ? array_map('strval', $claims['aud'])
            : [(string)($claims['aud'] ?? '')];
        if (!in_array($audience, $audiences, true)) {
            throw new \RuntimeException('Token OIDC ma nieprawidłowego odbiorcę.');
        }
        if (count($audiences) > 1 && (string)($claims['azp'] ?? '') !== $audience) {
            throw new \RuntimeException('Token OIDC ma nieprawidłową stronę autoryzowaną.');
        }

        $nonce = (string)($claims['nonce'] ?? '');
        if ($nonce === '' || !hash_equals($expectedNonce, $nonce)) {
            throw new \RuntimeException('Token OIDC ma nieprawidłowy nonce.');
        }
        if (empty($claims['sub'])) {
            throw new \RuntimeException('Token OIDC nie zawiera identyfikatora użytkownika.');
        }
        if (isset($claims['iat']) && (int)$claims['iat'] > time() + 60) {
            throw new \RuntimeException('Token OIDC został wystawiony w przyszłości.');
        }

        return $claims;
    }

    private function jwks(string $url): array
    {
        if (!preg_match('#^https://#i', $url)) {
            throw new \InvalidArgumentException('Adres JWKS musi używać HTTPS.');
        }
        if ($this->sharedCache !== null) {
            return $this->sharedJwks($url);
        }

        $directory = $this->cacheDirectory !== ''
            ? $this->cacheDirectory
            : dirname(__DIR__, 2) . '/storage/cache/oidc';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Nie udało się utworzyć katalogu cache OIDC.');
        }

        $cacheFile = rtrim($directory, '/\\') . '/' . hash('sha256', $url) . '.json';
        $cached = $this->readCache($cacheFile);
        if ($cached !== null && (time() - (int)filemtime($cacheFile)) <= $this->cacheTtlSeconds) {
            return $cached;
        }

        try {
            $fresh = $this->downloadJson($url);
            file_put_contents(
                $cacheFile,
                json_encode($fresh, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                LOCK_EX
            );
            return $fresh;
        } catch (\Throwable $error) {
            if ($cached !== null && (time() - (int)filemtime($cacheFile)) <= $this->staleTtlSeconds) {
                error_log('OIDC JWKS refresh failed; using stale cache: ' . $error->getMessage());
                return $cached;
            }
            throw $error;
        }
    }

    private function sharedJwks(string $url): array
    {
        $key = 'oidc_jwks:' . hash('sha256', $url);
        $lookup = $this->sharedCache?->get($key) ?? ['hit' => false, 'value' => null];
        $cached = $lookup['hit'] && is_array($lookup['value'])
            ? $lookup['value']
            : null;
        $keys = is_array($cached['keys'] ?? null) ? $cached['keys'] : null;
        $fetchedAt = (int)($cached['fetched_at'] ?? 0);
        if ($keys !== null && $fetchedAt + $this->cacheTtlSeconds >= time()) {
            return ['keys' => $keys];
        }

        try {
            $fresh = $this->downloadJson($url);
            $this->sharedCache?->set($key, [
                'keys' => $fresh['keys'],
                'fetched_at' => time(),
            ], $this->staleTtlSeconds);
            return $fresh;
        } catch (\Throwable $error) {
            if ($keys !== null && $fetchedAt + $this->staleTtlSeconds >= time()) {
                error_log('OIDC JWKS refresh failed; using stale shared cache: ' . $error->getMessage());
                return ['keys' => $keys];
            }
            throw $error;
        }
    }

    private function readCache(string $cacheFile): ?array
    {
        if (!is_file($cacheFile)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($cacheFile), true);
        return is_array($decoded) && isset($decoded['keys']) && is_array($decoded['keys'])
            ? $decoded
            : null;
    }

    private function downloadJson(string $url): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Nie udało się zainicjować połączenia OIDC.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Zrodlo-Slowa-OIDC/1.0',
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($response) || $response === '' || $status !== 200) {
            throw new \RuntimeException('Nie udało się pobrać kluczy OIDC' . ($error !== '' ? ': ' . $error : '.'));
        }
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            throw new \RuntimeException('Odpowiedź JWKS ma nieprawidłowy format.');
        }
        return $decoded;
    }
}
