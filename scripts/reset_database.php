<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Services\InstallService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Dostęp tylko przez CLI.";
    exit(1);
}

$options = getopt('', [
    'confirm',
    'yes-i-really-want-to-reset-the-database-zrodlo-slowa',
    'keep-admin::',
    'admin-name::',
    'admin-email::',
    'admin-password::',
    'force',
]);

$environment = strtolower((string)env('APP_ENV', 'local'));
if ($environment === 'production' && !isset($options['force'])) {
    fwrite(STDERR, "BŁĄD: reset_database.php jest zablokowany na APP_ENV=production.\n");
    exit(1);
}
if (!isset($options['confirm']) || !isset($options['yes-i-really-want-to-reset-the-database-zrodlo-slowa'])) {
    $databaseName = (string)env('DB_NAME', 'zrodlo_slowa');
    fwrite(STDERR, "UWAGA: operacja niszcząca na bazie danych: {$databaseName}\n");
    fwrite(STDERR, "BŁĄD: reset bazy usuwa wszystkie dane i odtwarza strukturę.\n");
    fwrite(STDERR, "Musisz podać DWA potwierdzenia:\n");
    fwrite(STDERR, "  --confirm\n");
    fwrite(STDERR, "  --yes-i-really-want-to-reset-the-database-zrodlo-slowa\n\n");
    fwrite(STDERR, "Przykład: php scripts/reset_database.php --confirm --yes-i-really-want-to-reset-the-database-zrodlo-slowa\n");
    exit(1);
}

$keepAdmin = trim((string)($options['keep-admin'] ?? ''));
$adminEmail = strtolower(trim((string)($options['admin-email'] ?? ($keepAdmin !== '' ? $keepAdmin : env('ADMIN_EMAIL', '')))));
$adminName = trim((string)($options['admin-name'] ?? env('ADMIN_DISPLAY_NAME', 'Administrator')));
$adminPassword = (string)($options['admin-password'] ?? env('ADMIN_PASSWORD', ''));
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "BŁĄD: adres administratora jest niepoprawny. Użyj --keep-admin albo --admin-email.\n");
    exit(1);
}
if ($adminName === '') {
    fwrite(STDERR, "BŁĄD: nazwa administratora nie może być pusta.\n");
    exit(1);
}
if (
    strlen($adminPassword) < 12
    || preg_match('/[A-Za-z]/', $adminPassword) !== 1
    || preg_match('/[^A-Za-z]/', $adminPassword) !== 1
) {
    fwrite(STDERR, "BŁĄD: ADMIN_PASSWORD albo --admin-password musi mieć minimum 12 znaków, litery i znaki innego typu.\n");
    exit(1);
}

$_ENV['ADMIN_DISPLAY_NAME'] = $adminName;
$_ENV['ADMIN_EMAIL'] = $adminEmail;
$_ENV['ADMIN_PASSWORD'] = $adminPassword;

$config = require __DIR__ . '/../config/database.php';
$service = new InstallService(dirname(__DIR__), $config);
try {
    $before = [
        'database' => $config['default']['database'] ?? null,
        'app_env' => $environment,
        'requested_admin_email' => $adminEmail,
    ];
    $result = $service->install(true, InstallService::FRESH_CONFIRMATION);
    $result['mode'] = 'reset_database';
    $result['reset_report'] = [
        'dropped_and_recreated_tables' => true,
        'structure_rebuilt_from_schema' => true,
        'migrations_baselined' => true,
        'admin_recreated' => $adminEmail,
        'before' => $before,
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD RESETU BAZY: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
