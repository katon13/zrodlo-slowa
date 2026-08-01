<?php
namespace App\Payments\Stripe;

use App\Payments\PaymentGatewayInterface;
use App\Payments\PaymentGatewayResult;

final class StripeGateway implements PaymentGatewayInterface
{
    private const STRIPE_API_BASE = 'https://api.stripe.com/v1';

    public function __construct(private readonly array $config = []) {}

    public function providerCode(): string
    {
        return 'stripe';
    }

    public function createCheckoutSession(array $paymentOrder, array $options = []): PaymentGatewayResult
    {
        if (!$this->isEnabled()) {
            return PaymentGatewayResult::failed('Stripe jest wyłączony w konfiguracji. Ustaw STRIPE_ENABLED=true dopiero po wpisaniu kluczy testowych.');
        }

        $secretKey = $this->secretKey();
        if ($secretKey === '' || str_starts_with($secretKey, 'sk_test_xxx') || str_starts_with($secretKey, 'sk_live_xxx')) {
            return PaymentGatewayResult::failed('Brak poprawnego STRIPE_SECRET_KEY w .env.');
        }

        $amountMinor = (int)($paymentOrder['amount_minor'] ?? 0);
        if ($amountMinor < 100) {
            return PaymentGatewayResult::failed('Nieprawidłowa kwota zamówienia Stripe.');
        }

        $currency = strtolower((string)($paymentOrder['currency'] ?? ($this->config['currency'] ?? 'pln')));
        $successUrl = (string)($this->config['success_url'] ?? '');
        $cancelUrl = (string)($this->config['cancel_url'] ?? '');
        if ($successUrl === '' || $cancelUrl === '') {
            return PaymentGatewayResult::failed('Brak success_url albo cancel_url dla Stripe Checkout.');
        }

        $methods = $this->configuredPaymentMethods();
        if ($methods === []) {
            $methods = ['card', 'p24'];
        }

        $payload = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string)($paymentOrder['public_id'] ?? $paymentOrder['id'] ?? ''),
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => [
                        'name' => 'Doładowanie portfela ŹRÓDŁA SŁOWA',
                        'description' => 'Zasilenie wewnętrznego portfela PLN. Zakup treści premium pozostaje portfelowy.',
                    ],
                    'unit_amount' => $amountMinor,
                ],
                'quantity' => 1,
            ]],
            'payment_method_types' => $methods,
            'metadata' => [
                'payment_order_id' => (string)($paymentOrder['id'] ?? ''),
                'payment_order_public_id' => (string)($paymentOrder['public_id'] ?? ''),
                'user_id' => (string)($paymentOrder['user_id'] ?? ''),
                'type' => 'wallet_topup',
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'payment_order_id' => (string)($paymentOrder['id'] ?? ''),
                    'payment_order_public_id' => (string)($paymentOrder['public_id'] ?? ''),
                    'user_id' => (string)($paymentOrder['user_id'] ?? ''),
                    'type' => 'wallet_topup',
                ],
            ],
        ];

        try {
            $session = $this->post('/checkout/sessions', $payload, (string)($paymentOrder['idempotency_key'] ?? ''));
            $sessionId = (string)($session['id'] ?? '');
            $url = (string)($session['url'] ?? '');
            if ($sessionId === '' || $url === '') {
                return PaymentGatewayResult::failed('Stripe nie zwrócił poprawnej sesji Checkout.');
            }

            return PaymentGatewayResult::redirect($url, [
                'stripe_session_id' => $sessionId,
                'stripe_payment_intent_id' => isset($session['payment_intent']) ? (string)$session['payment_intent'] : null,
                'raw' => $session,
            ]);
        } catch (\Throwable $e) {
            return PaymentGatewayResult::failed('Błąd Stripe Checkout: ' . $e->getMessage());
        }
    }

    public function configuredPaymentMethods(): array
    {
        $methods = (string)($this->config['payment_methods'] ?? 'card,p24');
        return array_values(array_filter(array_map(static fn($v) => trim((string)$v), explode(',', $methods))));
    }

    private function isEnabled(): bool
    {
        return in_array(strtolower((string)($this->config['enabled'] ?? 'false')), ['1', 'true', 'yes', 'on'], true) || ($this->config['enabled'] ?? false) === true;
    }

    private function secretKey(): string
    {
        return trim((string)($this->config['secret_key'] ?? ''));
    }

    private function post(string $path, array $payload, string $idempotencyKey = ''): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Rozszerzenie PHP cURL jest wymagane do połączenia ze Stripe API.');
        }

        $ch = curl_init(self::STRIPE_API_BASE . $path);
        $headers = [];
        if ($idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_USERPWD => $this->secretKey() . ':',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 25,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $body === '') {
            throw new \RuntimeException($curlError !== '' ? $curlError : 'pusta odpowiedź Stripe');
        }

        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('niepoprawna odpowiedź JSON ze Stripe');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $json['error']['message'] ?? ('HTTP ' . $httpCode);
            throw new \RuntimeException((string)$message);
        }

        return $json;
    }
}
