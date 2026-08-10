<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Security\Dors3\MobileOperationPolicy;
use App\Security\Dors3\MobileEnrollmentQrCode;
use App\Security\Dors3\MobileProtocol;
use App\Security\Dors3\MobileSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class Dors3MobileProtocolTest extends TestCase
{
    public function testCanonicalPayloadMatchesAndroidFieldOrderExactly(): void
    {
        $payload = MobileProtocol::canonicalPayload([
            'purpose' => 'operation',
            'request_id' => 'req-1',
            'challenge' => 'challenge-1',
            'account' => 'author@example.test',
            'organization_id' => 'zrodlo-slowa',
            'role_context' => 'author',
            'server_origin' => 'https://example.test',
            'environment' => 'TESTOWE',
            'browser_session_hash' => 'browser-hash',
            'action_fingerprint' => str_repeat('a', 64),
            'issued_at_epoch' => 1000,
            'expires_at_epoch' => 1060,
            'nonce' => 'nonce-1',
        ], 'approve', 'credential-1');

        self::assertSame(implode("\n", [
            'payload_version=1',
            'decision=approve',
            'purpose=operation',
            'req-1',
            'challenge-1',
            'author@example.test',
            'zrodlo-slowa',
            'author',
            'https://example.test',
            'TESTOWE',
            'browser-hash',
            str_repeat('a', 64),
            '1000',
            '1060',
            'nonce-1',
            'credential-1',
        ]), $payload);
    }

    public function testAuthorPolicyPhysicallyRejectsAdministrativeOperations(): void
    {
        self::assertTrue(MobileOperationPolicy::allows('author', 'article.submit'));
        self::assertFalse(MobileOperationPolicy::allows('author', 'payout.approve'));
        self::assertTrue(MobileOperationPolicy::allows('admin', 'payout.approve'));
        self::assertFalse(MobileOperationPolicy::allows('admin', 'article.submit'));
    }

    public function testBackendRoutesOperationToExactlyOneApplicationVariant(): void
    {
        self::assertSame('author', MobileOperationPolicy::requiredVariant('article.submit'));
        self::assertSame('author', MobileOperationPolicy::requiredVariant('article.publish'));
        self::assertSame('admin', MobileOperationPolicy::requiredVariant('payout.approve'));
        self::assertSame('admin', MobileOperationPolicy::requiredVariant('role.change'));
        self::assertSame('admin', MobileOperationPolicy::requiredVariant('security.settings.change'));
        self::assertSame('admin', MobileOperationPolicy::requiredVariant('payout_details.change'));
        self::assertSame('admin', MobileOperationPolicy::requiredVariant('wallet.own_operation'));
        self::assertFalse(MobileOperationPolicy::allows('author', 'payout_details.change'));
        self::assertSame(
            'dors3-author-dev://approve/request-1',
            MobileOperationPolicy::debugLaunchUri('author', 'request-1'),
        );
        self::assertSame(
            'dors3-admin-dev://approve/request-2',
            MobileOperationPolicy::debugLaunchUri('admin', 'request-2'),
        );
    }

    public function testEcdsaVerifierRejectsAnyPayloadChange(): void
    {
        $opensslConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (is_file($opensslConfig)) {
            putenv('OPENSSL_CONF=' . $opensslConfig);
        }
        $key = openssl_pkey_new([
            'config' => is_file($opensslConfig) ? $opensslConfig : null,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $pem = (string)$details['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?: '', true);
        self::assertIsString($der);
        $payload = 'payload-version-1';
        self::assertTrue(openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256));

        $verifier = new MobileSignatureVerifier();
        self::assertTrue($verifier->verify(base64_encode($der), $payload, base64_encode($signature), MobileProtocol::ALGORITHM));
        self::assertFalse($verifier->verify(base64_encode($der), $payload . '-tampered', base64_encode($signature), MobileProtocol::ALGORITHM));
    }

    public function testMobileFlagsRemainDisabledByDefault(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/dors3.php';
        self::assertFalse($config['mobile']['enabled']);
        self::assertSame('disabled', $config['mobile']['mode']);
        self::assertFalse($config['mobile']['admin_app_enabled']);
        self::assertFalse($config['mobile']['author_app_enabled']);
    }

    public function testEnrollmentQrIsGeneratedLocallyAsSvgDataUri(): void
    {
        $uri = MobileEnrollmentQrCode::dataUri([
            'type' => 'dors3_enrollment',
            'application_variant' => 'author',
            'token' => 'one-time-token',
        ]);

        self::assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
        self::assertIsString($svg);
        self::assertStringContainsString('<svg', $svg);
        self::assertStringNotContainsString('one-time-token', $uri);
    }
}
