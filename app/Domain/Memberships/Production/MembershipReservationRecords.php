<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Grants\Member\MemberActivationRecords;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/** Pure retained-record builders and verification, never an operation permit or database writer. */
final class MembershipReservationRecords
{
    public const TABLE = 'production_membership_redemptions';

    public const VERSION = 'membership-redemption-record-v1';

    public static function requestKey(MembershipInvoiceBinding $invoice, string $key): string
    {
        return CanonicalJson::hash(self::requestScope($invoice, $key));
    }

    /** Clock-independent retry intent. Neither a producer lease nor retry renews retained honor. */
    public static function intent(array $period, MembershipInvoiceBinding $invoice, MembershipPolicyBinding $policy, MembershipLicenseBinding $license): array
    {
        return self::selectedIntent($period, $invoice, $policy, [
            'track_id' => $license->trackId, 'offer_id' => $license->offerId, 'offer_revision_id' => $license->offerRevisionId,
            'license_version_id' => $license->licenseVersionId, 'selection_hash' => $license->selectionHash,
            'license_manifest_hash' => $license->licenseManifestHash, 'asset_manifest_hash' => $license->assetManifestHash,
            'original_terms_hash' => $license->originalTermsHash,
        ]);
    }

    public static function create(array $period, MembershipInvoiceBinding $invoice, MembershipPolicyBinding $policy,
        MembershipLicenseBinding $license, string $key, string $at): array
    {
        $intent = self::intent($period, $invoice, $policy, $license);
        MembershipValues::utc($at);
        MembershipException::require($at >= $period['created_at'], 'invalid_record_time');
        $scope = self::requestScope($invoice, $key);
        $id = (string) Str::uuid();
        $honor = $policy->redemptionTerms->honorDeadline($at, $period['credit_expires_at']);
        $payload = ['schema_version' => self::VERSION, 'purpose' => 'membership-credit-reservation',
            'redemption_id' => $id, 'request_scope' => $scope, 'request_key_hash' => CanonicalJson::hash($scope),
            'intent' => $intent, 'created_at' => $at, 'honor_deadline' => $honor];
        $row = ['id' => $id, 'period_id' => $period['id'], 'request_key_hash' => $payload['request_key_hash'],
            'owner_binding_hash' => CanonicalJson::hash($invoice->originalBuyerBinding), 'credit_amount' => $intent['credit_amount'],
            'selection_hash' => $license->selectionHash, 'license_manifest_hash' => $license->licenseManifestHash,
            'asset_manifest_hash' => $license->assetManifestHash, 'original_terms_hash' => $license->originalTermsHash,
            'intent_hash' => CanonicalJson::hash($intent), 'honor_deadline' => $honor,
            'payload_ciphertext' => Crypt::encryptString(CanonicalJson::encode($payload)), 'created_at' => $at];
        $row['seal'] = self::seal($row);

        return $row;
    }

    public static function verify(array $row, array $period, MembershipInvoiceBinding $invoice, MembershipPolicyBinding $policy): array
    {
        MembershipPolicyDocument::keys($row, ['id', 'period_id', 'request_key_hash', 'owner_binding_hash', 'credit_amount',
            'selection_hash', 'license_manifest_hash', 'asset_manifest_hash', 'original_terms_hash', 'intent_hash',
            'honor_deadline', 'payload_ciphertext', 'seal', 'created_at']);
        MembershipException::require(is_string($row['seal']) && is_string($row['payload_ciphertext'])
            && strlen($row['payload_ciphertext']) <= 65536, 'tampered');
        $matched = false;
        foreach (self::keys() as $key) {
            $matched = hash_equals(self::seal($row, $key), $row['seal']) || $matched;
        }
        MembershipException::require($matched, 'tampered');
        MembershipValues::id($row['id']);
        MembershipValues::utc($row['created_at']);
        MembershipValues::utc($row['honor_deadline']);
        try {
            $plain = Crypt::decryptString($row['payload_ciphertext']);
            $payload = json_decode($plain, true, 24, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new MembershipException('tampered');
        }
        MembershipException::require(is_array($payload) && strlen($plain) <= 16384 && CanonicalJson::encode($payload) === $plain, 'tampered');
        MembershipPolicyDocument::keys($payload, ['schema_version', 'purpose', 'redemption_id', 'request_scope',
            'request_key_hash', 'intent', 'created_at', 'honor_deadline']);
        MembershipException::require(is_array($payload['intent']) && is_array($payload['intent']['selection'] ?? null)
            && is_array($payload['request_scope']), 'tampered');
        $scope = $payload['request_scope'];
        MembershipPolicyDocument::keys($scope, ['purpose', 'buyer_origin_id', 'key_digest']);
        MembershipException::require($scope['purpose'] === 'membership-credit-reservation-request-v1'
            && $scope['buyer_origin_id'] === $invoice->originalBuyerBinding['origin_id'] && is_string($scope['key_digest']), 'tampered');
        MembershipValues::hash($scope['key_digest']);
        $intent = self::selectedIntent($period, $invoice, $policy, $payload['intent']['selection']);
        $expected = ['schema_version' => self::VERSION, 'purpose' => 'membership-credit-reservation',
            'redemption_id' => $row['id'], 'request_scope' => $scope, 'request_key_hash' => CanonicalJson::hash($scope),
            'intent' => $intent, 'created_at' => $row['created_at'],
            'honor_deadline' => $policy->redemptionTerms->honorDeadline($row['created_at'], $period['credit_expires_at'])];
        MembershipException::require(CanonicalJson::encode($expected) === $plain && $row['period_id'] === $period['id']
            && $row['created_at'] >= $period['created_at'] && $row['request_key_hash'] === $expected['request_key_hash']
            && $row['owner_binding_hash'] === $period['owner_binding_hash']
            && MembershipLedgerAudit::integer($row['credit_amount']) === $intent['credit_amount']
            && $row['intent_hash'] === CanonicalJson::hash($intent) && $row['honor_deadline'] === $expected['honor_deadline'], 'tampered');
        foreach (['selection_hash', 'license_manifest_hash', 'asset_manifest_hash', 'original_terms_hash'] as $field) {
            MembershipException::require($row[$field] === $intent['selection'][$field], 'tampered');
        }

        return $payload;
    }

    public static function reserveEvent(array $audit, array $redemption, array $actorBinding, string $at): array
    {
        $amount = MembershipLedgerAudit::integer($redemption['credit_amount']);
        MembershipException::require($amount > 0 && $redemption['period_id'] === $audit['period']['id']
            && $redemption['owner_binding_hash'] === $audit['period']['owner_binding_hash']
            && ! isset($audit['reservations'][$redemption['id']]) && $at === $redemption['created_at']
            && $at < $redemption['honor_deadline']
            && ($audit['period']['credit_expires_at'] === null || $at < $audit['period']['credit_expires_at']), 'invalid_reservation');
        MembershipException::require($audit['balances']['available'] >= $amount, 'insufficient_credit');
        $live = count(array_filter($audit['reservations'], fn (array $reservation): bool => $reservation['terminal'] === null));
        // Every live reservation must retain a terminal slot. A finite period also keeps an expiry
        // slot even when this reserve spends all availability: an earlier release can restore it.
        $next = MembershipLedgerAudit::integer($audit['tail']['sequence'], MembershipPolicy::MAX_EVENTS) + 1;
        MembershipException::require($next + $live + 1 + ($audit['period']['credit_expires_at'] === null ? 0 : 1)
            <= MembershipPolicy::MAX_EVENTS, 'ledger_capacity');
        $event = self::event($audit, 'reserve', $amount, $actorBinding, $at);
        $event['redemption_id'] = $redemption['id'];
        $event['key_hash'] = self::reserveKey($redemption);
        $event['request_hash'] = $redemption['intent_hash'];
        $event['after_available'] -= $amount;
        $event['after_reserved'] += $amount;

        return self::finish($event);
    }

    /** Only cleanup after retained honor expiry is admitted; no cancellation or voluntary release rule. */
    public static function releaseEvent(array $audit, string $redemptionId, MembershipPolicyBinding $policy, array $actorBinding, string $at): array
    {
        self::policy($audit, $policy, 'release');
        $reservation = $audit['reservations'][$redemptionId] ?? null;
        MembershipException::require(is_array($reservation) && $reservation['terminal'] === null, 'reservation_closed');
        $row = $reservation['record'];
        MembershipException::require($at >= $row['honor_deadline'], 'honor_current');
        $event = self::event($audit, 'release', MembershipLedgerAudit::integer($row['credit_amount']), $actorBinding, $at);
        $event['redemption_id'] = $row['id'];
        $event['reservation_event_id'] = $reservation['reserve']['id'];
        $event['key_hash'] = self::releaseKey($row);
        $event['request_hash'] = self::releaseRequest($audit['period'], $row, $reservation['reserve']);
        $event['after_reserved'] -= $event['amount'];
        $destination = $policy->redemptionTerms->releaseDestination($at, $audit['period']['credit_expires_at']);
        $event['after_'.$destination] += $event['amount'];

        return self::finish($event);
    }

    public static function expireEvent(array $audit, MembershipPolicyBinding $policy, array $actorBinding, string $at): array
    {
        self::policy($audit, $policy, 'expire');
        MembershipException::require($audit['period']['credit_expires_at'] !== null
            && $at >= $audit['period']['credit_expires_at'], 'credit_current');
        MembershipException::require($audit['balances']['available'] > 0, 'no_available_credit');
        $event = self::event($audit, 'expire', $audit['balances']['available'], $actorBinding, $at);
        $event['key_hash'] = self::expireKey($audit['period']);
        $event['request_hash'] = self::expireRequest($audit['period'], $event['amount']);
        $event['after_available'] = 0;
        $event['after_expired'] += $event['amount'];

        return self::finish($event);
    }

    /** A pure planned terminal record. Only the separately admitted activation writer may insert it. */
    public static function consumeEvent(array $audit, string $redemptionId, MembershipPolicyBinding $policy,
        array $readinessValue, array $actorBinding, string $at): array
    {
        self::policy($audit, $policy, 'consume');
        MembershipException::require($policy->consumptionTerms !== null, 'unsupported_policy');
        $reservation = $audit['reservations'][$redemptionId] ?? null;
        MembershipException::require(is_array($reservation) && is_array($reservation['reserve'])
            && $reservation['terminal'] === null, 'reservation_closed');
        $row = $reservation['record'];
        $reserve = $reservation['reserve'];
        $readiness = self::consumeReadiness($audit['period'], $row, $reserve, $readinessValue);
        MembershipException::require($readiness['policy_hash'] === $policy->policyHash
            && $readiness['consumption_terms_hash'] === $policy->consumptionTerms->hash()
            && $readiness['retention_policy_hash'] === $policy->retentionPolicyHash
            && $readiness['provenance'] === $policy->provenance, 'invalid_consumption');
        MembershipValues::utc($at);
        MembershipException::require($at >= $row['created_at'], 'invalid_record_time');
        MembershipException::require($at < $row['honor_deadline'], 'honor_expired');
        $amount = MembershipLedgerAudit::integer($row['credit_amount']);
        MembershipException::require($amount > 0 && $audit['balances']['reserved'] >= $amount, 'invalid_credit_balance');
        $event = self::event($audit, 'consume', $amount, $actorBinding, $at);
        $event['redemption_id'] = $row['id'];
        $event['reservation_event_id'] = $reserve['id'];
        $event['grant_origin_id'] = $readiness['origin_id'];
        $event['grant_receipt_hash'] = CanonicalJson::hash($readiness);
        $event['grant_purpose'] = MemberGrantIntent::PURPOSE;
        $event['key_hash'] = self::consumeKey($row['id']);
        $event['request_hash'] = self::consumeRequest($audit['period'], $row, $reserve, $readiness);
        $event['after_reserved'] -= $amount;
        $event['after_consumed'] += $amount;

        return self::finish($event);
    }

    public static function consumeKey(string $redemptionId): string
    {
        MembershipValues::id($redemptionId);

        return CanonicalJson::hash(['purpose' => 'membership-credit-consume-event-v1', 'redemption_id' => $redemptionId]);
    }

    public static function consumeRequest(array $period, array $redemption, array $reserve, array $readinessValue): string
    {
        $readiness = self::consumeReadiness($period, $redemption, $reserve, $readinessValue);

        return CanonicalJson::hash(['purpose' => 'membership-credit-consume-v1', 'period_id' => $period['id'],
            'period_seal' => $period['seal'], 'redemption_id' => $redemption['id'], 'redemption_seal' => $redemption['seal'],
            'intent_hash' => $redemption['intent_hash'], 'reservation_event_id' => $reserve['id'],
            'reservation_event_seal' => $reserve['seal'], 'grant_origin_id' => $readiness['origin_id'],
            'readiness_receipt_hash' => CanonicalJson::hash($readiness),
            'authorization_intent_hash' => $readiness['authorization_intent_hash'], 'honor_deadline' => $redemption['honor_deadline']]);
    }

    private static function consumeReadiness(array $period, array $redemption, array $reserve, array $value): array
    {
        $readiness = MemberActivationRecords::readiness($value);
        MembershipException::require($readiness['period_id'] === $period['id'] && $readiness['period_seal'] === $period['seal']
            && $readiness['redemption_id'] === $redemption['id'] && $readiness['redemption_seal'] === $redemption['seal']
            && $readiness['reservation_event_id'] === $reserve['id'] && $readiness['reservation_event_seal'] === $reserve['seal']
            && $readiness['source_invoice_hash'] === $period['source_invoice_hash']
            && $redemption['period_id'] === $period['id'] && $redemption['owner_binding_hash'] === $period['owner_binding_hash']
            && $reserve['period_id'] === $period['id'] && $reserve['redemption_id'] === $redemption['id']
            && $reserve['kind'] === 'reserve'
            && MembershipLedgerAudit::integer($reserve['amount']) === MembershipLedgerAudit::integer($redemption['credit_amount']), 'invalid_consumption');

        return $readiness;
    }

    public static function reserveKey(array $row): string
    {
        return CanonicalJson::hash(['purpose' => 'membership-credit-reserve-event-v1',
            'redemption_id' => $row['id'], 'request_key_hash' => $row['request_key_hash']]);
    }

    public static function releaseKey(array $row): string
    {
        return CanonicalJson::hash(['purpose' => 'membership-credit-release-event-v1', 'redemption_id' => $row['id']]);
    }

    public static function releaseRequest(array $period, array $row, array $reserve): string
    {
        return CanonicalJson::hash(['purpose' => 'membership-credit-release-v1', 'reason' => 'honor_expired',
            'period_id' => $period['id'], 'period_seal' => $period['seal'], 'redemption_id' => $row['id'],
            'redemption_seal' => $row['seal'], 'reservation_event_id' => $reserve['id'], 'reservation_seal' => $reserve['seal'],
            'honor_deadline' => $row['honor_deadline']]);
    }

    public static function expireKey(array $period): string
    {
        return CanonicalJson::hash(['purpose' => 'membership-credit-expire-event-v1', 'period_id' => $period['id']]);
    }

    public static function expireRequest(array $period, int $amount): string
    {
        return CanonicalJson::hash(['purpose' => 'membership-credit-expire-v1', 'period_id' => $period['id'],
            'period_seal' => $period['seal'], 'credit_expires_at' => $period['credit_expires_at'], 'amount' => $amount]);
    }

    public static function seal(array $row, ?string $key = null): string
    {
        unset($row['seal']);

        return hash_hmac('sha256', CanonicalJson::encode(['purpose' => self::VERSION,
            'table' => self::TABLE, 'row' => MembershipRecords::strings($row)]), $key ?? self::keys()[0]);
    }

    private static function selectedIntent(array $period, MembershipInvoiceBinding $invoice, MembershipPolicyBinding $policy, array $selection): array
    {
        MembershipException::require($policy->supports('reserve') && $policy->redemptionTerms !== null
            && $policy->eligibleLicensePolicyHash === $policy->redemptionTerms->eligibilityHash()
            && $policy->reservationHonorPolicyHash === $policy->redemptionTerms->honorHash(), 'unsupported_policy');
        MembershipException::require($period['plan_version_id'] === $policy->planVersionId
            && $invoice->planVersionId === $policy->planVersionId && $invoice->planPolicyHash === $policy->policyHash
            && $invoice->originalTermsHash === $policy->originalTermsHash
            && $period['source_invoice_hash'] === $invoice->sourceInvoiceHash
            && $period['invoice_graph_hash'] === $invoice->sourceGraphHash
            && $period['owner_binding_hash'] === CanonicalJson::hash($invoice->originalBuyerBinding), 'conflicting_invoice');
        MembershipPolicyDocument::keys($selection, ['track_id', 'offer_id', 'offer_revision_id', 'license_version_id',
            'selection_hash', 'license_manifest_hash', 'asset_manifest_hash', 'original_terms_hash']);
        foreach (['track_id', 'offer_id', 'offer_revision_id', 'license_version_id'] as $field) {
            MembershipException::require(is_int($selection[$field]) && $selection[$field] > 0 && $selection[$field] <= 2147483647, 'ineligible_license');
        }
        foreach (['selection_hash', 'license_manifest_hash', 'asset_manifest_hash', 'original_terms_hash'] as $field) {
            MembershipException::require(is_string($selection[$field]), 'ineligible_license');
            MembershipValues::hash($selection[$field]);
        }
        $rule = $policy->redemptionTerms->ruleFor($selection['track_id'], $selection['offer_id'], $selection['offer_revision_id'],
            $selection['license_version_id'], $selection['license_manifest_hash'], $selection['asset_manifest_hash'], $selection['original_terms_hash']);

        return ['purpose' => 'membership-credit-reservation-intent-v1', 'period_id' => $period['id'], 'period_seal' => $period['seal'],
            'invoice' => MembershipPeriodRecords::semantic($invoice), 'identity_origin_id' => MembershipLedgerAudit::integer($period['identity_origin_id'], PHP_INT_MAX),
            'plan_policy_hash' => $policy->policyHash, 'plan_original_terms_hash' => $policy->originalTermsHash, 'rule' => $rule,
            'selection' => $selection, 'credit_amount' => $rule['credit_amount'], 'reservation_honor_policy_hash' => $policy->reservationHonorPolicyHash];
    }

    private static function requestScope(MembershipInvoiceBinding $invoice, string $key): array
    {
        MembershipException::require(preg_match('/\A[\x20-\x7e]{1,128}\z/D', $key) === 1, 'invalid_request_key');

        return ['purpose' => 'membership-credit-reservation-request-v1',
            'buyer_origin_id' => $invoice->originalBuyerBinding['origin_id'], 'key_digest' => hash('sha256', $key)];
    }

    private static function event(array $audit, string $kind, int $amount, array $actorBinding, string $at): array
    {
        MembershipValues::buyer($actorBinding);
        MembershipValues::utc($at);
        foreach (['account_id', 'user_id', 'origin_id', 'account_public_id', 'provenance'] as $key) {
            MembershipException::require($actorBinding[$key] === $audit['buyer_binding'][$key], 'owner_changed');
        }
        $sequence = MembershipLedgerAudit::integer($audit['tail']['sequence'], MembershipPolicy::MAX_EVENTS) + 1;
        MembershipException::require($sequence <= MembershipPolicy::MAX_EVENTS && $amount > 0 && $amount <= MembershipPolicy::MAX_CREDITS, 'technical_bound');
        MembershipException::require($at >= $audit['tail']['created_at'], 'invalid_record_time');
        $event = ['id' => (string) Str::uuid(), 'period_id' => $audit['period']['id'], 'sequence' => $sequence, 'kind' => $kind,
            'amount' => $amount, 'redemption_id' => null, 'reservation_event_id' => null, 'grant_origin_id' => null,
            'grant_receipt_hash' => null, 'grant_purpose' => null, 'key_hash' => '', 'request_hash' => '',
            'prior_seal' => $audit['tail']['seal'], 'actor_binding_hash' => CanonicalJson::hash($actorBinding)];
        foreach (['before', 'after'] as $side) {
            foreach (['available', 'reserved', 'consumed', 'expired'] as $balance) {
                $event[$side.'_'.$balance] = $audit['balances'][$balance];
            }
        }
        $event['created_at'] = $at;

        return $event;
    }

    private static function finish(array $event): array
    {
        foreach (['available', 'reserved', 'consumed', 'expired'] as $balance) {
            MembershipLedgerAudit::integer($event['after_'.$balance]);
        }
        $event['seal'] = MembershipPeriodRecords::seal(MembershipPeriodRecords::EVENTS, $event);

        return $event;
    }

    private static function policy(array $audit, MembershipPolicyBinding $policy, string $operation): void
    {
        MembershipException::require($policy->supports($operation) && $policy->redemptionTerms !== null
            && $audit['policy_hash'] === $policy->policyHash && $audit['period']['plan_version_id'] === $policy->planVersionId, 'unsupported_policy');
    }

    private static function keys(): array
    {
        $current = config('app.key');
        $previous = config('app.previous_keys', []);
        MembershipException::require(is_string($current) && strlen($current) >= 32 && is_array($previous), 'key_absent');
        foreach ($previous as $key) {
            MembershipException::require(is_string($key) && strlen($key) >= 32, 'key_absent');
        }

        return array_values(array_unique([$current, ...$previous]));
    }
}
