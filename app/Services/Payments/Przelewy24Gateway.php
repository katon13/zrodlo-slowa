<?php
namespace Book100\Services\Payments;

use Book100\Core\Env;
use Book100\Repository\OrderRepository;
use Book100\Repository\SettingsRepository;
use Book100\Services\Http\SimpleHttpClient;

final class Przelewy24Gateway implements PaymentGatewayInterface
{
    public function createSession(array $order): array
    {
        $merchantId = (int)Env::get('P24_MERCHANT_ID', '0');
        $posId = (int)Env::get('P24_POS_ID', (string)$merchantId);
        $apiKey = Env::get('P24_API_KEY', '');
        $crc = Env::get('P24_CRC', '');
        if (!$merchantId || !$posId || !$apiKey || !$crc) {
            return ['status' => 'not_configured', 'message' => 'Brak peÅ‚nej konfiguracji P24 w .env. ZamÃ³wienie zapisane, ale nie przekierowano do pÅ‚atnoÅ›ci.'];
        }

        $sessionId = $order['order_number'];
        $amount = (int)round(((float)$order['total_gross']) * 100);
        $currency = $order['currency'] ?? 'PLN';
        $appUrl = rtrim(Env::get('APP_URL', ''), '/');
        $payload = [
            'merchantId' => $merchantId,
            'posId' => $posId,
            'sessionId' => $sessionId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => 'ZamÃ³wienie ' . $order['order_number'] . ' â€” ' . (new SettingsRepository())->get('shop_name', 'ARKA'),
            'email' => $order['customer_email'],
            'client' => $order['customer_name'],
            'country' => 'PL',
            'language' => 'pl',
            'urlReturn' => $appUrl . '/dziekujemy/' . $order['order_token'],
            'urlStatus' => $appUrl . '/api/webhooks/przelewy24',
            'timeLimit' => 30,
            'waitForResult' => true,
            'regulationAccept' => false,
            'shipping' => (int)round(((float)($order['shipping_gross'] ?? 0)) * 100),
            'sign' => self::signRegister($sessionId, $merchantId, $amount, $currency, $crc),
        ];
        $url = $this->baseUrl() . '/api/v1/transaction/register';
        $response = (new SimpleHttpClient())->postJson($url, $payload, [], [(string)$posId, $apiKey]);
        $token = $response['json']['data']['token'] ?? null;
        if (!$response['ok'] || !$token) {
            return ['status' => 'error', 'message' => 'P24 nie zwrÃ³ciÅ‚o tokenu transakcji.', 'session_id' => $sessionId, 'raw' => $response];
        }
        return [
            'status' => 'redirected',
            'provider' => 'przelewy24',
            'session_id' => $sessionId,
            'redirect_url' => $this->baseUrl() . '/trnRequest/' . $token,
            'raw' => $response['json'],
        ];
    }

    public function handleWebhook(string $payload, array $headers): array
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) return ['ok' => false, 'status' => 'bad_payload', 'message' => 'Niepoprawny JSON P24.'];
        $crc = Env::get('P24_CRC', '');
        $merchantId = isset($data['merchantId']) ? (int)$data['merchantId'] : 0;
        $posId = isset($data['posId']) ? (int)$data['posId'] : 0;
        $sessionId = (string)($data['sessionId'] ?? '');
        $amount = (int)($data['amount'] ?? 0);
        $originAmount = isset($data['originAmount']) ? (int)$data['originAmount'] : 0;
        $currency = (string)($data['currency'] ?? 'PLN');
        $orderIdP24 = isset($data['orderId']) ? (int)$data['orderId'] : null;
        $methodId = isset($data['methodId']) ? (int)$data['methodId'] : 0;
        $statement = (string)($data['statement'] ?? '');
        $receivedSign = (string)($data['sign'] ?? '');
        $configuredMerchantId = (int)Env::get('P24_MERCHANT_ID', '0');
        $configuredPosId = (int)Env::get('P24_POS_ID', (string)$configuredMerchantId);
        if (!$merchantId || !$posId || !$sessionId || !$amount || !$originAmount || !$crc || !$orderIdP24 || !$methodId || $statement === '' || !$receivedSign) {
            return ['ok'=>false, 'status'=>'missing_fields', 'message'=>'Brak wymaganych pÃ³l lub podpisu P24.'];
        }
        if ($merchantId !== $configuredMerchantId || $posId !== $configuredPosId) {
            return ['ok'=>false, 'status'=>'merchant_mismatch', 'message'=>'Niezgodny sprzedawca P24.'];
        }
        $expected = self::signNotification(
            $merchantId,
            $posId,
            $sessionId,
            $amount,
            $originAmount,
            $currency,
            $orderIdP24,
            $methodId,
            $statement,
            $crc
        );
        if (!hash_equals($expected, $receivedSign)) {
            return ['ok'=>false, 'status'=>'bad_signature', 'message'=>'Niepoprawny podpis P24.'];
        }
        $repo = new OrderRepository();
        $order = $repo->findByPaymentSession('przelewy24', $sessionId) ?: $repo->findByOrderNumber($sessionId);
        if (!$order) return ['ok' => false, 'status' => 'order_not_found', 'message' => 'Nie znaleziono zamÃ³wienia dla sessionId.'];
        if ((int)round(((float)$order['total_gross']) * 100) !== $amount) return ['ok' => false, 'status' => 'amount_mismatch', 'message' => 'Kwota P24 nie zgadza siÄ™ z zamÃ³wieniem.'];
        $verify = $this->verifyTransaction($sessionId, $orderIdP24, $amount, $currency);
        if (!$verify['ok']) return ['ok' => false, 'status' => 'verify_failed', 'message' => 'P24 verifyTransaction nie potwierdziÅ‚o pÅ‚atnoÅ›ci.', 'raw' => $verify];
        $repo->markPaid((int)$order['id'], 'przelewy24', $orderIdP24 ? (string)$orderIdP24 : null, $data);
        return ['ok' => true, 'status' => 'paid', 'order_id' => (int)$order['id']];
    }

    private function verifyTransaction(string $sessionId, ?int $orderIdP24, int $amount, string $currency): array
    {
        $merchantId = (int)Env::get('P24_MERCHANT_ID', '0');
        $posId = (int)Env::get('P24_POS_ID', (string)$merchantId);
        $apiKey = Env::get('P24_API_KEY', '');
        $crc = Env::get('P24_CRC', '');
        if (!$orderIdP24) return ['ok'=>false, 'status'=>'missing_order_id'];
        $payload = [
            'merchantId' => $merchantId,
            'posId' => $posId,
            'sessionId' => $sessionId,
            'amount' => $amount,
            'currency' => $currency,
            'orderId' => $orderIdP24,
            'sign' => self::signVerify($sessionId, $orderIdP24, $amount, $currency, $crc),
        ];
        $response = (new SimpleHttpClient())->putJson(
            $this->baseUrl() . '/api/v1/transaction/verify',
            $payload,
            [],
            [(string)$posId, $apiKey]
        );
        $verified = $response['ok']
            && (($response['json']['data']['status'] ?? '') === 'success')
            && (int)($response['json']['responseCode'] ?? 0) === 0;
        $response['ok'] = $verified;
        return $response;
    }

    public function refund(array $order, array $payment): array
    {
        $posId = (int)Env::get('P24_POS_ID', Env::get('P24_MERCHANT_ID', '0'));
        $apiKey = (string)Env::get('P24_API_KEY', '');
        $providerOrderId = (int)($payment['provider_payment_id'] ?? 0);
        if (!$posId || $apiKey === '' || !$providerOrderId) {
            return ['ok'=>false, 'message'=>'Brak konfiguracji P24 albo identyfikatora opÅ‚aconej transakcji.'];
        }
        $refundsUuid = bin2hex(random_bytes(16));
        $requestId = substr('ARKA-' . $order['id'] . '-' . date('YmdHis'), 0, 45);
        $payload = [
            'requestId'=>$requestId,
            'refunds'=>[[
                'orderId'=>$providerOrderId,
                'sessionId'=>(string)$order['order_number'],
                'amount'=>(int)round((float)$order['total_gross'] * 100),
                'description'=>'Zwrot zamÃ³wienia ' . $order['order_number'],
            ]],
            'refundsUuid'=>$refundsUuid,
            'urlStatus'=>rtrim((string)Env::get('APP_URL', ''), '/') . '/api/webhooks/przelewy24/refund',
        ];
        $response = (new SimpleHttpClient())->postJson(
            $this->baseUrl() . '/api/v1/transaction/refund',
            $payload,
            [],
            [(string)$posId, $apiKey]
        );
        $accepted = $response['ok']
            && (int)($response['json']['responseCode'] ?? -1) === 0
            && ($response['json']['data'][0]['status'] ?? false) === true;
        return [
            'ok'=>$accepted,
            'finalized'=>false,
            'refund_id'=>$refundsUuid,
            'message'=>$accepted ? 'Zwrot P24 zostaÅ‚ przyjÄ™ty i oczekuje na koÅ„cowe potwierdzenie.' : 'P24 nie przyjÄ™Å‚o zwrotu.',
            'raw'=>$response,
        ];
    }

    public function handleRefundWebhook(string $payload, array $headers): array
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return ['ok'=>false, 'status'=>'bad_payload', 'message'=>'Niepoprawny JSON zwrotu P24.'];
        }
        $required = ['orderId','sessionId','refundsUuid','merchantId','amount','currency','status','sign'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '') {
                return ['ok'=>false, 'status'=>'missing_fields', 'message'=>'Brak wymaganych pÃ³l zwrotu P24.'];
            }
        }
        $merchantId = (int)$data['merchantId'];
        $configuredMerchantId = (int)Env::get('P24_MERCHANT_ID', '0');
        $crc = (string)Env::get('P24_CRC', '');
        if (!$configuredMerchantId || $merchantId !== $configuredMerchantId || $crc === '') {
            return ['ok'=>false, 'status'=>'merchant_mismatch', 'message'=>'Niezgodny sprzedawca P24.'];
        }
        $signPayload = [
            'orderId'=>(int)$data['orderId'],
            'sessionId'=>(string)$data['sessionId'],
            'refundsUuid'=>(string)$data['refundsUuid'],
            'merchantId'=>$merchantId,
            'amount'=>(int)$data['amount'],
            'currency'=>(string)$data['currency'],
            'status'=>(int)$data['status'],
            'crc'=>$crc,
        ];
        $expected = hash('sha384', json_encode($signPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!hash_equals($expected, (string)$data['sign'])) {
            return ['ok'=>false, 'status'=>'bad_signature', 'message'=>'Niepoprawny podpis zwrotu P24.'];
        }

        $repo = new OrderRepository();
        $order = $repo->findByOrderNumber((string)$data['sessionId']);
        if (!$order) {
            return ['ok'=>false, 'status'=>'order_not_found', 'message'=>'Nie znaleziono zamÃ³wienia zwrotu P24.'];
        }
        $payment = $repo->paymentForOrder((int)$order['id']);
        if (!$payment
            || (string)($payment['provider_payment_id'] ?? '') !== (string)(int)$data['orderId']
            || (string)($payment['refund_id'] ?? '') !== (string)$data['refundsUuid']
            || (int)$data['amount'] !== (int)round((float)$order['total_gross'] * 100)
            || strtoupper((string)$data['currency']) !== strtoupper((string)$order['currency'])) {
            return ['ok'=>false, 'status'=>'refund_mismatch', 'message'=>'Dane zwrotu P24 nie zgadzajÄ… siÄ™ z zamÃ³wieniem.'];
        }

        if ((int)$data['status'] === 0) {
            $repo->markRefunded(
                (int)$order['id'],
                (string)$data['refundsUuid'],
                $data,
                $repo->requestedRefundRestock((int)$order['id'])
            );
            return ['ok'=>true, 'status'=>'refunded', 'order_id'=>(int)$order['id']];
        }
        $repo->markRefundRejected((int)$order['id'], $data);
        return ['ok'=>true, 'status'=>'refund_rejected', 'order_id'=>(int)$order['id']];
    }

    private function baseUrl(): string
    {
        return Env::get('P24_MODE', 'sandbox') === 'production' ? 'https://secure.przelewy24.pl' : 'https://sandbox.przelewy24.pl';
    }

    private static function signRegister(string $sessionId, int $merchantId, int $amount, string $currency, string $crc): string
    {
        return hash('sha384', json_encode(['sessionId'=>$sessionId,'merchantId'=>$merchantId,'amount'=>$amount,'currency'=>$currency,'crc'=>$crc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function signVerify(string $sessionId, int $orderId, int $amount, string $currency, string $crc): string
    {
        return hash('sha384', json_encode(['sessionId'=>$sessionId,'orderId'=>$orderId,'amount'=>$amount,'currency'=>$currency,'crc'=>$crc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function signNotification(
        int $merchantId,
        int $posId,
        string $sessionId,
        int $amount,
        int $originAmount,
        string $currency,
        int $orderId,
        int $methodId,
        string $statement,
        string $crc
    ): string {
        return hash('sha384', json_encode([
            'merchantId'=>$merchantId,
            'posId'=>$posId,
            'sessionId'=>$sessionId,
            'amount'=>$amount,
            'originAmount'=>$originAmount,
            'currency'=>$currency,
            'orderId'=>$orderId,
            'methodId'=>$methodId,
            'statement'=>$statement,
            'crc'=>$crc,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
