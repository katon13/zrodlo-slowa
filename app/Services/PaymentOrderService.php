<?php
namespace App\Services;

use App\Core\Database;

final class PaymentOrderService
{
    public function __construct(private readonly Database $db) {}

    public function createWalletTopupOrder(int $userId, array $package, string $provider = 'stripe', ?string $method = null): int
    {
        $amountMinor = (int)($package['amount_minor'] ?? 0);
        if ($amountMinor < 100) {
            throw new \InvalidArgumentException('Nieprawidłowa kwota doładowania.');
        }

        $publicId = 'po_' . bin2hex(random_bytes(16));
        $idempotencyKey = 'wallet_topup:' . $provider . ':' . $userId . ':' . $publicId;
        $metadata = [
            'package_code' => $package['code'] ?? null,
            'stage' => 'patch4_stage1',
        ];

        return $this->db->insert('INSERT INTO payment_orders(public_id,user_id,provider,method,type,status,amount_minor,currency,topup_package_id,idempotency_key,expires_at,metadata_json,created_at,updated_at) VALUES(:public_id,:user_id,:provider,:method,\'wallet_topup\',\'pending\',:amount_minor,:currency,:package_id,:idempotency_key,' . $this->db->nowPlus(2, 'hour') . ',:metadata,NOW(),NOW())', [
            'public_id' => $publicId,
            'user_id' => $userId,
            'provider' => $provider,
            'method' => $method,
            'amount_minor' => $amountMinor,
            'currency' => strtoupper((string)($package['currency'] ?? 'PLN')),
            'package_id' => isset($package['id']) ? (int)$package['id'] : null,
            'idempotency_key' => $idempotencyKey,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function attachStripeSession(int $orderId, string $sessionId, ?string $paymentIntentId = null): void
    {
        $this->db->query('UPDATE payment_orders SET stripe_session_id=:session_id, stripe_payment_intent_id=:pi, status=\'redirected\', updated_at=NOW() WHERE id=:id', [
            'id' => $orderId,
            'session_id' => $sessionId,
            'pi' => $paymentIntentId,
        ]);
    }

    public function find(int $orderId): ?array
    {
        return $this->db->one('SELECT po.*, p.code AS package_code, p.name AS package_name FROM payment_orders po LEFT JOIN wallet_topup_packages p ON p.id=po.topup_package_id WHERE po.id=:id LIMIT 1', ['id' => $orderId]) ?: null;
    }

    public function findByStripeSession(string $sessionId): ?array
    {
        return $this->db->one('SELECT * FROM payment_orders WHERE stripe_session_id=:session_id LIMIT 1', ['session_id' => $sessionId]) ?: null;
    }

    public function markRedirected(int $orderId): void
    {
        $this->db->query('UPDATE payment_orders SET status=\'redirected\', updated_at=NOW() WHERE id=:id AND status=\'pending\'', ['id' => $orderId]);
    }

    public function recentForUser(int $userId, int $limit = 10): array
    {
        try {
            return $this->db->all('SELECT po.*, u.display_name, u.email, p.name AS package_name FROM payment_orders po LEFT JOIN users u ON u.id=po.user_id LEFT JOIN wallet_topup_packages p ON p.id=po.topup_package_id WHERE po.user_id=:user ORDER BY po.created_at DESC, po.id DESC LIMIT ' . (int)$limit, ['user' => $userId]);
        } catch (\Throwable) {
            return [];
        }
    }
}
