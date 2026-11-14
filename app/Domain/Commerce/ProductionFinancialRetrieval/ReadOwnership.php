<?php

namespace App\Domain\Commerce\ProductionFinancialRetrieval;

use App\Domain\Commerce\ProductionCheckout\OriginalCommitDispatcher;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderCommittedReadReceiptV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderCommittedReadReceiptV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Commerce\ProductionTaxCheckout\TaxPaidOriginalCommitDispatcher;
use Throwable;

/** Original receipt custody only. No physical transaction cleanup or source lifetime renewal. */
final class ReadOwnership
{
    private array $receipts = [];

    private array $attempted = [];

    private OriginalCommitDispatcher|TaxPaidOriginalCommitDispatcher|null $observer = null;

    private ?int $effectiveDeadline = null;

    private bool $claimed = false;

    private bool $closed = false;

    private bool $firstClosed = false;

    public function __construct(private readonly ProductionPaidOrderSourceV1|ProductionTaxPaidOrderSourceV2 $source,
        private readonly CurrentRows $reader, private readonly int $requestedDeadline) {}

    public function attach(ProductionPaidOrderCommittedReadReceiptV1|ProductionTaxPaidOrderCommittedReadReceiptV2 $receipt): void
    {
        $class = $receipt::class;
        $expectedReceipt = $this->source instanceof ProductionPaidOrderSourceV1
            ? ProductionPaidOrderCommittedReadReceiptV1::class : ProductionTaxPaidOrderCommittedReadReceiptV2::class;
        FinancialRetrievalException::require($class === $expectedReceipt && count($this->receipts) < 2);
        $observer = RetrievalPolicy::raw($receipt, $class, 'observer');
        $expectedObserver = $this->source instanceof ProductionPaidOrderSourceV1 ? OriginalCommitDispatcher::class : TaxPaidOriginalCommitDispatcher::class;
        FinancialRetrievalException::require(is_object($observer) && $observer::class === $expectedObserver
            && RetrievalPolicy::raw($observer, $expectedObserver, 'source') === $this->source
            && RetrievalPolicy::raw($observer, $expectedObserver, 'reader') === $this->reader
            && ($this->observer === null || $this->observer === $observer));
        // Retain our actual mint before any further invariant can refuse it.
        $this->observer = $observer;
        $this->receipts[] = $receipt;
        $deadline = RetrievalPolicy::raw($observer, $expectedObserver, 'deadlineNs');
        FinancialRetrievalException::require(is_int($deadline) && $deadline <= $this->requestedDeadline
            && hrtime(true) < $deadline && ($this->effectiveDeadline === null || $this->effectiveDeadline === $deadline));
        $this->effectiveDeadline = $deadline;
        FinancialRetrievalException::require(RetrievalPolicy::raw($observer, $expectedObserver, 'receipts') === $this->receipts);
    }

    public function claim(): void
    {
        FinancialRetrievalException::require(! $this->claimed && ! $this->closed && count($this->receipts) === 2, 'used');
        $this->claimed = true;
    }

    public function proveActive(): void
    {
        FinancialRetrievalException::require($this->claimed && ! $this->closed && $this->effectiveDeadline !== null
            && hrtime(true) < $this->effectiveDeadline);
    }

    public function proveReceipt(int $index): void
    {
        FinancialRetrievalException::require(isset($this->receipts[$index]) && ! isset($this->attempted[$index]));
        $this->attempted[$index] = true;
        $this->receipts[$index]->proveClosed();
        if ($index === 0) {
            $this->firstClosed = true;
        }
    }

    public function proveDispatchAllowed(): void
    {
        $this->proveActive();
        FinancialRetrievalException::require($this->firstClosed && ! isset($this->attempted[1]));
    }

    /** Best effort, including early refusals and partial mint; never replace the original error. */
    public function close(bool $refused = true): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($refused) {
            try {
                if ($this->observer instanceof OriginalCommitDispatcher) {
                    $this->observer->invalidate();
                } else {
                    $this->observer?->poison();
                }
            } catch (Throwable) {
                // No log/chaining, new transaction or physical cleanup.
            }
        }
        foreach ($this->receipts as $index => $receipt) {
            if (! isset($this->attempted[$index])) {
                $this->attempted[$index] = true;
                try {
                    $receipt->proveClosed();
                } catch (Throwable) {
                    // The original observation owns its source-specific invalidation.
                }
            }
        }
    }

    public function __serialize(): never
    {
        throw new \LogicException('Original read custody cannot be serialized.');
    }

    public function __clone(): never
    {
        throw new \LogicException('Original read custody cannot be cloned.');
    }

    public function __unserialize(array $data): never
    {
        throw new \LogicException('Original read custody cannot be reconstructed.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'original_read_custody'];
    }
}
