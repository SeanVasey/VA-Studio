<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommitSeal;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use LogicException;
use Throwable;

/** One frozen original read after its positively observed commit. Never NEW source or grant authority. */
final class ProductionTaxPaidOrderCommittedReadReceiptV2 implements \JsonSerializable
{
    private bool $used = false;

    private ?IdentityHistoricalCommitSeal $seal = null;

    private function __construct(private readonly TaxPaidOriginalCommitDispatcher $observer,
        private readonly IdentityHistoricalCommittedReceipt $identity) {}

    /** @internal Only the typed original observer can supply both server-minted capabilities. */
    public static function fromObserver(TaxPaidOriginalCommitDispatcher $observer, IdentityHistoricalCommittedReceipt $identity): self
    {
        CheckoutException::require($observer->ownsIdentity($identity), 'committed_read_frame');

        return new self($observer, $identity);
    }

    public function proveClosed(): void
    {
        CheckoutException::require(! $this->used, 'committed_read_used');
        $this->used = true;
        try {
            $this->observer->proveOriginalClosed();
            $this->observer->closedIdentity(fn () => $this->identity->proveClosed());
            $this->observer->requireClosed();
            $this->observer->consumed();
        } catch (Throwable $error) {
            $this->observer->poison();
            throw $error;
        }
    }

    /** @internal Sequenced by the original connection observer; the seal cannot prove physical commit itself. */
    public function sealOriginalCommit(): void
    {
        CheckoutException::require(! $this->used && $this->seal === null, 'committed_read_frame');
        $this->seal = $this->identity->sealOriginalCommit();
    }

    public function observeOriginalCommitted(): void
    {
        CheckoutException::require(! $this->used && $this->seal !== null, 'committed_read_frame');
        $this->identity->observeOriginalCommitted($this->seal);
    }

    public function invalidateIdentity(): void
    {
        $this->identity->invalidate();
    }

    public function used(): bool
    {
        return $this->used;
    }

    public function abortOwnedFrame(): void
    {
        $this->observer->abortOwnedFrame();
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new LogicException('Tax committed read receipts cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Tax committed read receipts cannot be deserialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Tax committed read receipts are internal server objects.');
    }

    public function __debugInfo(): array
    {
        return ['schema_version' => 2, 'authority' => 'original_tax_committed_read_receipt'];
    }
}
