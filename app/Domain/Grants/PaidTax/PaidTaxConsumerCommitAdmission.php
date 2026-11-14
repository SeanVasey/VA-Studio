<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderConsumerCommitAdmissionV2;
use JsonSerializable;
use LogicException;
use PDO;

/** Current consumer write admission, separate from the original producer/history proof. */
final readonly class PaidTaxConsumerCommitAdmission implements JsonSerializable, ProductionTaxPaidOrderConsumerCommitAdmissionV2
{
    private function __construct(private PaidTaxReadReceipt $receipt, private PaidTaxDeadline $originalBudget,
        private int $originalDeadlineNs) {}

    /** Trusted original in-frame receipt and existing budget only; no browser evidence or replacement deadline. */
    public static function capture(PaidTaxReadReceipt $receipt, PaidTaxDeadline $originalBudget): self
    {
        $receipt->requireCommitAdmissionMint();
        $originalBudget->proveCurrent();

        return new self($receipt, $originalBudget, $originalBudget->value());
    }

    public function proveCurrent(PDO $capturedPrimary): void
    {
        PaidTaxException::require(hrtime(true) <= $this->originalDeadlineNs, 410);
        $this->originalBudget->proveCurrent();
        $this->receipt->proveCommitAdmission($capturedPrimary);
        PaidTaxException::require(hrtime(true) <= $this->originalDeadlineNs, 410);
        $this->originalBudget->proveCurrent();
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
