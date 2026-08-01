<?php
declare(strict_types=1);

/**
 * Jednorazowe podpisanie historycznej księgi formatem HMAC v2.
 *
 * Użycie:
 * php scripts/backfill_ledger_hashes.php --confirm=REBUILD_LEDGER_HASHES
 *
 * Jeżeli istnieją już podpisane wpisy, wymagane jest również --rewrite-all.
 * Skrypt zawsze tworzy tabelę kopii dotychczasowych hashy.
 */

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\LedgerHashService;

if (!in_array('--confirm=REBUILD_LEDGER_HASHES', $argv, true)) {
    fwrite(STDERR, "ODMOWA: wymagany parametr --confirm=REBUILD_LEDGER_HASHES\n");
    exit(2);
}

$rewriteAll = in_array('--rewrite-all', $argv, true);
$dbConfig = require __DIR__ . '/../config/database.php';
$db = new Database($dbConfig['default']);
$hashService = LedgerHashService::fromEnvironment();

$signedCount = (int)$db->cell('SELECT COUNT(*) FROM wallet_transactions WHERE entry_hash IS NOT NULL AND entry_hash<>\'\'');
if ($signedCount > 0 && !$rewriteAll) {
    fwrite(STDERR, "ODMOWA: istnieje $signedCount podpisanych wpisów. Po ręcznej analizie użyj --rewrite-all.\n");
    exit(2);
}

$backupTable = 'wallet_transaction_hash_backup_' . date('Ymd_His');
if (!preg_match('/^[a-z0-9_]+$/', $backupTable)) {
    throw new RuntimeException('Nieprawidłowa nazwa tabeli kopii.');
}
$db->query('CREATE TABLE ' . $db->quoteIdentifier($backupTable) . ' AS
    SELECT id,previous_hash,entry_hash,hash_algorithm,hash_version,signed_at
    FROM wallet_transactions');

$processed = $db->transaction(function (Database $db) use ($hashService): int {
    $head = $db->one('SELECT * FROM financial_ledger_head WHERE id=1 FOR UPDATE');
    if (!$head) {
        throw new RuntimeException('Brak financial_ledger_head. Najpierw uruchom migracje.');
    }

    $transactions = $db->all(
        'SELECT wt.*, w.currency
         FROM wallet_transactions wt
         JOIN wallets w ON w.id=wt.wallet_id
         ORDER BY wt.id ASC
         FOR UPDATE'
    );
    $previousHash = LedgerHashService::GENESIS_HASH;

    foreach ($transactions as $transaction) {
        $meta = json_decode((string)($transaction['meta_json'] ?? '{}'), true);
        if (!is_array($meta)) {
            throw new RuntimeException("Transakcja #{$transaction['id']} ma nieprawidłowe meta_json.");
        }
        $balanceType = (string)($meta['balance_type'] ?? 'available');
        $entryHash = $hashService->sign(
            $transaction,
            (string)$transaction['currency'],
            $balanceType,
            $previousHash
        );
        $db->query(
            'UPDATE wallet_transactions
             SET previous_hash=:previous,entry_hash=:entry,hash_algorithm=:algorithm,
                 hash_version=:version,signed_at=NOW()
             WHERE id=:id',
            [
                'previous' => $previousHash,
                'entry' => $entryHash,
                'algorithm' => LedgerHashService::ALGORITHM,
                'version' => LedgerHashService::VERSION,
                'id' => $transaction['id'],
            ]
        );
        $previousHash = $entryHash;
    }

    $last = $transactions ? $transactions[array_key_last($transactions)] : null;
    $db->query(
        'UPDATE financial_ledger_head
         SET last_transaction_id=:transaction,last_entry_hash=:hash,hash_version=:version,updated_at=NOW()
         WHERE id=1',
        [
            'transaction' => $last['id'] ?? null,
            'hash' => $previousHash,
            'version' => LedgerHashService::VERSION,
        ]
    );
    return count($transactions);
});

echo "ZAKOŃCZONO: podpisano $processed transakcji.\n";
echo "Kopia poprzednich hashy: $backupTable\n";
echo "Uruchom teraz: php scripts/verify_ledger.php\n";
