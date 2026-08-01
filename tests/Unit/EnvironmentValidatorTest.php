<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EnvironmentValidator;
use PHPUnit\Framework\TestCase;

final class EnvironmentValidatorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach ([
            'GOOGLE_LOGIN_ENABLED',
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET',
            'GOOGLE_REDIRECT_URI',
            'OPENAI_ENABLED',
            'OPENAI_API_KEY',
        ] as $key) {
            $this->saved[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testEnabledOAuthProviderCannotHaveEmptyCredentials(): void
    {
        $_ENV['GOOGLE_LOGIN_ENABLED'] = 'true';
        $_ENV['GOOGLE_CLIENT_ID'] = '';
        $_ENV['GOOGLE_CLIENT_SECRET'] = '';
        $_ENV['GOOGLE_REDIRECT_URI'] = 'http://localhost:8080/auth/google/callback';

        $result = (new EnvironmentValidator())->validate();
        self::assertFalse($result['ok']);
        self::assertStringContainsString(
            'GOOGLE_CLIENT_ID',
            implode("\n", $result['errors'])
        );
        self::assertStringContainsString(
            'GOOGLE_CLIENT_SECRET',
            implode("\n", $result['errors'])
        );
    }

    public function testLegacyOpenAiFeatureFlagStillRequiresApiKey(): void
    {
        $_ENV['GOOGLE_LOGIN_ENABLED'] = 'false';
        $_ENV['OPENAI_ENABLED'] = 'true';
        $_ENV['OPENAI_API_KEY'] = '';

        $result = (new EnvironmentValidator())->validate();

        self::assertFalse($result['ok']);
        self::assertStringContainsString('OPENAI_API_KEY', implode("\n", $result['errors']));
    }
}
