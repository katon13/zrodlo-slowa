<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\LedgerMigrationService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

try {
    $config = require __DIR__ . '/../config/database.php';
    $db = new Database($config['default']);
    $result = (new LedgerMigrationService($db))->verifyAndActivate();
    echo json_encode(['ok' => true] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'PRZEŁĄCZENIE KSIĘGI ZATRZYMANE: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
