<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RouteSecurityConfigurationTest extends TestCase
{
    public function testOnlyExternalCallbacksAndSignedMobileDeviceEndpointsDisableCsrf(): void
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
        foreach ([
            '/api/3dors/mobile/enrollment/complete',
            '/api/3dors/mobile/enrollment/confirm',
            '/api/3dors/mobile/requests/{public_id}/approve',
            '/api/3dors/mobile/requests/{public_id}/reject',
            '/api/3dors/mobile/devices/{device_public_id}/heartbeat',
            '/api/mobile/referral/install',
            '/api/mobile/referral/registration-nonce',
            '/api/mobile/referral/first-session',
        ] as $mobileEndpoint) {
            self::assertStringContainsString("\$router->post('{$mobileEndpoint}'", $source);
        }
        self::assertSame(10, substr_count($source, "['csrf' => false]"));
        self::assertStringContainsString(
            "\$router->post('/admin/security/mobile/enrollment/start', [Dors3MobileAdminController::class, 'startEnrollment']);",
            $source
        );
        self::assertStringContainsString(
            "\$router->post('/admin/security/mobile/enrollments/{enrollment_public_id}/approve', [Dors3MobileAdminController::class, 'approveEnrollment']);",
            $source
        );
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

    public function testAdministratorLegacyAccountSecurityRouteRedirectsToUnifiedPanel(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AccountSecurityController.php');
        $dashboard = (string)file_get_contents(dirname(__DIR__, 2) . '/views/admin/dashboard.php');
        self::assertStringContainsString("if (\$this->app->session->role() === 'admin')", $controller);
        self::assertStringContainsString("redirect('/admin/security/3dors');", $controller);
        self::assertStringNotContainsString('href="/account/security"', $dashboard);
        self::assertSame(1, substr_count($dashboard, 'href="/admin/security/3dors"'));
    }

    public function testMobileSessionUsesDedicatedReadOnlyEndpoint(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/MobileSessionController.php');

        self::assertStringContainsString(
            "\$router->get('/api/mobile/session', [MobileSessionController::class, 'show']);",
            $routes
        );
        self::assertStringContainsString("'authenticated' => false", $controller);
        self::assertStringContainsString("'authenticated' => true", $controller);
        self::assertStringContainsString("header('Cache-Control: no-store, max-age=0')", $controller);
        self::assertStringNotContainsString('password_hash', $controller);
    }

    public function testLimitedWebRecoveryRoutesKeepCsrfAndCannotEscapeToAdminPanel(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $router = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Core/Router.php');
        foreach ([
            '/security/recovery/start',
            '/security/recovery/enrollment/start',
            '/security/recovery/enrollments/{enrollment_public_id}/approve',
            '/security/recovery/enrollments/{enrollment_public_id}/cancel',
            '/security/recovery/codes/generate',
            '/security/recovery/codes/confirm',
            '/security/recovery/finish',
        ] as $path) {
            self::assertStringContainsString("\$router->post('{$path}'", $routes);
        }
        self::assertStringContainsString("get('_admin_recovery_capability')", $router);
        self::assertStringContainsString("redirect('/security/recovery');", $router);
        self::assertStringContainsString("str_starts_with(\$path, '/security/recovery')", $router);
    }
}
