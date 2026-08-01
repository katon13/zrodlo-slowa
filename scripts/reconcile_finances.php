<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Infrastructure\Finance\DatabaseFinancialReconciliationSource;

$config = require __DIR__ . '/../config/database.php';
$db = new Database($config['default']);
$snapshot = (new DatabaseFinancialReconciliationSource($db))->snapshot();

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/verify_ledger.php');
$status = 0;
passthru($command, $status);

$report = [
    'ok' => $status === 0,
    'snapshot' => $snapshot,
    'checks' => [
        'ledger_hash_and_wallet_balance' => $status === 0 ? 'ok' : 'failed',
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? 0 : 1);
