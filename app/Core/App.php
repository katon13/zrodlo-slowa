<?php
namespace App\Core;

use App\Contracts\DistributedLockInterface;
use App\Contracts\ObjectStorageInterface;
use App\Contracts\QueueSignalInterface;
use App\Contracts\RateLimiterInterface;
use App\Contracts\ValkeyClientInterface;
use App\Infrastructure\Cache\NullCacheStore;
use App\Infrastructure\Cache\ValkeyCacheStore;
use App\Infrastructure\Session\SharedSessionHandler;
use App\Infrastructure\Storage\ObjectStorageFactory;
use App\Infrastructure\Valkey\NullDistributedLock;
use App\Infrastructure\Valkey\NullQueueSignal;
use App\Infrastructure\Valkey\ValkeyClientFactory;
use App\Infrastructure\Valkey\ValkeyDistributedLock;
use App\Infrastructure\Valkey\ValkeyQueueSignal;
use App\Infrastructure\Valkey\ValkeyRateLimiter;
use App\Services\CacheService;
use PDO;

final class App
{
    public function __construct(
        public readonly string $rootPath,
        public readonly array $config,
        public readonly Database $db,
        public readonly View $view,
        public readonly Session $session,
        public readonly CacheService $cache,
        public readonly ?ValkeyClientInterface $valkey,
        public readonly DistributedLockInterface $locks,
        public readonly ?RateLimiterInterface $rateLimiter,
        public readonly QueueSignalInterface $queueSignals,
        public readonly ObjectStorageInterface $objectStorage,
    ) {}

    public static function boot(string $rootPath): self
    {
        $appConfig = require $rootPath . '/config/app.php';
        header_remove('X-Powered-By');
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-Request-ID: ' . RequestContext::requestId());
        $instanceId = trim((string)env('APP_INSTANCE_ID', ''));
        if (($appConfig['env'] ?? 'production') !== 'production' && $instanceId !== '') {
            header('X-App-Instance: ' . preg_replace('/[^A-Za-z0-9_.-]/', '-', $instanceId));
        }
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        $contentSecurityPolicy = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "img-src 'self' data: blob: https:",
            "media-src 'self' data: blob:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'unsafe-inline'",
            "connect-src 'self'",
        ];
        if (($appConfig['env'] ?? 'production') === 'production') {
            $contentSecurityPolicy[] = 'upgrade-insecure-requests';
        }
        header('Content-Security-Policy: ' . implode('; ', $contentSecurityPolicy));

        if (($appConfig['env'] ?? 'production') === 'production') {
            (new \App\Services\EnvironmentValidator())->assertInstallable();
        }

        $dbConfig = require $rootPath . '/config/database.php';
        $database = new Database($dbConfig['default']);
        $storageConfig = require $rootPath . '/config/storage.php';
        $objectStorage = ObjectStorageFactory::create($rootPath, $storageConfig);
        $valkeyConfig = require $rootPath . '/config/valkey.php';
        $valkey = self::connectValkeyWhenNeeded($valkeyConfig);
        $locks = ($valkeyConfig['lock_driver'] ?? 'none') === 'valkey' && $valkey !== null
            ? new ValkeyDistributedLock($valkey)
            : new NullDistributedLock();
        $rateLimiter = ($valkeyConfig['rate_limit_driver'] ?? 'database') === 'valkey' && $valkey !== null
            ? new ValkeyRateLimiter($valkey)
            : null;
        $queueSignals = ($valkeyConfig['queue_signal_driver'] ?? 'none') === 'valkey' && $valkey !== null
            ? new ValkeyQueueSignal($valkey)
            : new NullQueueSignal();

        $sessionConfig = $appConfig['session'];
        $readOnlyMobileSessionProbe = self::isReadOnlyMobileSessionProbe();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string)(int)($valkeyConfig['session_ttl_seconds'] ?? 86400));
        session_name($appConfig['session_name']);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (($valkeyConfig['session_driver'] ?? 'file') === 'valkey') {
                session_set_save_handler(new SharedSessionHandler(
                    $valkey,
                    $database,
                    (int)($valkeyConfig['session_ttl_seconds'] ?? 86400),
                    $readOnlyMobileSessionProbe,
                ), true);
            } else {
                self::configureSessionStorage($rootPath);
            }
            session_set_cookie_params([
                'lifetime' => (int)$sessionConfig['lifetime'],
                'path' => (string)$sessionConfig['path'],
                'domain' => (string)$sessionConfig['domain'],
                'secure' => (bool)$sessionConfig['secure'],
                'httponly' => true,
                'samesite' => (string)$sessionConfig['samesite'],
            ]);
            $sessionStartOptions = $readOnlyMobileSessionProbe ? ['read_and_close' => true] : [];
            if (!@session_start($sessionStartOptions)) {
                throw new \RuntimeException('Nie udało się uruchomić bezpiecznej sesji aplikacji.');
            }
        }
        if ((bool)$sessionConfig['secure'] && !headers_sent()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        $session = new Session();
        if ($session->userId() !== null) {
            try {
                $currentUser = $database->one('SELECT session_version,status FROM users WHERE id=:id LIMIT 1', [
                    'id' => $session->userId(),
                ]);
                $invalidSession =
                    $currentUser === null
                    || !in_array((string)$currentUser['status'], ['active', 'pending_author'], true)
                    || (int)$currentUser['session_version'] !== (int)$session->get('_session_version', -1);
                if ($invalidSession && !$readOnlyMobileSessionProbe) {
                    $session->resetAnonymous();
                }
            } catch (\PDOException $error) {
                error_log('Session version validation unavailable before database migration: ' . $error->getMessage());
            }
        }
        $cacheDriver = (string)($valkeyConfig['cache_driver'] ?? 'file');
        $cache = match ($cacheDriver) {
            'valkey' => new CacheService(
                $valkey !== null ? new ValkeyCacheStore($valkey) : new NullCacheStore(),
                $locks
            ),
            'none' => new CacheService(new NullCacheStore()),
            default => new CacheService($rootPath),
        };

        return new self(
            $rootPath,
            [
                'app' => $appConfig,
                'database' => $dbConfig,
                'storage' => $storageConfig,
                'valkey' => $valkeyConfig,
                'payments' => require $rootPath . '/config/payments.php',
                'ai' => require $rootPath . '/config/ai.php',
                'dors3' => require $rootPath . '/config/dors3.php',
                'languages' => require $rootPath . '/config/languages.php',
                'sites' => require $rootPath . '/config/sites.php',
            ],
            $database,
            new View($rootPath . '/views'),
            $session,
            $cache,
            $valkey,
            $locks,
            $rateLimiter,
            $queueSignals,
            $objectStorage
        );
    }

    private static function connectValkeyWhenNeeded(array $config): ?ValkeyClientInterface
    {
        foreach (['session_driver', 'cache_driver', 'rate_limit_driver', 'lock_driver', 'queue_signal_driver'] as $driver) {
            if (($config[$driver] ?? null) === 'valkey') {
                return ValkeyClientFactory::connect($config);
            }
        }
        return null;
    }

    private static function isReadOnlyMobileSessionProbe(): bool
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        return $method === 'GET' && $path === '/api/mobile/session';
    }

    private static function configureSessionStorage(string $rootPath): void
    {
        $configured = trim((string)env('SESSION_SAVE_PATH', ''));
        $candidates = [];
        if ($configured !== '') {
            $candidates[] = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/D', $configured) === 1
                ? $configured
                : $rootPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured);
        }
        $candidates[] = $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
        $candidates[] = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR . 'zrodlo-slowa'
            . DIRECTORY_SEPARATOR . 'sessions';

        foreach (array_values(array_unique($candidates)) as $directory) {
            if (self::writableRuntimeDirectory($directory)) {
                session_save_path($directory);
                return;
            }
        }

        throw new \RuntimeException(
            'Brak zapisywalnego katalogu sesji. Sprawdź SESSION_SAVE_PATH i uprawnienia storage/sessions.'
        );
    }

    private static function writableRuntimeDirectory(string $directory): bool
    {
        if (
            !is_dir($directory)
            && !@mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            return false;
        }

        $probe = @tempnam($directory, 'zs_session_probe_');
        if ($probe === false) {
            return false;
        }

        $expectedDirectory = realpath($directory);
        $actualDirectory = realpath(dirname($probe));
        $valid = $expectedDirectory !== false
            && $actualDirectory !== false
            && strcasecmp($expectedDirectory, $actualDirectory) === 0;
        @unlink($probe);
        return $valid;
    }
}
