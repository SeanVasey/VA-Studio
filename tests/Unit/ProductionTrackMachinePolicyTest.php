<?php

namespace Tests\Unit;

use App\Domain\Commerce\Policy\ProductionTrackPolicyDraft;
use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1;
use App\Support\CanonicalJson;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionTrackCapabilitiesFixtures;
use Tests\Support\ProductionTrackPolicyFixtures;
use Tests\TestCase;

class ProductionTrackMachinePolicyTest extends TestCase
{
    public function test_supplied_choices_and_source_hashes_round_trip_without_defaults_or_activation(): void
    {
        $source = ProductionTrackPolicyFixtures::authored();
        $policy = ProductionTrackCapabilitiesFixtures::machine($source);
        $this->assertSame($policy, MachinePolicyV1::forSource($policy, $source));
        $canonical = CanonicalJson::encode($policy);
        $this->assertSame($canonical, CanonicalJson::encode(MachinePolicyV1::forSource(json_decode($canonical, true, 8, JSON_THROW_ON_ERROR), $source)));
        $this->assertArrayNotHasKey('activation_allowed', $policy);
        $this->assertArrayNotHasKey('execution_allowed', $policy);
        $this->assertCount(14, $policy['choices']);
    }

    public function test_explicit_supported_alternatives_do_not_coerce_currency_tax_or_identity(): void
    {
        $policy = ProductionTrackCapabilitiesFixtures::machine();
        $policy['choices']['currency'] = ['code' => 'JPY', 'minor_unit_exponent' => 0];
        $policy['choices']['provider_account']['capture_method'] = 'manual';
        $policy['choices']['tax_calculation'] = ['strategy' => 'declared_exemption', 'behavior' => 'inclusive', 'maximum_rate_bps' => 0, 'rounding' => 'not_applicable'];
        $policy['choices']['buyer_identity']['mode'] = 'verified_guest_claim';
        $policy['choices']['recovery']['mode'] = 'verified_guest_claim_recovery';
        $policy['choices']['storage']['adapter'] = 'private_object_store';
        $policy['choices']['delivery']['transfer'] = 'private_object_single_attempt';
        $this->assertSame($policy, MachinePolicyV1::validate($policy));
    }

    public static function unsupportedChoices(): array
    {
        return [
            'currency exponent drift' => ['currency', 'minor_unit_exponent', 0],
            'string exponent' => ['currency', 'minor_unit_exponent', '2'],
            'unknown currency' => ['currency', 'code', 'XXX'],
            'float tax ceiling' => ['tax_calculation', 'maximum_rate_bps', 2500.0],
            'excessive tax ceiling' => ['tax_calculation', 'maximum_rate_bps', 10001],
            'legacy tax' => ['tax_calculation', 'strategy', 'fixed_test'],
            'undeclared zero-tax exemption' => ['tax_calculation', 'strategy', 'declared_exemption'],
            'test mode' => ['provider_account', 'mode', 'test'],
            'unknown capture' => ['provider_account', 'capture_method', 'default'],
            'credential in account identity' => ['provider_account', 'account_id', 'sk_live_UNSAFE'],
            'malformed dated API' => ['provider_account', 'api_version', '2026-02-30.dahlia'],
            'plain HTTP return' => ['provider_account', 'return_origin', 'http://review.invalid'],
            'return URL user info' => ['provider_account', 'return_origin', 'https://user:password@review.invalid'],
            'return URL path' => ['provider_account', 'return_origin', 'https://review.invalid/path'],
            'return URL query' => ['provider_account', 'return_origin', 'https://review.invalid?token=x'],
            'return URL loopback' => ['provider_account', 'return_origin', 'https://127.0.0.1'],
            'unverified guest' => ['buyer_identity', 'mode', 'unverified_guest'],
            'wrong recovery identity' => ['recovery', 'mode', 'verified_guest_claim_recovery'],
            'purchase implies marketing' => ['recovery', 'purchase_implies_marketing', true],
            'integer publication flag' => ['license_terms', 'publication_required', 1],
            'revoke prior licenses' => ['license_terms', 'prior_grants', 'revoke'],
            'retry exceeds reservation' => ['reservation_and_exclusives', 'retry_seconds', 1801],
            'boolean reservation' => ['reservation_and_exclusives', 'reservation_seconds', true],
            'unbounded delivery time' => ['delivery', 'authorization_seconds', 3601],
            'float delivery bytes' => ['delivery', 'maximum_bytes', 1048576.0],
            'public storage' => ['storage', 'adapter', 'public'],
            'mismatched transfer' => ['delivery', 'transfer', 'private_object_single_attempt'],
            'filesystem path boundary' => ['storage', 'boundary_id', '/private/originals'],
            'legacy private capture' => ['original_documents', 'purpose', 'private_capture'],
            'replace historical original' => ['original_documents', 'preservation', 'regenerate'],
            'infer unknown consent' => ['privacy', 'unknown_consent', 'accept'],
            'malformed UTF8' => ['seller_identity', 'legal_name', "\xff"],
            'multiline seller' => ['seller_identity', 'legal_name', "seller\nother"],
            'oversized seller' => ['seller_identity', 'legal_name', str_repeat('x', 513)],
            'empty assent' => ['assent', 'text', ' '],
            'assent control character' => ['assent', 'text', "text\0"],
            'oversized assent' => ['assent', 'text', str_repeat('x', 4097)],
            'credential in assent' => ['assent', 'text', 'sk_live_UNSAFE'],
        ];
    }

    #[DataProvider('unsupportedChoices')]
    public function test_unsupported_or_malformed_choices_are_refused(string $category, string $field, mixed $value): void
    {
        $policy = ProductionTrackCapabilitiesFixtures::machine();
        $policy['choices'][$category][$field] = $value;
        $this->expectException(ValidationException::class);
        MachinePolicyV1::validate($policy);
    }

    public static function categories(): array
    {
        return array_combine(ProductionTrackPolicyDraft::CATEGORIES, array_map(fn (string $category): array => [$category], ProductionTrackPolicyDraft::CATEGORIES));
    }

    #[DataProvider('categories')]
    public function test_every_category_is_explicit_and_source_commitments_are_exact(string $category): void
    {
        $policy = ProductionTrackCapabilitiesFixtures::machine();
        unset($policy['choices'][$category]);
        $this->expectException(ValidationException::class);
        MachinePolicyV1::validate($policy);
    }

    #[DataProvider('categories')]
    public function test_no_category_accepts_an_unreviewed_activation_choice(string $category): void
    {
        $policy = ProductionTrackCapabilitiesFixtures::machine();
        $policy['choices'][$category]['execution_allowed'] = true;
        $this->expectException(ValidationException::class);
        MachinePolicyV1::validate($policy);
    }

    public static function malformedCommitments(): array
    {
        return [[null], [1], [str_repeat('A', 64)], [str_repeat('a', 63)], [' '.str_repeat('a', 64)]];
    }

    #[DataProvider('malformedCommitments')]
    public function test_source_commitment_is_a_supplied_exact_lowercase_sha256(mixed $value): void
    {
        $policy = ProductionTrackCapabilitiesFixtures::machine();
        $policy['source_commitments']['currency'] = $value;
        $this->expectException(ValidationException::class);
        MachinePolicyV1::validate($policy);
    }

    #[DataProvider('categories')]
    public function test_unresolved_source_categories_are_not_machine_policy(string $category): void
    {
        $source = ProductionTrackPolicyFixtures::authored();
        $policy = ProductionTrackCapabilitiesFixtures::machine($source);
        $source['declarations'][$category] = ['state' => 'unresolved', 'choice' => null, 'source_reference' => null, 'source_sha256' => null, 'note' => 'Requires actual supplied source.'];
        $this->expectException(ValidationException::class);
        MachinePolicyV1::forSource($policy, $source);
    }

    public function test_changed_source_hash_is_refused_even_when_all_choices_still_match(): void
    {
        $source = ProductionTrackPolicyFixtures::authored();
        $policy = ProductionTrackCapabilitiesFixtures::machine($source);
        $source['declarations']['currency']['source_sha256'] = hash('sha256', 'NONBINDING CHANGED SOURCE');
        $this->expectException(ValidationException::class);
        MachinePolicyV1::forSource($policy, $source);
    }

    public static function foreignSchemas(): array
    {
        return [
            'future schema' => ['schema_version', 2], 'string schema' => ['schema_version', '1'],
            'legacy purpose' => ['purpose', 'test_order_preparation'], 'empty version' => ['version', ''],
            'unknown top level activation' => ['activation_allowed', true],
        ];
    }

    #[DataProvider('foreignSchemas')]
    public function test_foreign_or_permissive_schema_is_refused(string $field, mixed $value): void
    {
        $policy = ProductionTrackCapabilitiesFixtures::machine();
        $policy[$field] = $value;
        $this->expectException(ValidationException::class);
        MachinePolicyV1::validate($policy);
    }
}
