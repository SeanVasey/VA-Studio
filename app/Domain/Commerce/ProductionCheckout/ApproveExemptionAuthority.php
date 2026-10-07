<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Internal explicit owner policy approval, separate from customer checkout and qualifier observations. */
final class ApproveExemptionAuthority
{
    public function approve(int $candidateId, array $policy, string $key, User $owner): array
    {
        return CommandTransaction::run(function (Records $rows) use ($candidateId, $policy, $key, $owner): array {
            $owners = config('production_checkout.exemption_policy_owner_ids');
            CheckoutException::require(config('production_checkout.exemption_authoring_enabled') === true
                && is_array($owners) && in_array($owner->id, $owners, true), 'authority', 403);
            $staff = StaffProof::lock($owner, $rows->current);
            $current = CurrentPolicy::load($rows->current, $candidateId);
            $policy = ExemptionPolicyV1::validate($policy, $current);
            $digest = Evidence::key($key);
            $requestHash = CanonicalJson::hash(['candidate' => $current['binding'], 'policy' => $policy]);
            $existing = $rows->selector('authority', 'owner_user_id = ? AND request_key = ?', [$owner->id, $digest]);
            CheckoutException::require(count($existing) <= 1);
            if ($existing !== []) {
                $record = $existing[0];
                $body = Evidence::open($record, 'production_checkout_exemption_authority');
                CheckoutException::require($record['request_hash'] === $requestHash);
                Evidence::same($body['policy'], $policy);
                Evidence::same($body['candidate'], $current['binding']);
            } else {
                $at = CarbonImmutable::now('UTC');
                ExemptionPolicyV1::effective($policy, $at);
                $public = (string) Str::uuid();
                $created = $at->format('Y-m-d\TH:i:s\Z');
                $body = ['schema_version' => 1, 'purpose' => 'production_checkout_exemption_authority', 'public_id' => $public,
                    'created_at' => $created, 'owner_user_id' => $owner->id, 'candidate' => $current['binding'],
                    'policy' => $policy, 'policy_hash' => CanonicalJson::hash($policy), 'request_hash' => $requestHash,
                    'authority_meaning' => 'owner_approved_scoped_qualification_delegation', 'external_tax_fact_verified' => false];
                $record = $rows->insert('authority', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($body),
                    'candidate_id' => $candidateId, 'owner_user_id' => $owner->id, 'request_key' => $digest, 'request_hash' => $requestHash]);
            }
            Evidence::same([$record], $rows->selector('authority', 'owner_user_id = ? AND request_key = ?', [$owner->id, $digest]));
            Evidence::same($owners, config('production_checkout.exemption_policy_owner_ids'));
            CheckoutException::require(config('production_checkout.exemption_authoring_enabled') === true, 'authority', 403);
            CurrentPolicy::proveCurrent($rows->current, $current);
            StaffProof::proveCurrent($owner, $rows->current, $staff);

            return $record;
        });
    }
}
