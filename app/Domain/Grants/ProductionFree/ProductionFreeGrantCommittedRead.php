<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityMetadataAdmission;
use App\Models\User;
use LogicException;
use Throwable;

/**
 * Owner doc step 5: the one-use proof that runs after the redemption has committed and before any header or byte
 * leaves. On the transfer's original deadline (never renewed) it opens one read-only committed identity frame
 * (`IdentityCommittedFrame`: SQLite query_only, MySQL READ ONLY / READ COMMITTED), re-proves the original owner evidence
 * there (`ProductionCustomerAccess::proveCommitted`), then re-reads the exact Free rows with plain non-locking SELECTs
 * (`ProductionFreeGrantCommittedRows`): the origin, authorization, redemption and original byte-for-byte as committed,
 * no revocation, and the owned schema and parent floor without a session shadow.
 *
 * Any withdrawal after the redemption commit means no bytes and the fixed reason `outcome_unknown`: the committed
 * redemption is retained, nothing is retried and no deadline is renewed. The customer may authorize again if still
 * entitled; a withdrawn grant refuses there.
 */
final class ProductionFreeGrantCommittedRead
{
    private bool $used = false;

    /**
     * @param  array<string, mixed>  $identity  the original raw owner evidence locked inside the redemption commit
     * @param  array<string, array<string, ?string>>  $rows  the exact stored rows (without payload) as that commit left them
     */
    public function __construct(private readonly ProductionCustomerPrincipal $principal, private readonly User $actor,
        private readonly array $identity, private readonly array $policy, private readonly array $rows,
        private readonly int $originalDeadlineNs)
    {
        ProductionFreeGrantException::require(array_keys($rows) === ['origin', 'authorization', 'redemption', 'original'], 'outcome_unknown');
    }

    public function proveBeforeBytes(): void
    {
        ProductionFreeGrantException::require(! $this->used, 'outcome_unknown');
        $this->used = true;
        try {
            // Raw connection and facade metadata are admitted before any facade or connection getter (the shared
            // generic/identity metadata P2, on the surface this path reaches). The frame re-admits the same state at every step.
            $admission = IdentityMetadataAdmission::capture();
            // Extensible configuration reads finish before the final frame, and must leave the admitted state unchanged.
            (new ProductionFreeGrantPolicy)->prove($this->policy);
            $admission->requireCurrent();
            $connection = $admission->connection();
            $reader = new CurrentRows($connection->getRawPdo(), $connection->getDriverName());
            $frame = IdentityCommittedFrame::begin($reader, $this->originalDeadlineNs);
            try {
                (new ProductionCustomerAccess)->proveCommitted($this->principal, $this->actor, $frame, $this->identity);
                (new ProductionFreeGrantCommittedRows($frame))->prove($this->rows);
                $admission->requireCurrent($reader->identityPrimary());
                $frame->finish();
            } finally {
                $frame->close();
            }
        } catch (Throwable) {
            throw new ProductionFreeGrantException('outcome_unknown');
        }
    }

    public function __serialize(): array
    {
        throw new LogicException('Committed Free reads cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['used' => $this->used];
    }

    private function __clone() {}
}
