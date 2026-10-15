<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderCommittedReadReceiptV2;
use JsonSerializable;
use LogicException;
use Throwable;

/** Server-only body closure: original captured receipts, no browser evidence or renewed grant authority. */
final class PaidTaxProjectionRead implements JsonSerializable
{
    private ?PaidTaxReadReceipt $owner = null;

    private ?ProductionTaxPaidOrderCommittedReadReceiptV2 $producer = null;

    private ?PaidTaxDeadline $deadline = null;

    private bool $used = false;

    private function __construct() {}

    public static function begin(): self
    {
        return new self;
    }

    public function capture(PaidTaxReadReceipt $owner, ProductionTaxPaidOrderCommittedReadReceiptV2 $producer, PaidTaxDeadline $deadline): void
    {
        PaidTaxException::require($this->owner === null && ! $this->used);
        $deadline->proveCurrent();
        $this->owner = $owner;
        $this->producer = $producer;
        $this->deadline = $deadline;
    }

    public function proveBeforeBytes(): void
    {
        PaidTaxException::require(! $this->used && $this->owner !== null && $this->producer !== null && $this->deadline !== null);
        $this->used = true;
        // Any callback-capable consumer work precedes the producer's fixed raw financial/history closure.
        try {
            $this->owner->proveClosed();
            $this->producer->proveClosed();
            $this->owner->proveRawClosed();
            $this->deadline->proveCurrent();
        } catch (Throwable $error) {
            try {
                // Even an owner/budget refusal BEFORE the sibling's proof must release this original
                // observer. Its exact frame cleanup preserves durable commits, peers and replacements.
                $this->producer->abortOwnedFrame();
            } catch (Throwable) {
                // Preserve the original refusal; never infer replacement physical ownership.
            }
            throw $error;
        }
    }

    public function __serialize(): array
    {
        throw new LogicException('Paid projection reads cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Paid projection reads cannot be deserialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Paid projection reads are internal.');
    }

    private function __clone() {}

    /** Abandon only the original body sibling; withdrawal/expiry must never prevent cleanup. */
    public function abandon(): void
    {
        $this->used = true;
        $producer = $this->producer;
        $this->producer = null;
        try {
            $producer?->abortOwnedFrame();
        } catch (Throwable) {
            // Preserve durable commits, peer/reopened frames and replacement dispatchers.
        }
    }
}
