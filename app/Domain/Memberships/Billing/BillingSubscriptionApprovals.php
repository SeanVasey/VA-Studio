<?php

namespace App\Domain\Memberships\Billing;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyStaff;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\MembershipValues;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;

/** Immutable subscription approval inside a captured outer transaction; returns only provisional identity. */
final class BillingSubscriptionApprovals
{
    public function approve(string $planVersionId, string $customerRef, string $subscriptionRef, string $priceRef,
        ProductionCustomerPrincipal $principal, User $buyer, User $author, MembershipRows $rows): array
    {
        MembershipValues::id($planVersionId);
        $billing = new BillingPolicy;
        $configuration = $billing->current();
        $membership = new MembershipPolicy;
        $membershipConfiguration = $membership->authoring();
        BillingException::require($membershipConfiguration['provenance'] === $configuration['provenance'], 'binding_mismatch');
        $schema = new BillingSchema;
        $schema->assertOwned($rows->identity());
        $table = $schema->table(BillingSchema::TABLES[0]);
        // Both user locks precede the account/origin locks, regardless of which user was created first.
        $users = [(int) $author->getKey(), $principal->userId];
        sort($users, SORT_NUMERIC);
        foreach (array_unique($users) as $id) {
            BillingException::require($id > 0, 'binding_mismatch');
            $rows->current()->one('users', $id);
        }
        $staff = new MembershipPolicyStaff;
        $staffRaw = $staff->lock($author, $rows);
        $access = new ProductionCustomerAccess;
        $buyerRaw = $access->lock($principal, $buyer, $rows->current());
        $buyerBinding = $access->durableBinding($principal);
        $plan = $rows->one(MembershipRecords::TABLE, 'id = ?', [$planVersionId]);
        BillingException::require($plan !== [], 'policy_absent');
        $document = MembershipPolicyVersions::document($plan);
        BillingException::require($document->hash() === $configuration['approved_subscription_policy_hash']
            && $document->facts['provenance'] === $configuration['provenance']
            && $buyerBinding['provenance'] === $configuration['provenance'], 'policy_not_approved');
        $expectation = new BillingExpectation($configuration['account_ref'], $configuration['mode'], $customerRef, $subscriptionRef, $priceRef,
            $document->facts['billing_currency'], $document->facts['billing_price_minor']);
        $facts = ['schema_version' => 2, 'purpose' => 'production_membership_billing_subscription', 'account_ref' => $expectation->accountRef,
            'mode' => $expectation->mode, 'customer_ref' => $expectation->customerRef, 'subscription_ref' => $expectation->subscriptionRef,
            'price_ref' => $expectation->priceRef, 'currency' => $expectation->currency, 'amount_minor' => $expectation->amountMinor,
            'plan_version_id' => $planVersionId, 'plan_policy_hash' => $document->hash(), 'original_terms_hash' => $document->termsHash(),
            'original_buyer_binding' => $buyerBinding];
        $subscriptionHash = BillingValues::hash('subscription', $expectation->accountRef, $expectation->mode, $subscriptionRef);
        $existing = $this->find($table, $subscriptionHash, $rows);
        if ($existing === []) {
            $payload = [...$facts, 'author_user_id' => (int) $staffRaw['id'],
                'author_binding_hash' => CanonicalJson::hash(MembershipRecords::strings($staffRaw))];
            $row = ['id' => BillingValues::id(), 'account_id' => $principal->accountId, 'user_id' => $principal->userId,
                'identity_origin_id' => (int) $buyerRaw['origin']['id'],
                'provider_account_hash' => BillingValues::hash('provider-account', $expectation->accountRef, $expectation->mode, $expectation->accountRef),
                'mode' => $expectation->mode, 'customer_ref_hash' => BillingValues::hash('customer', $expectation->accountRef, $expectation->mode, $customerRef),
                'subscription_ref_hash' => $subscriptionHash, 'price_ref_hash' => BillingValues::hash('price', $expectation->accountRef, $expectation->mode, $priceRef),
                'plan_version_id' => $planVersionId, 'approval_binding_hash' => BillingSubscriptionRecord::approvalHash($payload),
                'provenance' => $configuration['provenance'], 'created_at' => BillingValues::utc(CarbonImmutable::now('UTC')->timestamp)];
            $payload['record'] = BillingSubscriptionRecord::record($row);
            $row['payload_ciphertext'] = BillingValues::encrypt($payload);
            $row['seal'] = BillingValues::seal($row);
            $this->prove($staff, $author, $staffRaw, $access, $principal, $buyer, $buyerRaw, $rows, $plan, $billing, $configuration, $membership, $membershipConfiguration);
            $rows->execute('INSERT INTO '.$table.' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')', array_values($row));
            $existing = $this->find($table, $subscriptionHash, $rows);
        }
        $verified = BillingSubscriptionRecord::verify($existing, $configuration);
        $storedFacts = $verified['payload'];
        unset($storedFacts['author_user_id'], $storedFacts['author_binding_hash'], $storedFacts['record']);
        BillingException::require(CanonicalJson::encode($storedFacts) === CanonicalJson::encode($facts), 'conflicting_approval');
        $result = ['id' => $verified['row']['id'], 'approval_binding_hash' => $verified['row']['approval_binding_hash'], 'plan_version_id' => $planVersionId];
        $this->prove($staff, $author, $staffRaw, $access, $principal, $buyer, $buyerRaw, $rows, $plan, $billing, $configuration, $membership, $membershipConfiguration);
        BillingException::require(CanonicalJson::encode($this->find($table, $subscriptionHash, $rows)) === CanonicalJson::encode($verified['row']), 'binding_mismatch');
        $billing->proveConfiguration($configuration);
        $membership->proveConfiguration($membershipConfiguration);
        $rows->assertCurrent();

        return $result;
    }

    private function find(string $table, string $hash, MembershipRows $rows): array
    {
        $found = $rows->current()->rows($table, 'subscription_ref_hash = ?', [$hash], 2);
        BillingException::require(count($found) <= 1, 'ambiguous_source');

        return $found === [] ? [] : BillingValues::integers($found[0], ['account_id', 'user_id', 'identity_origin_id']);
    }

    private function prove(MembershipPolicyStaff $staff, User $author, array $staffRaw, ProductionCustomerAccess $access,
        ProductionCustomerPrincipal $principal, User $buyer, array $buyerRaw, MembershipRows $rows, array $plan,
        BillingPolicy $billing, array $configuration, MembershipPolicy $membership, array $membershipConfiguration): void
    {
        $staff->proveCurrent($author, $rows, $staffRaw);
        $access->proveCurrent($principal, $buyer, $rows->current(), $buyerRaw);
        (new BillingSchema)->assertOwned($rows->identity());
        // Customer decryption is a callback boundary too. Close staff and plan with captured raw reads afterwards.
        BillingException::require(MembershipRecords::strings($rows->current()->one('users', (int) $staffRaw['id'])) === MembershipRecords::strings($staffRaw)
            && MembershipRecords::strings($rows->one(MembershipRecords::TABLE, 'id = ?', [$plan['id']])) === MembershipRecords::strings($plan), 'binding_mismatch');
        $billing->proveConfiguration($configuration);
        $membership->proveConfiguration($membershipConfiguration);
        $rows->assertCurrent();
    }
}
