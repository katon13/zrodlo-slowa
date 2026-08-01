<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class LedgerIntegrityService
{
    private readonly LedgerHashService $hashService;

    public function __construct(private readonly Database $db, ?LedgerHashService $hashService = null)
    {
        $this->hashService = $hashService ?? LedgerHashService::fromEnvironment();
    }

    /** @return array{ok:bool,mode:string,legacy_transactions:int,wallet_transactions:int,wallets:int,anchors:int,errors:list<string>} */
    public function verify(bool $requireWalletChains = true): array
    {
        $errors = [];
        $state = $this->db->one('SELECT * FROM financial_ledger_migration_state WHERE id=1');
        $mode = (string)($state['mode'] ?? 'legacy_global');
        $cutover = isset($state['legacy_cutover_transaction_id'])
            ? (int)$state['legacy_cutover_transaction_id']
            : null;
        $transactions = $this->db->all(
            'SELECT wt.*,w.currency
             FROM wallet_transactions wt
             JOIN wallets w ON w.id=wt.wallet_id
             ORDER BY wt.id'
        );

        $legacyPrevious = LedgerHashService::GENESIS_HASH;
        $legacyLast = null;
        $legacyCount = 0;
        $walletPrevious = [];
        $walletCounts = [];
        $walletLast = [];
        $lastBalances = [];

        foreach ($transactions as $transaction) {
            $id = (int)$transaction['id'];
            $walletId = (int)$transaction['wallet_id'];
            $meta = json_decode((string)($transaction['meta_json'] ?? '{}'), true);
            if (!is_array($meta)) {
                $errors[] = "Transakcja #$id ma nieprawidłowe meta_json.";
                $meta = [];
            }
            $balanceType = (string)($meta['balance_type'] ?? 'available');
            $isLegacy = $mode === 'legacy_global' || ($cutover !== null && $id <= $cutover);

            if ($isLegacy) {
                $legacyCount++;
                $storedPrevious = (string)($transaction['previous_hash'] ?? '');
                if (!hash_equals($legacyPrevious, $storedPrevious)) {
                    $errors[] = "Transakcja #$id przerywa archiwalny łańcuch globalny.";
                }
                $storedHash = (string)($transaction['entry_hash'] ?? '');
                $version = (int)($transaction['hash_version'] ?? 1);
                $valid = $version >= LedgerHashService::VERSION
                    ? $this->hashService->verifyStored($transaction, (string)$transaction['currency'], $balanceType, $storedPrevious, $storedHash)
                    : $this->hashService->verifyLegacyV1($transaction, (string)$transaction['currency'], $balanceType, $storedPrevious, $storedHash);
                if (!$valid) {
                    $errors[] = "Transakcja #$id ma nieprawidłowy archiwalny podpis HMAC.";
                }
                if ($storedHash !== '') {
                    $legacyPrevious = $storedHash;
                }
                $legacyLast = $transaction;
            } elseif ((string)($transaction['previous_hash'] ?? '') !== '' || (string)($transaction['entry_hash'] ?? '') !== '') {
                $errors[] = "Transakcja #$id po przełączeniu nie powinna rozszerzać globalnego łańcucha.";
            }

            if ($requireWalletChains) {
                $expectedPrevious = $walletPrevious[$walletId] ?? LedgerHashService::GENESIS_HASH;
                $storedPrevious = (string)($transaction['wallet_previous_hash'] ?? '');
                $storedHash = (string)($transaction['wallet_entry_hash'] ?? '');
                if (!hash_equals($expectedPrevious, $storedPrevious)) {
                    $errors[] = "Transakcja #$id przerywa łańcuch portfela #$walletId.";
                }
                if ((int)($transaction['wallet_hash_version'] ?? 0) !== LedgerHashService::VERSION) {
                    $errors[] = "Transakcja #$id ma nieprawidłową wersję podpisu per-portfel.";
                } elseif (!$this->hashService->verifyStored(
                    $transaction,
                    (string)$transaction['currency'],
                    $balanceType,
                    $storedPrevious,
                    $storedHash
                )) {
                    $errors[] = "Transakcja #$id ma nieprawidłowy podpis HMAC per-portfel.";
                }
                if ($storedHash !== '') {
                    $walletPrevious[$walletId] = $storedHash;
                }
                $walletCounts[$walletId] = ($walletCounts[$walletId] ?? 0) + 1;
                $walletLast[$walletId] = $transaction;
            }

            $accountType = (string)$transaction['account_type'];
            $balanceKey = $walletId . ':' . $accountType . ':' . $balanceType;
            $legacyPoints = $accountType === 'points'
                && (int)($transaction['hash_version'] ?? 1) < LedgerHashService::VERSION
                && (int)$transaction['points'] !== 0;
            $after = $legacyPoints
                ? (int)$transaction['points_after']
                : (int)$transaction['balance_after_minor'];
            $delta = $legacyPoints
                ? (int)$transaction['points']
                : (int)$transaction['amount_minor'];
            $before = $legacyPoints
                ? (isset($lastBalances[$balanceKey]) ? (int)$lastBalances[$balanceKey]['balance'] : $after - $delta)
                : (int)$transaction['balance_before_minor'];
            if (isset($lastBalances[$balanceKey]) && $before !== (int)$lastBalances[$balanceKey]['balance']) {
                $errors[] = "Transakcja #$id nie kontynuuje poprzedniego salda $balanceKey.";
            }
            if ($after !== $before + $delta) {
                $errors[] = "Transakcja #$id ma niespójną zmianę salda.";
            }
            $lastBalances[$balanceKey] = ['balance' => $after, 'transaction_id' => $id];
        }

        $head = $this->db->one('SELECT * FROM financial_ledger_head WHERE id=1');
        if (!$head) {
            $errors[] = 'Brak archiwalnej głowy globalnej księgi.';
        } elseif ($legacyLast === null) {
            if ($head['last_transaction_id'] !== null || !hash_equals(LedgerHashService::GENESIS_HASH, (string)$head['last_entry_hash'])) {
                $errors[] = 'Archiwalna głowa pustej księgi jest niespójna.';
            }
        } elseif (
            (int)$head['last_transaction_id'] !== (int)$legacyLast['id']
            || !hash_equals((string)$head['last_entry_hash'], (string)$legacyLast['entry_hash'])
        ) {
            $errors[] = 'Archiwalna głowa globalnej księgi nie odpowiada punktowi odcięcia.';
        }

        if ($requireWalletChains) {
            $heads = $this->db->all('SELECT * FROM financial_wallet_ledger_heads ORDER BY wallet_id');
            $headMap = [];
            foreach ($heads as $walletHead) {
                $headMap[(int)$walletHead['wallet_id']] = $walletHead;
            }
            foreach ($walletLast as $walletId => $last) {
                $walletHead = $headMap[$walletId] ?? null;
                if (!$walletHead) {
                    $errors[] = "Brak głowy łańcucha portfela #$walletId.";
                    continue;
                }
                if (
                    (int)$walletHead['last_transaction_id'] !== (int)$last['id']
                    || !hash_equals((string)$walletHead['last_entry_hash'], (string)$last['wallet_entry_hash'])
                    || (int)$walletHead['transaction_count'] !== (int)$walletCounts[$walletId]
                ) {
                    $errors[] = "Głowa łańcucha portfela #$walletId jest niespójna.";
                }
            }
            foreach ($headMap as $walletId => $walletHead) {
                if ((int)$walletHead['transaction_count'] > 0 && !isset($walletLast[$walletId])) {
                    $errors[] = "Głowa portfela #$walletId wskazuje nieistniejącą historię.";
                }
            }
        }

        $wallets = $this->db->all('SELECT * FROM wallets ORDER BY id');
        foreach ($wallets as $wallet) {
            $fields = [
                ['main', 'available', 'main_available_minor'],
                ['main', 'reserved', 'main_reserved_minor'],
                ['slowo', 'available', 'slowo_available_minor'],
                ['slowo', 'reserved', 'slowo_reserved_minor'],
                ['points', 'available', 'points_balance'],
            ];
            foreach ($fields as [$accountType, $balanceType, $field]) {
                $key = (int)$wallet['id'] . ':' . $accountType . ':' . $balanceType;
                if (isset($lastBalances[$key]) && (int)$wallet[$field] !== (int)$lastBalances[$key]['balance']) {
                    $errors[] = sprintf(
                        'Portfel #%d: %s=%d, historia #%d wskazuje %d.',
                        $wallet['id'],
                        $field,
                        $wallet[$field],
                        $lastBalances[$key]['transaction_id'],
                        $lastBalances[$key]['balance']
                    );
                }
            }
            if ((int)$wallet['available_minor'] !== (int)$wallet['main_available_minor'] + (int)$wallet['slowo_available_minor']) {
                $errors[] = "Portfel #{$wallet['id']} ma niespójne łączne saldo dostępne.";
            }
            if ((int)$wallet['reserved_minor'] !== (int)$wallet['main_reserved_minor'] + (int)$wallet['slowo_reserved_minor']) {
                $errors[] = "Portfel #{$wallet['id']} ma niespójne łączne saldo zarezerwowane.";
            }
        }

        $anchorErrors = $this->verifyAnchors();
        array_push($errors, ...$anchorErrors);
        $anchorCount = (int)$this->db->cell('SELECT COUNT(*) FROM financial_ledger_anchors');
        return [
            'ok' => $errors === [],
            'mode' => $mode,
            'legacy_transactions' => $legacyCount,
            'wallet_transactions' => count($transactions),
            'wallets' => count($wallets),
            'anchors' => $anchorCount,
            'errors' => $errors,
        ];
    }

    /** @return list<string> */
    private function verifyAnchors(): array
    {
        $errors = [];
        $previous = LedgerHashService::GENESIS_HASH;
        $anchors = $this->db->all('SELECT * FROM financial_ledger_anchors ORDER BY period_end,id');
        foreach ($anchors as $anchor) {
            $id = (int)$anchor['id'];
            $manifest = json_decode((string)$anchor['manifest_json'], true);
            if (!is_array($manifest)) {
                $errors[] = "Anchor #$id ma nieprawidłowy manifest.";
                continue;
            }
            $manifest = LedgerMerkleService::manifest($manifest);
            $root = LedgerMerkleService::root($manifest);
            if (!hash_equals($root, (string)$anchor['merkle_root'])) {
                $errors[] = "Anchor #$id ma nieprawidłowy Merkle root.";
            }
            if (!hash_equals($previous, (string)$anchor['previous_anchor_hash'])) {
                $errors[] = "Anchor #$id przerywa łańcuch anchorów.";
            }
            $payload = LedgerAnchorService::payload(
                (string)$anchor['period_start'],
                (string)$anchor['period_end'],
                isset($anchor['cutoff_transaction_id']) ? (int)$anchor['cutoff_transaction_id'] : null,
                (int)$anchor['wallet_count'],
                (int)$anchor['transaction_count'],
                (string)$anchor['merkle_root'],
                (string)$anchor['previous_anchor_hash']
            );
            if (!$this->hashService->verifyCanonical($payload, (string)$anchor['anchor_hash'])) {
                $errors[] = "Anchor #$id ma nieprawidłowy podpis HMAC.";
            }
            if ((int)$anchor['wallet_count'] !== count($manifest)) {
                $errors[] = "Anchor #$id ma nieprawidłową liczbę portfeli.";
            }
            $previous = (string)$anchor['anchor_hash'];
        }
        return $errors;
    }
}
