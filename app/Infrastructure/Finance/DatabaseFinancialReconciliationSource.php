<?php
declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Contracts\FinancialReconciliationSourceInterface;
use App\Core\Database;

final class DatabaseFinancialReconciliationSource implements FinancialReconciliationSourceInterface
{
    public function __construct(private readonly Database $db) {}

    public function snapshot(): array
    {
        $wallets = $this->db->one(
            'SELECT COUNT(*) AS wallet_count,
                    COALESCE(SUM(main_available_minor),0) AS main_available_minor,
                    COALESCE(SUM(main_reserved_minor),0) AS main_reserved_minor,
                    COALESCE(SUM(slowo_available_minor),0) AS slowo_available_minor,
                    COALESCE(SUM(slowo_reserved_minor),0) AS slowo_reserved_minor,
                    COALESCE(SUM(points_balance),0) AS points_balance
             FROM wallets'
        ) ?? [];
        $ledger = $this->db->one(
            'SELECT COUNT(*) AS transaction_count,
                    COALESCE(SUM(amount_minor),0) AS transaction_amount_minor
             FROM wallet_transactions'
        ) ?? [];
        $payouts = $this->db->one(
            'SELECT COUNT(*) AS payout_count,
                    COALESCE(SUM(amount_minor),0) AS payout_amount_minor,
                    COALESCE(SUM(CASE WHEN status=\'paid\' THEN amount_minor ELSE 0 END),0) AS paid_payout_minor,
                    COALESCE(SUM(CASE WHEN status IN (\'requested\',\'approved\') THEN amount_minor ELSE 0 END),0) AS open_payout_minor
             FROM payouts'
        ) ?? [];

        $snapshot = [
            'source' => 'application_database',
            'captured_at' => gmdate('c'),
        ];
        foreach ($wallets + $ledger + $payouts as $key => $value) {
            $snapshot[$key] = (int)$value;
        }
        return $snapshot;
    }
}
