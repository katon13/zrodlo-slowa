<?php
namespace App\Payments\Stripe;

final class StripeWebhookVerifier
{
    /**
     * Weryfikacja zgodna z kontraktem Stripe: podpis liczony na surowym body.
     * Nie wolno wcześniej dekodować ani modyfikować $rawBody.
     */
    public function verify(string $rawBody, string $signatureHeader, string $webhookSecret): array
    {
        $webhookSecret = trim($webhookSecret);
        if ($webhookSecret === '' || str_starts_with($webhookSecret, 'whsec_xxx')) {
            throw new \RuntimeException('Brak poprawnego STRIPE_WEBHOOK_SECRET.');
        }
        if ($signatureHeader === '') {
            throw new \RuntimeException('Brak nagłówka Stripe-Signature.');
        }

        $parts = $this->parseSignatureHeader($signatureHeader);
        $timestamp = $parts['t'] ?? null;
        $signatures = $parts['v1'] ?? [];
        if (!$timestamp || $signatures === []) {
            throw new \RuntimeException('Niepoprawny nagłówek Stripe-Signature.');
        }

        if (abs(time() - (int)$timestamp) > 300) {
            throw new \RuntimeException('Podpis Stripe jest poza tolerancją czasu.');
        }

        $signedPayload = $timestamp . '.' . $rawBody;
        $expected = hash_hmac('sha256', $signedPayload, $webhookSecret);
        $ok = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, (string)$signature)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new \RuntimeException('Nieprawidłowy podpis webhooka Stripe.');
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
            throw new \RuntimeException('Webhook Stripe nie zawiera poprawnego event.id/event.type.');
        }
        return $event;
    }

    private function parseSignatureHeader(string $header): array
    {
        $out = [];
        $signatures = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($key === '' || $value === '') {
                continue;
            }
            if ($key === 'v1') {
                $signatures[] = $value;
            } else {
                $out[$key] = $value;
            }
        }
        if ($signatures !== []) {
            $out['v1'] = $signatures;
        }
        return $out;
    }
}
