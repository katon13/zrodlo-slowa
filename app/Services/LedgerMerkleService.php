<?php
declare(strict_types=1);

namespace App\Services;

final class LedgerMerkleService
{
    /** @param list<array<string,mixed>> $heads */
    public static function manifest(array $heads): array
    {
        $manifest = [];
        foreach ($heads as $head) {
            $manifest[] = [
                'wallet_id' => (int)$head['wallet_id'],
                'last_transaction_id' => isset($head['last_transaction_id']) ? (int)$head['last_transaction_id'] : null,
                'last_entry_hash' => (string)$head['last_entry_hash'],
                'transaction_count' => (int)$head['transaction_count'],
                'hash_version' => (int)$head['hash_version'],
            ];
        }
        usort($manifest, static fn(array $left, array $right): int => $left['wallet_id'] <=> $right['wallet_id']);
        return $manifest;
    }

    /** @param list<array<string,mixed>> $manifest */
    public static function root(array $manifest): string
    {
        if ($manifest === []) {
            return LedgerHashService::GENESIS_HASH;
        }

        $level = array_map(static function (array $leaf): string {
            $json = json_encode($leaf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            return hash('sha256', "wallet-ledger-leaf:v1\n" . $json);
        }, $manifest);

        while (count($level) > 1) {
            $next = [];
            $count = count($level);
            for ($index = 0; $index < $count; $index += 2) {
                $left = $level[$index];
                $right = $level[$index + 1] ?? $left;
                $next[] = hash('sha256', "wallet-ledger-node:v1\n" . $left . $right);
            }
            $level = $next;
        }
        return $level[0];
    }
}
