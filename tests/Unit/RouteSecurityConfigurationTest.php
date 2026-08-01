<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RouteSecurityConfigurationTest extends TestCase
{
    public function testOnlyExternalCallbacksExplicitlyDisableCsrf(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        self::assertStringContainsString(
            "\$router->post('/auth/apple/callback', [OAuthController::class, 'appleCallback'], ['csrf' => false]);",
            $source
        );
        self::assertStringContainsString(
            "\$router->post('/stripe/webhook', [StripeWebhookController::class, 'handle'], ['csrf' => false]);",
            $source
        );
        self::assertSame(2, substr_count($source, "['csrf' => false]"));
    }

    public function testEveryPostFormRendersACsrfToken(): void
    {
        $views = dirname(__DIR__, 2) . '/views';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views));
        $missing = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            preg_match_all(
                '/<form\b(?=[^>]*method\s*=\s*["\']?post)[^>]*>.*?<\/form>/is',
                $source,
                $forms,
                PREG_OFFSET_CAPTURE
            );
            foreach ($forms[0] as [$form, $offset]) {
                if (!str_contains($form, 'csrf_field(')) {
                    $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                    $missing[] = $file->getPathname() . ':' . $line;
                }
            }
        }
        self::assertSame([], $missing, 'Formularze POST bez tokenu CSRF: ' . implode(', ', $missing));
    }
}
