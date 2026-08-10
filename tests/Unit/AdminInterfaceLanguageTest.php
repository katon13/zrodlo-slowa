<?php
declare(strict_types=1);

use App\Services\PublicLanguageService;
use App\Services\PublicSiteResolver;
use PHPUnit\Framework\TestCase;

final class AdminInterfaceLanguageTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup;

    /** @var array<string,mixed> */
    private array $sessionBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->sessionBackup = $_SESSION ?? [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'localhost:8080';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_SESSION = $this->sessionBackup;
        $_GET = [];
        $_POST = [];
    }

    public function testAdminGetRetainsExplicitInterfaceLanguageFromSession(): void
    {
        $_SESSION['interface_language'] = 'de';
        $_SERVER['REQUEST_URI'] = '/admin/settings';

        self::assertSame('de', $this->service()->current('localhost', null, '/admin/settings'));
    }

    public function testPublicGetStillIgnoresSessionLanguageWithoutUrlPrefix(): void
    {
        $_SESSION['interface_language'] = 'de';
        $_SERVER['REQUEST_URI'] = '/articles';

        self::assertSame('pl', $this->service()->current('localhost', null, '/articles'));
    }

    public function testAdminLanguagePrefixOverridesPreviousSessionChoice(): void
    {
        $_SESSION['interface_language'] = 'pl';
        $_SERVER['REQUEST_URI'] = '/en/admin/settings';

        self::assertSame('en', $this->service()->current('localhost', null, '/en/admin/settings'));
    }

    public function testTranslationHelpersNoLongerForcePolishForAdminRequests(): void
    {
        $root = dirname(__DIR__, 2);
        $bootstrap = (string)file_get_contents($root . '/app/Core/bootstrap.php');
        $dors3 = (string)file_get_contents($root . '/app/Services/Dors3UiText.php');

        self::assertStringNotContainsString("if (\$language === null && function_exists('is_admin_request')", $bootstrap);
        self::assertStringNotContainsString("if (\$language === '' && function_exists('is_admin_request')", $dors3);
    }

    private function service(): PublicLanguageService
    {
        $root = dirname(__DIR__, 2);
        $languages = require $root . '/config/languages.php';
        $sites = require $root . '/config/sites.php';
        return new PublicLanguageService($languages, new PublicSiteResolver($sites, $languages));
    }
}
