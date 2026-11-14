<?php

namespace App\Domain\Customers\ProductionFeatures;

use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityMetadataAdmission;
use LogicException;
use Throwable;

/** Original positive ordinary commit's one-use closing context; no fresh authority or time. */
final class ProductionFeatureRelease
{
    private bool $attempted = false;

    private function __construct(private readonly ProductionFeatureContext $context,
        private readonly ProductionAccountFeatureIdentity $identity,
        private readonly ProductionAccountFeatureAccess $access,
        private readonly int $deadline,
        private readonly IdentityMetadataAdmission $metadata) {}

    public static function fromOperation(ProductionFeatureContext $context): self
    {
        [$identity, $access, $deadline, $metadata] = ProductionFeatureOperation::issuing($context);

        return new self($context, $identity, $access, $deadline, $metadata);
    }

    public function finish(): void
    {
        if ($this->attempted) {
            throw new ProductionFeatureException;
        }
        $this->attempted = true;
        try {
            $this->metadata->requireCurrent();
            $this->context->admitConfiguration();
            $frame = IdentityCommittedFrame::begin($this->context->reader(), $this->deadline);
            try {
                $this->metadata->requireCurrent($this->context->reader()->identityPrimary());
                $this->access->lockCommitted($this->identity, $frame, $this->context->authority());
                $this->context->proveCommitted($frame);
                $this->context->admitConfiguration();
                $this->metadata->requireCurrent($this->context->reader()->identityPrimary());
                $this->access->proveCommitted($this->identity, $frame, $this->context->authority());
            } finally {
                $frame->close();
            }
        } catch (Throwable) {
            // A write may be durable; refusal never grants permission to retry it.
            throw new ProductionFeatureException;
        }
    }

    public function __serialize(): never
    {
        throw new LogicException('Feature closing authority cannot be serialized.');
    }
}
