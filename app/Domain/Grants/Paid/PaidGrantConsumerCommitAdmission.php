<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderConsumerCommitAdmissionV1;
use JsonSerializable;
use LogicException;
use PDO;

/** Current consumer write admission, separate from the original producer/history proof. */
final readonly class PaidGrantConsumerCommitAdmission implements JsonSerializable, ProductionPaidOrderConsumerCommitAdmissionV1
{
    private function __construct(private PaidGrantReadReceipt $receipt, private int $originalDeadlineNs) {}

    /** Trusted original in-frame receipt and existing budget only; no browser evidence or replacement deadline. */
    public static function capture(PaidGrantReadReceipt $receipt, PaidGrantDeadline $originalBudget): self
    {
        $receipt->requireCommitAdmissionMint();
        $originalBudget->proveCurrent();

        return new self($receipt, $originalBudget->value());
    }

    public function proveCurrent(PDO $capturedPrimary): void
    {
        PaidGrantException::require(hrtime(true) <= $this->originalDeadlineNs, 410);
        $this->receipt->proveCommitAdmission($capturedPrimary);
        PaidGrantException::require(hrtime(true) <= $this->originalDeadlineNs, 410);
    }

    public function __serialize(): array
    {
        throw new LogicException('Paid commit admission cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Paid commit admission cannot be deserialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Paid commit admission is internal.');
    }

    private function __clone() {}

    public function __debugInfo(): array
    {
        return ['purpose' => 'paid-consumer-original-current-write-admission'];
    }
}
