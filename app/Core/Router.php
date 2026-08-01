<?php
namespace App\Core;

final class Router
{
    private array $routes = [];
    public function __construct(private readonly App $app) {}
    public function get(string $path, array $handler): void
    {
        $this->routes['GET'][$path] = ['handler' => $handler, 'csrf' => false];
    }

    public function post(string $path, array $handler, array $options = []): void
    {
        $this->routes['POST'][$path] = [
            'handler' => $handler,
            'csrf' => ($options['csrf'] ?? true) === true,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $route = $this->routes[$method][$path] ?? null;
        if (!$route) {
            http_response_code(404);
            echo $this->app->view->render('layouts/error', ['title' => '404', 'message' => 'Nie znaleziono strony.']);
            return;
        }
        if ($method === 'POST' && $route['csrf']) {
            verify_csrf();
        }
        if ($method === 'POST' && $this->requiresStructuredAudit($path)) {
            $this->registerStructuredAudit($path);
        }
        $handler = $route['handler'];
        [$class, $action] = $handler;
        $controller = new $class($this->app);
        echo $controller->$action();
    }

    private function requiresStructuredAudit(string $path): bool
    {
        if (str_starts_with($path, '/admin/')) {
            return true;
        }
        foreach ([
            '/wallet',
            '/payout',
            '/donation',
            '/article/buy',
            '/article/support',
            '/survey/submit',
            '/campaign',
            '/activity/record',
            '/stripe/webhook',
            '/payment/webhook',
        ] as $financialPath) {
            if ($path === $financialPath || str_starts_with($path, $financialPath . '/')) {
                return true;
            }
        }
        return false;
    }

    private function registerStructuredAudit(string $path): void
    {
        $database = $this->app->db;
        $actorUserId = $this->app->session->userId();
        register_shutdown_function(static function () use ($database, $actorUserId, $path): void {
            $status = http_response_code();
            $status = is_int($status) && $status > 0 ? $status : 200;
            $lastError = error_get_last();
            $fatal = is_array($lastError)
                && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
            $result = !$fatal && $status < 400 ? 'success' : 'failure';
            try {
                (new \App\Services\StructuredAuditService($database))->record(
                    $actorUserId,
                    'http.POST ' . $path,
                    ['http_status' => $status],
                    $result
                );
            } catch (\Throwable) {
                // Brak schematu audytu nie może zamienić odpowiedzi w drugi błąd.
            }
        });
    }
}
