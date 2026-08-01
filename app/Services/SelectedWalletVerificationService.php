<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class SelectedWalletVerificationService
{
    public function __construct(
        private readonly Database $database,
        private readonly int $adminId,
        private readonly int $openingPoints,
        private readonly string $manifestHash,
    ) {}

    /** @return array{ok:bool,run_id:?int,archive_count:int,active_transaction_count:int,errors:list<string>} */
    public function verify(): array
    {
        $errors = [];
        $runId = $this->database->cell(
            'SELECT MAX(run_id) FROM selected_migration_legacy_wallet_transactions WHERE source_user_id=:admin',
            ['admin' => $this->adminId]
        );
        $runId = $runId !== null ? (int)$runId : null;
        $archive = $runId !== null
            ? $this->database->all(
                'SELECT * FROM selected_migration_legacy_wallet_transactions
                 WHERE run_id=:run ORDER BY source_transaction_id',
                ['run' => $runId]
            )
            : [];
        if (count($archive) !== 41) {
            $errors[] = 'Archiwum administratora nie zawiera dokładnie 41 transakcji źródłowych.';
        }
        $seen = [];
        foreach ($archive as $item) {
            $sourceId = (int)$item['source_transaction_id'];
            if (isset($seen[$sourceId])) {
                $errors[] = "Archiwum zawiera duplikat transakcji źródłowej #{$sourceId}.";
            }
            $seen[$sourceId] = true;
            $source = json_decode((string)$item['source_row_json'], true);
            if (!is_array($source)) {
                $errors[] = "Archiwalna transakcja #{$sourceId} ma nieprawidłowy JSON.";
                continue;
            }
            $checksum = hash('sha256', $this->canonicalJson($source));
            if (!hash_equals((string)$item['source_row_checksum'], $checksum)) {
                $errors[] = "Archiwalna transakcja #{$sourceId} ma niezgodną sumę SHA-256.";
            }
            foreach ([
                'previous_hash' => 'original_previous_hash',
                'entry_hash' => 'original_entry_hash',
                'hash_algorithm' => 'original_hash_algorithm',
                'hash_version' => 'original_hash_version',
            ] as $sourceField => $archiveField) {
                if ((string)($source[$sourceField] ?? '') !== (string)($item[$archiveField] ?? '')) {
                    $errors[] = "Archiwalna transakcja #{$sourceId} nie zachowuje pola {$sourceField}.";
                }
            }
            if ((int)$item['source_user_id'] !== $this->adminId || (int)$item['source_wallet_id'] !== $this->adminId) {
                $errors[] = "Archiwalna transakcja #{$sourceId} nie należy wyłącznie do administratora.";
            }
        }

        $run = $runId !== null
            ? $this->database->one('SELECT * FROM selected_migration_runs WHERE id=:id', ['id' => $runId])
            : null;
        if (!$run || !hash_equals($this->manifestHash, (string)$run['source_manifest_hash'])) {
            $errors[] = 'Przebieg archiwizacji nie odpowiada zatwierdzonemu manifestowi.';
        }

        $transactions = $this->database->all(
            'SELECT wt.*,w.points_balance,w.main_available_minor,w.main_reserved_minor,
                    w.slowo_available_minor,w.slowo_reserved_minor
             FROM wallet_transactions wt JOIN wallets w ON w.id=wt.wallet_id
             WHERE wt.user_id=:admin ORDER BY wt.id',
            ['admin' => $this->adminId]
        );
        if (count($transactions) !== 1) {
            $errors[] = 'Aktywny łańcuch administratora nie zawiera dokładnie jednego wpisu otwarcia.';
        } else {
            $opening = $transactions[0];
            $meta = json_decode((string)($opening['meta_json'] ?? '{}'), true);
            $validOpening = (string)$opening['type'] === 'selective_migration_opening'
                && (string)$opening['account_type'] === 'points'
                && (string)$opening['source_module'] === 'selected_migration'
                && (int)$opening['amount_minor'] === $this->openingPoints
                && (int)$opening['balance_before_minor'] === 0
                && (int)$opening['balance_after_minor'] === $this->openingPoints
                && (string)$opening['wallet_previous_hash'] === LedgerHashService::GENESIS_HASH
                && (string)$opening['wallet_entry_hash'] !== ''
                && (string)($opening['previous_hash'] ?? '') === ''
                && (string)($opening['entry_hash'] ?? '') === ''
                && is_array($meta)
                && (int)($meta['source_transaction_count'] ?? 0) === 41
                && hash_equals($this->manifestHash, (string)($meta['manifest_hash'] ?? ''));
            if (!$validOpening) {
                $errors[] = 'Aktywny wpis otwarcia nie odpowiada zatwierdzonym parametrom migracji.';
            }
            foreach (['points_balance' => $this->openingPoints, 'main_available_minor' => 0, 'main_reserved_minor' => 0,
                         'slowo_available_minor' => 0, 'slowo_reserved_minor' => 0] as $field => $expected) {
                if ((int)$opening[$field] !== $expected) {
                    $errors[] = "Końcowe saldo {$field} nie odpowiada raportowi startowemu.";
                }
            }
        }

        if ((int)$this->database->cell('SELECT COUNT(*) FROM wallets') !== 1) {
            $errors[] = 'Docelowa baza zawiera portfel inny niż portfel administratora.';
        }
        if ((int)$this->database->cell(
            'SELECT COUNT(*) FROM financial_ledger_anchors WHERE wallet_count=1 AND transaction_count=1'
        ) < 1) {
            $errors[] = 'Brak anchora obejmującego pojedynczy aktywny łańcuch administratora.';
        }
        if ((int)$this->database->cell(
            'SELECT COUNT(*) FROM financial_audit_log WHERE user_id=:admin AND action=\'selective_migration_opening\'',
            ['admin' => $this->adminId]
        ) !== 1) {
            $errors[] = 'Brak jednoznacznego finansowego zdarzenia audytowego otwarcia.';
        }

        return [
            'ok' => $errors === [],
            'run_id' => $runId,
            'archive_count' => count($archive),
            'active_transaction_count' => count($transactions),
            'errors' => $errors,
        ];
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            return $item;
        };
        return json_encode(
            $normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }
}
