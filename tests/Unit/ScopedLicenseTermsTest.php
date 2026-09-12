<?php

namespace Tests\Unit;

use App\Domain\Rights\LicenseContent;
use App\Domain\Rights\LicenseSourceVariables;
use App\Domain\Rights\LicenseTerms;
use App\Domain\Rights\ScopedLicenseTerms;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ScopedLicenseFixtures;
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

class ScopedLicenseTermsTest extends TestCase
{
    public static function invalidFields(): array
    {
        return [
            ['schema_version', '3'], ['schema_version', 4], ['invented', true], ['features', ['Worldwide forever']],
            ['territory', null], ['territory.mode', 'exclude'], ['territory.country_codes', []],
            ['territory.country_codes', ['US', 'US']], ['territory.country_codes', ['us']],
            ['territory.country_codes', ['USA']], ['territory.country_codes', ['ZZ']],
            ['territory.country_codes', ['UK']], ['territory.country_codes', ['US-IA']],
            ['territory.country_codes', ['EU']], ['territory.country_codes', [true]],
            ['territory.country_codes', [' US']], ['territory.country_codes', ['bad' => 'US']],
            ['territory.country_codes', array_fill(0, 250, 'US')],
            ['territory.mode', 'worldwide'], ['territory.exclusions', ['CA']],
            ['duration', []], ['duration.mode', 'fixed_days'], ['duration.starts_at', 'publication'],
            ['duration.starts_at', 'quote'], ['duration.starts_at', null], ['duration.months', 0],
            ['duration.months', -1], ['duration.months', 1201], ['duration.months', '120'],
            ['duration.months', 1.5], ['duration.months', true], ['duration.months', null],
            ['duration.mode', 'perpetual'], ['duration.ends_at', '2100-01-01'],
            ['usage.audio_releases.limit', 0], ['permissions.content_id', 'unknown'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_incomplete_unsupported_or_contradictory_scope_is_rejected(string $field, mixed $value): void
    {
        $terms = ScopedLicenseFixtures::terms();
        // Use fixed modes for invalid nested-field cases independently of fixture display order.
        $terms['territory'] = ['mode' => 'countries', 'country_codes' => ['US', 'CA']];
        $terms['duration'] = ['mode' => 'fixed_months', 'starts_at' => 'grant', 'months' => 120];
        Arr::set($terms, $field, $value);
        $this->expectException(ValidationException::class);
        app(LicenseTerms::class)->validate($terms);
    }

    public function test_scope_values_are_exact_stable_and_shared_without_reordering_the_stored_terms(): void
    {
        $terms = ScopedLicenseFixtures::terms();
        $terms['territory'] = ['mode' => 'countries', 'country_codes' => ['US', 'CA']];
        $terms['duration'] = ['mode' => 'fixed_months', 'starts_at' => 'grant', 'months' => 120];
        $this->assertSame($terms, app(LicenseTerms::class)->validate($terms));
        $statements = app(ScopedLicenseTerms::class)->statements($terms);
        $this->assertCount(15, $statements);
        $this->assertSame('Territory: CA, US (ISO 3166-1 alpha-2).', $statements['territory']);
        $this->assertSame('License duration: 120 calendar months from the grant timestamp (UTC; end-of-month clamping).', $statements['duration']);
        $reordered = array_reverse($terms, true);
        $reordered['territory']['country_codes'] = ['CA', 'US'];
        $this->assertSame($statements, app(ScopedLicenseTerms::class)->statements($reordered));
        $rendered = app(LicenseSourceVariables::class)->render(ScopedLicenseFixtures::source(), $terms);
        foreach ($statements as $statement) {
            $this->assertStringContainsString($statement, $rendered);
        }
        $terms['duration']['months'] = 1;
        $this->assertSame('License duration: 1 calendar month from the grant timestamp (UTC; end-of-month clamping).', app(ScopedLicenseTerms::class)->statements($terms)['duration']);
        $terms['duration']['months'] = 1200;
        $this->assertSame($terms, app(LicenseTerms::class)->validate($terms));
        $terms['territory'] = ['mode' => 'worldwide'];
        $terms['duration'] = ['mode' => 'perpetual', 'starts_at' => 'grant'];
        $statements = app(ScopedLicenseTerms::class)->statements($terms);
        $this->assertSame('Territory: worldwide.', $statements['territory']);
        $this->assertSame('License duration: perpetual from the grant timestamp.', $statements['duration']);
    }

    public function test_new_variables_are_required_only_in_the_new_schema(): void
    {
        foreach (['territory', 'duration'] as $variable) {
            try {
                app(LicenseContent::class)->validate(['authored_source' => str_replace('{{'.$variable.'}}', '', ScopedLicenseFixtures::source()), 'structured_terms' => ScopedLicenseFixtures::terms()]);
                $this->fail('Incomplete scope source was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('authored_source', $exception->errors());
            }
            try {
                app(LicenseSourceVariables::class)->render(TypedLicenseFixtures::source().' {{'.$variable.'}}', TypedLicenseFixtures::terms());
                $this->fail('A v3 variable was interpreted in historical v2 source.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('authored_source', $exception->errors());
            }
        }
    }
}
