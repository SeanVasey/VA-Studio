<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommitSeal;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use LogicException;
use Throwable;

/** One frozen read closure after the matching original commit; it confers no renewed paid-source authority. */
final class ProductionPaidOrderCommittedReadReceiptV1 implements \JsonSerializable
{
    private bool $used = false;

    private ?IdentityHistoricalCommitSeal $seal = null;

    private function __construct(
        private readonly ProductionPaidOrderSourceV1 $source,
        private readonly OriginalCommitDispatcher $observer,
        private readonly IdentityHistoricalCommittedReceipt $identity,
    ) {}

    public static function capture(ProductionPaidOrderSourceV1 $source, CurrentRows $reader, int $originalDeadlineNs, ?ProductionPaidOrderConsumerCommitAdmissionV1 $admission = null): self
    {
        $context = $source->committedReadContext($reader);
        $context->requireReceiptsEnabled();
        $deadline = $context->deadline($originalDeadlineNs);
        $observer = OriginalCommitDispatcher::capture($source, $reader, $context, $deadline, $admission);
        try {
            $receipt = new self($source, $observer, $observer->historicalReceipt());
            $observer->register($receipt);

            return $receipt;
        } catch (Throwable $error) {
            $observer->invalidate();
            throw $error;
        }
    }

    /** Callback-free comparison only; no new transaction, provider, source, graph or deadline. */
    public function proveClosed(): void
    {
        CheckoutException::require(! $this->used, 'committed_read_used');
        $this->used = true;
        try {
            $this->observer->requireClosed();
            $rows = $this->observer->rows();
            $this->source->proveCommittedOriginal($this, $rows);
            $this->identity->proveClosed();
            $rows->provePrimary();
            $this->observer->requireClosed();
            $this->observer->consumed();
        } catch (Throwable $error) {
            $this->observer->invalidate();
            throw $error;
        }
    }

    /** @internal Bound typed identity seal; only the original observer sequences it around framework commit. */
    public function sealOriginalCommit(): void
    {
        CheckoutException::require(! $this->used && $this->seal === null, 'committed_read_frame');
        $this->seal = $this->identity->sealOriginalCommit();
    }

    /** @internal Matching positive commit must have been observed by the shared identity witness. */
    public function observeOriginalCommitted(): void
    {
        CheckoutException::require(! $this->used && $this->seal !== null, 'committed_read_frame');
        $this->identity->observeOriginalCommitted($this->seal);
    }

    /** @internal Identity and graph proof capability never accepts caller evidence arrays. */
    public function belongsTo(ProductionPaidOrderSourceV1 $source, CurrentRows $reader): bool
    {
        return $source === $this->source && $this->observer->matches($source, $reader);
    }

    public function used(): bool
    {
        return $this->used;
    }

    public function invalidate(): void
    {
        $this->identity->invalidate();
    }

    public function __serialize(): array
    {
        throw new LogicException('Committed paid read receipts cannot be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Committed paid read receipts are internal server objects.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Committed paid read receipts cannot be deserialized.');
    }

    public function __debugInfo(): array
    {
        return ['schema_version' => 1, 'authority' => 'original_committed_read_receipt'];
    }
}
