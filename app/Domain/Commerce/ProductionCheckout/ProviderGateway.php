<?php

namespace App\Domain\Commerce\ProductionCheckout;

use SensitiveParameter;

interface ProviderGateway
{
    /** Identifies the adapter/transport evidence origin, never supplied by a browser. */
    public function provenance(ExecutionContextV1 $context): string;

    /** Own credential account lookup; a configured account string alone is insufficient. */
    public function account(ExecutionContextV1 $context): array;

    public function create(ExecutionContextV1 $context, #[SensitiveParameter] array $params, #[SensitiveParameter] string $key): array;

    public function retrieve(ExecutionContextV1 $context, string $sessionId): array;

    public function paymentIntent(ExecutionContextV1 $context, string $paymentId): array;
}
