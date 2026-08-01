<?php
namespace App\Services;

use App\Core\Database;

final class WalletTopupPackageService
{
    public function __construct(private readonly Database $db) {}

    public function activePackages(): array
    {
        if (!$this->tableExists('wallet_topup_packages')) {
            return $this->fallbackPackages();
        }

        return $this->db->all('SELECT * FROM wallet_topup_packages WHERE is_active=1 ORDER BY sort_order ASC, amount_minor ASC, id ASC');
    }

    public function find(int $id): ?array
    {
        if ($id <= 0 || !$this->tableExists('wallet_topup_packages')) {
            foreach ($this->fallbackPackages() as $package) {
                if ((int)$package['id'] === $id) {
                    return $package;
                }
            }
            return null;
        }

        return $this->db->one('SELECT * FROM wallet_topup_packages WHERE id=:id AND is_active=1 LIMIT 1', ['id' => $id]);
    }

    private function fallbackPackages(): array
    {
        return [
            ['id' => 1, 'code' => 'TOPUP_10_PLN', 'name' => '10 PLN', 'description' => 'Dobry start. Doładowanie portfela PLN.', 'amount_minor' => 1000, 'currency' => 'PLN', 'talent_amount' => null],
            ['id' => 2, 'code' => 'TOPUP_25_PLN', 'name' => '25 PLN', 'description' => 'Najczęściej wybierany pakiet doładowania.', 'amount_minor' => 2500, 'currency' => 'PLN', 'talent_amount' => null],
            ['id' => 3, 'code' => 'TOPUP_50_PLN', 'name' => '50 PLN', 'description' => 'Dla stałego czytelnika i autora.', 'amount_minor' => 5000, 'currency' => 'PLN', 'talent_amount' => null],
            ['id' => 4, 'code' => 'TOPUP_100_PLN', 'name' => '100 PLN', 'description' => 'Dla aktywnego mecenasa ŹRÓDŁA SŁOWA.', 'amount_minor' => 10000, 'currency' => 'PLN', 'talent_amount' => null],
            ['id' => 5, 'code' => 'TOPUP_200_PLN', 'name' => '200 PLN', 'description' => 'Dla większego zasilenia portfela PLN.', 'amount_minor' => 20000, 'currency' => 'PLN', 'talent_amount' => null],
            ['id' => 6, 'code' => 'TOPUP_500_PLN', 'name' => '500 PLN', 'description' => 'Dla stałego mecenasa i aktywnego wydawcy.', 'amount_minor' => 50000, 'currency' => 'PLN', 'talent_amount' => null],
        ];
    }

    private function tableExists(string $table): bool
    {
        return $this->db->tableExists($table);
    }
}
