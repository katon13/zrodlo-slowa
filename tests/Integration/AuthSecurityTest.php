<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\SlowoSnajperConfig;
use App\Services\AuthSecurityService;

final class AuthSecurityTest extends DatabaseTestCase
{
    public function testTwoFactorSecretIsEncryptedAtRestAndPlainInOtpAuthUri(): void
    {
        $userId = (int)$this->database->cell(
            'SELECT id FROM users WHERE status=\'active\' ORDER BY id LIMIT 1'
        );
        self::assertGreaterThan(0, $userId);
        $service = new AuthSecurityService(
            $this->database,
            SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2))
        );

        $plainSecret = $service->startTwoFactorSetup($userId);
        $storedSecret = (string)$this->database->cell(
            'SELECT two_factor_secret FROM users WHERE id=:id',
            ['id' => $userId]
        );
        self::assertStringStartsWith('v1:', $storedSecret);
        self::assertStringNotContainsString($plainSecret, $storedSecret);

        $uri = $service->otpauthUri($userId, 'Źródło Słowa Test');
        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=' . rawurlencode($plainSecret), $uri);
        self::assertStringNotContainsString(rawurlencode($storedSecret), $uri);
    }
}
