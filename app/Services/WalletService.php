<?php
namespace App\Services;

use App\Core\Database;

final class WalletService
{
    private LedgerService $ledger;
    public function __construct(private readonly Database $db)
    {
        $this->ledger = new LedgerService($db, new \App\Services\FinancialService($db));
    }

    public function walletForUser(int $userId): array
    {
        $this->ensureWalletAllowed($userId);
        return $this->ledger->walletForUser($userId);
    }

    public function optionalWalletForUser(int $userId): ?array
    {
        return $this->db->one('SELECT * FROM wallets WHERE user_id=:id LIMIT 1', ['id' => $userId]);
    }

    public function transactions(int $userId, int $limit=20): array
    {
        return $this->ledger->transactions($userId, $limit);
    }

    public function add(int $userId, int $amountMinor, int $points, string $type, string $description, ?string $legacySource=null, ?int $legacyId=null): void
    {
        if ($points !== 0) {
            $this->ensureTalentAllowed($userId);
        }
        if ($amountMinor !== 0) {
            $this->ensureWalletAllowed($userId);
        }
        $this->ledger->post($userId, $type, $amountMinor, $points, $description, ['legacy_source'=>$legacySource, 'legacy_id'=>$legacyId, 'source_module'=>$legacySource ? 'legacy_cm' : 'system']);
    }

    private function ensureWalletAllowed(int $userId): void
    {
        $enabled = (int)$this->db->cell('SELECT wallet_enabled FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if ($enabled !== 1) {
            throw new \RuntimeException('Konto rozliczeniowe nie jest aktywne. Wymagana jest ręczna zgoda administracji.');
        }
    }

    private function ensureTalentAllowed(int $userId): void
    {
        $enabled = (int)$this->db->cell('SELECT talent_enabled FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if ($enabled !== 1) {
            throw new \RuntimeException('Talent nie jest aktywny dla tego użytkownika.');
        }
    }
}
