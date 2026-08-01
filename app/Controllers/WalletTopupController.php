<?php
namespace App\Controllers;

use App\Payments\Stripe\StripeGateway;
use App\Services\PaymentOrderService;
use App\Services\PaymentRuntimeConfigService;
use App\Services\WalletTopupPackageService;

final class WalletTopupController extends BaseController
{
    public function index(): string
    {
        $userId = $this->requireAuth();
        $packages = (new WalletTopupPackageService($this->app->db))->activePackages();
        $orders = (new PaymentOrderService($this->app->db))->recentForUser($userId, 8);

        return $this->view('wallet/topup', [
            'title' => t('wallet.topup_title'),
            'packages' => $packages,
            'orders' => $orders,
            'flash_success' => $this->app->session->pullFlash('success'),
            'flash_error' => $this->app->session->pullFlash('error'),
        ]);
    }

    public function create(): never
    {
        $userId = $this->requireAuth();
        $lang = public_language();
        try {
            $packageId = (int)($_POST['package_id'] ?? 0);
            $package = (new WalletTopupPackageService($this->app->db))->find($packageId);
            if (!$package) {
                throw new \RuntimeException(t('wallet.topup.package_not_found'));
            }

            $paymentOrders = new PaymentOrderService($this->app->db);
            $orderId = $paymentOrders->createWalletTopupOrder($userId, $package, 'stripe', 'checkout');
            $order = $paymentOrders->find($orderId);
            if (!$order) {
                throw new \RuntimeException(t('wallet.topup.order_not_found'));
            }

            $paymentsConfig = $this->paymentsConfig();
            $gateway = new StripeGateway($paymentsConfig['stripe'] ?? []);
            $result = $gateway->createCheckoutSession($order);

            if (!$result->ok) {
                $this->app->session->flash('error', $result->error ?: t('wallet.topup.stripe_error'));
                redirect(public_language_url($lang, '/wallet/topup'));
            }

            $paymentOrders->attachStripeSession($orderId, (string)$result->sessionId, $result->paymentIntentId);
            redirect((string)$result->checkoutUrl);
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('wallet.topup.stripe_error'), 'wallet_topup'));
        }
        redirect(public_language_url($lang, '/wallet/topup'));
    }

    public function success(): string
    {
        $this->requireAuth();
        return $this->view('wallet/topup_success', [
            'title' => t('wallet.topup_success_title'),
            'session_id' => trim((string)($_GET['session_id'] ?? '')),
        ]);
    }

    public function cancel(): string
    {
        $this->requireAuth();
        return $this->view('wallet/topup_cancel', [
            'title' => t('wallet.topup_cancel_title'),
        ]);
    }

    private function paymentsConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/config/payments.php';
        $base = is_file($path) ? (array)require $path : [];
        return (new PaymentRuntimeConfigService($this->app->db, $base))->config();
    }
}
