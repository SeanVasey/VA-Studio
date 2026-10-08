<?php

namespace App\Domain\Customers\ProductionFeatures\Suppression;

use App\Domain\Customers\Preferences\ConsentPolicy;
use Illuminate\Support\Str;

/** Only the reviewed adapter translates an authenticated, exact, positive provider inspection. */
final readonly class ProductionSuppressionReceipt
{
    public function __construct(public string $operationId, public string $requestHash, public string $recipientHmac,
        public string $providerHash, public string $providerReceiptId, public string $status) {}

    /** Positive only for this exact operation, request, recipient and provider binding scope. */
    public function confirms(ProductionSuppressionRequest $request): bool
    {
        return $this->status === 'suppressed' && Str::isUuid($this->operationId) && $this->operationId === $request->operationId()
            && hash_equals($request->requestHash(), $this->requestHash) && hash_equals($request->recipientHmac(), $this->recipientHmac)
            && hash_equals($request->providerHash(), $this->providerHash) && ConsentPolicy::text($this->providerReceiptId, 200, 800)
            && trim($this->providerReceiptId) === $this->providerReceiptId && preg_match('/[\p{Cc}\p{Cf}]/u', $this->providerReceiptId) === 0;
    }

    public function capture(): array
    {
        return ['schema' => 1, 'operationId' => $this->operationId, 'requestHash' => $this->requestHash, 'recipientHmac' => $this->recipientHmac,
            'providerHash' => $this->providerHash, 'providerReceiptId' => $this->providerReceiptId, 'status' => $this->status];
    }
}
