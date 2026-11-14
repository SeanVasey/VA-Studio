<?php

namespace Tests\Unit;

use App\Domain\Rights\EconomicLicenseTerms;
use App\Domain\Rights\LicenseContent;
use App\Domain\Rights\LicenseSourceVariables;
use App\Domain\Rights\LicenseTerms;
use App\Domain\Rights\TieredLicenseTerms;
use App\Domain\Rights\TypedLicenseTerms;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\TieredLicenseFixtures;
use Tests\TestCase;

class TieredLicenseTermsTest extends TestCase
{
    public static function invalidFields(): array
    {
        return [
            ['schema_version', '5'], ['schema_version', 4], ['schema_version', 6], ['features', ['Owner approved']],
            ['usage', null], ['usage', []], ['usage.monetized_streams', ['mode' => 'unlimited']],
            ['usage.live_performances', ['mode' => 'unlimited']], ['usage.audio_streams', 'unlimited'],
            ['usage.audio_streams.mode', 'some'], ['usage.audio_streams.mode', null],
            ['usage.monetized_video_streams', ['mode' => 'limited']], ['usage.free_downloads.limit', 0],
            ['usage.free_downloads.limit', -1], ['usage.free_downloads.limit', '3000'], ['usage.free_downloads.limit', 3000.0],
            ['usage.free_downloads.limit', 2147483648], ['usage.not_for_profit_performances', ['mode' => 'unlimited', 'limit' => 5]],
            ['usage.for_profit_performances', ['mode' => 'prohibited', 'limit' => 1]], ['usage.music_videos.note', 'monetized'],
            ['permissions.content_id', 'allowed'], ['credit.text', '{{credit}}'], ['required_asset_roles', []],
            ['territory.country_codes', ['ZZ']], ['duration.months', 0], ['ownership.source_recording.policy_key', 'missing'],
            ['publishing_income.licensor_bps', 10001], ['recording_royalty.basis', 'profit'], ['policies.0.text', '{{policy_texts}}'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_missing_stale_and_unsupported_v5_fields(string $field, mixed $value): void
    {
        $terms = TieredLicenseFixtures::terms();
        Arr::set($terms, $field, $value);
        $this->expectException(ValidationException::class);
        app(LicenseTerms::class)->validate($terms);
    }

    public function test_every_tier_usage_choice_is_required_without_defaults_or_v4_substitutes(): void
    {
        foreach (array_keys(TieredLicenseTerms::USAGE) as $key) {
            $terms = TieredLicenseFixtures::terms();
            unset($terms['usage'][$key]);
            try {
                app(LicenseTerms::class)->validate($terms);
                $this->fail('A missing tier usage choice was defaulted: '.$key);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('structured_terms.usage', $exception->errors());
            }
        }
        // The v4 vocabulary is not an alternative spelling of v5: its combined slots are refused.
        $terms = TieredLicenseFixtures::terms();
        $terms['usage'] = EconomicLicenseFixtures::terms()['usage'];
        $this->expectException(ValidationException::class);
        app(LicenseTerms::class)->validate($terms);
    }

    public function test_limits_keep_the_full_integer_range_and_an_explicit_mode(): void
    {
        $terms = TieredLicenseFixtures::terms();
        foreach (array_keys(TieredLicenseTerms::USAGE) as $key) {
            foreach ([['mode' => 'limited', 'limit' => 1], ['mode' => 'limited', 'limit' => 2147483647], ['mode' => 'unlimited'], ['mode' => 'prohibited']] as $use) {
                $terms['usage'][$key] = $use;
                $this->assertSame($terms, app(LicenseTerms::class)->validate($terms));
            }
        }
        $this->assertSame('Free downloads: up to 2,147,483,647.', app(LicenseTerms::class)->statements(array_replace_recursive($terms, ['usage' => ['free_downloads' => ['mode' => 'limited', 'limit' => 2147483647]]]))['usage.free_downloads']);
    }

    public function test_statements_list_each_tier_slot_then_the_unchanged_v4_declarations(): void
    {
        $terms = TieredLicenseFixtures::terms();
        $this->assertSame($terms, app(LicenseTerms::class)->validate($terms));
        $statements = app(LicenseTerms::class)->statements($terms);
        $this->assertSame([
            'usage.audio_releases' => 'Audio releases: up to 2.',
            'usage.copies_downloads' => 'Copies / downloads: up to 1,500.',
            'usage.audio_streams' => 'Audio streams: up to 250,000.',
            'usage.monetized_video_streams' => 'Monetized video streams: up to 50,000.',
            'usage.non_monetized_video_streams' => 'Non-monetized video streams: unlimited.',
            'usage.music_videos' => 'Monetized music videos: up to 1.',
            'usage.radio_stations' => 'Radio stations: not permitted.',
            'usage.free_downloads' => 'Free downloads: up to 3,000.',
            'usage.for_profit_performances' => 'For-profit performances: not permitted.',
            'usage.not_for_profit_performances' => 'Not-for-profit performances: unlimited.',
        ], array_slice($statements, 0, 10, true));
        // Every non-usage statement is byte-identical to the v4 text for the same declarations.
        $economic = app(EconomicLicenseTerms::class)->statements(EconomicLicenseFixtures::terms());
        $usageKeys = array_fill_keys(array_map(fn ($key) => 'usage.'.$key, array_keys(TypedLicenseTerms::USAGE)), true);
        $this->assertSame(array_diff_key($economic, $usageKeys), array_slice($statements, 10, null, true));
        $this->assertCount(24, $statements);
        foreach (['monetized_streams', 'non_monetized_streams', 'live_performances'] as $retired) {
            $this->assertArrayNotHasKey('usage.'.$retired, $statements);
        }
        $this->assertStringNotContainsString('Live performances', implode(' ', $statements));
        $this->assertArrayNotHasKey('policy_texts', $statements);
        $values = app(LicenseTerms::class)->sourceValues($terms);
        $this->assertSame($statements, array_diff_key($values, ['policy_texts' => true]));
        $this->assertSame(app(EconomicLicenseTerms::class)->sourceValues(EconomicLicenseFixtures::terms())['policy_texts'], $values['policy_texts']);
        $this->assertSame(array_merge(array_keys($statements), ['policy_texts']), array_keys($values));
    }

    public function test_source_substitution_requires_every_v5_variable_once_and_refuses_retired_or_unknown_ones(): void
    {
        $terms = TieredLicenseFixtures::terms();
        $source = TieredLicenseFixtures::source();
        $rendered = app(LicenseSourceVariables::class)->render($source, $terms);
        foreach (app(LicenseTerms::class)->statements($terms) as $statement) {
            $this->assertStringContainsString($statement, $rendered);
        }
        $this->assertStringContainsString(hash('sha256', $terms['policies'][0]['text']), $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
        $invalid = array_map(fn ($key) => str_replace('{{'.$key.'}}', '', $source), [...TieredLicenseFixtures::USAGE_VARIABLES, 'deliverables', 'territory', 'duration', 'publishing_income', 'policy_texts']);
        foreach (['usage.monetized_streams', 'usage.non_monetized_streams', 'usage.live_performances', 'usage.unknown', 'features'] as $unsupported) {
            $invalid[] = $source.' {{'.$unsupported.'}}';
        }
        $invalid[] = $source.' {{policy_texts}}';
        $invalid[] = str_replace('{{usage.free_downloads}}', '{{ usage.free_downloads }}', $source);
        foreach ($invalid as $candidate) {
            try {
                app(LicenseContent::class)->validate(['authored_source' => $candidate, 'structured_terms' => $terms]);
                $this->fail('Incomplete, retired or unknown v5 source variables were accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('authored_source', $exception->errors());
            }
        }
        // Small statements may repeat; only the policy bundle is bounded to one occurrence.
        $this->assertStringContainsString('Free downloads: up to 3,000.', app(LicenseSourceVariables::class)->render($source.' {{usage.free_downloads}}', $terms));
    }

    public function test_historical_v4_statements_source_values_and_substitution_match_frozen_bytes(): void
    {
        // Generated from origin/main f578e10c before schema 5 existed.
        $golden = json_decode(file_get_contents(base_path('tests/Fixtures/license-terms-v4.json')), true, 32, JSON_THROW_ON_ERROR);
        $terms = EconomicLicenseFixtures::terms();
        $terms['credit']['text'] = '<SYNTHETIC> & PRODUCER';
        $terms['policies'][0]['text'] .= "\n<script>synthetic & 'quoted'</script>";
        $this->assertSame($golden['terms'], $terms);
        $this->assertSame($golden['statements'], app(LicenseTerms::class)->statements($terms));
        $this->assertSame($golden['source_values'], app(LicenseTerms::class)->sourceValues($terms));
        $this->assertSame($golden['rendered'], app(LicenseSourceVariables::class)->render('<script>synthetic</script>'.EconomicLicenseFixtures::source(), $terms));
        $this->assertSame(5, LicenseTerms::SCHEMA_VERSION);
    }
}
