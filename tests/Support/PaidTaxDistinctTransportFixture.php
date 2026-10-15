<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutTransport;
use App\Domain\Commerce\ProductionTaxCheckout\TaxExecutionContext;

/** Distinct synthetic provider identities through the actual checkout protocol, never copied paid rows. */
final class PaidTaxDistinctTransportFixture implements TaxCheckoutTransport
{
    public bool $paid = false;

    private readonly RecordingTaxCheckoutTransport $inner;

    public function __construct(private readonly string $session, private readonly string $payment)
    {
        $this->inner = new RecordingTaxCheckoutTransport;
    }

    public function boundTo(): string
    {
        return $this->inner->boundTo();
    }

    public function create(TaxExecutionContext $context, array $params, string $idempotencyKey): array
    {
        $created = $this->inner->create($context, $params, $idempotencyKey);

        return [...$created, 'id' => $this->session];
    }

    public function retrieve(TaxExecutionContext $context, string $sessionId): array
    {
        CheckoutException::require($sessionId === $this->session);
        $this->inner->paid = $this->paid;
        $session = $this->inner->retrieve($context, RecordingTaxCheckoutTransport::SESSION);

        return [...$session, 'id' => $this->session, 'payment_intent' => $this->paid ? $this->payment : null,
            'url' => $this->paid ? null : 'https://checkout.stripe.com/c/pay/'.$this->session];
    }

    public function paymentIntent(TaxExecutionContext $context, string $paymentId): array
    {
        CheckoutException::require($paymentId === $this->payment);
        $this->inner->paid = $this->paid;
        $payment = $this->inner->paymentIntent($context, RecordingTaxCheckoutTransport::PAYMENT);

        return [...$payment, 'id' => $this->payment];
    }
}
