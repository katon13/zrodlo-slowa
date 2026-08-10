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
        if ($this->app->session->get('_pending_dors3_mobile_login') !== null && !$this->mobileLoginGateAllows($path)) {
            redirect('/login/3dors-mobile');
        }
        if ($this->app->session->get('_admin_recovery_capability') !== null && !$this->webRecoveryGateAllows($path)) {
            redirect('/security/recovery');
        }
        $route = $this->routes[$method][$path] ?? $this->matchDynamicRoute($method, $path);
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

    private function mobileLoginGateAllows(string $path): bool
    {
        if (in_array($path, ['/login/3dors-mobile', '/login/3dors-mobile/complete', '/logout'], true)) {
            return true;
        }
        if ($path === '/api/mobile/session') {
            return true;
        }
        return str_starts_with($path, '/auth/3dors/mobile/status/')
            || str_starts_with($path, '/api/3dors/mobile/');
    }

    private function webRecoveryGateAllows(string $path): bool
    {
        return in_array($path, ['/logout', '/api/mobile/session'], true)
            || str_starts_with($path, '/security/recovery');
    }

    /** @return array{handler:array,csrf:bool}|null */
    private function matchDynamicRoute(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as $pattern => $route) {
            if (!str_contains($pattern, '{')) {
                continue;
            }
            $parameterNames = [];
            $regexSegments = [];
            foreach (explode('/', trim($pattern, '/')) as $segment) {
                if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D', $segment, $match) === 1) {
                    $parameterNames[] = $match[1];
                    $regexSegments[] = '([^/]{1,200})';
                } else {
                    $regexSegments[] = preg_quote($segment, '~');
                }
            }
            $regex = '/' . implode('/', $regexSegments);
            if (preg_match('~^' . $regex . '$~D', $path, $matches) !== 1) {
                continue;
            }
            array_shift($matches);
            foreach ($parameterNames as $index => $name) {
                $_GET[$name] = rawurldecode((string)($matches[$index] ?? ''));
            }
            return $route;
        }
        return null;
    }

    private function requiresStructuredAudit(string $path): bool
    {
        if ($path === '/security/recovery' || str_starts_with($path, '/security/recovery/')) {
            return true;
        }
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
            '/opinie',
            '/api/talent/referrals',
            '/api/mobile/referral',
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
