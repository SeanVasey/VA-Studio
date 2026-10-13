<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Commerce\ProductionCheckout\CurrentSelection;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use Carbon\CarbonImmutable;
use WeakMap;

/** Real retained catalog proof. Construction does not register or enable a membership capability. */
final class CurrentEligibleLicenses implements MembershipEligibleLicenseAuthority
{
    private WeakMap $issued;

    public function __construct()
    {
        $this->issued = new WeakMap;
    }

    public function __clone(): void
    {
        throw new MembershipException('clone_refused');
    }

    public function lock(string $selection, int $expectedRevision, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipEligibleLicenseProof
    {
        // A bounded producer admission lease, not commercial honor terms or a blocking-call timeout.
        $startedNs = hrtime(true);
        $deadlineNs = $startedNs + 60_000_000_000;
        $at = CarbonImmutable::now('UTC');
        $policy = new MembershipPolicy;
        $configuration = $policy->facts();
        $access = new ProductionCustomerAccess;
        $current = $access->lock($principal, $actor, $rows->current());
        $snapshot = MembershipCatalogEvidence::lock($rows, $selection, $expectedRevision, $at, $deadlineNs, $startedNs);
        $proof = MembershipEligibleLicenseToken::value($snapshot->binding);
        $this->issued[$proof] = ['rows' => $rows, 'pdo' => $rows->identity(), 'principal' => $principal, 'actor' => $actor,
            'access' => $access, 'current' => $current, 'policy' => $policy, 'configuration' => $configuration, 'snapshot' => $snapshot];
        $this->proveCurrent($proof, $principal, $actor, $rows);

        return $proof;
    }

    public function proveCurrent(MembershipEligibleLicenseProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
    {
        MembershipException::require($proof instanceof MembershipEligibleLicenseToken && isset($this->issued[$proof]), 'foreign_license_proof');
        $issued = $this->issued[$proof];
        $snapshot = $issued['snapshot'];
        MembershipException::require($issued['rows'] === $rows && $issued['pdo'] === $rows->identity()
            && $issued['principal'] === $principal && $issued['actor'] === $actor
            && hrtime(true) < $snapshot->deadlineNs, 'foreign_or_expired_license_proof');
        $issued['policy']->proveConfiguration($issued['configuration']);
        $access = $issued['access'];
        $access->proveCurrent($principal, $actor, $rows->current(), $issued['current']);
        MembershipCatalogEvidence::proveBytes($snapshot);
        CurrentSelection::proveCurrent($rows->current(), $snapshot->selection, CarbonImmutable::now('UTC'));
        // Evaluate the last clock before the last identity proof: a clock callback may withdraw
        // the buyer, and that withdrawal must be observed before any source authority returns.
        $now = CarbonImmutable::now('UTC');
        $access->proveCurrent($principal, $actor, $rows->current(), $issued['current']);
        MembershipException::require((int) $now->format('Uu') < $snapshot->binding->validUntilUnixMicros, 'expired_license_proof');
        $issued['policy']->proveConfiguration($issued['configuration']);
        $rows->assertCurrent();
        MembershipCatalogEvidence::assertCurrent($snapshot, $rows->identity(), $rows->driver());
        MembershipException::require(hrtime(true) < $snapshot->deadlineNs, 'expired_license_proof');
    }

    /** Exact issuer admission precedes export; this immutable evidence has no authority of its own. */
    public function snapshot(MembershipEligibleLicenseProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipCatalogSnapshot
    {
        $this->proveCurrent($proof, $principal, $actor, $rows);

        return $this->issued[$proof]['snapshot'];
    }
}
