<?php
namespace App\Controllers;

use App\Services\PaymentService;

final class PaymentWebhookController extends BaseController
{
    public function manualPaid(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $payment = $this->app->db->one('SELECT * FROM payments WHERE id=:id LIMIT 1', ['id' => $paymentId]);
            if (!$payment) {
                throw new \RuntimeException('Nie znaleziono płatności.');
            }
            if ((string)$payment['provider'] !== 'manual') {
                throw new \RuntimeException('Płatność operatora zewnętrznego może potwierdzić wyłącznie jego bezpieczny webhook.');
            }
            $externalId = trim((string)($_POST['external_id'] ?? '')) ?: null;
            $this->authorizeCriticalOperation(
                $adminId,
                'payment.manual_mark_paid',
                'payment',
                (string)$paymentId,
                [
                    'user_id' => (int)($payment['user_id'] ?? 0),
                    'amount_minor' => (int)($payment['amount_minor'] ?? 0),
                    'currency' => (string)($payment['currency'] ?? ''),
                    'external_id' => $externalId,
                ],
                ['status' => (string)($payment['status'] ?? '')],
                ['status' => 'paid'],
            );
            (new PaymentService($this->app->db))->markPaid($paymentId, $externalId);
            $this->app->session->flash('success', 'Płatność oznaczona jako zapłacona.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się oznaczyć płatności jako zapłaconej.', 'manual_payment'));
        }
        redirect('/admin/payments');
    }
}
