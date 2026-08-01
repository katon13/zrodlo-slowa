<?php
namespace App\Services;

use App\Core\Database;

final class WalletTopupService
{
    public function __construct(private readonly Database $db, private readonly LedgerService $ledger) {}

    public function creditStripeCheckoutSession(array $session): ?int
    {
        $sessionId = (string)($session['id'] ?? '');
        if ($sessionId === '') {
            throw new \RuntimeException('Webhook nie zawiera Stripe Checkout Session ID.');
        }

        return $this->db->transaction(function (Database $db) use ($session, $sessionId): ?int {
            $order = $db->one('SELECT * FROM payment_orders WHERE stripe_session_id=:session_id LIMIT 1', ['session_id' => $sessionId]);
            if (!$order) {
                $metaOrderId = (int)($session['metadata']['payment_order_id'] ?? 0);
                if ($metaOrderId > 0) {
                    $order = $db->one('SELECT * FROM payment_orders WHERE id=:id LIMIT 1', ['id' => $metaOrderId]);
                }
            }
            if (!$order) {
                throw new \RuntimeException('Nie znaleziono lokalnego payment_order dla sesji Stripe.');
            }
            if (($order['type'] ?? '') !== 'wallet_topup') {
                throw new \RuntimeException('Payment order nie jest doładowaniem portfela.');
            }
            if (!empty($order['credited_at']) || ($order['status'] ?? '') === 'credited') {
                return null;
            }

            $amountTotal = isset($session['amount_total']) ? (int)$session['amount_total'] : (int)($session['amount_subtotal'] ?? 0);
            $currency = strtoupper((string)($session['currency'] ?? ''));
            if ($amountTotal !== (int)$order['amount_minor']) {
                throw new \RuntimeException('Kwota Stripe nie zgadza się z lokalnym zamówieniem.');
            }
            if ($currency !== strtoupper((string)$order['currency'])) {
                throw new \RuntimeException('Waluta Stripe nie zgadza się z lokalnym zamówieniem.');
            }

            $paymentStatus = (string)($session['payment_status'] ?? '');
            $status = (string)($session['status'] ?? '');
            if ($paymentStatus !== 'paid' && $status !== 'complete') {
                throw new \RuntimeException('Sesja Stripe nie jest jeszcze opłacona. payment_status=' . $paymentStatus . ', status=' . $status);
            }

            $paymentIntentId = isset($session['payment_intent']) ? (string)$session['payment_intent'] : (string)($order['stripe_payment_intent_id'] ?? '');
            $orderId = (int)$order['id'];
            $userId = (int)$order['user_id'];

            $txId = $this->ledger->post($userId, 'wallet_topup', (int)$order['amount_minor'], 0, 'Doładowanie portfela PLN przez Stripe / Przelewy24', [
                'account_type' => 'main',
                'source_module' => 'stripe',
                'ref_type' => 'payment_order',
                'ref_id' => $orderId,
                'idempotency_key' => 'payment_order:' . $orderId . ':wallet_topup',
                'meta' => [
                    'stripe_session_id' => $sessionId,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'provider' => 'stripe',
                ],
            ]);

            $db->query('UPDATE payment_orders SET status=\'credited\', credited_at=NOW(), stripe_payment_intent_id=COALESCE(NULLIF(:pi,\'\'), stripe_payment_intent_id), updated_at=NOW() WHERE id=:id AND credited_at IS NULL', [
                'id' => $orderId,
                'pi' => $paymentIntentId,
            ]);

            return $txId;
        });
    }

    public function markStripeCheckoutFailed(array $session, string $status): void
    {
        $sessionId = (string)($session['id'] ?? '');
        if ($sessionId === '') {
            return;
        }
        $safeStatus = in_array($status, ['failed', 'expired', 'cancelled'], true) ? $status : 'failed';
        $this->db->query('UPDATE payment_orders SET status=:status, updated_at=NOW() WHERE stripe_session_id=:session_id AND credited_at IS NULL', [
            'status' => $safeStatus,
            'session_id' => $sessionId,
        ]);
    }
}
