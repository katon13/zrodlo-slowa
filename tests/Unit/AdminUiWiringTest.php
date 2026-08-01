<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminUiWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testEveryDeclaredRouteTargetsAnExistingPublicControllerMethod(): void
    {
        $source = (string)file_get_contents($this->root . '/public/index.php');
        preg_match_all(
            '/\$router->(get|post)\(\'([^\']+)\',\s*\[([A-Za-z0-9_]+)::class,\s*\'([A-Za-z0-9_]+)\'\]/',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        self::assertNotEmpty($matches);
        $errors = [];
        foreach ($matches as $route) {
            [, $method, $path, $controller, $action] = $route;
            $class = 'App\\Controllers\\' . $controller;
            if (!class_exists($class)) {
                $errors[] = strtoupper($method) . ' ' . $path . ': brak klasy ' . $class;
                continue;
            }
            if (!method_exists($class, $action)) {
                $errors[] = strtoupper($method) . ' ' . $path . ': brak metody ' . $class . '::' . $action;
                continue;
            }
            if (!(new \ReflectionMethod($class, $action))->isPublic()) {
                $errors[] = strtoupper($method) . ' ' . $path . ': metoda nie jest publiczna';
            }
        }

        self::assertSame([], $errors, implode("\n", $errors));
    }

    public function testEveryLiteralAdminFormAndFetchEndpointHasAPostRoute(): void
    {
        $routes = $this->routes();
        $postRoutes = array_keys(array_filter($routes, static fn(array $methods): bool => isset($methods['post'])));
        $errors = [];

        foreach ($this->adminViewSources() as $file => $source) {
            preg_match_all('/<form\b[^>]*\baction\s*=\s*["\'](\/admin[^"\']*)["\']/i', $source, $forms);
            preg_match_all('/\bfetch\s*\(\s*["\'](\/admin[^"\']*)["\']/i', $source, $fetches);
            foreach (array_merge($forms[1], $fetches[1]) as $endpoint) {
                $path = (string)(parse_url(html_entity_decode($endpoint), PHP_URL_PATH) ?: '');
                if (!in_array($path, $postRoutes, true)) {
                    $errors[] = $file . ': brak trasy POST ' . $path;
                }
            }
        }

        self::assertSame([], array_values(array_unique($errors)), implode("\n", $errors));
    }

    public function testEveryLiteralAdminLinkHasAGetRoute(): void
    {
        $routes = $this->routes();
        $getRoutes = array_keys(array_filter($routes, static fn(array $methods): bool => isset($methods['get'])));
        $errors = [];

        foreach ($this->adminViewSources() as $file => $source) {
            preg_match_all('/\bhref\s*=\s*["\'](\/admin[^"\']*)["\']/i', $source, $links);
            foreach ($links[1] as $endpoint) {
                $path = (string)(parse_url(html_entity_decode($endpoint), PHP_URL_PATH) ?: '');
                if (!in_array($path, $getRoutes, true)) {
                    $errors[] = $file . ': brak trasy GET ' . $path;
                }
            }
        }

        self::assertSame([], array_values(array_unique($errors)), implode("\n", $errors));
    }

    public function testAdminViewsDoNotUseBrowserPopupDialogs(): void
    {
        $errors = [];
        foreach ($this->adminViewSources() as $file => $source) {
            if (preg_match('/\b(?:window\.)?(?:alert|confirm|prompt)\s*\(/i', $source)) {
                $errors[] = $file . ': używa wyskakującego okna przeglądarki';
            }
        }

        self::assertSame([], $errors, implode("\n", $errors));
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function routes(): array
    {
        $source = (string)file_get_contents($this->root . '/public/index.php');
        preg_match_all('/\$router->(get|post)\(\'([^\']+)\'/', $source, $matches, PREG_SET_ORDER);
        $routes = [];
        foreach ($matches as $route) {
            $routes[$route[2]][$route[1]] = true;
        }
        return $routes;
    }

    /**
     * @return array<string, string>
     */
    private function adminViewSources(): array
    {
        $directory = $this->root . '/views/admin';
        $sources = [];
        foreach (new \DirectoryIterator($directory) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $sources[$file->getFilename()] = (string)file_get_contents($file->getPathname());
        }
        return $sources;
    }
}
