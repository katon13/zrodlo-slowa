<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class LedgerAnchorService
{
    private readonly LedgerHashService $hashService;

    public function __construct(private readonly Database $db, ?LedgerHashService $hashService = null)
    {
        $this->hashService = $hashService ?? LedgerHashService::fromEnvironment();
    }

    /** @return array<string,mixed> */
    public function createHourly(?\DateTimeImmutable $moment = null): array
    {
        $moment ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $utc = $moment->setTimezone(new \DateTimeZone('UTC'));
        $end = $utc->setTime((int)$utc->format('H'), 0, 0);
        return $this->create($end->modify('-1 hour'), $end);
    }

    /** @return array<string,mixed> */
    public function create(\DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        if ($periodEnd <= $periodStart) {
            throw new \InvalidArgumentException('Koniec okresu anchora musi być późniejszy niż początek.');
        }
        $start = $periodStart->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end = $periodEnd->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return $this->db->transaction(function (Database $db) use ($start, $end): array {
            $state = $db->one('SELECT mode FROM financial_ledger_migration_state WHERE id=1 FOR SHARE');
            if (!$state || (string)$state['mode'] !== 'per_wallet') {
                return ['skipped' => true, 'reason' => 'wallet_ledger_not_active'];
            }
            $existing = $db->one(
                'SELECT * FROM financial_ledger_anchors WHERE period_start=:start AND period_end=:end LIMIT 1',
                ['start' => $start, 'end' => $end]
            );
            if ($existing) {
                return $existing + ['duplicate' => true];
            }

            $heads = $db->all(
                'SELECT wallet_id,last_transaction_id,last_entry_hash,transaction_count,hash_version
                 FROM financial_wallet_ledger_heads
                 WHERE transaction_count>0
                 ORDER BY wallet_id FOR SHARE'
            );
            $manifest = LedgerMerkleService::manifest($heads);
            $merkleRoot = LedgerMerkleService::root($manifest);
            $previous = $db->one('SELECT anchor_hash,period_end FROM financial_ledger_anchors ORDER BY period_end DESC,id DESC LIMIT 1');
            if ($previous && (string)$previous['period_end'] > $start) {
                throw new \RuntimeException('Nie można dopisać anchora przed końcem ostatniego okresu.');
            }
            $previousHash = (string)($previous['anchor_hash'] ?? LedgerHashService::GENESIS_HASH);
            $cutoff = null;
            $transactionCount = 0;
            foreach ($manifest as $head) {
                if ($head['last_transaction_id'] !== null) {
                    $cutoff = max((int)($cutoff ?? 0), (int)$head['last_transaction_id']);
                }
                $transactionCount += (int)$head['transaction_count'];
            }
            $payload = self::payload($start, $end, $cutoff, count($manifest), $transactionCount, $merkleRoot, $previousHash);
            $anchorHash = $this->hashService->signCanonical($payload);
            $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $sql = $db->isPostgres()
                ? 'INSERT INTO financial_ledger_anchors(period_start,period_end,cutoff_transaction_id,wallet_count,transaction_count,merkle_root,previous_anchor_hash,anchor_hash,hash_algorithm,hash_version,manifest_json,created_at)
                   VALUES(:start,:end,:cutoff,:wallet_count,:transaction_count,:root,:previous,:anchor,:algorithm,:version,:manifest,NOW())
                   ON CONFLICT(period_start,period_end) DO NOTHING'
                : 'INSERT IGNORE INTO financial_ledger_anchors(period_start,period_end,cutoff_transaction_id,wallet_count,transaction_count,merkle_root,previous_anchor_hash,anchor_hash,hash_algorithm,hash_version,manifest_json,created_at)
                   VALUES(:start,:end,:cutoff,:wallet_count,:transaction_count,:root,:previous,:anchor,:algorithm,:version,:manifest,NOW())';
            $db->query($sql, [
                'start' => $start,
                'end' => $end,
                'cutoff' => $cutoff,
                'wallet_count' => count($manifest),
                'transaction_count' => $transactionCount,
                'root' => $merkleRoot,
                'previous' => $previousHash,
                'anchor' => $anchorHash,
                'algorithm' => LedgerHashService::ALGORITHM,
                'version' => LedgerHashService::VERSION,
                'manifest' => $manifestJson,
            ]);
            $anchor = $db->one(
                'SELECT * FROM financial_ledger_anchors WHERE period_start=:start AND period_end=:end LIMIT 1',
                ['start' => $start, 'end' => $end]
            );
            if (!$anchor) {
                throw new \RuntimeException('Nie udało się utrwalić anchora księgi.');
            }
            return $anchor + ['duplicate' => false];
        });
    }

    /** @return array<string,mixed> */
    public static function payload(string $start, string $end, ?int $cutoff, int $walletCount, int $transactionCount, string $root, string $previous): array
    {
        return [
            'anchor_version' => 1,
            'cutoff_transaction_id' => $cutoff,
            'hash_version' => LedgerHashService::VERSION,
            'merkle_root' => $root,
            'period_end' => $end,
            'period_start' => $start,
            'previous_anchor_hash' => $previous,
            'transaction_count' => $transactionCount,
            'wallet_count' => $walletCount,
        ];
    }
}
