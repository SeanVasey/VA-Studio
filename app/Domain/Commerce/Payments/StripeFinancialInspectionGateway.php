<?php

namespace App\Domain\Commerce\Payments;

/** Own-account test-mode GET observations only; no financial mutation capability. */
interface StripeFinancialInspectionGateway
{
    public function financialState(string $paymentIntentId): array;
}
