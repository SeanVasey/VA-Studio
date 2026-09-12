<?php

namespace Tests\Unit;

use App\Domain\Rights\EconomicLicenseTerms;
use App\Domain\Rights\LicenseContent;
use App\Domain\Rights\LicenseSourceVariables;
use App\Domain\Rights\LicenseTerms;
use App\Support\CanonicalJson;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\ScopedLicenseFixtures;
use Tests\TestCase;

class EconomicLicenseTermsTest extends TestCase
{
    public static function invalidFields(): array
    {
        return [
            ['schema_version', '4'], ['schema_version', 5], ['features', ['Owner approved']],
            ['ownership', []], ['ownership.source_recording', null],
            ['ownership.source_recording.policy_key', 'missing'], ['ownership.source_composition.policy_key', []],
            ['ownership.resulting_recording.percent', 50], ['ownership.resulting_composition.policy_key', null],
            ['publishing_income.mode', 'unknown'], ['publishing_income.mode', 'none'],
            ['publishing_income.licensor_bps', 0], ['publishing_income.licensor_bps', -1],
            ['publishing_income.licensor_bps', 10001], ['publishing_income.licensor_bps', '1234'],
            ['publishing_income.licensor_bps', 12.34], ['publishing_income.licensor_bps', true],
            ['publishing_income.denominator', 'publisher_share'], ['publishing_income.policy_key', 'missing'],
            ['recording_royalty.mode', 'royalty_free'], ['recording_royalty.mode', 'none'],
            ['recording_royalty.rate_bps', 0], ['recording_royalty.rate_bps', 10001],
            ['recording_royalty.rate_bps', '567'], ['recording_royalty.rate_bps', 5.67],
            ['recording_royalty.rate_bps', null], ['recording_royalty.basis', 'profit'],
            ['recording_royalty.payer', 'customer'], ['recording_royalty.policy_key', 'missing'],
            ['policies', null], ['policies', []], ['policies', ['key' => 'economic-fixture']],
            ['policies.0.key', 'UPPERCASE'], ['policies.0.key', 'economic-fixture '],
            ['policies.0.key', '0-fixture'], ['policies.0.key', str_repeat('a', 65)],
            ['policies.0.version', ''], ['policies.0.version', 1], ['policies.0.version', 'v1/v2'],
            ['policies.0.version', str_repeat('a', 33)], ['policies.0.text', '  '],
            ['policies.0.text', "\xC3\x28"], ['policies.0.text', "Synthetic\x00policy"],
            ['policies.0.text', str_repeat('a', 20001)], ['policies.0.text', '{{recording_royalty}}'],
            ['policies.0.text', '{{'], ['policies.0.text', '}}'],
            ['policies.0.sha256', str_repeat('a', 64)], ['policies.0.url', 'https://example.test/policy'],
            ['usage.audio_releases.limit', 0], ['duration.months', 0], ['territory.country_codes', ['ZZ']],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_ambiguous_missing_stale_and_unsupported_economic_fields(string $field, mixed $value): void
    {
        $terms = EconomicLicenseFixtures::terms();
        Arr::set($terms, $field, $value);
        $this->expectException(ValidationException::class);
        app(LicenseTerms::class)->validate($terms);
    }

    public function test_all_new_decisions_and_references_are_required_without_defaults(): void
    {
        foreach (['ownership', 'ownership.source_composition', 'publishing_income', 'publishing_income.policy_key', 'recording_royalty', 'recording_royalty.basis', 'policies', 'policies.0.text'] as $field) {
            $terms = EconomicLicenseFixtures::terms();
            Arr::forget($terms, $field);
            try {
                app(LicenseTerms::class)->validate($terms);
                $this->fail('A missing economic declaration was defaulted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_precise_rates_subjects_and_policy_references_are_shared_with_source(): void
    {
        $terms = EconomicLicenseFixtures::terms();
        $this->assertSame($terms, app(LicenseTerms::class)->validate($terms));
        $statements = app(LicenseTerms::class)->statements($terms);
        $this->assertCount(21, $statements);
        $this->assertSame('Licensed source recording ownership: defined by retained policy economic-fixture @ test-v1.', $statements['ownership.source_recording']);
        $this->assertStringContainsString('Resulting composition ownership:', $statements['ownership.resulting_composition']);
        $this->assertSame('Publishing income: licensor share is 12.34% of total publishing income attributable to the resulting composition, as defined by retained policy economic-fixture @ test-v1.', $statements['publishing_income']);
        $this->assertSame('Recording royalty: licensee pays licensor 5.67% of gross receipts from the resulting recording, as defined by retained policy economic-fixture @ test-v1.', $statements['recording_royalty']);
        $rendered = app(LicenseSourceVariables::class)->render(EconomicLicenseFixtures::source(), $terms);
        foreach ($statements as $statement) {
            $this->assertStringContainsString($statement, $rendered);
        }
        $this->assertStringContainsString($terms['policies'][0]['text'], $rendered);
        $this->assertStringContainsString(hash('sha256', $terms['policies'][0]['text']), $rendered);
        $this->assertStringNotContainsString($terms['policies'][0]['text'], implode(' ', $statements));
        $this->assertArrayNotHasKey('policy_texts', $statements);
        foreach ([1 => '0.01', 100 => '1.00', 9999 => '99.99', 10000 => '100.00'] as $bps => $percentage) {
            $terms['publishing_income']['licensor_bps'] = $bps;
            $terms['recording_royalty']['rate_bps'] = $bps;
            $terms['recording_royalty']['basis'] = 'net_receipts';
            $generated = app(LicenseTerms::class)->statements($terms);
            $this->assertStringContainsString($percentage.'% of total publishing income', $generated['publishing_income']);
            $this->assertStringContainsString($percentage.'% of net receipts', $generated['recording_royalty']);
        }
        $terms['publishing_income'] = ['mode' => 'none', 'policy_key' => 'economic-fixture'];
        $terms['recording_royalty'] = ['mode' => 'none', 'policy_key' => 'economic-fixture'];
        $generated = app(LicenseTerms::class)->statements($terms);
        $this->assertStringContainsString('no additional publishing-income entitlement', $generated['publishing_income']);
        $this->assertStringContainsString('no additional recording royalty is payable by the licensee to the licensor under this license', $generated['recording_royalty']);
        $this->assertStringNotContainsString('royalty free', implode(' ', $generated));
    }

    private function sixPolicies(): array
    {
        $terms = EconomicLicenseFixtures::terms();
        $terms['policies'] = [];
        $paths = array_map(fn ($subject) => 'ownership.'.$subject.'.policy_key', array_keys(EconomicLicenseTerms::OWNERSHIP));
        $paths = [...$paths, 'publishing_income.policy_key', 'recording_royalty.policy_key'];
        foreach ($paths as $index => $path) {
            $key = 'fixture-'.$index;
            Arr::set($terms, $path, $key);
            $terms['policies'][] = ['key' => $key, 'version' => 'test-v1', 'text' => "NONBINDING SYNTHETIC policy {$index}.\nLiteral retained text."];
        }

        return $terms;
    }

    public function test_policy_output_is_sorted_but_retained_order_and_bytes_are_unchanged(): void
    {
        $terms = $this->sixPolicies();
        $terms['policies'] = array_reverse($terms['policies']);
        $this->assertSame($terms, app(LicenseTerms::class)->validate($terms));
        $originalHash = CanonicalJson::hash($terms);
        $values = app(LicenseTerms::class)->sourceValues($terms);
        $sorted = $terms;
        $sorted['policies'] = array_reverse($sorted['policies']);
        $this->assertSame($values, app(LicenseTerms::class)->sourceValues($sorted));
        $this->assertSame($originalHash, CanonicalJson::hash($terms));
        $this->assertStringStartsWith('Retained policy fixture-0 @ test-v1;', $values['policy_texts']);
        $changed = $terms;
        $changed['policies'][0]['text'] .= "\nDifferent synthetic policy bytes.";
        $this->assertNotSame($originalHash, CanonicalJson::hash($changed));
        $this->assertNotSame($values['policy_texts'], app(LicenseTerms::class)->sourceValues($changed)['policy_texts']);
    }

    public function test_duplicate_unreferenced_and_oversized_policy_bundles_are_rejected(): void
    {
        $duplicate = EconomicLicenseFixtures::terms();
        $duplicate['policies'][] = $duplicate['policies'][0];
        $unused = EconomicLicenseFixtures::terms();
        $unused['policies'][] = ['key' => 'unused', 'version' => 'v1', 'text' => 'SYNTHETIC UNUSED'];
        $tooMany = $this->sixPolicies();
        $tooMany['policies'][] = ['key' => 'seventh', 'version' => 'v1', 'text' => 'SYNTHETIC SEVENTH'];
        $tooLarge = $this->sixPolicies();
        foreach ($tooLarge['policies'] as &$policy) {
            $policy['text'] = str_repeat('x', 10001);
        }
        unset($policy);
        foreach ([$duplicate, $unused, $tooMany, $tooLarge] as $terms) {
            try {
                app(LicenseTerms::class)->validate($terms);
                $this->fail('Unresolvable or unbounded policy evidence was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $boundary = $this->sixPolicies();
        foreach ($boundary['policies'] as &$policy) {
            $policy['text'] = str_repeat('x', 10000);
        }
        unset($policy);
        $this->assertSame($boundary, app(LicenseTerms::class)->validate($boundary));
    }

    public function test_complete_policy_bundle_occurs_once_and_all_economic_variables_are_required(): void
    {
        $source = EconomicLicenseFixtures::source();
        $terms = EconomicLicenseFixtures::terms();
        $variables = ['ownership.source_recording', 'ownership.source_composition', 'ownership.resulting_recording', 'ownership.resulting_composition', 'publishing_income', 'recording_royalty', 'policy_texts'];
        $sources = array_map(fn ($key) => str_replace('{{'.$key.'}}', '', $source), $variables);
        $sources[] = $source.' {{policy_texts}}';
        $sources[] = str_replace('{{policy_texts}}', '{{ policy_texts }}', $source);
        $sources[] = $source.' {{unknown_policy}}';
        foreach ($sources as $invalid) {
            try {
                app(LicenseContent::class)->validate(['authored_source' => $invalid, 'structured_terms' => $terms]);
                $this->fail('Incomplete or multiplied policy evidence was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('authored_source', $exception->errors());
            }
        }
        // Repeated small variables remain supported, including the historical schemas.
        $this->assertStringContainsString('Recording royalty:', app(LicenseSourceVariables::class)->render($source.' {{recording_royalty}}', $terms));
        $this->assertStringContainsString('Territory:', app(LicenseSourceVariables::class)->render(ScopedLicenseFixtures::source().' {{territory}}', ScopedLicenseFixtures::terms()));
        $this->expectException(ValidationException::class);
        app(LicenseSourceVariables::class)->render(ScopedLicenseFixtures::source().' {{policy_texts}}', ScopedLicenseFixtures::terms());
    }
}
