<?php

namespace Tests\Unit;

use App\Domain\Rights\LicenseContent;
use App\Domain\Rights\LicenseSourceVariables;
use App\Domain\Rights\LicenseTerms;
use App\Domain\Rights\TypedLicenseTerms;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

class TypedLicenseTermsTest extends TestCase
{
    public static function invalidFields(): array
    {
        return [
            ['schema_version', '2'], ['schema_version', 3], ['features', ['Invented unlimited use']],
            ['required_asset_roles', []], ['required_asset_roles', ['master_wav', 'master_wav']], ['required_asset_roles', ['preview_tagged']],
            ['usage', []], ['usage.audio_releases', null], ['usage.audio_releases.mode', 'permitted'],
            ['usage.audio_releases.limit', 0], ['usage.audio_releases.limit', -1], ['usage.audio_releases.limit', '2'],
            ['usage.audio_releases.limit', 1.5], ['usage.audio_releases.limit', true], ['usage.audio_releases.limit', 2147483648],
            ['usage.audio_releases.limit', null], ['usage.radio_stations.limit', 1], ['usage.non_monetized_streams.limit', 99],
            ['permissions', []], ['permissions.content_id', true], ['permissions.content_id', 'unknown'], ['permissions.invented', 'permitted'],
            ['credit.mode', 'maybe'], ['credit.text', ''], ['credit.text', ' leading'], ['credit.text', str_repeat('x', 121)],
            ['credit.text', "bad\ncredit"], ['credit.text', '{{buyer}}'], ['credit.mode', 'not_required'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_incomplete_contradictory_or_malformed_terms_are_rejected(string $field, mixed $value): void
    {
        $terms = TypedLicenseFixtures::terms();
        Arr::set($terms, $field, $value);
        $this->expectException(ValidationException::class);
        app(LicenseTerms::class)->validate($terms);
    }

    public function test_card_statements_and_substituted_source_share_exact_typed_values(): void
    {
        $terms = TypedLicenseFixtures::terms();
        $this->assertSame($terms, app(LicenseTerms::class)->validate($terms));
        $expected = [
            'Audio releases: up to 2.', 'Copies / downloads: up to 1,500.', 'Monetized streams: up to 250,000.',
            'Non-monetized streams: unlimited.', 'Music videos: up to 1.', 'Live performances: unlimited.', 'Radio stations: not permitted.',
            'Content ID registration: not permitted.', 'Paid advertising: permitted.', 'Sublicensing: not permitted.', 'Standalone resale: not permitted.',
            'Producer credit required: SYNTHETIC PRODUCER', 'Deliverables: WAV master.',
        ];
        $this->assertSame($expected, array_values(app(TypedLicenseTerms::class)->statements($terms)));
        $rendered = app(LicenseSourceVariables::class)->render(TypedLicenseFixtures::source(), $terms);
        $this->assertSame("SYNTHETIC NONBINDING REVIEW FIXTURE. Not an actual license.\n".implode("\n", $expected), $rendered);
        $terms['usage']['audio_releases'] = ['mode' => 'limited', 'limit' => 2147483647];
        $terms['credit'] = ['mode' => 'not_required'];
        $terms['required_asset_roles'] = ['stems_zip', 'master_wav', 'download_mp3'];
        $statements = app(TypedLicenseTerms::class)->statements($terms);
        $this->assertSame('Audio releases: up to 2,147,483,647.', $statements['usage.audio_releases']);
        $this->assertSame('Producer credit: not required.', $statements['credit']);
        $this->assertSame('Deliverables: MP3, WAV master, Stems ZIP.', $statements['deliverables']);
        $reordered = array_reverse($terms, true);
        $reordered['usage'] = array_reverse($terms['usage'], true);
        $this->assertSame($statements, app(TypedLicenseTerms::class)->statements($reordered));
    }

    public function test_unknown_missing_and_malformed_variables_block_content_without_evaluation(): void
    {
        $source = TypedLicenseFixtures::source();
        foreach ([str_replace('{{credit}}', '', $source), $source.' {{buyer.name}}', $source.' {{ usage.audio_releases }}', $source.' {{usage.audio_releases', $source.' }}', $source.' {{{{credit}}}}', $source.' {{file_get_contents("/etc/passwd")}}'] as $invalid) {
            try {
                app(LicenseContent::class)->validate(['authored_source' => $invalid, 'structured_terms' => TypedLicenseFixtures::terms()]);
                $this->fail('Invalid source variables were accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('authored_source', $exception->errors());
            }
        }
        $rendered = app(LicenseSourceVariables::class)->render($source."\n{{credit}}", TypedLicenseFixtures::terms());
        $this->assertSame(2, substr_count($rendered, 'Producer credit required: SYNTHETIC PRODUCER'));
        $legacy = ['authored_source' => 'Literal legacy {{buyer}}', 'structured_terms' => ['schema_version' => 1, 'features' => ['Legacy'], 'required_asset_roles' => ['master_wav']]];
        $this->assertSame($legacy['authored_source'], app(LicenseContent::class)->validate($legacy)['authored_source']);
    }
}
