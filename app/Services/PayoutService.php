<?php
namespace App\Services;

use App\Core\Database;
use App\Core\SlowoSnajperConfig;

final class PayoutService
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    private LedgerService $ledger;
    private FraudGuardService $fraudGuard;

    private array $allowedTransitions = [
        self::STATUS_REQUESTED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_PAID, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_PAID => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    public function __construct(
        private readonly Database $db,
        ?FraudGuardService $fraudGuard = null,
    ) {
        $this->ledger = new LedgerService($db, new \App\Services\FinancialService($db));
        $this->fraudGuard = $fraudGuard ?? new FraudGuardService(
            $db,
            SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2))
        );
    }

    public function request(int $userId, int $amountMinor, string $note = '', ?int $methodId = null): int
    {
        if ($amountMinor < 1000) {
            throw new \InvalidArgumentException('Minimalna wypłata to 10 PLN.');
        }
        (new UserService($this->db))->assertPayoutAccountEligible($userId);

        $this->fraudGuard->assertPayoutAllowed($userId);

        return $this->ledger->synchronized(function(Database $db) use ($userId, $amountMinor, $note, $methodId) {
            if ($methodId !== null) {
                $method = $db->one('SELECT id FROM payout_methods WHERE id=:id AND user_id=:user FOR UPDATE', [
                    'id' => $methodId,
                    'user' => $userId,
                ]);
                if (!$method) {
                    throw new \RuntimeException('Wybrana metoda wypłaty nie należy do użytkownika.');
                }
            }
            $payoutId = $db->insert('INSERT INTO payouts(user_id,payout_method_id,amount_minor,currency,status,note,requested_at,updated_at) VALUES(:user,:method,:amount,\'PLN\',\'requested\',:note,NOW(),NOW())', [
                'user' => $userId,
                'method' => $methodId,
                'amount' => $amountMinor,
                'note' => $note,
            ]);

            $this->ledger->reserveForPayout($userId, $amountMinor, 'Wniosek o wypłatę #' . $payoutId, [
                'ref_type' => 'payout',
                'ref_id' => $payoutId,
                'idempotency_key' => 'payout-request-' . $payoutId,
            ]);

            $this->logStatus($payoutId, null, self::STATUS_REQUESTED, $userId, 'Wniosek złożony przez użytkownika.');
            return $payoutId;
        });
    }

    public function setStatus(int $payoutId, string $status, string $adminNote = '', ?int $adminId = null): void
    {
        if (!array_key_exists($status, $this->allowedTransitions)) {
            throw new \InvalidArgumentException('Nieobsługiwany status wypłaty.');
        }

        $this->ledger->synchronized(function(Database $db) use ($payoutId, $status, $adminNote, $adminId) {
            $payout = $db->one('SELECT * FROM payouts WHERE id=:id FOR UPDATE', ['id' => $payoutId]);
            if (!$payout) {
                throw new \RuntimeException('Nie znaleziono wypłaty.');
            }

            $current = (string)$payout['status'];
            if ($current === $status) {
                return;
            }
            if (!in_array($status, $this->allowedTransitions[$current] ?? [], true)) {
                throw new \RuntimeException("Niedozwolona zmiana statusu wypłaty: {$current} -> {$status}.");
            }

            $userId = (int)$payout['user_id'];
            $amountMinor = (int)$payout['amount_minor'];

            if (in_array($status, [self::STATUS_APPROVED, self::STATUS_PAID], true)) {
                (new UserService($db))->assertPayoutAccountEligible($userId);
                $this->fraudGuard->assertPayoutAllowed($userId, $payoutId);
            }

            if ($status === self::STATUS_APPROVED) {
                $db->query('UPDATE payouts SET status=\'approved\',admin_note=:note,approved_at=NOW(),updated_at=NOW() WHERE id=:id', ['id' => $payoutId, 'note' => $adminNote]);
                $this->ledger->approvePayout($userId, $amountMinor, 'Wypłata zatwierdzona #' . $payoutId, ['ref_type' => 'payout', 'ref_id' => $payoutId, 'idempotency_key' => 'payout-approved-' . $payoutId]);
                $this->logStatus($payoutId, $current, self::STATUS_APPROVED, $adminId, $adminNote);
                return;
            }

            if ($status === self::STATUS_PAID) {
                $db->query('UPDATE payouts SET status=\'paid\',admin_note=:note,paid_at=NOW(),updated_at=NOW() WHERE id=:id', ['id' => $payoutId, 'note' => $adminNote]);
                $this->ledger->markReservedPaid($userId, $amountMinor, 'Wypłata zrealizowana #' . $payoutId, ['ref_type' => 'payout', 'ref_id' => $payoutId, 'idempotency_key' => 'payout-paid-' . $payoutId]);
                $this->logStatus($payoutId, $current, self::STATUS_PAID, $adminId, $adminNote);
                return;
            }

            if ($status === self::STATUS_REJECTED || $status === self::STATUS_CANCELLED) {
                $stamp = $status === self::STATUS_REJECTED ? 'rejected_at=NOW(),' : '';
                $db->query("UPDATE payouts SET status=:status,admin_note=:note,{$stamp}updated_at=NOW() WHERE id=:id", ['id' => $payoutId, 'note' => $adminNote, 'status' => $status]);
                $this->ledger->releaseReserved($userId, $amountMinor, 'Wypłata cofnięta #' . $payoutId, ['ref_type' => 'payout', 'ref_id' => $payoutId, 'idempotency_key' => 'payout-release-' . $payoutId, 'meta' => ['target_status' => $status]]);
                $this->logStatus($payoutId, $current, $status, $adminId, $adminNote);
            }
        });
    }

    public function listAll(int $limit = 50, int $offset = 0, ?string $status = null): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $where = '';
        $params = [];
        if ($status !== null && array_key_exists($status, $this->allowedTransitions)) {
            $where = ' WHERE p.status=:status';
            $params['status'] = $status;
        }
        return $this->db->all('SELECT p.*, u.display_name, u.email, pm.label AS method_label, pm.type AS method_type, pm.account_ref FROM payouts p JOIN users u ON u.id=p.user_id LEFT JOIN payout_methods pm ON pm.id=p.payout_method_id' . $where . ' ORDER BY p.requested_at DESC, p.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
    }

    public function all(int $limit = 50, int $offset = 0, ?string $status = null): array
    {
        return $this->listAll($limit, $offset, $status);
    }

    public function forUser(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->all('SELECT p.*, pm.label AS method_label, pm.type AS method_type, pm.account_ref FROM payouts p LEFT JOIN payout_methods pm ON pm.id=p.payout_method_id WHERE p.user_id=:user ORDER BY p.requested_at DESC, p.id DESC LIMIT ' . $limit, ['user' => $userId]);
    }

    public function statusLogs(int $payoutId): array
    {
        return $this->db->all('SELECT l.*, u.display_name, u.email FROM payout_status_logs l LEFT JOIN users u ON u.id=l.changed_by_user_id WHERE l.payout_id=:id ORDER BY l.created_at DESC, l.id DESC', ['id' => $payoutId]);
    }

    public function summary(): array
    {
        return $this->db->one(
            'SELECT COUNT(*) AS total_count,
                    COALESCE(SUM(amount_minor),0) AS total_amount,
                    SUM(CASE WHEN status=\'requested\' THEN 1 ELSE 0 END) AS requested_count,
                    COALESCE(SUM(CASE WHEN status=\'requested\' THEN amount_minor ELSE 0 END),0) AS requested_amount,
                    SUM(CASE WHEN status=\'approved\' THEN 1 ELSE 0 END) AS approved_count,
                    COALESCE(SUM(CASE WHEN status=\'approved\' THEN amount_minor ELSE 0 END),0) AS approved_amount,
                    SUM(CASE WHEN status=\'paid\' THEN 1 ELSE 0 END) AS paid_count,
                    COALESCE(SUM(CASE WHEN status=\'paid\' THEN amount_minor ELSE 0 END),0) AS paid_amount,
                    SUM(CASE WHEN status IN (\'rejected\',\'cancelled\') THEN 1 ELSE 0 END) AS closed_count
             FROM payouts'
        );
    }

    private function logStatus(int $payoutId, ?string $fromStatus, string $toStatus, ?int $changedByUserId, string $note = ''): void
    {
        $this->db->query('INSERT INTO payout_status_logs(payout_id,from_status,to_status,changed_by_user_id,note,created_at) VALUES(:payout,:from_status,:to_status,:user,:note,NOW())', [
            'payout' => $payoutId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'user' => $changedByUserId,
            'note' => $note,
        ]);
    }
}
