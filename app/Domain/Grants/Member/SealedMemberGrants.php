<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MemberGrantReceipt;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;
use WeakMap;

/** Provisional readiness for one exact privately planned activation, never a detached candidate. */
final class SealedMemberGrants implements MemberGrantAuthority
{
    private WeakMap $issued;

    public function __construct(private readonly MemberOriginalSourceCapture $sources)
    {
        $this->issued = new WeakMap;
    }

    public function prepare(MemberGrantIntent $intent, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberGrantReceipt
    {
        $inspection = $this->sources->grantInspection($this, $rows);
        $binding = $inspection->consumeGrantPlan($this, $intent, $principal, $actor, $rows);
        $receipt = MemberActivationReadyToken::value($binding);
        $this->issued[$receipt] = [$inspection, $binding, $intent, $principal, $actor, $rows, $rows->identity()];

        return $receipt;
    }

    public function proveReadyCurrent(MemberGrantReceipt $receipt, MemberGrantIntent $intent, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
    {
        $entry = $this->issued[$receipt] ?? null;
        MemberGrantException::require($receipt instanceof MemberActivationReadyToken && is_array($entry)
            && array_slice($entry, 2) === [$intent, $principal, $actor, $rows, $rows->identity()]
            && $receipt->binding() === $entry[1] && $this->sources->grantInspection($this, $rows) === $entry[0], 'foreign_grant_plan');
        $entry[0]->proveGrantPlan($this, $entry[1], $intent, $principal, $actor, $rows);
    }

    public function retireInspection(MemberActivationInspection $inspection): void
    {
        foreach ($this->issued as $receipt => $entry) {
            if ($entry[0] === $inspection) {
                unset($this->issued[$receipt]);
            }
        }
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_grant_issuer');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_grant_issuer');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_grant_issuer');
    }
}
