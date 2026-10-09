<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderCommittedReadReceiptV1;
use JsonSerializable;
use LogicException;

/** Server-only body closure: original captured receipts, no browser evidence or renewed grant authority. */
final class PaidGrantProjectionRead implements JsonSerializable
{
    private ?PaidGrantReadReceipt $owner = null;

    private ?ProductionPaidOrderCommittedReadReceiptV1 $producer = null;

    private ?PaidGrantDeadline $deadline = null;

    private bool $used = false;

    private function __construct() {}

    public static function begin(): self
    {
        return new self;
    }

    public function capture(PaidGrantReadReceipt $owner, ?ProductionPaidOrderCommittedReadReceiptV1 $producer, PaidGrantDeadline $deadline): void
    {
        PaidGrantException::require($this->owner === null && ! $this->used);
        $deadline->proveCurrent();
        $this->owner = $owner;
        $this->producer = $producer;
        $this->deadline = $deadline;
    }

    public function proveBeforeBytes(): void
    {
        PaidGrantException::require(! $this->used && $this->owner !== null && $this->deadline !== null);
        $this->used = true;
        // Any callback-capable consumer work precedes the producer's fixed raw financial/history closure.
        $this->owner->proveClosed();
        $this->producer?->proveClosed();
        $this->owner->proveRawClosed();
        $this->deadline->proveCurrent();
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
}
