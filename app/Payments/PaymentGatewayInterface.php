<?php
namespace App\Payments;

interface PaymentGatewayInterface
{
    public function providerCode(): string;

    /**
     * Etap 1: metoda rezerwuje kontrakt. Pełne tworzenie sesji Stripe jest w PATCH 4D.
     */
    public function createCheckoutSession(array $paymentOrder, array $options = []): PaymentGatewayResult;
}
