<?php

namespace Tests\Support;

use App\Domain\Customers\Preferences\Suppression\SuppressionAdapter;
use App\Domain\Customers\Preferences\Suppression\SuppressionPolicy;
use App\Domain\Customers\Preferences\Suppression\SuppressionReceipt;
use App\Domain\Customers\Preferences\Suppression\SuppressionRequest;
use Closure;

final class SuppressionFixtures implements SuppressionAdapter
{
    public int $sent = 0;

    public int $inspected = 0;

    public ?Closure $onSuppress = null;

    public ?Closure $onInspect = null;

    public ?Closure $onBinding = null;

    public readonly string $hash;

    public function __construct()
    {
        config(['customer-suppression' => ['enabled' => true, 'binding' => ['adapter' => 'synthetic-test-adapter', 'version' => 'synthetic-v1',
            'scope' => 'Synthetic isolated test list only', 'reviewReference' => 'Synthetic fixture only; no production activation']]]);
        $this->hash = SuppressionPolicy::capture()['hash'];
    }

    public function boundTo(): ?string
    {
        if ($this->onBinding) {
            ($this->onBinding)();
        }

        return $this->hash;
    }

    public function suppress(SuppressionRequest $request): ?SuppressionReceipt
    {
        $this->sent++;

        return $this->onSuppress ? ($this->onSuppress)($request) : self::positive($request);
    }

    public function inspect(SuppressionRequest $request): ?SuppressionReceipt
    {
        $this->inspected++;

        return $this->onInspect ? ($this->onInspect)($request) : self::positive($request);
    }

    public static function positive(SuppressionRequest $request): SuppressionReceipt
    {
        return new SuppressionReceipt($request->operationId, $request->recipientHmac, $request->bindingHash, $request->requestHash, 'SYNTHETIC-POSITIVE-RECEIPT');
    }
}
