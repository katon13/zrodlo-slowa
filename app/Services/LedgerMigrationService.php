<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class LedgerMigrationService
{
    private readonly LedgerHashService $hashService;

    public function __construct(private readonly Database $db, ?LedgerHashService $hashService = null)
    {
        $this->hashService = $hashService ?? LedgerHashService::fromEnvironment();
    }

    /** @return array<string,mixed> */
    public function verifyAndActivate(): array
    {
        return $this->db->transaction(function (Database $db): array {
            $state = $db->one('SELECT * FROM financial_ledger_migration_state WHERE id=1 FOR UPDATE');
            if (!$state) {
                throw new \RuntimeException('Brak stanu migracji księgi. Najpierw uruchom migracje SQL.');
            }
            $db->one('SELECT * FROM financial_ledger_head WHERE id=1 FOR UPDATE');

            if ((string)$state['mode'] === 'per_wallet') {
                $report = (new LedgerIntegrityService($db, $this->hashService))->verify(true);
                if (!$report['ok']) {
                    throw new \RuntimeException('Aktywna księga per-portfel jest niespójna: ' . implode(' | ', $report['errors']));
                }
                return ['activated' => false, 'already_active' => true, 'report' => $report];
            }

            $legacyReport = (new LedgerIntegrityService($db, $this->hashService))->verify(false);
            if (!$legacyReport['ok']) {
                throw new \RuntimeException('Przełączenie zatrzymane: stara księga lub salda są niespójne: ' . implode(' | ', $legacyReport['errors']));
            }

            $transactions = $db->all(
                'SELECT wt.*,w.currency
                 FROM wallet_transactions wt
                 JOIN wallets w ON w.id=wt.wallet_id
                 ORDER BY wt.id'
            );
            $previousByWallet = [];
            $heads = [];
            foreach ($transactions as $transaction) {
                $walletId = (int)$transaction['wallet_id'];
                $previous = $previousByWallet[$walletId] ?? LedgerHashService::GENESIS_HASH;
                $meta = json_decode((string)($transaction['meta_json'] ?? '{}'), true);
                $balanceType = is_array($meta) ? (string)($meta['balance_type'] ?? 'available') : 'available';
                $entryHash = $this->hashService->sign($transaction, (string)$transaction['currency'], $balanceType, $previous);
                $db->query(
                    'UPDATE wallet_transactions
                     SET wallet_previous_hash=:previous,wallet_entry_hash=:entry,
                         wallet_hash_algorithm=:algorithm,wallet_hash_version=:version,wallet_signed_at=NOW()
                     WHERE id=:id',
                    [
                        'previous' => $previous,
                        'entry' => $entryHash,
                        'algorithm' => LedgerHashService::ALGORITHM,
                        'version' => LedgerHashService::VERSION,
                        'id' => $transaction['id'],
                    ]
                );
                $previousByWallet[$walletId] = $entryHash;
                $heads[$walletId] = [
                    'transaction_id' => (int)$transaction['id'],
                    'entry_hash' => $entryHash,
                    'count' => (int)($heads[$walletId]['count'] ?? 0) + 1,
                ];
            }

            $db->query('DELETE FROM financial_wallet_ledger_heads');
            foreach ($heads as $walletId => $head) {
                $db->query(
                    'INSERT INTO financial_wallet_ledger_heads(wallet_id,last_transaction_id,last_entry_hash,transaction_count,hash_version,updated_at)
                     VALUES(:wallet,:transaction,:entry,:count,:version,NOW())',
                    [
                        'wallet' => $walletId,
                        'transaction' => $head['transaction_id'],
                        'entry' => $head['entry_hash'],
                        'count' => $head['count'],
                        'version' => LedgerHashService::VERSION,
                    ]
                );
            }

            $walletReport = (new LedgerIntegrityService($db, $this->hashService))->verify(true);
            if (!$walletReport['ok']) {
                throw new \RuntimeException('Przełączenie zatrzymane: nowy model nie przeszedł testu zgodności: ' . implode(' | ', $walletReport['errors']));
            }
            $head = $db->one('SELECT last_transaction_id FROM financial_ledger_head WHERE id=1');
            $cutover = isset($head['last_transaction_id']) ? (int)$head['last_transaction_id'] : null;
            $compliance = [
                'legacy_transactions' => $legacyReport['legacy_transactions'],
                'wallet_transactions' => $walletReport['wallet_transactions'],
                'wallets' => $walletReport['wallets'],
                'errors' => [],
            ];
            $json = json_encode($compliance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $db->query(
                'UPDATE financial_ledger_migration_state
                 SET mode=\'per_wallet\',legacy_cutover_transaction_id=:cutover,
                     compliance_report_json=:report,compliance_report_hash=:hash,
                     verified_at=NOW(),activated_at=NOW(),updated_at=NOW()
                 WHERE id=1',
                ['cutover' => $cutover, 'report' => $json, 'hash' => hash('sha256', $json)]
            );
            $walletReport['mode'] = 'per_wallet';
            return ['activated' => true, 'already_active' => false, 'cutover_transaction_id' => $cutover, 'report' => $walletReport];
        });
    }
}
