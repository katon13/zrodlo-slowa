<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\LedgerAnchorService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

try {
    $config = require __DIR__ . '/../config/database.php';
    $db = new Database($config['default']);
    $anchor = (new LedgerAnchorService($db))->createHourly();
    echo json_encode(['ok' => true, 'anchor' => $anchor], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD ANCHORA KSIĘGI: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
