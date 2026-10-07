<?php

namespace App\Domain\Customers\Preferences\Suppression;

use App\Domain\Customers\Preferences\ConsentPolicy;
use Illuminate\Support\Str;

/** Only the reviewed adapter may translate an authenticated exact positive provider receipt. */
final readonly class SuppressionReceipt
{
    public function __construct(public string $operationId, public string $recipientHmac, public string $bindingHash,
        public string $requestHash, public string $providerReceiptId) {}

    public function confirms(SuppressionRequest $request): bool
    {
        return Str::isUuid($this->operationId) && $this->operationId === $request->operationId
            && hash_equals($request->recipientHmac, $this->recipientHmac) && hash_equals($request->bindingHash, $this->bindingHash)
            && hash_equals($request->requestHash, $this->requestHash) && ConsentPolicy::text($this->providerReceiptId, 200, 800)
            && preg_match('/[\p{Cc}\p{Cf}]/u', $this->providerReceiptId) === 0;
    }
}
