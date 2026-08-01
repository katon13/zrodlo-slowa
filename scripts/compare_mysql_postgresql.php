<?php
declare(strict_types=1);

/**
 * Read-only parity gate for a controlled MySQL -> PostgreSQL data migration.
 *
 * Source credentials are accepted only from MYSQL_SOURCE_DB_* environment
 * variables. This script never creates, updates or deletes data.
 */

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

$required = [
    'MYSQL_SOURCE_DB_HOST',
    'MYSQL_SOURCE_DB_NAME',
    'MYSQL_SOURCE_DB_USER',
];
foreach ($required as $name) {
    if (trim((string)env($name, '')) === '') {
        fwrite(STDERR, "Brak {$name}. Skrypt nie używa danych Laragona ani wartości domyślnych.\n");
        exit(2);
    }
}

$sourceDatabase = (string)env('MYSQL_SOURCE_DB_NAME');
$source = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string)env('MYSQL_SOURCE_DB_HOST'),
        (int)env('MYSQL_SOURCE_DB_PORT', 3306),
        $sourceDatabase
    ),
    (string)env('MYSQL_SOURCE_DB_USER'),
    (string)env('MYSQL_SOURCE_DB_PASS', ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]
);

$targetConfig = require __DIR__ . '/../config/database.php';
$target = new Database($targetConfig['default']);
if (!$target->isPostgres()) {
    fwrite(STDERR, "Baza docelowa musi używać DB_DRIVER=pgsql.\n");
    exit(2);
}

$sourceTablesStatement = $source->prepare(
    'SELECT table_name
     FROM information_schema.tables
     WHERE table_schema=:schema AND table_type=\'BASE TABLE\'
     ORDER BY table_name'
);
$sourceTablesStatement->execute(['schema' => $sourceDatabase]);
$sourceTables = array_map('strval', $sourceTablesStatement->fetchAll(PDO::FETCH_COLUMN));
$targetTables = array_map(
    static fn(array $row): string => (string)$row['table_name'],
    $target->all(
        'SELECT table_name
         FROM information_schema.tables
         WHERE table_schema=:schema AND table_type=\'BASE TABLE\'
         ORDER BY table_name',
        ['schema' => $target->schema()]
    )
);

$counts = [];
$differences = [];
foreach ($sourceTables as $table) {
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
        throw new RuntimeException("Nieprawidłowa nazwa tabeli źródłowej: {$table}");
    }
    $sourceCount = (int)$source->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
    $targetCount = in_array($table, $targetTables, true)
        ? (int)$target->cell('SELECT COUNT(*) FROM ' . $target->quoteIdentifier($table))
        : null;
    $counts[$table] = ['mysql' => $sourceCount, 'postgresql' => $targetCount];
    if ($targetCount !== $sourceCount) {
        $differences['counts'][$table] = $counts[$table];
    }
}

foreach (array_values(array_diff($targetTables, [...$sourceTables, 'schema_migrations'])) as $table) {
    $differences['unexpected_target_tables'][] = $table;
}

$criticalTables = [
    'wallets',
    'wallet_transactions',
    'payouts',
    'payout_status_logs',
    'financial_approvals',
    'financial_audit_log',
    'payments',
    'payment_events',
    'payment_gateway_events',
    'payment_orders',
    'platform_revenues',
    'donations',
    'article_purchases',
];
$criticalHashes = [];
foreach ($criticalTables as $table) {
    if (!in_array($table, $sourceTables, true) || !in_array($table, $targetTables, true)) {
        $differences['critical_hashes'][$table] = ['mysql' => null, 'postgresql' => null];
        continue;
    }
    $sourceHash = tableHash(
        $source->query('SELECT * FROM `' . $table . '` ORDER BY `id`')
    );
    $targetHash = tableHash(
        $target->query('SELECT * FROM ' . $target->quoteIdentifier($table) . ' ORDER BY id')
    );
    $criticalHashes[$table] = ['mysql' => $sourceHash, 'postgresql' => $targetHash];
    if (!hash_equals($sourceHash, $targetHash)) {
        $differences['critical_hashes'][$table] = $criticalHashes[$table];
    }
}

$report = [
    'ok' => $differences === [],
    'generated_at' => gmdate('c'),
    'source' => [
        'driver' => 'mysql',
        'database' => $sourceDatabase,
        'table_count' => count($sourceTables),
    ],
    'target' => [
        'driver' => 'pgsql',
        'database' => (string)$targetConfig['default']['database'],
        'schema' => $target->schema(),
        'table_count' => count($targetTables),
    ],
    'counts' => $counts,
    'critical_history_hashes' => $criticalHashes,
    'differences' => $differences,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? 0 : 1);

function tableHash(\PDOStatement $statement): string
{
    $hash = hash_init('sha256');
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        ksort($row);
        foreach ($row as $key => $value) {
            if (str_ends_with((string)$key, '_json') || in_array($key, ['payload', 'operation_payload'], true)) {
                $decoded = is_string($value) ? json_decode($value, true) : null;
                if (is_array($decoded)) {
                    $value = canonicalJson($decoded);
                }
            } elseif (is_bool($value) || is_int($value) || is_float($value)) {
                $value = (string)(int)$value;
            }
            $row[$key] = $value;
        }
        hash_update($hash, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    return hash_final($hash);
}

function canonicalJson(array $value): string
{
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = json_decode(canonicalJson($item), true, 512, JSON_THROW_ON_ERROR);
        }
    }
    if (!array_is_list($value)) {
        ksort($value);
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
