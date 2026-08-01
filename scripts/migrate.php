<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Services\InstallService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Dostęp tylko przez CLI.";
    exit(1);
}

try {
    $config = require __DIR__ . '/../config/database.php';
    $service = new InstallService(dirname(__DIR__), $config);
    $result = $service->migrate();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD MIGRACJI: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
