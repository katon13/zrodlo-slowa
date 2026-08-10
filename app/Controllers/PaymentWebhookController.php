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
                throw new \RuntimeException(t('controller.paymentwebhook.nie_znaleziono_patnosci'));
            }
            if ((string)$payment['provider'] !== 'manual') {
                throw new \RuntimeException(t('controller.paymentwebhook.patnosc_operatora_zewnetrznego_moze_potwierdzic_wyaczni_53e11c40'));
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
            $this->app->session->flash('success', t('controller.paymentwebhook.patnosc_oznaczona_jako_zapacona'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.paymentwebhook.nie_udao_sie_oznaczyc_patnosci_jako_zapaconej'), 'manual_payment'));
        }
        redirect('/admin/payments');
    }
}
