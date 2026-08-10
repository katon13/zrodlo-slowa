<?php
declare(strict_types=1);

$rootPath = dirname(__DIR__);

// Ustaw środowisko przed pierwszym wywołaniem env(). Funkcja env() wczyta
// pozostałe parametry połączenia z lokalnego .env, ale nie nadpisze wartości
// jawnie ustawionych dla procesu PHPUnit.
$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = 'false';

require_once $rootPath . '/app/Core/bootstrap.php';

restore_error_handler();
restore_exception_handler();

date_default_timezone_set('Europe/Warsaw');

$databaseHost = strtolower(trim((string)env('DB_HOST', '127.0.0.1')));
$allowedDatabaseHosts = ['127.0.0.1', 'localhost', 'postgres', 'mariadb', 'mysql'];
if (!in_array($databaseHost, $allowedDatabaseHosts, true)) {
    throw new RuntimeException(
        'PHPUnit odmówił połączenia z nieznanym hostem bazy: ' . $databaseHost
    );
}

$testRunSuffix = bin2hex(random_bytes(6));
$databaseDriver = strtolower((string)env('DB_DRIVER', 'mysql'));
$ownerPid = getmypid();

$_ENV['DB_APPLICATION_NAME'] = 'zrodlo-slowa-phpunit-' . $testRunSuffix;
$_ENV['VALKEY_DATABASE'] = '1';
$_ENV['VALKEY_PREFIX'] = 'zrodlo-slowa:phpunit:' . $testRunSuffix;
$_ENV['ADMIN_EMAIL'] = 'phpunit-admin-' . $testRunSuffix . '@example.test';
$_ENV['ADMIN_DISPLAY_NAME'] = 'Administrator PHPUnit';
$_ENV['ADMIN_PASSWORD'] = 'PHPUnit-Only-Strong-Password-2026!';
$_ENV['SEED_PLATFORM_ACCOUNT'] = 'false';

if ($databaseDriver === 'pgsql') {
    $isolatedDatabaseName = (string)env('DB_NAME', 'zrodlo_slowa');
    $isolatedSchemaName = 'zrodlo_slowa_test_' . $testRunSuffix;
    $_ENV['DB_SCHEMA'] = $isolatedSchemaName;
    $_ENV['DB_ALLOW_CREATE_SCHEMA'] = 'true';
    $_ENV['DB_ALLOW_CREATE_DATABASE'] = 'false';
} elseif ($databaseDriver === 'mysql') {
    $isolatedDatabaseName = 'zrodlo_slowa_test_' . $testRunSuffix;
    $isolatedSchemaName = '';
    $_ENV['DB_NAME'] = $isolatedDatabaseName;
    $_ENV['DB_ALLOW_CREATE_DATABASE'] = 'true';
    $_ENV['DB_ALLOW_CREATE_SCHEMA'] = 'false';
} else {
    throw new RuntimeException('PHPUnit nie obsługuje sterownika bazy: ' . $databaseDriver);
}

$testDatabaseConfig = require $rootPath . '/config/database.php';
$installResult = (new \App\Services\InstallService($rootPath, $testDatabaseConfig))->install();
if (($installResult['ok'] ?? false) !== true) {
    throw new RuntimeException('Nie udało się przygotować izolowanej bazy PHPUnit.');
}

// Dane są dostępne dla testu kontrolnego i raportowania, bez ujawniania
// poświadczeń połączenia.
$GLOBALS['PHPUNIT_DATABASE_ISOLATION'] = [
    'driver' => $databaseDriver,
    'database' => $isolatedDatabaseName,
    'schema' => $isolatedSchemaName,
    'run_suffix' => $testRunSuffix,
];

register_shutdown_function(static function () use (
    $ownerPid,
    $databaseDriver,
    $isolatedDatabaseName,
    $isolatedSchemaName,
    $testDatabaseConfig,
): void {
    // Procesy potomne testów współbieżności dziedziczą callback po fork(),
    // ale nie mogą usuwać środowiska należącego do procesu głównego.
    if (getmypid() !== $ownerPid) {
        return;
    }

    try {
        $config = $testDatabaseConfig['default'];
        if ($databaseDriver === 'pgsql') {
            if (preg_match('/^zrodlo_slowa_test_[a-f0-9]{12}$/D', $isolatedSchemaName) !== 1) {
                return;
            }
            $database = new \App\Core\Database($config);
            $database->pdo()->exec(
                'DROP SCHEMA IF EXISTS ' . $database->quoteIdentifier($isolatedSchemaName) . ' CASCADE'
            );
            return;
        }

        if (preg_match('/^zrodlo_slowa_test_[a-f0-9]{12}$/D', $isolatedDatabaseName) !== 1) {
            return;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['charset'],
        );
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $isolatedDatabaseName) . '`');
    } catch (Throwable $error) {
        fwrite(STDERR, 'Nie udało się usunąć izolowanej bazy PHPUnit: ' . $error->getMessage() . PHP_EOL);
    }
});
