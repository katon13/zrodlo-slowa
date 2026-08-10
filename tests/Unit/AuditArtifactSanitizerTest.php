<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuditArtifactSanitizer;
use PHPUnit\Framework\TestCase;

final class AuditArtifactSanitizerTest extends TestCase
{
    public function testRedactsIdentityAndFinancialIdsWithoutRemovingTestMetrics(): void
    {
        $input = json_encode([
            'tests' => 147,
            'assertions' => 916,
            'email' => 'real.person@example.com',
            'user_id' => 42,
            'wallet_id' => 9,
            'context' => ['tx_id' => 123, 'result' => 'success'],
        ], JSON_THROW_ON_ERROR);

        $result = (new AuditArtifactSanitizer())->sanitize($input);

        self::assertStringContainsString('"tests":147', $result);
        self::assertStringContainsString('"assertions":916', $result);
        self::assertStringContainsString('"email":"[REDACTED]"', $result);
        self::assertStringContainsString('"user_id":"[REDACTED]"', $result);
        self::assertStringContainsString('"wallet_id":"[REDACTED]"', $result);
        self::assertStringContainsString('"tx_id":"[REDACTED]"', $result);
        self::assertStringNotContainsString('real.person@example.com', $result);
    }

    public function testSanitizesTimestampPrefixedJsonLogAndPlainSecrets(): void
    {
        $input = "[2026-08-03] {\"actor_user_id\":7,\"payer_email\":\"payer@example.test\",\"result\":\"success\"}\n"
            . "APP_KEY=real-development-secret\nAuthorization: Bearer device-token";

        $sanitizer = new AuditArtifactSanitizer();
        $result = $sanitizer->sanitize($input);

        self::assertStringContainsString('"actor_user_id":"[REDACTED]"', $result);
        self::assertStringContainsString('"payer_email":"[REDACTED]"', $result);
        self::assertStringContainsString('APP_KEY=[SECRET_REDACTED]', $result);
        self::assertStringContainsString('Bearer [TOKEN_REDACTED]', $result);
        self::assertFalse($sanitizer->containsSensitiveData($result));
    }

    public function testRemovesPrivateKeyBlocks(): void
    {
        $input = "before\n-----BEGIN PRIVATE KEY-----\nsecret-body\n-----END PRIVATE KEY-----\nafter";
        $sanitizer = new AuditArtifactSanitizer();
        $result = $sanitizer->sanitize($input);

        self::assertStringContainsString('[PRIVATE_KEY_REDACTED]', $result);
        self::assertStringNotContainsString('secret-body', $result);
        self::assertFalse($sanitizer->containsSensitiveData($result));
    }

    public function testBlocksEveryRealEnvironmentVariantAndAllowsOnlyNamedExamples(): void
    {
        foreach (['.env', '.env.prod', '.env.staging', '.env.secret', '.ENV.PROD'] as $name) {
            self::assertTrue(AuditArtifactSanitizer::isBlockedEnvironmentFileName($name), $name);
        }

        foreach ([
            '.env.example',
            '.env.install.example',
            '.env.local.example',
            '.env.test.example',
            '.env.production.example',
        ] as $name) {
            self::assertFalse(AuditArtifactSanitizer::isBlockedEnvironmentFileName($name), $name);
        }

        self::assertFalse(AuditArtifactSanitizer::isBlockedEnvironmentFileName('environment.md'));
    }
}
