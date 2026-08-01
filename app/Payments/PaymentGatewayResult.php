<?php
namespace App\Payments;

final class PaymentGatewayResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $checkoutUrl = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $paymentIntentId = null,
        public readonly ?string $error = null,
        public readonly array $raw = [],
    ) {}

    public static function pending(string $message): self
    {
        return new self(false, null, null, null, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, null, null, null, $message);
    }

    public static function redirect(string $checkoutUrl, array $raw = []): self
    {
        return new self(
            true,
            $checkoutUrl,
            isset($raw['stripe_session_id']) ? (string)$raw['stripe_session_id'] : null,
            isset($raw['stripe_payment_intent_id']) ? (string)$raw['stripe_payment_intent_id'] : null,
            null,
            $raw
        );
    }
}
