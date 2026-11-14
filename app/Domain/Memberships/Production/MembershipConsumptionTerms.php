<?php

namespace App\Domain\Memberships\Production;

use App\Support\CanonicalJson;

/** Explicit retained technical confirmation terms; neither legal assent nor write authority. */
final readonly class MembershipConsumptionTerms
{
    private function __construct(private string $canonical) {}

    public static function from(array $facts): self
    {
        $facts = self::detached($facts);
        MembershipPolicyDocument::keys($facts, ['schema_version', 'purpose', 'reference', 'text', 'retention_policy', 'source_rule', 'authorization_policy']);
        MembershipException::require($facts['schema_version'] === 'membership-consumption-terms-v1'
            && $facts['purpose'] === MemberGrantIntent::PURPOSE && $facts['source_rule'] === 'current_selected_catalog', 'unsupported_policy');
        self::text($facts['reference'], 160);
        self::text($facts['text'], 65536, true);
        MembershipException::require(is_array($facts['retention_policy']) && is_array($facts['authorization_policy']), 'invalid_policy_value');
        $retention = $facts['retention_policy'];
        MembershipPolicyDocument::keys($retention, ['schema_version', 'reference', 'text']);
        MembershipException::require($retention['schema_version'] === 'member-original-retention-facts-v1', 'unsupported_policy');
        self::text($retention['reference'], 160);
        self::text($retention['text'], 65536, true);
        $authorization = $facts['authorization_policy'];
        MembershipPolicyDocument::keys($authorization, ['schema_version', 'mode', 'action', 'record_claim', 'display_reference', 'display_text', 'required_sources']);
        MembershipException::require($authorization['schema_version'] === 'member-license-authorization-policy-v1'
            && $authorization['mode'] === 'explicit_owner_confirmation_v1'
            && $authorization['action'] === 'activate_member_license'
            && $authorization['record_claim'] === 'owner_requested_activation'
            && $authorization['required_sources'] === 'selected_license_and_consumption_terms', 'unsupported_policy');
        self::text($authorization['display_reference'], 160);
        self::text($authorization['display_text'], 65536, true);

        return new self(CanonicalJson::encode($facts));
    }

    public function value(): array
    {
        return json_decode($this->canonical, true, 32, JSON_THROW_ON_ERROR);
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonical);
    }

    public function retentionHash(): string
    {
        return CanonicalJson::hash($this->value()['retention_policy']);
    }

    public function authorizationHash(): string
    {
        return CanonicalJson::hash($this->value()['authorization_policy']);
    }

    /** New v3 inputs only. Copy scalars recursively without object callbacks or caller references. */
    public static function detached(array $value): array
    {
        $nodes = 8192;
        $bytes = 524288;

        return self::copy($value, 0, $nodes, $bytes);
    }

    private static function copy(mixed $value, int $depth, int &$nodes, int &$bytes): mixed
    {
        MembershipException::require(--$nodes >= 0 && $depth <= 16, 'invalid_policy_value');
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                MembershipException::require(is_int($key) || (is_string($key) && strlen($key) <= 160
                    && mb_check_encoding($key, 'UTF-8') && ! str_contains($key, "\0")), 'invalid_policy_value');
                $result[$key] = self::copy($child, $depth + 1, $nodes, $bytes);
            }

            return $result;
        }
        MembershipException::require($value === null || is_bool($value) || is_int($value) || is_string($value), 'invalid_policy_value');
        if (is_string($value)) {
            $bytes -= strlen($value);
            MembershipException::require(strlen($value) <= 65536 && $bytes >= 0
                && mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0"), 'invalid_policy_value');
        }

        return $value;
    }

    private static function text(mixed $value, int $max, bool $multiline = false): void
    {
        // Match the mandatory confirmation display and C4b retention vocabulary without normalizing bytes.
        MembershipException::require(is_string($value) && trim($value) !== '' && strlen($value) <= $max
            && preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $value) === 0, 'invalid_policy_value');
    }
}
