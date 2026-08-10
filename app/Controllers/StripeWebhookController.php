<?php
namespace App\Controllers;

use App\Payments\Stripe\StripeWebhookVerifier;
use App\Services\LedgerService;
use App\Services\PaymentGatewayEventService;
use App\Services\PaymentOrderService;
use App\Services\PaymentRuntimeConfigService;
use App\Services\WalletTopupService;

final class StripeWebhookController extends BaseController
{
    public function handle(): never
    {
        $rawBody = (string)file_get_contents('php://input');
        $signature = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
        $config = $this->paymentsConfig()['stripe'] ?? [];
        $eventService = new PaymentGatewayEventService($this->app->db);
        $gatewayEventId = null;

        try {
            $event = (new StripeWebhookVerifier())->verify($rawBody, $signature, (string)($config['webhook_secret'] ?? ''));
            $object = $event['data']['object'] ?? [];
            $sessionId = is_array($object) ? (string)($object['id'] ?? '') : '';
            $paymentIntentId = is_array($object) ? (string)($object['payment_intent'] ?? '') : '';
            $orderId = is_array($object) ? (int)($object['metadata']['payment_order_id'] ?? 0) : 0;

            $record = $eventService->recordReceived('stripe', (string)$event['id'], (string)$event['type'], $rawBody, [
                'payment_order_id' => $orderId > 0 ? $orderId : null,
                'stripe_session_id' => $sessionId !== '' ? $sessionId : null,
                'stripe_payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : null,
            ]);
            $gatewayEventId = (int)$record['id'];

            if ($record['duplicate']) {
                $this->respond(200, 'duplicate');
            }

            switch ((string)$event['type']) {
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    if (!is_array($object)) {
                        throw new \RuntimeException(t('controller.stripewebhook.brak_obiektu_checkout_session_w_webhooku'));
                    }
                    $txId = (new WalletTopupService($this->app->db, new LedgerService($this->app->db, new \App\Services\FinancialService($this->app->db))))->creditStripeCheckoutSession($object);
                    $order = $sessionId !== '' ? (new PaymentOrderService($this->app->db))->findByStripeSession($sessionId) : null;
                    $eventService->attachOrder($gatewayEventId, $order ? (int)$order['id'] : ($orderId ?: null), $sessionId ?: null, $paymentIntentId ?: null);
                    $eventService->markProcessed($gatewayEventId);
                    $this->respond(200, $txId ? 'credited' : 'already credited');

                case 'checkout.session.async_payment_failed':
                    if (is_array($object)) {
                        (new WalletTopupService($this->app->db, new LedgerService($this->app->db, new \App\Services\FinancialService($this->app->db))))->markStripeCheckoutFailed($object, 'failed');
                    }
                    $eventService->markProcessed($gatewayEventId);
                    $this->respond(200, 'failed recorded');

                case 'checkout.session.expired':
                    if (is_array($object)) {
                        (new WalletTopupService($this->app->db, new LedgerService($this->app->db, new \App\Services\FinancialService($this->app->db))))->markStripeCheckoutFailed($object, 'expired');
                    }
                    $eventService->markProcessed($gatewayEventId);
                    $this->respond(200, 'expired recorded');

                case 'payment_intent.payment_failed':
                    $eventService->markIgnored($gatewayEventId, 'PaymentIntent failed recorded as informational event. Checkout session handles local order status when available.');
                    $this->respond(200, 'payment intent failure ignored');

                default:
                    $eventService->markIgnored($gatewayEventId, 'Unsupported event type for wallet topup fulfillment.');
                    $this->respond(200, 'ignored');
            }
        } catch (\Throwable $e) {
            $reference = (new \App\Services\ErrorReporter())->report($e, 'stripe_webhook');
            if ($gatewayEventId) {
                $eventService->markFailed(
                    $gatewayEventId,
                    str_replace('{reference}', (string)$reference, t('controller.stripewebhook.processing_failed'))
                );
            }
            $this->respond(400, 'webhook rejected');
        }
    }

    private function paymentsConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/config/payments.php';
        $base = is_file($path) ? (array)require $path : [];
        return (new PaymentRuntimeConfigService($this->app->db, $base))->config();
    }

    private function respond(int $code, string $message): never
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
}
