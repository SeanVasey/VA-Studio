<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Rights\EconomicLicenseTerms;
use App\Domain\Rights\LicenseTerritories;
use App\Domain\Rights\TypedLicenseTerms;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;

/** Pure bounded retained values. No facade, runtime registry, legal-text transformation or authority. */
final class MemberGrantDocumentValues
{
    public const MAX_BYTES = 524288;

    public static function keys(mixed $value, array $expected): void
    {
        MemberGrantException::require(is_array($value), 'invalid_document');
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        MemberGrantException::require($keys === $expected, 'invalid_document');
    }

    public static function bounded(array $value): void
    {
        self::tree($value, 0);
        MemberGrantException::require(strlen(CanonicalJson::encode($value)) <= self::MAX_BYTES, 'invalid_document');
    }

    private static function tree(mixed $value, int $depth): void
    {
        MemberGrantException::require($depth <= 24, 'invalid_document');
        if (is_array($value)) {
            MemberGrantException::require(count($value) <= 512, 'invalid_document');
            foreach ($value as $key => $child) {
                MemberGrantException::require(is_int($key) || (is_string($key) && strlen($key) <= 160 && mb_check_encoding($key, 'UTF-8')), 'invalid_document');
                self::tree($child, $depth + 1);
            }
        } else {
            MemberGrantException::require($value === null || is_bool($value) || is_int($value)
                || (is_string($value) && strlen($value) <= self::MAX_BYTES && mb_check_encoding($value, 'UTF-8')), 'invalid_document');
        }
    }

    public static function text(mixed $value, int $max, bool $multiline = false): void
    {
        MemberGrantException::require(is_string($value) && trim($value) !== '' && strlen($value) <= $max
            && mb_check_encoding($value, 'UTF-8')
            && preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $value) === 0, 'invalid_document');
    }

    public static function integer(mixed $value, int $min = 1, int $max = 2147483647): void
    {
        MemberGrantException::require(is_int($value) && $value >= $min && $value <= $max, 'invalid_document');
    }

    public static function hash(mixed $value): void
    {
        MemberGrantException::require(is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1, 'invalid_document');
    }

    public static function id(mixed $value): void
    {
        MemberGrantException::require(is_string($value) && preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $value) === 1, 'invalid_document');
    }

    public static function provenance(mixed $value): void
    {
        MemberGrantException::require(in_array($value, [IdentityPolicy::REHEARSAL, IdentityPolicy::PRODUCTION], true), 'invalid_document');
    }

    public static function family(array $document): void
    {
        MemberGrantException::require($document['family'] === MemberGrantIntent::FAMILY && $document['purpose'] === MemberGrantIntent::PURPOSE, 'invalid_document');
        self::provenance($document['provenance']);
    }

    public static function iso(mixed $value, bool $nullable = false): void
    {
        if ($nullable && $value === null) {
            return;
        }
        MemberGrantException::require(is_string($value), 'invalid_document');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        MemberGrantException::require($date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value, 'invalid_document');
    }

    public static function roles(mixed $roles): void
    {
        MemberGrantException::require(is_array($roles) && array_is_list($roles) && count($roles) >= 1 && count($roles) <= 3, 'invalid_document');
        foreach ($roles as $role) {
            MemberGrantException::require(is_string($role) && array_key_exists($role, TypedLicenseTerms::DELIVERABLES), 'invalid_document');
        }
        MemberGrantException::require(count(array_unique($roles, SORT_STRING)) === count($roles), 'invalid_document');
    }

    /** The retained license vocabularies, checked without their container-resolved authoring validators. */
    public static function terms(mixed $terms): void
    {
        MemberGrantException::require(is_array($terms) && in_array($terms['schema_version'] ?? null, [1, 2, 3, 4], true), 'invalid_document');
        $version = $terms['schema_version'];
        $keys = $version === 1 ? ['schema_version', 'features', 'required_asset_roles'] : ['schema_version', 'required_asset_roles', 'usage', 'permissions', 'credit'];
        if ($version >= 3) {
            $keys = [...$keys, 'territory', 'duration'];
        }
        if ($version === 4) {
            $keys = [...$keys, 'ownership', 'publishing_income', 'recording_royalty', 'policies'];
        }
        self::keys($terms, $keys);
        self::roles($terms['required_asset_roles']);
        if ($version === 1) {
            self::strings($terms['features'], 1, 20, 960);
            foreach ($terms['features'] as $feature) {
                MemberGrantException::require(trim($feature) === $feature && mb_strlen($feature) <= 240, 'invalid_document');
            }

            return;
        }
        self::keys($terms['usage'], array_keys(TypedLicenseTerms::USAGE));
        foreach ($terms['usage'] as $use) {
            MemberGrantException::require(is_array($use) && in_array($use['mode'] ?? null, ['prohibited', 'limited', 'unlimited'], true), 'invalid_document');
            self::keys($use, $use['mode'] === 'limited' ? ['mode', 'limit'] : ['mode']);
            if ($use['mode'] === 'limited') {
                self::integer($use['limit']);
            }
        }
        self::keys($terms['permissions'], array_keys(TypedLicenseTerms::PERMISSIONS));
        foreach ($terms['permissions'] as $permission) {
            MemberGrantException::require(in_array($permission, ['permitted', 'prohibited'], true), 'invalid_document');
        }
        $credit = $terms['credit'];
        MemberGrantException::require(is_array($credit) && in_array($credit['mode'] ?? null, ['required', 'not_required'], true), 'invalid_document');
        self::keys($credit, $credit['mode'] === 'required' ? ['mode', 'text'] : ['mode']);
        if ($credit['mode'] === 'required') {
            self::text($credit['text'], 480);
            MemberGrantException::require(trim($credit['text']) === $credit['text'] && mb_strlen($credit['text']) <= 120
                && ! str_contains($credit['text'], '{{') && ! str_contains($credit['text'], '}}'), 'invalid_document');
        }
        if ($version >= 3) {
            self::scope($terms);
        }
        if ($version === 4) {
            self::economics($terms);
        }
    }

    private static function scope(array $terms): void
    {
        $territory = $terms['territory'];
        MemberGrantException::require(is_array($territory) && in_array($territory['mode'] ?? null, ['worldwide', 'countries'], true), 'invalid_document');
        self::keys($territory, $territory['mode'] === 'countries' ? ['mode', 'country_codes'] : ['mode']);
        if ($territory['mode'] === 'countries') {
            self::strings($territory['country_codes'], 1, count(LicenseTerritories::COUNTRIES), 2);
            foreach ($territory['country_codes'] as $code) {
                MemberGrantException::require(array_key_exists($code, LicenseTerritories::COUNTRIES), 'invalid_document');
            }
        }
        $duration = $terms['duration'];
        MemberGrantException::require(is_array($duration) && in_array($duration['mode'] ?? null, ['perpetual', 'fixed_months'], true), 'invalid_document');
        self::keys($duration, $duration['mode'] === 'fixed_months' ? ['mode', 'starts_at', 'months'] : ['mode', 'starts_at']);
        MemberGrantException::require($duration['starts_at'] === 'grant', 'invalid_document');
        if ($duration['mode'] === 'fixed_months') {
            self::integer($duration['months'], 1, 1200);
        }
    }

    private static function economics(array $terms): void
    {
        $policies = $terms['policies'];
        MemberGrantException::require(is_array($policies) && array_is_list($policies) && count($policies) >= 1 && count($policies) <= 6, 'invalid_document');
        $known = [];
        $bytes = 0;
        foreach ($policies as $policy) {
            self::keys($policy, ['key', 'version', 'text']);
            MemberGrantException::require(is_string($policy['key']) && preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $policy['key']) === 1
                && is_string($policy['version']) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/D', $policy['version']) === 1
                && ! isset($known[$policy['key']]), 'invalid_document');
            self::text($policy['text'], 20000, true);
            MemberGrantException::require(! str_contains($policy['text'], '{{') && ! str_contains($policy['text'], '}}'), 'invalid_document');
            $bytes += strlen($policy['text']);
            $known[$policy['key']] = true;
        }
        MemberGrantException::require($bytes <= 60000, 'invalid_document');
        self::keys($terms['ownership'], array_keys(EconomicLicenseTerms::OWNERSHIP));
        $used = [];
        foreach ($terms['ownership'] as $ownership) {
            self::keys($ownership, ['policy_key']);
            self::policyReference($ownership, $known, $used);
        }
        foreach (['publishing_income' => ['none', 'share'], 'recording_royalty' => ['none', 'rate']] as $key => $modes) {
            $value = $terms[$key];
            MemberGrantException::require(is_array($value) && in_array($value['mode'] ?? null, $modes, true), 'invalid_document');
            $extra = $key === 'publishing_income' ? ['licensor_bps'] : ['rate_bps', 'basis'];
            self::keys($value, $value['mode'] === 'none' ? ['mode', 'policy_key'] : ['mode', 'policy_key', ...$extra]);
            if ($value['mode'] !== 'none') {
                self::integer($value[$extra[0]], 1, 10000);
                if ($key === 'recording_royalty') {
                    MemberGrantException::require(in_array($value['basis'], ['gross_receipts', 'net_receipts'], true), 'invalid_document');
                }
            }
            self::policyReference($value, $known, $used);
        }
        MemberGrantException::require(array_diff_key($known, $used) === [], 'invalid_document');
    }

    private static function policyReference(array $declaration, array $known, array &$used): void
    {
        $key = $declaration['policy_key'];
        MemberGrantException::require(is_string($key) && isset($known[$key]), 'invalid_document');
        $used[$key] = true;
    }

    public static function strings(mixed $values, int $min, int $max, int $bytes): void
    {
        MemberGrantException::require(is_array($values) && array_is_list($values) && count($values) >= $min && count($values) <= $max, 'invalid_document');
        foreach ($values as $value) {
            self::text($value, $bytes);
        }
        MemberGrantException::require(count(array_unique($values, SORT_STRING)) === count($values), 'invalid_document');
    }

    public static function originalTerms(mixed $value): void
    {
        self::keys($value, ['purpose', 'authored_source', 'structured_terms', 'renderer_version', 'render_fixture_hash']);
        MemberGrantException::require($value['purpose'] === 'membership-license-original-terms-v1', 'invalid_document');
        self::text($value['authored_source'], 100000, true);
        self::terms($value['structured_terms']);
        self::text($value['renderer_version'], 160);
        self::hash($value['render_fixture_hash']);
    }

    public static function license(mixed $license): void
    {
        self::keys($license, ['id', 'template_id', 'name', 'version', 'type', 'authored_source', 'source_hash', 'model_hash',
            'structured_terms', 'features', 'required_asset_roles', 'renderer_version', 'render_fixture_hash', 'submission_hash',
            'review_evidence_id', 'review_evidence_hash', 'approval_reference', 'approved_by', 'approved_at', 'effective_from', 'effective_until']);
        foreach (['id', 'template_id', 'version', 'review_evidence_id', 'approved_by'] as $key) {
            self::integer($license[$key]);
        }
        self::text($license['name'], 1024);
        MemberGrantException::require($license['type'] === 'non-exclusive', 'invalid_document');
        self::originalTerms(self::termsProjection($license));
        foreach (['source_hash', 'model_hash', 'submission_hash', 'review_evidence_hash'] as $key) {
            self::hash($license[$key]);
        }
        MemberGrantException::require(hash('sha256', $license['authored_source']) === $license['source_hash']
            && CanonicalJson::hash($license['structured_terms']) === $license['model_hash'], 'invalid_document');
        self::strings($license['features'], 1, 64, 65536);
        self::roles($license['required_asset_roles']);
        MemberGrantException::require($license['required_asset_roles'] === $license['structured_terms']['required_asset_roles'], 'invalid_document');
        if ($license['structured_terms']['schema_version'] === 1) {
            MemberGrantException::require($license['features'] === $license['structured_terms']['features'], 'invalid_document');
        }
        self::text($license['approval_reference'], 1024);
        self::iso($license['approved_at']);
        self::iso($license['effective_from'], true);
        self::iso($license['effective_until'], true);
        MemberGrantException::require($license['effective_from'] === null || $license['effective_until'] === null
            || $license['effective_from'] < $license['effective_until'], 'invalid_document');
    }

    public static function termsProjection(array $license): array
    {
        return ['purpose' => 'membership-license-original-terms-v1', 'authored_source' => $license['authored_source'],
            'structured_terms' => $license['structured_terms'], 'renderer_version' => $license['renderer_version'],
            'render_fixture_hash' => $license['render_fixture_hash']];
    }

    public static function assets(mixed $assets, array $required): void
    {
        MemberGrantException::require(is_array($assets) && array_is_list($assets) && count($assets) >= 1 && count($assets) <= 3, 'invalid_document');
        foreach ($assets as $asset) {
            MemberGrantException::require(is_array($asset) && in_array($asset['role'] ?? null, ['master_wav', 'download_mp3', 'stems_zip'], true), 'invalid_document');
            $keys = ['id', 'role', 'sha256', 'mime_type', 'size_bytes', 'original_name', 'parent_asset_id', 'processing_run_id'];
            self::keys($asset, $asset['role'] === 'stems_zip' ? [...$keys, 'recording_binding'] : $keys);
            foreach (['id', 'parent_asset_id', 'processing_run_id'] as $key) {
                self::integer($asset[$key]);
            }
            self::integer($asset['size_bytes'], 1, DeliveryAssetFiles::MAX_BYTES);
            self::hash($asset['sha256']);
            self::text($asset['original_name'], 1024);
            MemberGrantException::require($asset['mime_type'] === match ($asset['role']) {
                'master_wav' => 'audio/wav', 'download_mp3' => 'audio/mpeg', 'stems_zip' => 'application/zip',
            }, 'invalid_document');
            if ($asset['role'] === 'stems_zip') {
                $binding = $asset['recording_binding'];
                self::keys($binding, ['id', 'stems_asset_id', 'master_asset_id', 'preview_asset_id', 'recording_source_id', 'evidence_hash']);
                foreach (['id', 'stems_asset_id', 'master_asset_id', 'preview_asset_id', 'recording_source_id'] as $key) {
                    self::integer($binding[$key]);
                }
                self::hash($binding['evidence_hash']);
                MemberGrantException::require($binding['stems_asset_id'] === $asset['id'], 'invalid_document');
            }
        }
        $roles = array_column($assets, 'role');
        sort($roles, SORT_STRING);
        sort($required, SORT_STRING);
        MemberGrantException::require($roles === $required && count(array_unique(array_column($assets, 'id'))) === count($assets), 'invalid_document');
    }
}
