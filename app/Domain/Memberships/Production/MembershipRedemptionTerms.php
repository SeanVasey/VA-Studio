<?php

namespace App\Domain\Memberships\Production;

use App\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;

/** Explicit per-period credit rules. These values neither prove catalog facts nor authorize a write. */
final readonly class MembershipRedemptionTerms
{
    public const MAX_RULES = 256;

    public const MAX_HONOR_SECONDS = 315360000;

    private function __construct(public array $facts) {}

    public static function from(array $facts): self
    {
        MembershipPolicyDocument::keys($facts, ['eligibility_rules', 'honor', 'release', 'available_expiry']);
        MembershipException::require(is_array($facts['eligibility_rules']) && array_is_list($facts['eligibility_rules'])
            && count($facts['eligibility_rules']) >= 1 && count($facts['eligibility_rules']) <= self::MAX_RULES, 'invalid_policy_value');
        $ids = [];
        $targets = [];
        foreach ($facts['eligibility_rules'] as $rule) {
            MembershipException::require(is_array($rule), 'invalid_policy_value');
            MembershipPolicyDocument::keys($rule, ['rule_id', 'track_id', 'offer_id', 'offer_revision_id', 'license_version_id',
                'license_manifest_hash', 'asset_manifest_hash', 'original_terms_hash', 'credit_amount']);
            MembershipException::require(is_string($rule['rule_id'])
                && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $rule['rule_id']) === 1, 'invalid_policy_value');
            foreach (['track_id', 'offer_id', 'offer_revision_id', 'license_version_id'] as $key) {
                MembershipException::require(is_int($rule[$key]) && $rule[$key] >= 1 && $rule[$key] <= 2147483647, 'invalid_policy_value');
            }
            foreach (['license_manifest_hash', 'asset_manifest_hash', 'original_terms_hash'] as $key) {
                MembershipException::require(is_string($rule[$key]), 'invalid_policy_value');
                MembershipValues::hash($rule[$key]);
            }
            MembershipException::require(is_int($rule['credit_amount']) && $rule['credit_amount'] >= 1
                && $rule['credit_amount'] <= MembershipPolicy::MAX_CREDITS, 'invalid_policy_value');
            $target = implode(':', [$rule['track_id'], $rule['offer_id'], $rule['offer_revision_id'], $rule['license_version_id']]);
            MembershipException::require(! isset($ids[$rule['rule_id']]) && ! isset($targets[$target]), 'ambiguous_eligibility_rule');
            $ids[$rule['rule_id']] = $targets[$target] = true;
        }
        foreach (['honor', 'release', 'available_expiry'] as $key) {
            MembershipException::require(is_array($facts[$key]), 'invalid_policy_value');
        }
        MembershipPolicyDocument::keys($facts['honor'], ['duration_seconds', 'expiry_mode']);
        MembershipException::require(is_int($facts['honor']['duration_seconds']) && $facts['honor']['duration_seconds'] >= 1
            && $facts['honor']['duration_seconds'] <= self::MAX_HONOR_SECONDS
            && in_array($facts['honor']['expiry_mode'], ['cap_at_credit_expiry', 'allow_beyond_credit_expiry'], true), 'invalid_policy_value');
        MembershipPolicyDocument::keys($facts['release'], ['before_credit_expiry', 'at_or_after_credit_expiry']);
        MembershipException::require($facts['release']['before_credit_expiry'] === 'available'
            && $facts['release']['at_or_after_credit_expiry'] === 'expired', 'unsupported_policy');
        MembershipPolicyDocument::keys($facts['available_expiry'], ['mode']);
        MembershipException::require($facts['available_expiry']['mode'] === 'expire_available_at_credit_expiry', 'unsupported_policy');

        return new self($facts);
    }

    public function rule(string $ruleId): array
    {
        foreach ($this->facts['eligibility_rules'] as $rule) {
            if ($rule['rule_id'] === $ruleId) {
                return $rule;
            }
        }
        throw new MembershipException('ineligible_license');
    }

    /** Match only current, producer-authenticated identities; no caller credit amount is accepted. */
    public function ruleFor(int $trackId, int $offerId, int $offerRevisionId, int $licenseVersionId,
        string $licenseManifestHash, string $assetManifestHash, string $originalTermsHash): array
    {
        foreach ($this->facts['eligibility_rules'] as $rule) {
            if ([$rule['track_id'], $rule['offer_id'], $rule['offer_revision_id'], $rule['license_version_id'],
                $rule['license_manifest_hash'], $rule['asset_manifest_hash'], $rule['original_terms_hash']]
                === [$trackId, $offerId, $offerRevisionId, $licenseVersionId, $licenseManifestHash, $assetManifestHash, $originalTermsHash]) {
                return $rule;
            }
        }
        throw new MembershipException('ineligible_license');
    }

    /** An explicit beyond-expiry honor window never permits reserving already expired credits. */
    public function honorDeadline(string $reservedAt, ?string $creditExpiresAt): string
    {
        MembershipValues::utc($reservedAt);
        if ($creditExpiresAt !== null) {
            MembershipValues::utc($creditExpiresAt);
            MembershipException::require($reservedAt < $creditExpiresAt, 'credit_expired');
        }
        $deadline = (new DateTimeImmutable($reservedAt, new DateTimeZone('UTC')))
            ->modify('+'.$this->facts['honor']['duration_seconds'].' seconds')->format('Y-m-d H:i:s');
        MembershipException::require(strlen($deadline) === 19, 'invalid_policy_value');
        MembershipValues::utc($deadline);
        if ($creditExpiresAt !== null && $this->facts['honor']['expiry_mode'] === 'cap_at_credit_expiry') {
            $deadline = min($deadline, $creditExpiresAt);
        }
        MembershipException::require($reservedAt < $deadline, 'invalid_policy_value');

        return $deadline;
    }

    /** Destination only; this does not authorize an early, voluntary, staff or cancellation release. */
    public function releaseDestination(string $at, ?string $creditExpiresAt): string
    {
        MembershipValues::utc($at);
        if ($creditExpiresAt !== null) {
            MembershipValues::utc($creditExpiresAt);
        }

        return $creditExpiresAt !== null && $at >= $creditExpiresAt ? 'expired' : 'available';
    }

    public function eligibilityHash(): string
    {
        return CanonicalJson::hash($this->facts['eligibility_rules']);
    }

    public function honorHash(): string
    {
        return CanonicalJson::hash($this->facts['honor']);
    }
}
