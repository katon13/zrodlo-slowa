<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Services\InstallService;

$options = getopt('', [
    'confirm',
    'admin-name:',
    'admin-email:',
    'admin-password:',
    'force',
]);

$env = (string)env('APP_ENV', 'local');
if ($env === 'production' && !isset($options['force'])) {
    fwrite(STDERR, "BŁĄD: install_fresh.php jest zablokowany na APP_ENV=production.\n");
    exit(1);
}

if (!isset($options['confirm'])) {
    fwrite(STDERR, "BŁĄD: To jest instalacja od zera. Dodaj --confirm.\n");
    fwrite(STDERR, "Przykład: php scripts/install_fresh.php --confirm --admin-name=\"Jacek\" --admin-email=\"admin@zrodlo-slowa.pl\" --admin-password=\"MocneHaslo12345!\"\n");
    exit(1);
}

$adminName = trim((string)($options['admin-name'] ?? env('ADMIN_DISPLAY_NAME', 'Administrator')));
$adminEmail = trim((string)($options['admin-email'] ?? env('ADMIN_EMAIL', 'admin@zrodlo-slowa.local')));
$adminPassword = (string)($options['admin-password'] ?? env('ADMIN_PASSWORD', ''));

if ($adminName === '') {
    fwrite(STDERR, "BŁĄD: --admin-name nie może być pusty.\n");
    exit(1);
}
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "BŁĄD: --admin-email musi być poprawnym adresem e-mail.\n");
    exit(1);
}
if (strlen($adminPassword) < 12) {
    fwrite(STDERR, "BŁĄD: --admin-password musi mieć minimum 12 znaków.\n");
    exit(1);
}

$_ENV['ADMIN_DISPLAY_NAME'] = $adminName;
$_ENV['ADMIN_EMAIL'] = $adminEmail;
$_ENV['ADMIN_PASSWORD'] = $adminPassword;

$config = require __DIR__ . '/../config/database.php';
$service = new InstallService(dirname(__DIR__), $config);

try {
    $result = $service->install(true);
    $result['mode'] = 'install_fresh';
    $result['admin']['email'] = $adminEmail;
    $result['admin']['display_name'] = $adminName;
    $result['safety'] = [
        'app_env' => $env,
        'production_block' => $env === 'production' ? 'force_used' : 'not_needed',
        'confirm' => true,
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "BŁĄD INSTALACJI OD ZERA: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
