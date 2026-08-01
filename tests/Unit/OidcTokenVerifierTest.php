<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\OidcTokenVerifier;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

final class OidcTokenVerifierTest extends TestCase
{
    private string $cacheDirectory;
    private string $privateKey = '';
    private string $jwksUrl = 'https://issuer.example/.well-known/jwks.json';

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zs-oidc-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDirectory, 0777, true);
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $localOpenSslConfig = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras'
            . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        if (is_file($localOpenSslConfig)) {
            $options['config'] = $localOpenSslConfig;
        }
        $resource = openssl_pkey_new($options);
        self::assertNotFalse($resource);
        $exportOptions = isset($options['config']) ? ['config' => $options['config']] : [];
        self::assertTrue(openssl_pkey_export($resource, $this->privateKey, null, $exportOptions));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        $jwk = [
            'kty' => 'RSA',
            'kid' => 'test-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $this->base64Url($details['rsa']['n']),
            'e' => $this->base64Url($details['rsa']['e']),
        ];
        file_put_contents(
            $this->cacheDirectory . DIRECTORY_SEPARATOR . hash('sha256', $this->jwksUrl) . '.json',
            json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR)
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->cacheDirectory);
    }

    public function testValidTokenAndNonceAreAccepted(): void
    {
        $token = $this->token(['aud' => 'client-123', 'nonce' => 'nonce-123']);
        $claims = (new OidcTokenVerifier($this->cacheDirectory))->verify(
            $token,
            $this->jwksUrl,
            ['https://issuer.example'],
            'client-123',
            'nonce-123'
        );
        self::assertSame('subject-1', $claims['sub']);
    }

    public function testWrongNonceIsRejected(): void
    {
        $token = $this->token(['aud' => 'client-123', 'nonce' => 'nonce-123']);
        $this->expectException(\RuntimeException::class);
        (new OidcTokenVerifier($this->cacheDirectory))->verify(
            $token,
            $this->jwksUrl,
            ['https://issuer.example'],
            'client-123',
            'wrong'
        );
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $token = $this->token(['aud' => 'client-123', 'nonce' => 'nonce-123']);
        [$header, $payload, $signature] = explode('.', $token);
        $tamperedPayload = $this->base64Url(json_encode([
            'iss' => 'https://issuer.example',
            'aud' => 'client-123',
            'sub' => 'attacker',
            'nonce' => 'nonce-123',
            'iat' => time(),
            'exp' => time() + 300,
        ], JSON_THROW_ON_ERROR));
        $this->expectException(\UnexpectedValueException::class);
        (new OidcTokenVerifier($this->cacheDirectory))->verify(
            "{$header}.{$tamperedPayload}.{$signature}",
            $this->jwksUrl,
            ['https://issuer.example'],
            'client-123',
            'nonce-123'
        );
    }

    private function token(array $overrides): string
    {
        return JWT::encode(array_replace([
            'iss' => 'https://issuer.example',
            'aud' => 'client-123',
            'sub' => 'subject-1',
            'nonce' => 'nonce-123',
            'iat' => time(),
            'exp' => time() + 300,
        ], $overrides), $this->privateKey, 'RS256', 'test-key');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
