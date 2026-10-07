<?php

namespace Tests\Support;

use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionProvider;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionReceipt;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Synthetic in-process adapter. No network, credential or real provider; records every call. */
final class RecordingSuppressionProvider implements ProductionSuppressionProvider
{
    public array $suppressed = [];

    public array $inspected = [];

    public bool $throwOnSuppress = true;

    public ?ProductionSuppressionRequest $last = null;

    /** @var null|callable(ProductionSuppressionRequest): ?ProductionSuppressionReceipt */
    public $answer = null;

    public function __construct(public ?string $binding) {}

    public function boundTo(): ?string
    {
        return $this->binding;
    }

    public function suppress(ProductionSuppressionRequest $request): void
    {
        $this->last = $request;
        // Evidence that the attempt was committed and the operation closed before this transport call.
        $this->suppressed[] = ['operation' => $request->operationId(), 'recipient' => $request->recipient(), 'request' => $request->requestHash(),
            'transactionLevel' => DB::connection()->transactionLevel(), 'inTransaction' => DB::connection()->getPdo()->inTransaction(),
            'durableAttempt' => DB::table('production_suppression_attempts')->where('public_id', $request->operationId())->count()];
        if ($this->throwOnSuppress) {
            throw new RuntimeException('SYNTHETIC transport timeout: outcome unknown');
        }
    }

    public function inspect(ProductionSuppressionRequest $request): ?ProductionSuppressionReceipt
    {
        $this->inspected[] = ['operation' => $request->operationId(), 'recipient' => $request->recipient(), 'request' => $request->requestHash()];

        return $this->answer === null ? null : ($this->answer)($request);
    }

    public static function positive(ProductionSuppressionRequest $request): ProductionSuppressionReceipt
    {
        return new ProductionSuppressionReceipt($request->operationId(), $request->requestHash(), $request->recipientHmac(),
            $request->providerHash(), 'SYNTHETIC-provider-receipt-1', 'suppressed');
    }
}
