<?php
namespace App\Services;

use App\Core\Database;

final class WalletTransferService
{
    public function __construct(private readonly Database $db, private readonly LedgerService $ledger) {}

    public function quoteTalentToPln(int $talentAmount): array
    {
        $rate = $this->settingInt('wallet.tt_per_pln', 10);
        $talentAmount = max(0, $talentAmount);
        $grossMinor = intdiv($talentAmount * 100, $rate);
        $feePercent = $this->settingInt('wallet.transfer.talent_to_pln.fee_percent', 5);
        $feeMinor = intdiv($grossMinor * $feePercent, 100);
        $netMinor = max(0, $grossMinor - $feeMinor);

        return [
            'talent_amount' => $talentAmount,
            'gross_minor' => $grossMinor,
            'fee_percent' => $feePercent,
            'fee_minor' => $feeMinor,
            'net_minor' => $netMinor,
            'rate_label' => $rate . ' TT = 1 PLN',
        ];
    }

    public function convertTalentToPln(int $userId, int $talentAmount): int
    {
        if (!$this->settingBool('wallet.transfer.talent_to_pln.enabled', true)) {
            throw new \RuntimeException('Konwersja Talent → PLN jest chwilowo wyłączona.');
        }

        $minTalent = $this->settingInt('wallet.transfer.talent_to_pln.min_talent', 100);
        if ($talentAmount < $minTalent) {
            throw new \InvalidArgumentException('Minimalna konwersja to ' . $minTalent . ' Talentów.');
        }

        return $this->ledger->synchronized(function (Database $db) use ($userId, $talentAmount): int {
            $wallet = $this->ledger->walletForUser($userId, true);
            if ((int)$wallet['points_balance'] < $talentAmount) {
                throw new \RuntimeException('Brak Talentów do wykonania konwersji.');
            }

            $quote = $this->quoteTalentToPln($talentAmount);
            $rate = $this->settingInt('wallet.tt_per_pln', 10);
            $risk = $this->riskForTalentToPln($userId, $talentAmount, $quote);
            $status = $risk['requires_review'] ? 'held' : 'completed';
            $completedAt = $status === 'completed' ? 'NOW()' : 'NULL';
            $reason = $risk['reason'];
            $meta = ['quote' => $quote, 'stage' => 'patch4_stage4', 'risk' => $risk, 'talent_deducted' => true, 'rate_used' => $rate];

            $transferId = $db->insert('INSERT INTO wallet_transfers(user_id,direction,status,source_wallet,target_wallet,source_amount,target_amount,fee_amount,rate_numerator,rate_denominator,risk_score,completed_at,reason,metadata_json,created_at,updated_at) VALUES(:user,\'talent_to_pln\',:status,\'talent\',\'pln\',:source,:target,:fee,1,:rate_denominator,:risk,' . $completedAt . ',:reason,:meta,NOW(),NOW())', [
                'user' => $userId,
                'status' => $status,
                'source' => $talentAmount,
                'target' => $quote['net_minor'],
                'fee' => $quote['fee_minor'],
                'risk' => (int)$risk['score'],
                'rate_denominator' => $rate,
                'reason' => $reason,
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);

            $this->ledger->post($userId, $status === 'held' ? 'transfer_hold' : 'transfer_out', 0, -$talentAmount, $status === 'held' ? 'Wstrzymanie Talentów do kontroli konwersji PLN' : 'Przeniesienie Talentów do portfela PLN', [
                'account_type' => 'points',
                'status' => $status === 'held' ? 'reserved' : 'posted',
                'source_module' => 'system',
                'ref_type' => 'wallet_transfer',
                'ref_id' => $transferId,
                'idempotency_key' => 'wallet_transfer:' . $transferId . ':talent_out',
                'meta' => ['direction' => 'talent_to_pln', 'status' => $status, 'risk' => $risk],
            ]);

            if ($status === 'completed') {
                $this->creditCompletedTransfer($transferId, $userId, $quote);
            }

            return $transferId;
        });
    }

    public function approveTransfer(int $transferId, int $adminId): void
    {
        if ($transferId <= 0) {
            throw new \InvalidArgumentException('Brak ID transferu.');
        }

        $this->ledger->synchronized(function (Database $db) use ($transferId, $adminId): void {
            $transfer = $db->one('SELECT * FROM wallet_transfers WHERE id=:id FOR UPDATE', ['id' => $transferId]);
            if (!$transfer) {
                throw new \RuntimeException('Nie znaleziono transferu.');
            }
            if ((string)$transfer['direction'] !== 'talent_to_pln') {
                throw new \RuntimeException('Obsługiwany jest tylko transfer Talent → PLN.');
            }
            if ((string)$transfer['status'] === 'completed') {
                return;
            }
            if (!in_array((string)$transfer['status'], ['pending', 'held', 'approved'], true)) {
                throw new \RuntimeException('Transfer ma status, którego nie można zatwierdzić: ' . $transfer['status']);
            }

            $quote = [
                'net_minor' => (int)$transfer['target_amount'],
                'fee_minor' => (int)$transfer['fee_amount'],
                'fee_percent' => $this->settingInt('wallet.transfer.talent_to_pln.fee_percent', 5),
            ];
            $this->creditCompletedTransfer($transferId, (int)$transfer['user_id'], $quote);

            $db->query('UPDATE wallet_transfers SET status=\'completed\', reviewed_by=:admin, reviewed_at=NOW(), completed_at=NOW(), updated_at=NOW(), reason=COALESCE(reason,\'Zatwierdzone przez administrację\') WHERE id=:id', [
                'admin' => $adminId,
                'id' => $transferId,
            ]);
        });
    }

    public function rejectTransfer(int $transferId, int $adminId, string $reason): void
    {
        if ($transferId <= 0) {
            throw new \InvalidArgumentException('Brak ID transferu.');
        }

        $this->ledger->synchronized(function (Database $db) use ($transferId, $adminId, $reason): void {
            $transfer = $db->one('SELECT * FROM wallet_transfers WHERE id=:id FOR UPDATE', ['id' => $transferId]);
            if (!$transfer) {
                throw new \RuntimeException('Nie znaleziono transferu.');
            }
            if ((string)$transfer['status'] === 'completed') {
                throw new \RuntimeException('Nie można odrzucić transferu już zaksięgowanego.');
            }
            if (!in_array((string)$transfer['status'], ['pending', 'held', 'approved'], true)) {
                throw new \RuntimeException('Transfer ma status, którego nie można odrzucić: ' . $transfer['status']);
            }

            $this->ledger->post((int)$transfer['user_id'], 'transfer_rejected', 0, (int)$transfer['source_amount'], 'Zwrot Talentów po odrzuceniu konwersji PLN', [
                'account_type' => 'points',
                'source_module' => 'system',
                'ref_type' => 'wallet_transfer',
                'ref_id' => $transferId,
                'idempotency_key' => 'wallet_transfer:' . $transferId . ':talent_return',
                'meta' => ['direction' => 'talent_to_pln', 'reason' => $reason],
            ]);

            $db->query('UPDATE wallet_transfers SET status=\'rejected\', reviewed_by=:admin, reviewed_at=NOW(), reason=:reason, updated_at=NOW() WHERE id=:id', [
                'admin' => $adminId,
                'reason' => mb_substr($reason, 0, 255),
                'id' => $transferId,
            ]);
        });
    }

    private function creditCompletedTransfer(int $transferId, int $userId, array $quote): void
    {
        if ((int)$quote['net_minor'] > 0) {
            $this->ledger->post($userId, 'transfer_in', (int)$quote['net_minor'], 0, 'Zasilenie portfela PLN po konwersji Talentów', [
                'account_type' => 'main',
                'source_module' => 'system',
                'ref_type' => 'wallet_transfer',
                'ref_id' => $transferId,
                'idempotency_key' => 'wallet_transfer:' . $transferId . ':pln_in',
                'meta' => ['direction' => 'talent_to_pln', 'fee_minor' => (int)$quote['fee_minor']],
            ]);
        }

        if ((int)$quote['fee_minor'] > 0) {
            $this->ledger->post($userId, 'platform_fee', 0, 0, 'Prowizja systemu za konwersję Talentów do PLN: ' . (int)$quote['fee_percent'] . '%', [
                'account_type' => 'main',
                'source_module' => 'system',
                'ref_type' => 'wallet_transfer',
                'ref_id' => $transferId,
                'idempotency_key' => 'wallet_transfer:' . $transferId . ':fee_note',
                'meta' => ['fee_minor' => (int)$quote['fee_minor']],
            ]);
        }
    }

    private function riskForTalentToPln(int $userId, int $talentAmount, array $quote): array
    {
        $maxDaily = $this->settingInt('wallet.transfer.talent_to_pln.max_daily_talent', 5000);
        $autoApproveBelow = $this->settingInt('wallet.transfer.talent_to_pln.auto_approve_below_pln_minor', 5000);
        $todayUsed = 0;
        try {
            $todayUsed = (int)$this->db->cell('SELECT COALESCE(SUM(source_amount),0) FROM wallet_transfers WHERE user_id=:user AND direction=\'talent_to_pln\' AND status IN (\'pending\',\'held\',\'completed\',\'approved\') AND DATE(created_at)=CURRENT_DATE', ['user' => $userId]);
        } catch (\Throwable) {}

        if ($todayUsed + $talentAmount > $maxDaily) {
            return ['requires_review' => true, 'score' => 80, 'reason' => 'Przekroczony dzienny limit konwersji Talent → PLN.'];
        }
        if ((int)$quote['gross_minor'] > $autoApproveBelow) {
            return ['requires_review' => true, 'score' => 45, 'reason' => 'Kwota przekracza próg autoakceptacji. Wymagana kontrola administracji.'];
        }
        return ['requires_review' => false, 'score' => 0, 'reason' => 'Autoakceptacja w limicie PATCH 4.'];
    }

    private function settingBool(string $name, bool $default): bool
    {
        try {
            $value = $this->db->cell('SELECT value FROM settings WHERE name=:name LIMIT 1', ['name' => $name]);
            if ($value === null) return $default;
            return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function settingInt(string $name, int $default): int
    {
        try {
            $value = $this->db->cell('SELECT value FROM settings WHERE name=:name LIMIT 1', ['name' => $name]);
            return is_numeric($value) ? (int)$value : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
