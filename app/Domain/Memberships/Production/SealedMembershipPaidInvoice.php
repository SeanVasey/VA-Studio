<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Billing\BillingFreshnessWindow;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingRetrievalSchema;
use App\Models\User;
use App\Support\CanonicalJson;
use WeakMap;

/** Retained, approved billing evidence only. Construction does not register a runtime capability. */
final class SealedMembershipPaidInvoice implements MembershipPaidInvoiceAuthority
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

    public function lock(string $locator, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipPaidInvoiceProof
    {
        $deadlineNs = hrtime(true) + 60_000_000_000;
        $policy = new MembershipPolicy;
        $configuration = $policy->facts();
        $billingPolicy = new BillingPolicy;
        $billing = $billingPolicy->current();
        MembershipException::require($configuration['approved_policy_hash'] === $billing['approved_subscription_policy_hash']
            && $configuration['provenance'] === $billing['provenance'], 'policy_not_approved');
        $access = new ProductionCustomerAccess;
        $current = $access->lock($principal, $actor, $rows->current());
        $evidence = MembershipBillingEvidence::lock($rows, $locator, $billing, $deadlineNs);
        $binding = $evidence['binding'];
        $this->sameOwner($binding->originalBuyerBinding, $access->durableBinding($principal));
        $historical = $access->verifyHistoricalBinding($binding->originalBuyerBinding, $rows->current());
        $proof = MembershipPaidInvoiceToken::value($binding);
        $this->issued[$proof] = ['rows' => $rows, 'pdo' => $rows->identity(), 'principal' => $principal, 'actor' => $actor,
            'access' => $access, 'current' => $current, 'historical' => $historical, 'policy' => $policy,
            'configuration' => $configuration, 'billing_policy' => $billingPolicy, 'billing' => $billing, 'deadline_ns' => $deadlineNs, 'freshness' => $evidence['freshness']];
        $this->proveCurrent($proof, $principal, $actor, $rows);

        return $proof;
    }

    public function proveCurrent(MembershipPaidInvoiceProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
    {
        MembershipException::require($proof instanceof MembershipPaidInvoiceToken && isset($this->issued[$proof]), 'foreign_invoice_proof');
        $issued = $this->issued[$proof];
        MembershipException::require($issued['rows'] === $rows && $issued['pdo'] === $rows->identity()
            && $issued['principal'] === $principal && $issued['actor'] === $actor && hrtime(true) < $issued['deadline_ns'], 'foreign_or_expired_invoice_proof');
        $issued['policy']->proveConfiguration($issued['configuration']);
        $issued['billing_policy']->proveConfiguration($issued['billing']);
        $access = $issued['access'];
        $access->proveCurrent($principal, $actor, $rows->current(), $issued['current']);
        $snapshot = MembershipBillingEvidence::currentSnapshot($rows, $proof->binding(), $issued['billing'], $issued['freshness']);
        $access->proveHistoricalBindingCurrent($proof->binding()->originalBuyerBinding, $rows->current(), $issued['historical']);
        $access->proveCurrent($principal, $actor, $rows->current(), $issued['current']);
        (new BillingRetrievalSchema)->assertOwned($rows->identity());
        MembershipBillingEvidence::assertCurrentSnapshot($rows, $snapshot);
        $issued['policy']->proveConfiguration($issued['configuration']);
        $issued['billing_policy']->proveConfiguration($issued['billing']);
        $rows->assertCurrent();
        MembershipException::require(hrtime(true) < $issued['deadline_ns'], 'expired_invoice_proof');
        $issued['freshness']->assertDeadline();
    }

    public function freshnessWindow(MembershipPaidInvoiceProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): BillingFreshnessWindow
    {
        $this->proveCurrent($proof, $principal, $actor, $rows);

        return $this->issued[$proof]['freshness'];
    }

    /** One complete lock proof exports its same original observation; no window is renewed. */
    public function capture(string $locator, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): array
    {
        $proof = $this->lock($locator, $principal, $actor, $rows);
        $issued = $this->issued[$proof];
        MembershipException::require($issued['principal'] === $principal && $issued['actor'] === $actor
            && $issued['rows'] === $rows && $issued['pdo'] === $rows->identity(), 'foreign_invoice_proof');
        MembershipException::require(hrtime(true) < $issued['deadline_ns'], 'expired_invoice_proof');
        $issued['freshness']->assertDeadline();

        return [$proof, $issued['freshness']];
    }

    private function sameOwner(array $original, array $current): void
    {
        // Recovery may change the verification observation; the original owner and origin remain fixed.
        foreach (['verification_observation_id', 'verification_observation_hash'] as $key) {
            unset($original[$key], $current[$key]);
        }
        MembershipException::require(CanonicalJson::encode($original) === CanonicalJson::encode($current), 'invoice_owner_mismatch');
    }
}
