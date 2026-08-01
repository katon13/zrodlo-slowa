<?php
declare(strict_types=1);

/** Pełna, tylko-do-odczytu kontrola starego łańcucha, łańcuchów per-portfel, anchorów i sald. */

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\LedgerIntegrityService;

try {
    $dbConfig = require __DIR__ . '/../config/database.php';
    $db = new Database($dbConfig['default']);
    $report = (new LedgerIntegrityService($db))->verify(true);

    echo "--- WERYFIKACJA INTEGRALNOŚCI KSIĘGI ---\n";
    echo 'Tryb: ' . $report['mode'] . "\n";
    echo 'Transakcje archiwalnego łańcucha: ' . $report['legacy_transactions'] . "\n";
    echo 'Transakcje per-portfel: ' . $report['wallet_transactions'] . "\n";
    echo 'Portfele: ' . $report['wallets'] . "\n";
    echo 'Anchory: ' . $report['anchors'] . "\n";

    if (!$report['ok']) {
        foreach ($report['errors'] as $error) {
            echo "[BŁĄD] $error\n";
        }
        echo 'WYNIK: NIEPOPRAWNY (' . count($report['errors']) . " problemów)\n";
        exit(1);
    }

    echo "WYNIK: POPRAWNY — historia, podpisy HMAC, głowy, anchory i wszystkie salda są spójne.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD WERYFIKACJI: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
