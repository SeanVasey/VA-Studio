<?php

namespace Tests\Feature;

use App\Domain\Catalog\OfferSnapshot;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Rights\LicenseDisclosure;
use App\Domain\Rights\LicenseTerms;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\TypedLicenseTerms;
use App\Models\User;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\ScopedLicenseFixtures;
use Tests\Support\TieredLicenseFixtures;
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

/**
 * FP-005: what a buyer reads in the public license comparison must be the typed terms of the published license version.
 *
 * The oracle is deliberately not LicenseTerms::statements(): each public summary line is read back into a typed value
 * with the wording pinned in this file, and the result must equal the stored structured terms field for field. A summary
 * that drops a field, adds one, rounds a number or words a mode differently fails here. Synthetic nonbinding terms only.
 */
class PublicLicenseSummaryReconciliationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const USAGE_V2 = [
        'Audio releases' => 'audio_releases', 'Copies / downloads' => 'copies_downloads', 'Monetized streams' => 'monetized_streams',
        'Non-monetized streams' => 'non_monetized_streams', 'Music videos' => 'music_videos', 'Live performances' => 'live_performances',
        'Radio stations' => 'radio_stations',
    ];

    private const USAGE_V5 = [
        'Audio releases' => 'audio_releases', 'Copies / downloads' => 'copies_downloads', 'Audio streams' => 'audio_streams',
        'Monetized video streams' => 'monetized_video_streams', 'Non-monetized video streams' => 'non_monetized_video_streams',
        'Monetized music videos' => 'music_videos', 'Radio stations' => 'radio_stations', 'Free downloads' => 'free_downloads',
        'For-profit performances' => 'for_profit_performances', 'Not-for-profit performances' => 'not_for_profit_performances',
    ];

    private const PERMISSIONS = [
        'Content ID registration' => 'content_id', 'Paid advertising' => 'paid_advertising',
        'Sublicensing' => 'sublicensing', 'Standalone resale' => 'standalone_resale',
    ];

    private const OWNERSHIP = [
        'Licensed source recording ownership' => 'source_recording', 'Licensed source composition ownership' => 'source_composition',
        'Resulting recording ownership' => 'resulting_recording', 'Resulting composition ownership' => 'resulting_composition',
    ];

    private const DELIVERABLES = ['MP3' => 'download_mp3', 'WAV master' => 'master_wav', 'Stems ZIP' => 'stems_zip'];

    /** The storefront's one deliverable label map (DD-30); every client surface that names a file reads it. */
    private const CLIENT_LABELS = 'resources/js/lib/deliverable-labels.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function publish(array $terms, string $source, User $author): LicenseVersion
    {
        $draft = LicenseFixtures::draft($author, $terms, ['authored_source' => $source]);
        $submitted = app(ReviewLicense::class)->submit($draft, $author);
        $approved = app(ReviewLicense::class)->approve($submitted, LicenseFixtures::admin(), [
            'approval_reference' => 'SYNTHETIC SUMMARY RECONCILIATION REVIEW', 'review_hash' => $submitted->submission_hash,
            'summary_consistency_confirmed' => true,
        ]);

        return app(PublishLicense::class)->handle($approved, $author);
    }

    /** @return array<string, array{0: int, 1: array, 2: string}> */
    public static function typedSchemas(): array
    {
        // The mirror of each fixture: every mode the fixture does not use, so both sides of every choice are read back.
        $flip = function (array $terms): array {
            foreach ($terms['usage'] as $key => $use) {
                $terms['usage'][$key] = match ($use['mode']) {
                    'limited' => ['mode' => 'unlimited'], 'unlimited' => ['mode' => 'prohibited'], 'prohibited' => ['mode' => 'limited', 'limit' => 2147483647],
                };
            }
            foreach ($terms['permissions'] as $key => $value) {
                $terms['permissions'][$key] = $value === 'permitted' ? 'prohibited' : 'permitted';
            }
            $terms['credit'] = ['mode' => 'not_required'];
            if (isset($terms['territory'])) {
                $terms['territory'] = ['mode' => 'worldwide'];
                $terms['duration'] = ['mode' => 'perpetual', 'starts_at' => 'grant'];
            }
            if (isset($terms['publishing_income'])) {
                $terms['publishing_income'] = ['mode' => 'none', 'policy_key' => 'economic-fixture'];
                $terms['recording_royalty'] = ['mode' => 'rate', 'policy_key' => 'economic-fixture', 'rate_bps' => 5, 'basis' => 'net_receipts'];
            }

            return $terms;
        };
        $cases = [];
        foreach ([
            2 => [TypedLicenseFixtures::terms(), TypedLicenseFixtures::source()], 3 => [ScopedLicenseFixtures::terms(), ScopedLicenseFixtures::source()],
            4 => [EconomicLicenseFixtures::terms(), EconomicLicenseFixtures::source()], 5 => [TieredLicenseFixtures::terms(), TieredLicenseFixtures::source()],
        ] as $schema => [$terms, $source]) {
            $cases["schema {$schema} fixture terms"] = [$schema, $terms, $source];
            $cases["schema {$schema} mirrored modes"] = [$schema, $flip($terms), $source];
        }
        $single = ScopedLicenseFixtures::terms();
        $single['duration'] = ['mode' => 'fixed_months', 'starts_at' => 'grant', 'months' => 1];
        $single['territory'] = ['mode' => 'countries', 'country_codes' => ['US']];
        $cases['schema 3 one month, one country'] = [3, $single, ScopedLicenseFixtures::source()];
        $whole = EconomicLicenseFixtures::terms();
        $whole['publishing_income']['licensor_bps'] = 10000;
        $whole['recording_royalty'] = ['mode' => 'none', 'policy_key' => 'economic-fixture'];
        $cases['schema 4 whole share, no royalty'] = [4, $whole, EconomicLicenseFixtures::source()];

        return $cases;
    }

    #[DataProvider('typedSchemas')]
    public function test_every_public_summary_field_equals_the_typed_term_of_the_published_version(int $schema, array $terms, string $source): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $version = $this->publish($terms, $source, $actor);
        $this->assertSame([$schema, 'published'], [$version->terms_schema_version, $version->status]);
        app(SaveOfferDraft::class)->handle($fixture['offer'], ['license_version_id' => $version->id], $actor);
        $revision = app(PublishOffer::class)->handle($fixture['offer'], $actor);
        $stored = LicenseVersion::findOrFail($version->id);
        $template = $stored->template()->firstOrFail();

        // Surface 1: the comparison cards of the storefront and track page.
        $catalog = $this->getJson('/api/catalog')->assertOk();
        $tier = collect($catalog->json('licenseTiers'))->where('id', (string) $stored->id)->sole();
        $this->assertSame(['id', 'name', 'version', 'type', 'features', 'requiredAssetRoles'], array_keys($tier));
        $this->assertSame([$template->name, $stored->version, $template->type], [$tier['name'], $tier['version'], $tier['type']]);
        $this->assertSame($stored->structured_terms['required_asset_roles'], $tier['requiredAssetRoles']);
        $this->assertReconciled($stored->structured_terms, $tier['features']);
        $offer = collect($catalog->json('tracks.0.offers'))->where('licenseVersionId', (string) $stored->id)->sole();
        $this->assertSame([$template->name, $stored->structured_terms['required_asset_roles']], [$offer['licenseName'], $offer['deliverableRoles']]);

        // Surface 2: the full disclosure a buyer opens before choosing; its summaries are the same lines and every line
        // stands verbatim in the license text shown with them.
        $disclosure = $this->getJson('/tracks/'.$fixture['track']->slug.'/offers/'.$revision->id.'/license')->assertOk()->json();
        $this->assertSame([(string) $stored->id, $template->name, $stored->version, $template->type],
            [$disclosure['licenseVersionId'], $disclosure['name'], $disclosure['version'], $disclosure['type']]);
        $this->assertSame($tier['features'], $disclosure['features']);
        $this->assertSame($stored->structured_terms['required_asset_roles'], $disclosure['deliverableRoles']);
        foreach ($disclosure['features'] as $line) {
            $this->assertSame(1, substr_count($disclosure['termsText'], $line), $line);
        }
        foreach ($stored->structured_terms['policies'] ?? [] as $policy) {
            $this->assertStringContainsString($policy['text'], $disclosure['termsText']);
            $this->assertStringContainsString(hash('sha256', $policy['text']), $disclosure['termsText']);
        }
        $this->assertStringNotContainsString('{{', $disclosure['termsText']);
    }

    public function test_deliverable_summaries_follow_the_typed_roles_for_every_combination(): void
    {
        $actor = LicenseFixtures::admin();
        foreach ([['download_mp3'], ['master_wav'], ['stems_zip'], ['stems_zip', 'download_mp3'], ['master_wav', 'download_mp3'], ['stems_zip', 'master_wav', 'download_mp3']] as $roles) {
            $terms = array_replace(TieredLicenseFixtures::terms(), ['required_asset_roles' => $roles]);
            $version = $this->publish($terms, TieredLicenseFixtures::source(), $actor);
            $snapshot = app(OfferSnapshot::class)->license($version);
            $public = app(LicenseDisclosure::class)->fromSnapshot($snapshot);
            $this->assertSame($roles, $public['deliverableRoles']);
            $this->assertReconciled(LicenseVersion::findOrFail($version->id)->structured_terms, $public['features']);
        }
    }

    public function test_the_storefront_file_line_uses_the_generated_deliverable_wording(): void
    {
        // DD-30: the generated, typed-term wording is canonical. The client's map must be that wording, role for role and
        // in the generated order, and no client source may carry another name for a file role.
        $labels = $this->clientLabels();
        $this->assertSame(array_flip(self::DELIVERABLES), $labels);
        $this->assertSame(TypedLicenseTerms::DELIVERABLES, $labels);
        $this->assertSame(LicenseTerms::ASSET_ROLES, array_keys($labels));
        $sources = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/js'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (in_array($file->getExtension(), ['ts', 'tsx', 'json'], true)) {
                $sources[] = $file->getPathname();
                $this->assertSame(0, preg_match('/untagged|track stems|master wav/i', file_get_contents($file->getPathname())), $file->getPathname());
            }
        }
        $this->assertContains(base_path(self::CLIENT_LABELS), $sources);
        $this->assertStringContainsString("from './deliverable-labels.json'", file_get_contents(base_path('resources/js/lib/catalog.ts')));
    }

    /** @return array<string, string> */
    private function clientLabels(): array
    {
        return json_decode(file_get_contents(base_path(self::CLIENT_LABELS)), true, 4, JSON_THROW_ON_ERROR);
    }

    public function test_untyped_schema_one_summaries_are_the_reviewed_prose_exactly(): void
    {
        // Schema 1 has no typed usage terms: its summaries are free prose a reviewer attested against the source. They can
        // be shown only byte for byte; nothing here proves their meaning matches the license text.
        $fixture = QuoteFixtures::selection();
        $stored = LicenseVersion::findOrFail($fixture['revision']->snapshot['license']['id']);
        $this->assertSame(1, $stored->structured_terms['schema_version']);
        $tier = collect($this->getJson('/api/catalog')->assertOk()->json('licenseTiers'))->where('id', (string) $stored->id)->sole();
        $this->assertSame($stored->structured_terms['features'], $tier['features']);
        $this->assertSame($stored->structured_terms['required_asset_roles'], $tier['requiredAssetRoles']);
        $this->assertTrue($stored->reviewEvidence()->firstOrFail()->summary_consistency_confirmed);
    }

    /** Reads every summary line back into typed terms and requires exact equality with the stored version. */
    private function assertReconciled(array $terms, array $features): void
    {
        $schema = $terms['schema_version'];
        $usage = $schema === 5 ? self::USAGE_V5 : self::USAGE_V2;
        $read = ['usage' => [], 'permissions' => []];
        foreach ($features as $line) {
            $this->assertIsString($line);
            if (preg_match('/\A([^:]+): (up to ([0-9]{1,3}(?:,[0-9]{3})*)|unlimited|not permitted|permitted)\.\z/', $line, $m) === 1 && isset($usage[$m[1]])) {
                $this->assertNotSame('permitted', $m[2], $line);
                $this->assertArrayNotHasKey($usage[$m[1]], $read['usage'], $line);
                $read['usage'][$usage[$m[1]]] = match (true) {
                    isset($m[3]) => ['mode' => 'limited', 'limit' => (int) str_replace(',', '', $m[3])],
                    $m[2] === 'unlimited' => ['mode' => 'unlimited'],
                    default => ['mode' => 'prohibited'],
                };
            } elseif (preg_match('/\A([^:]+): (permitted|not permitted)\.\z/', $line, $m) === 1 && isset(self::PERMISSIONS[$m[1]])) {
                $this->assertArrayNotHasKey(self::PERMISSIONS[$m[1]], $read['permissions'], $line);
                $read['permissions'][self::PERMISSIONS[$m[1]]] = $m[2] === 'permitted' ? 'permitted' : 'prohibited';
            } elseif ($line === 'Producer credit: not required.') {
                $this->assertArrayNotHasKey('credit', $read, $line);
                $read['credit'] = ['mode' => 'not_required'];
            } elseif (str_starts_with($line, 'Producer credit required: ')) {
                $this->assertArrayNotHasKey('credit', $read, $line);
                $read['credit'] = ['mode' => 'required', 'text' => substr($line, strlen('Producer credit required: '))];
            } elseif (preg_match('/\ADeliverables: (.+)\.\z/', $line, $m) === 1) {
                $this->assertArrayNotHasKey('deliverables', $read, $line);
                $read['deliverables'] = array_map(fn (string $label): string => self::DELIVERABLES[$label], explode(', ', $m[1]));
            } elseif ($line === 'Territory: worldwide.') {
                $this->assertArrayNotHasKey('territory', $read, $line);
                $read['territory'] = ['mode' => 'worldwide'];
            } elseif (preg_match('/\ATerritory: ([A-Z]{2}(?:, [A-Z]{2})*) \(ISO 3166-1 alpha-2\)\.\z/', $line, $m) === 1) {
                $this->assertArrayNotHasKey('territory', $read, $line);
                $read['territory'] = ['mode' => 'countries', 'country_codes' => explode(', ', $m[1])];
            } elseif ($line === 'License duration: perpetual from the grant timestamp.') {
                $this->assertArrayNotHasKey('duration', $read, $line);
                $read['duration'] = ['mode' => 'perpetual', 'starts_at' => 'grant'];
            } elseif (preg_match('/\ALicense duration: ([0-9]+) calendar (month|months) from the grant timestamp \(UTC; end-of-month clamping\)\.\z/', $line, $m) === 1) {
                $this->assertArrayNotHasKey('duration', $read, $line);
                $this->assertSame($m[1] === '1' ? 'month' : 'months', $m[2], $line);
                $read['duration'] = ['mode' => 'fixed_months', 'starts_at' => 'grant', 'months' => (int) $m[1]];
            } elseif (preg_match('/\A([^:]+): defined by retained policy (\S+) @ (\S+)\.\z/', $line, $m) === 1 && isset(self::OWNERSHIP[$m[1]])) {
                $this->assertArrayNotHasKey(self::OWNERSHIP[$m[1]], $read['ownership'] ?? [], $line);
                $read['ownership'][self::OWNERSHIP[$m[1]]] = ['policy_key' => $m[2]];
                $read['policy_versions'][$m[2]] = $m[3];
            } elseif (preg_match('/\APublishing income: (.+), as defined by retained policy (\S+) @ (\S+)\.\z/', $line, $m) === 1) {
                $this->assertArrayNotHasKey('publishing_income', $read, $line);
                if ($m[1] === 'this license provides no additional publishing-income entitlement to the licensor') {
                    $read['publishing_income'] = ['mode' => 'none', 'policy_key' => $m[2]];
                } else {
                    $this->assertSame(1, preg_match('/\Alicensor share is ([0-9]{1,3})\.([0-9]{2})% of total publishing income attributable to the resulting composition\z/', $m[1], $share), $line);
                    $read['publishing_income'] = ['mode' => 'share', 'policy_key' => $m[2], 'licensor_bps' => (int) $share[1] * 100 + (int) $share[2]];
                }
                $read['policy_versions'][$m[2]] = $m[3];
            } elseif (preg_match('/\ARecording royalty: (.+), as defined by retained policy (\S+) @ (\S+)\.\z/', $line, $m) === 1) {
                $this->assertArrayNotHasKey('recording_royalty', $read, $line);
                if ($m[1] === 'no additional recording royalty is payable by the licensee to the licensor under this license') {
                    $read['recording_royalty'] = ['mode' => 'none', 'policy_key' => $m[2]];
                } else {
                    $this->assertSame(1, preg_match('/\Alicensee pays licensor ([0-9]{1,3})\.([0-9]{2})% of (gross|net) receipts from the resulting recording\z/', $m[1], $rate), $line);
                    $read['recording_royalty'] = ['mode' => 'rate', 'policy_key' => $m[2], 'rate_bps' => (int) $rate[1] * 100 + (int) $rate[2], 'basis' => $rate[3].'_receipts'];
                }
                $read['policy_versions'][$m[2]] = $m[3];
            } else {
                $this->fail('A public summary line states something no typed term defines: '.$line);
            }
        }
        // Exactly one line per typed field of the schema: nothing omitted, nothing extra.
        $expectedLines = count($usage) + count(self::PERMISSIONS) + 2 + ($schema >= 3 ? 2 : 0) + ($schema >= 4 ? count(self::OWNERSHIP) + 2 : 0);
        $this->assertCount($expectedLines, $features);
        $this->assertEquals($terms['usage'], $read['usage']);
        $this->assertEquals($terms['permissions'], $read['permissions']);
        $this->assertSame($terms['credit'], $read['credit']);
        // The card lists files in the fixed MP3, WAV, stems order whatever order the version stores.
        $this->assertSame(array_values(array_intersect(array_values(self::DELIVERABLES), $terms['required_asset_roles'])), $read['deliverables']);
        $this->assertEqualsCanonicalizing($terms['required_asset_roles'], $read['deliverables']);
        // The card's file line (the client label of each required role, in map order) names the same files in the same
        // words as the generated Deliverables line under it.
        $labels = $this->clientLabels();
        $fileLine = array_values(array_intersect_key($labels, array_flip($terms['required_asset_roles'])));
        $this->assertCount(count($terms['required_asset_roles']), $fileLine);
        $this->assertContains('Deliverables: '.implode(', ', $fileLine).'.', $features);
        if ($schema >= 3) {
            $territory = $terms['territory'];
            if ($territory['mode'] === 'countries') {
                sort($territory['country_codes'], SORT_STRING);
            }
            $this->assertSame($territory, $read['territory']);
            $this->assertEquals($terms['duration'], $read['duration']);
        } else {
            $this->assertArrayNotHasKey('territory', $read);
            $this->assertArrayNotHasKey('duration', $read);
        }
        if ($schema >= 4) {
            $this->assertEquals($terms['ownership'], $read['ownership']);
            $this->assertEquals($terms['publishing_income'], $read['publishing_income']);
            $this->assertEquals($terms['recording_royalty'], $read['recording_royalty']);
            $this->assertEquals(array_column($terms['policies'], 'version', 'key'), $read['policy_versions']);
        } else {
            $this->assertArrayNotHasKey('ownership', $read);
            $this->assertArrayNotHasKey('publishing_income', $read);
            $this->assertArrayNotHasKey('recording_royalty', $read);
        }
    }
}
