<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Owner-delegated qualified exemption attestation. Buyer declarations and staff reports alone are refused. */
final class TaxExemptions
{
    public function __construct(private readonly ProductionCustomerAccess $access) {}

    public function qualify(ProductionCustomerPrincipal $principal, User $buyer, string $authorityId, array $items,
        array $attestation, string $key, User $qualifier): array
    {
        return CommandTransaction::run(function (Records $rows) use ($principal, $buyer, $authorityId, $items, $attestation, $key, $qualifier): array {
            CheckoutException::require(config('production_checkout.exemption_authoring_enabled') === true, 'authority', 403);
            // Both identity users precede account/policy/catalog locks in one deterministic order.
            User::query()->whereIn('id', [$buyer->id, $qualifier->id])->orderBy('id')->lockForUpdate()->get();
            $access = $this->access->lock($principal, $buyer, $rows->current);
            $binding = $this->access->durableBinding($principal);
            $staff = StaffProof::lock($qualifier, $rows->current);
            $authority = $rows->one('authority', $authorityId);
            $current = CurrentPolicy::load($rows->current, $authority['candidate_id']);
            $current['context']->requireBuyer($binding);
            $authorityBody = self::authority($authority, $current);
            $ownerDelegation = config('production_checkout.exemption_policy_owner_ids', []);
            $policy = $authorityBody['policy'];
            CheckoutException::require(in_array($qualifier->id, $policy['qualifier_ids'], true), 'authority', 403);
            Evidence::keys($attestation, ['qualified_exemption_confirmed', 'reference', 'source_sha256', 'effective_from', 'effective_until']);
            CheckoutException::require($attestation['qualified_exemption_confirmed'] === true && Evidence::hash($attestation['source_sha256'])
                && is_string($attestation['reference']) && trim($attestation['reference']) !== '' && strlen($attestation['reference']) <= 512
                && ! preg_match('/[\x00-\x1f\x7f]/u', $attestation['reference'])
                && ($binding['provenance'] !== 'verified_production' || ! str_starts_with(strtolower($attestation['reference']), 'synthetic:')));
            ExemptionPolicyV1::interval($attestation['effective_from'], $attestation['effective_until']);
            CheckoutException::require($attestation['effective_from'] >= $policy['effective_from'] && $attestation['effective_until'] <= $policy['effective_until']);
            $at = CarbonImmutable::now('UTC');
            ExemptionPolicyV1::effective($policy, $at);
            ExemptionPolicyV1::effective($attestation, $at);
            $selection = CurrentSelection::load($rows->current, $items, $at);
            $digest = Evidence::key($key);
            $request = ['authority_id' => $authorityId, 'authority_hash' => $authority['payload_hash'], 'candidate' => $current['binding'],
                'buyer' => $binding, 'selection_hash' => $selection['selection_hash'], 'attestation' => $attestation, 'qualifier_id' => $qualifier->id];
            $requestHash = CanonicalJson::hash($request);
            $existing = $rows->selector('basis', 'created_by = ? AND request_key = ?', [$qualifier->id, $digest]);
            CheckoutException::require(count($existing) <= 1);
            $created = $existing === [];
            if (! $created) {
                $record = $existing[0];
                $body = Evidence::open($record, 'production_checkout_exemption_basis');
                Evidence::same($request, $body['request']);
                CheckoutException::require($body['request_hash'] === $requestHash);
            } else {
                $public = (string) Str::uuid();
                $created = $at->format('Y-m-d\TH:i:s\Z');
                $body = ['schema_version' => 1, 'purpose' => 'production_checkout_exemption_basis', 'public_id' => $public,
                    'created_at' => $created, 'request' => $request, 'request_hash' => $requestHash,
                    'authority_meaning' => 'accepted_owner_delegated_qualified_exemption_attestation',
                    'tax_minor' => 0, 'total_minor' => $selection['selection']['advertised_subtotal_minor'], 'external_tax_fact_verified' => false];
                $record = $rows->insert('basis', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($body),
                    'authority_id' => $authority['id'], 'candidate_id' => $authority['candidate_id'], 'buyer_origin_id' => $binding['origin_id'],
                    'selection_hash' => $selection['selection_hash'], 'request_key' => $digest, 'created_by' => $qualifier->id]);
            }
            Evidence::same($authority, $rows->current->one(CheckoutSchema::TABLES['authority'], $authority['id']));
            Evidence::same([$record], $rows->selector('basis', 'created_by = ? AND request_key = ?', [$qualifier->id, $digest]));
            CheckoutException::require(config('production_checkout.exemption_authoring_enabled') === true, 'authority', 403);
            Evidence::same($ownerDelegation, config('production_checkout.exemption_policy_owner_ids', []));
            CurrentSelection::proveBytes($selection);
            CurrentSelection::proveCurrent($rows->current, $selection, CarbonImmutable::now('UTC'));
            CurrentPolicy::proveCurrent($rows->current, $current);
            StaffProof::proveCurrent($qualifier, $rows->current, $staff);
            $this->access->proveCurrent($principal, $buyer, $rows->current, $access);
            if ($created) {
                // Only a NEW basis installs the one commit observer; an exact replay writes nothing.
                CheckoutStaffWriteAdmission::basis($rows, $qualifier, $staff, $buyer, $access, $current, $selection,
                    $authority, $policy, $attestation, $record, $digest);
            }

            return $record;
        });
    }

    public static function authority(array $row, array $current): array
    {
        $body = Evidence::open($row, 'production_checkout_exemption_authority');
        Evidence::same($body['candidate'], $current['binding']);
        CheckoutException::require($row['candidate_id'] === $current['binding']['candidate_id']
            && $body['owner_user_id'] === $row['owner_user_id'] && $body['policy_hash'] === CanonicalJson::hash($body['policy'])
            && $body['request_hash'] === $row['request_hash'] && $body['external_tax_fact_verified'] === false
            && $body['authority_meaning'] === 'owner_approved_scoped_qualification_delegation'
            && in_array($row['owner_user_id'], config('production_checkout.exemption_policy_owner_ids', []), true));
        ExemptionPolicyV1::validate($body['policy'], $current);

        return $body;
    }

    public static function basis(Records $rows, string $publicId, array $current, array $buyer, array $selection, CarbonImmutable $at, ?string $requiredUntil = null): array
    {
        $row = $rows->one('basis', $publicId);
        $body = Evidence::open($row, 'production_checkout_exemption_basis');
        $authority = $rows->current->one(CheckoutSchema::TABLES['authority'], $row['authority_id']);
        $approval = self::authority($authority, $current);
        $request = $body['request'];
        Evidence::same($buyer, $request['buyer']);
        Evidence::same($current['binding'], $request['candidate']);
        CheckoutException::require($body['request_hash'] === CanonicalJson::hash($request)
            && $row['buyer_origin_id'] === $buyer['origin_id'] && $row['selection_hash'] === $selection['selection_hash']
            && $row['candidate_id'] === $current['binding']['candidate_id']
            && $request['authority_id'] === $authority['public_id'] && $request['authority_hash'] === $authority['payload_hash']
            && $request['selection_hash'] === $selection['selection_hash'] && $request['qualifier_id'] === $row['created_by']
            && in_array($request['qualifier_id'], $approval['policy']['qualifier_ids'], true)
            && $request['attestation']['qualified_exemption_confirmed'] === true && $body['tax_minor'] === 0
            && $body['total_minor'] === $selection['selection']['advertised_subtotal_minor']
            && $body['authority_meaning'] === 'accepted_owner_delegated_qualified_exemption_attestation' && $body['external_tax_fact_verified'] === false);
        ExemptionPolicyV1::effective($approval['policy'], $at);
        ExemptionPolicyV1::effective($request['attestation'], $at);
        CheckoutException::require($request['attestation']['effective_until'] > ($requiredUntil ?? $at->addSeconds($current['context']->reservationSeconds)->format('Y-m-d\TH:i:s\Z')), 'expired');

        return ['row' => $row, 'body' => $body, 'authority' => $authority,
            'owner_delegation' => config('production_checkout.exemption_policy_owner_ids', [])];
    }

    public static function proveRetained(Records $rows, array $expected): void
    {
        Evidence::same($expected['row'], $rows->current->one(CheckoutSchema::TABLES['basis'], $expected['row']['id']));
        Evidence::same($expected['authority'], $rows->current->one(CheckoutSchema::TABLES['authority'], $expected['authority']['id']));
        Evidence::same($expected['owner_delegation'], config('production_checkout.exemption_policy_owner_ids', []));
    }
}
