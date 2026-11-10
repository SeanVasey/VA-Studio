<?php

namespace Tests\Feature;

use App\Domain\Media\PrivateMediaFiles;
use App\Domain\SiteBuilder\SiteContent;
use App\Models\User;
use App\Support\Diagnostics\CutoverLedgerChecks as Checks;
use App\Support\Diagnostics\CutoverMarkers;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LegalPageFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;
use Vasey\Ops\CapabilityBoundary;

require_once dirname(__DIR__, 2).'/scripts/ops/capability-boundary.php';

/**
 * BL-49: `vasey:cutover-check` reads the machine-checkable part of docs/production-cleanup-ledger.md. Each check is
 * planted in its staging state and seen to FAIL under the production profile, then seen to PASS without it. Every
 * fixture is synthetic; no real price, license text, key or account appears here.
 */
class CutoverCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->scratch = sys_get_temp_dir().'/cutover-check-'.Str::uuid();
        mkdir($this->scratch.'/root', 0700, true);
        $this->bindChecks($this->scratch.'/absent.htpasswd');
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($this->scratch));
    }

    private function bindChecks(string $basicAuthFile, ?string $root = null): void
    {
        $this->app->bind(Checks::class, fn () => new Checks($basicAuthFile, $root));
    }

    /** @return array{exit: int, output: string, rows: array<string, array{status: string, findings: list<array{status: string, detail: string}>, checked: list<string>, manual: ?string}>, report: array} */
    private function check(?string $profile = Checks::PRODUCTION): array
    {
        $exit = Artisan::call('vasey:cutover-check', ['--json' => true] + ($profile === null ? [] : ['--expect' => $profile]));
        $output = Artisan::output();
        $report = json_decode($output, true, 16, JSON_THROW_ON_ERROR);

        $rows = array_map(fn (array $row): array => $row + ['checked' => array_column($row['findings'], 'detail')], array_column($report['rows'], null, 'id'));

        return ['exit' => $exit, 'output' => $output, 'rows' => $rows, 'report' => $report];
    }

    private function rowStatus(string $id, ?string $profile = Checks::PRODUCTION): string
    {
        return $this->check($profile)['rows'][$id]['status'];
    }

    private function checked(string $id, ?string $profile = Checks::PRODUCTION): string
    {
        return implode('; ', $this->check($profile)['rows'][$id]['checked']);
    }

    private function privateFile(string $relative, string $bytes): string
    {
        Storage::disk('local')->put($relative, $bytes);

        return hash('sha256', $bytes);
    }

    private function track(string $slug, string $title, string $artist = 'Fixture artist'): int
    {
        return DB::table('tracks')->insertGetId(['title' => $title, 'slug' => $slug, 'artist' => $artist, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function licenseTemplate(string $name, string $slug): int
    {
        return DB::table('license_templates')->insertGetId(['name' => $name, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A published release written straight into the tables, as a release published before the legal guard existed would be. */
    private function publishRaw(array $content): void
    {
        $actor = LicenseFixtures::admin();
        $previous = DB::table('site_publications')->where('id', 1)->first();
        $release = DB::table('site_releases')->insertGetId(['label' => 'Synthetic raw release', 'schema_version' => $content['schema_version'],
            'content' => json_encode($content, JSON_THROW_ON_ERROR), 'content_hash' => str_repeat('a', 64), 'canonicalization_version' => 'fixture',
            'created_by' => $actor->id, 'created_at' => now()]);
        DB::table('site_publication_revisions')->insert(['revision' => $previous->revision + 1, 'release_id' => $release,
            'previous_release_id' => $previous->active_release_id, 'operation' => 'publish', 'content_hash' => str_repeat('a', 64),
            'actor_id' => $actor->id, 'created_at' => now()]);
        DB::table('site_publications')->where('id', 1)->update(['revision' => $previous->revision + 1, 'active_release_id' => $release, 'updated_at' => now()]);
    }

    private function legalContent(?string $paragraph = null, string $document = 'terms'): array
    {
        $content = LegalPageFixtures::content('SYNTHETIC LEGAL', ['privacy', 'terms', 'licenses']);
        if ($paragraph !== null) {
            $content['legal'][$document]['sections'][0]['paragraphs'][0] = $paragraph;
        }

        return $content;
    }

    // ---- PCL-01 seller tag ----

    public function test_seller_tag_fails_in_production_while_unset_staging_named_marked_or_unpinned_and_passes_when_clean(): void
    {
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-01'));
        $this->assertStringContainsString('no seller tag is configured', $this->checked('PCL-01'));

        $staging = $this->privateFile('branding/STAGING-synthetic-seller-tag.wav', 'RIFF fixture tone');
        config(['media.tag_path' => 'branding/STAGING-synthetic-seller-tag.wav', 'media.tag_sha256' => $staging]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-01'));
        $this->assertStringContainsString('staging or synthetic file', $this->checked('PCL-01'));
        $this->assertStringContainsString('1 file named STAGING-synthetic-* in private storage', $this->checked('PCL-01'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-01', Checks::STAGING));
        $this->assertStringContainsString('the staging synthetic tag', $this->checked('PCL-01', Checks::STAGING));

        // A renamed copy still carries the marker in its bytes.
        Storage::disk('local')->delete('branding/STAGING-synthetic-seller-tag.wav');
        $renamed = $this->privateFile('branding/seller-tag.wav', 'RIFF LIST INFO '.CutoverMarkers::STAGING_SELLER_TAG_MARKER.' data');
        config(['media.tag_path' => 'branding/seller-tag.wav', 'media.tag_sha256' => $renamed]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-01'));
        $this->assertStringContainsString('content marker', $this->checked('PCL-01'));

        $approved = $this->privateFile('branding/seller-tag.wav', 'RIFF fixture approved tone');
        config(['media.tag_sha256' => str_repeat('0', 64)]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-01'));
        $this->assertStringContainsString('does not match its pinned SHA-256', $this->checked('PCL-01'));

        config(['media.tag_sha256' => $approved]);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-01'));

        // The configured tag is clean but a staging stand-in is still on the disk.
        $this->privateFile('previews/old/staging-SYNTHETIC-seller-tag.wav', 'x');
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-01'));
        Storage::disk('local')->delete('previews/old/staging-SYNTHETIC-seller-tag.wav');
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-01'));

        config(['media.tag_path' => 'branding/missing.wav']);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-01'));
        $this->assertStringContainsString('not in private storage', $this->checked('PCL-01'));
    }

    // ---- PCL-02 synthetic prices, PCL-03 synthetic catalog ----

    public static function syntheticLicenses(): array
    {
        return [
            'synthetic title prefix' => ['[SYNTHETIC] MP3 license', 'fixture-a'],
            'TEST PRICE in the name' => ['Fixture TEST PRICE MP3 license', 'fixture-b'],
            'lower-case test price' => ['Fixture test price license', 'fixture-c'],
            'synthetic rehearsal slug' => ['Fixture license', 'synthetic-rehearsal-mp3'],
        ];
    }

    #[DataProvider('syntheticLicenses')]
    public function test_a_synthetic_license_fails_the_price_check_in_production_and_passes_once_gone(string $name, string $slug): void
    {
        $this->licenseTemplate('Fixture ordinary license', 'fixture-ordinary');
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-02'));
        $id = $this->licenseTemplate($name, $slug);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-02'));
        $this->assertStringContainsString('1 license template(s)', $this->checked('PCL-02'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-02', Checks::STAGING));
        DB::table('license_templates')->where('id', $id)->delete();
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-02'));
    }

    public function test_an_offer_of_synthetic_content_fails_the_price_check(): void
    {
        $author = LicenseFixtures::admin();
        $version = fn (int $template): int => DB::table('license_versions')->insertGetId(['license_template_id' => $template, 'version' => 1,
            'authored_source' => 'Fixture source', 'structured_terms' => '{}', 'author_id' => $author->id, 'created_at' => now(), 'updated_at' => now()]);
        $offer = fn (int $track, int $license): int => DB::table('offers')->insertGetId(['track_id' => $track, 'license_version_id' => $license,
            'price_minor' => 111, 'currency' => 'USD', 'deliverable_asset_ids' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $ordinary = $version($this->licenseTemplate('Fixture ordinary license', 'fixture-ordinary'));
        $offer($this->track('fixture-real-track', 'Fixture real track'), $ordinary);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-02'));

        // An ordinary license offered on a synthetic track.
        $onSyntheticTrack = $offer($this->track('synthetic-rehearsal-01', 'Fixture tone'), $ordinary);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-02'));
        $this->assertStringContainsString('0 license template(s), 1 offer(s), 0 offer revision(s)', $this->checked('PCL-02'));
        DB::table('offers')->where('id', $onSyntheticTrack)->delete();
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-02'));

        // A synthetic license offered on an ordinary track.
        $synthetic = $version($this->licenseTemplate('[SYNTHETIC] TEST PRICE WAV license', 'synthetic-rehearsal-wav'));
        $offer($this->track('fixture-second-track', 'Fixture second track'), $synthetic);
        $this->assertStringContainsString('1 license template(s), 1 offer(s), 0 offer revision(s)', $this->checked('PCL-02'));
    }

    public static function syntheticTracks(): array
    {
        return [
            'slug prefix' => ['synthetic-rehearsal-01', 'Fixture tone', 'Fixture artist'],
            'title prefix' => ['fixture-tone', '[SYNTHETIC] Rehearsal tone 01', 'Fixture artist'],
            'synthetic artist' => ['fixture-tone', 'Fixture tone', 'Synthetic Test Content'],
        ];
    }

    #[DataProvider('syntheticTracks')]
    public function test_a_synthetic_track_fails_the_catalog_check_in_production_and_passes_once_gone(string $slug, string $title, string $artist): void
    {
        $this->track('fixture-real-track', 'Fixture real track');
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-03'));
        $id = $this->track($slug, $title, $artist);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-03'));
        $this->assertStringContainsString('1 synthetic rehearsal track(s)', $this->checked('PCL-03'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-03', Checks::STAGING));
        DB::table('tracks')->where('id', $id)->delete();
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-03'));
    }

    public function test_a_synthetic_rights_declaration_fails_the_catalog_check_in_production(): void
    {
        $track = $this->track('fixture-real-track', 'Fixture real track');
        $declaration = fn (string $provenance): int => DB::table('rights_declarations')->insertGetId(['track_id' => $track,
            'provenance_reference' => $provenance, 'sample_disclosure' => 'Fixture disclosure', 'created_at' => now(), 'updated_at' => now()]);
        $declaration('FIXTURE-ORIGINAL-SOURCE');
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-03'));
        $declaration('SYNTHETIC-REHEARSAL-01');
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-03'));
        $this->assertStringContainsString('0 synthetic rehearsal track(s), 1 synthetic rights declaration(s)', $this->checked('PCL-03'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-03', Checks::STAGING));
    }

    public function test_the_synthetic_catalog_flag_must_read_false_in_production(): void
    {
        foreach ([true, 'true', null] as $value) {
            config(['staging-synthetic-catalog.enabled' => $value]);
            $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-03'));
            $this->assertSame(Checks::PASS, $this->rowStatus('PCL-03', Checks::STAGING));
        }
        config(['staging-synthetic-catalog.enabled' => false]);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-03'));
    }

    // ---- PCL-05 free-license text ----

    public static function freeTexts(): array
    {
        return [
            'staging placeholder banner' => ['STAGING PLACEHOLDER — not in effect. Fixture free terms.', Checks::FAIL],
            'synthetic prefix' => ['[SYNTHETIC] fixture free terms', Checks::FAIL],
            'set at production' => ['Fixture free terms, effective date set at production', Checks::FAIL],
            'approved-looking text' => ['Fixture free terms with no marker; staging is only a word here', Checks::PASS],
        ];
    }

    #[DataProvider('freeTexts')]
    public function test_free_definitions_are_read_for_staging_markers(string $title, string $expected): void
    {
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-05'));
        $this->freeDefinition(Crypt::encryptString(json_encode(['schema_version' => 'free-definition-v1', 'title' => $title], JSON_UNESCAPED_UNICODE)));
        $this->assertSame($expected, $this->rowStatus('PCL-05'));
        $this->assertStringContainsString($expected === Checks::FAIL ? '1 with a staging or synthetic marker' : '0 with a staging or synthetic marker', $this->checked('PCL-05'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-05', Checks::STAGING));
    }

    public function test_an_unreadable_free_definition_fails_closed_in_production(): void
    {
        $this->freeDefinition('not-a-ciphertext');
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-05'));
        $this->assertStringContainsString('1 unreadable', $this->checked('PCL-05'));
    }

    private function freeDefinition(string $payload): void
    {
        $author = LicenseFixtures::admin();
        $template = $this->licenseTemplate('Fixture free license '.Str::random(6), 'fixture-free-'.Str::lower(Str::random(8)));
        $license = DB::table('license_versions')->insertGetId(['license_template_id' => $template, 'version' => 1, 'authored_source' => 'Fixture source',
            'structured_terms' => '{}', 'author_id' => $author->id, 'created_at' => now(), 'updated_at' => now()]);
        $track = $this->track('fixture-free-'.Str::lower(Str::random(8)), 'Fixture free track');
        $scope = DB::table('rights_scopes')->insertGetId($this->requiredColumns('rights_scopes', ['public_id' => (string) Str::uuid(), 'created_by' => $author->id, 'created_at' => now()]));
        DB::table('free_definitions')->insert(['public_id' => (string) Str::uuid(), 'author_id' => $author->id, 'license_id' => $license, 'track_id' => $track,
            'scope_id' => $scope, 'creation_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'payload' => $payload,
            'payload_hash' => str_repeat('c', 64), 'created_at' => now()->utc()->format('Y-m-d H:i:s')]);
    }

    /** Fills the NOT NULL columns of a fixture parent row that this test has no interest in. */
    private function requiredColumns(string $table, array $known): array
    {
        foreach (DB::getSchemaBuilder()->getColumns($table) as $column) {
            if ($column['nullable'] || $column['default'] !== null || $column['auto_increment'] || array_key_exists($column['name'], $known)) {
                continue;
            }
            $known[$column['name']] = str_contains($column['type_name'], 'int') ? 1 : 'fixture-'.Str::lower(Str::random(10));
        }

        return $known;
    }

    // ---- PCL-06 signature ----

    public function test_signature_fails_in_production_while_unset_specimen_or_unpinned_and_passes_when_clean(): void
    {
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-06'));
        $this->assertStringContainsString('no signature image is configured', $this->checked('PCL-06'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-06', Checks::STAGING));

        $specimen = $this->privateFile('contracts/STAGING-specimen-signature.png', 'PNG fixture');
        config(['contracts.vasey_license_v1.signature_path' => 'contracts/STAGING-specimen-signature.png', 'contracts.vasey_license_v1.signature_sha256' => $specimen]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-06'));
        $this->assertStringContainsString('staging specimen', $this->checked('PCL-06'));
        $this->assertStringContainsString('the staging specimen', $this->checked('PCL-06', Checks::STAGING));

        $renamed = $this->privateFile('contracts/signature.png', 'PNG tEXt '.CutoverMarkers::SPECIMEN_SIGNATURE_MARKER.' — NOT A SIGNATURE');
        config(['contracts.vasey_license_v1.signature_path' => 'contracts/signature.png', 'contracts.vasey_license_v1.signature_sha256' => $renamed]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-06'));

        $real = $this->privateFile('contracts/signature.png', 'PNG fixture approved');
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-06'));
        $this->assertStringContainsString('does not match its pinned SHA-256', $this->checked('PCL-06'));
        config(['contracts.vasey_license_v1.signature_sha256' => $real]);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-06'));

        config(['contracts.vasey_license_v1.signature_path' => '../outside.png']);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-06'));
        $this->assertStringContainsString('not in private storage', $this->checked('PCL-06'));
    }

    // ---- PCL-07 legal pages ----

    public function test_legal_pages_need_a_published_release_in_production(): void
    {
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-07'));
        $this->assertStringContainsString('no readable site release is published', $this->checked('PCL-07'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-07', Checks::STAGING));
    }

    public function test_a_release_published_through_the_domain_with_resolved_legal_pages_passes(): void
    {
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $site = app(SiteContent::class);
        $release = $site->create($this->legalContent(), 'Synthetic resolved release', $actor);
        $site->publish($release->id, 0, $actor);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-07'));
        $this->assertStringContainsString('0 unresolved marker(s), 0 page(s) with STAGING', $this->checked('PCL-07'));
    }

    public static function legalMarkers(): array
    {
        return [
            'square-bracket owner decision' => ['courts located in Iowa [county — Sean to confirm]', 'terms.owner_decision=1'],
            'square-bracket publication value' => ['Effective date: [set on publication]', 'terms.publication_value=1'],
            'legal review marker' => ['Limitation of liability [For legal review]', 'terms.legal_review_marker=1'],
            'staging placeholder banner' => ['STAGING PLACEHOLDER — not in effect', '1 page(s) with STAGING or "set at production" text (terms=1)'],
            'set at production' => ['Postal address: set at production', '1 page(s) with STAGING or "set at production" text (terms=1)'],
        ];
    }

    #[DataProvider('legalMarkers')]
    public function test_a_published_legal_page_with_a_marker_fails_in_production_and_passes_once_replaced(string $paragraph, string $reported): void
    {
        $this->publishRaw($this->legalContent($paragraph));
        $run = $this->check();
        $this->assertSame(Checks::FAIL, $run['rows']['PCL-07']['status']);
        $this->assertStringContainsString($reported, implode('; ', $run['rows']['PCL-07']['checked']));
        // The page text is counted, never quoted.
        $this->assertStringNotContainsString('Iowa', $run['output']);
        $this->assertStringNotContainsString('Postal address', $run['output']);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-07', Checks::STAGING));

        $this->publishRaw($this->legalContent());
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-07'));
    }

    public function test_a_release_without_privacy_or_terms_fails_in_production(): void
    {
        $this->publishRaw(LegalPageFixtures::content('SYNTHETIC LEGAL', ['privacy']));
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-07'));
        $this->assertStringContainsString('no terms page', $this->checked('PCL-07'));
    }

    public function test_the_contact_form_privacy_notice_is_read_for_markers(): void
    {
        $this->publishRaw($this->legalContent());
        foreach (['STAGING PLACEHOLDER — fixture notice', 'Fixture notice, retention set at production', 'Fixture notice [address]'] as $notice) {
            config(['inquiries.privacy_notice' => $notice]);
            $run = $this->check();
            $this->assertSame(Checks::FAIL, $run['rows']['PCL-07']['status']);
            $this->assertStringNotContainsString('fixture notice', strtolower($run['output']));
        }
        config(['inquiries.privacy_notice' => 'Fixture notice with nothing left to fill.']);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-07'));
    }

    // ---- PCL-09 logo, PCL-30 pins (filesystem under a project root) ----

    public function test_the_logo_source_check_needs_an_svg_under_assets_in_production(): void
    {
        $this->bindChecks($this->scratch.'/absent.htpasswd', $this->scratch.'/root');
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-09'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-09', Checks::STAGING));
        mkdir($this->scratch.'/root/assets/brand', 0700, true);
        file_put_contents($this->scratch.'/root/assets/brand/mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-09'));
    }

    public function test_contract_package_pins_match_the_installed_packages_and_a_changed_pin_fails(): void
    {
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-30'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-30', Checks::STAGING));

        $this->bindChecks($this->scratch.'/absent.htpasswd', $this->scratch.'/root');
        foreach (Checks::PINNED_CONTRACT_PROFILES as $profile) {
            mkdir($this->scratch.'/root/resources/contracts/'.$profile, 0700, true);
            copy(base_path('resources/contracts/'.$profile.'/profile-assets.json'), $this->scratch.'/root/resources/contracts/'.$profile.'/profile-assets.json');
        }
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-30'));
        $path = $this->scratch.'/root/resources/contracts/vasey-license-v2/profile-assets.json';
        $assets = json_decode(file_get_contents($path), true);
        $original = $assets;
        $assets['packages']['tecnickcom/tc-lib-pdf']['version'] = '0.0.1';
        file_put_contents($path, json_encode($assets));
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-30'));
        $this->assertStringContainsString('vasey-license-v2: tecnickcom/tc-lib-pdf', $this->checked('PCL-30'));
        // Same version, another commit: a held update that kept the version string still differs.
        $assets = $original;
        $assets['packages']['tecnickcom/tc-lib-pdf-font']['reference'] = str_repeat('0', 40);
        file_put_contents($path, json_encode($assets));
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-30'));
        $this->assertStringContainsString('vasey-license-v2: tecnickcom/tc-lib-pdf-font', $this->checked('PCL-30'));
        unlink($path);
        $this->assertStringContainsString('vasey-license-v2 (pins unreadable)', $this->checked('PCL-30', Checks::STAGING));
    }

    // ---- PCL-10 mail ----

    public function test_mail_fails_in_production_while_nothing_is_sent_and_on_staging_when_real_mail_is_admitted(): void
    {
        foreach (['log', 'array', null] as $silent) {
            config(['mail.default' => $silent]);
            $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-10'));
        }
        config(['mail.default' => 'smtp']);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-10'));

        config(['mail.default' => 'log']);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-10', Checks::STAGING));
        config(['production-runtime.real_mail_admitted' => true]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-10', Checks::STAGING));
        $this->assertStringContainsString('admitted off production', $this->checked('PCL-10', Checks::STAGING));
    }

    // ---- PCL-11 staff ----

    public function test_staff_accounts_fail_in_production_for_a_synthetic_name_or_an_unenrolled_administrator(): void
    {
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-11'), 'No administrator at all is not a production state.');
        $admin = LicenseFixtures::admin();
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-11'));
        $this->assertStringContainsString('1 administrator(s), 1 without an enrolled authenticator', $this->checked('PCL-11'));
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-11'));

        $synthetic = User::factory()->create(['name' => 'Synthetic Catalog Operator']);
        $run = $this->check();
        $this->assertSame(Checks::FAIL, $run['rows']['PCL-11']['status']);
        $this->assertStringNotContainsString('Catalog Operator', $run['output']);
        $this->assertStringNotContainsString($synthetic->email, $run['output']);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-11', Checks::STAGING));
        DB::table('users')->where('id', $synthetic->id)->delete();
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-11'));
    }

    // ---- PCL-12 Stripe ----

    public function test_stripe_key_mode_is_judged_from_the_prefix_in_each_profile(): void
    {
        $test = 'sk_test_'.str_repeat('Ab1', 8);
        $live = 'sk_live_'.str_repeat('Cd2', 8);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-12'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-12', Checks::STAGING));

        // Production: no test key anywhere.
        config(['payments.stripe.secret_key' => $test]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-12'));
        $this->assertStringContainsString('1 configured value(s) with a Stripe test key prefix', $this->checked('PCL-12'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-12', Checks::STAGING));

        // Production: a live key only in the one admitted slot, and only while live Stripe is admitted.
        config(['payments.stripe.secret_key' => $live]);
        $this->assertStringContainsString('outside the one admitted slot', $this->checked('PCL-12'));
        config(['payments.stripe.secret_key' => null, 'production_checkout.secret_key' => $live]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-12'));
        $this->assertStringContainsString('while live Stripe is not admitted', $this->checked('PCL-12'));
        config(['production-runtime.live_stripe_admitted' => true]);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-12'));
        $this->assertStringContainsString('production checkout key slot: live', $this->checked('PCL-12'));

        // Staging: no live key, mode or admission.
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-12', Checks::STAGING));
        config(['production_checkout.secret_key' => null]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-12', Checks::STAGING), 'Live admission alone fails off production.');
        config(['production-runtime.live_stripe_admitted' => false, 'payments.stripe.mode' => 'live']);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-12', Checks::STAGING));
        config(['payments.stripe.mode' => 'test']);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-12', Checks::STAGING));
        foreach (['rk_live_'.str_repeat('x', 20), 'pk_live_'.str_repeat('y', 20)] as $other) {
            config(['services.fixture_key' => $other]);
            $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-12', Checks::STAGING));
        }
    }

    // ---- PCL-13 test commerce ----

    public static function flagCases(): array
    {
        return array_map(fn (string $environmentKey, string $configKey): array => [$environmentKey, $configKey],
            array_combine(array_keys(Checks::TEST_COMMERCE_FLAGS), array_keys(Checks::TEST_COMMERCE_FLAGS)), Checks::TEST_COMMERCE_FLAGS);
    }

    #[DataProvider('flagCases')]
    public function test_each_test_commerce_flag_must_read_false_in_production(string $environmentKey, string $configKey): void
    {
        $this->assertFalse(config($configKey), $configKey.' is not a boolean false by default.');
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-13'));
        config([$configKey => true]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-13'));
        $this->assertStringContainsString($environmentKey, $this->checked('PCL-13'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-13', Checks::STAGING));
    }

    public function test_the_test_commerce_flags_cover_every_test_capability_of_the_capability_boundary(): void
    {
        $boundary = array_filter(CapabilityBoundary::CAPABILITY_FLAGS,
            fn (string $key): bool => str_contains($key, '_TEST_') || str_contains($key, '_FIXTURE_'), ARRAY_FILTER_USE_KEY);
        $this->assertNotEmpty($boundary);
        $this->assertEqualsCanonicalizing(array_keys($boundary), array_keys(array_intersect_key(Checks::TEST_COMMERCE_FLAGS, $boundary)));
        foreach ($boundary + array_intersect_key(CapabilityBoundary::stagedFlags(), Checks::PRE_LAUNCH_HOLDS) as $environmentKey => $configKey) {
            $this->assertSame($configKey, (Checks::TEST_COMMERCE_FLAGS + Checks::PRE_LAUNCH_HOLDS)[$environmentKey] ?? null, $environmentKey);
        }
        $this->assertCount(4, array_intersect_key(CapabilityBoundary::stagedFlags(), Checks::PRE_LAUNCH_HOLDS));
    }

    // ---- PCL-14 basic auth ----

    public function test_the_staging_basic_auth_file_fails_in_production_and_is_expected_on_the_staging_host(): void
    {
        $file = $this->scratch.'/vasey-staging.htpasswd';
        $this->bindChecks($file);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-14'));
        $this->assertSame(Checks::INFO, $this->rowStatus('PCL-14', Checks::STAGING), 'Local and testing have no web server to protect.');
        config(['app.env' => 'staging']);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-14', Checks::STAGING));
        config(['app.env' => 'testing']);

        file_put_contents($file, 'fixture-user:fixture-hash-never-printed');
        $run = $this->check();
        $this->assertSame(Checks::FAIL, $run['rows']['PCL-14']['status']);
        $this->assertStringNotContainsString('fixture-hash-never-printed', $run['output']);
        $this->assertStringNotContainsString($this->scratch, $run['output']);
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-14', Checks::STAGING));
    }

    // ---- PCL-16 policy values ----

    public function test_the_retention_schedule_must_be_configured_in_production(): void
    {
        config(['customer-data-rights.retention' => array_fill_keys(['policy_reference', 'financial_records_years', 'consent_records_years',
            'correspondence_records_years', 'security_records_years', 'evidence_records_years', 'abandoned_checkout_days', 'operational_records_days'], null)]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-16'));
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-16', Checks::STAGING));
        $set = ['policy_reference' => 'Synthetic retention schedule SR-1 (test only)', 'financial_records_years' => '7', 'consent_records_years' => 3,
            'correspondence_records_years' => '2', 'security_records_years' => 1, 'evidence_records_years' => '7', 'abandoned_checkout_days' => 30,
            'operational_records_days' => '14'];
        config(['customer-data-rights.retention' => $set]);
        $run = $this->check();
        $this->assertSame(Checks::PASS, $run['rows']['PCL-16']['status']);
        $this->assertStringNotContainsString('SR-1', $run['output']);
        config(['customer-data-rights.retention' => ['operational_records_days' => null] + $set]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-16'));
    }

    // ---- section 3 switches ----

    public static function productionLanes(): array
    {
        $cases = [];
        foreach (['PCL-18' => Checks::PRODUCTION_CHECKOUT_SWITCHES, 'PCL-19' => Checks::TAX_CHECKOUT_SWITCHES, 'PCL-22' => Checks::PRODUCTION_FREE_GRANT_SWITCHES] as $id => $switches) {
            foreach ($switches as $label => $key) {
                $cases[$id.' '.$label] = [$id, $label, $key];
            }
        }

        return $cases;
    }

    #[DataProvider('productionLanes')]
    public function test_a_production_lane_switch_is_informational_in_production_and_fails_off_production(string $id, string $label, string $key): void
    {
        $this->assertFalse(config($key));
        $this->assertSame(Checks::INFO, $this->rowStatus($id));
        $this->assertSame(Checks::PASS, $this->rowStatus($id, Checks::STAGING));
        // Strict, as the capability boundary is: anything that is not boolean false is not off.
        foreach (['true', '1', 1, null] as $loose) {
            config([$key => $loose]);
            $this->assertSame(Checks::FAIL, $this->rowStatus($id, Checks::STAGING), var_export($loose, true));
        }
        config([$key => true]);
        $this->assertSame(Checks::INFO, $this->rowStatus($id), 'Activation is a separately authorized step; the row states the switch and judges nothing.');
        $this->assertStringContainsString($label, $this->checked($id));
        $this->assertSame(Checks::FAIL, $this->rowStatus($id, Checks::STAGING));
    }

    public function test_the_contract_v2_gates_are_informational_together_and_fail_when_they_disagree(): void
    {
        $gates = array_values(Checks::CONTRACT_V2_GATES);
        foreach (Checks::PROFILES as $profile) {
            config(array_fill_keys($gates, false));
            $this->assertSame(Checks::INFO, $this->rowStatus('PCL-20', $profile));
            config([$gates[1] => true]);
            $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-20', $profile));
            $this->assertStringContainsString($gates[1], $this->checked('PCL-20', $profile));
            config(array_fill_keys($gates, true));
            $this->assertSame(Checks::INFO, $this->rowStatus('PCL-20', $profile));
            $this->assertStringContainsString('all three contract v2 gates are on', $this->checked('PCL-20', $profile));
        }
    }

    public function test_informational_switch_rows_state_the_switch_in_both_profiles(): void
    {
        foreach (Checks::PROFILES as $profile) {
            config(array_fill_keys(Checks::PRE_LAUNCH_HOLDS, false) + array_fill_keys(Checks::CUSTOMER_FEATURE_SWITCHES, false)
                + ['media.upload_session_sweep.scheduled' => false, 'legacy-redirects.enabled' => false, 'production-tax-checkout.provider' => null]);
            $rows = $this->check($profile)['rows'];
            $this->assertSame(Checks::INFO, $rows['PCL-08']['status']);
            $this->assertStringContainsString('0 of 4 tax merchant facts are bound', $rows['PCL-08']['checked'][0]);
            $this->assertSame(Checks::INFO, $rows['PCL-21']['status']);
            $this->assertStringContainsString('all four pre-launch holds read false', $rows['PCL-21']['checked'][0]);
            $this->assertSame(Checks::INFO, $rows['PCL-24']['status']);
            $this->assertMatchesRegularExpression('/enabled reads false; map rows: redirect=7, retained=6, unresolved=7\z/', $rows['PCL-24']['checked'][0]);
            $this->assertSame(Checks::INFO, $rows['PCL-26']['status']);
            $this->assertStringContainsString('reads false', $rows['PCL-26']['checked'][0]);
            $this->assertStringContainsString('0 of 6 customer-facing switches not false', $rows['PCL-25']['checked'][0]);

            config(['discovery-sitemap.enabled' => true, 'inquiries.enabled' => true, 'media.upload_session_sweep.scheduled' => true,
                'legacy-redirects.enabled' => true, 'production-tax-checkout.provider' => 'fixture-provider-name']);
            $run = $this->check($profile);
            $rows = $run['rows'];
            $this->assertSame(Checks::INFO, $rows['PCL-21']['status']);
            $this->assertStringContainsString('1 of four pre-launch holds not false: DISCOVERY_SITEMAP_ENABLED', $rows['PCL-21']['checked'][0]);
            $this->assertStringContainsString('1 of 6 customer-facing switches not false: CONTACT_INQUIRIES_ENABLED', $rows['PCL-25']['checked'][0]);
            $this->assertStringContainsString('reads true', $rows['PCL-26']['checked'][0]);
            $this->assertStringContainsString('enabled reads true', $rows['PCL-24']['checked'][0]);
            $this->assertStringContainsString('1 of 4 tax merchant facts are bound', $rows['PCL-08']['checked'][0]);
            $this->assertStringNotContainsString('fixture-provider-name', $run['output']);
        }
    }

    public function test_memberships_stay_off_in_both_profiles(): void
    {
        foreach (Checks::PROFILES as $profile) {
            $this->assertSame(Checks::PASS, $this->rowStatus('PCL-23', $profile));
            foreach (Checks::MEMBERSHIP_SWITCHES as $key) {
                config([$key => true]);
                $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-23', $profile), $key);
                $this->assertStringContainsString($key, $this->checked('PCL-23', $profile));
                config([$key => false]);
            }
            foreach (Checks::MEMBERSHIP_APPROVED_HASHES as $key) {
                config([$key => str_repeat('d', 64)]);
                $run = $this->check($profile);
                $this->assertSame(Checks::FAIL, $run['rows']['PCL-23']['status'], $key);
                $this->assertStringNotContainsString(str_repeat('d', 64), $run['output']);
                config([$key => null]);
            }
        }
    }

    public function test_the_marketing_segment_stays_off_in_production(): void
    {
        $this->assertSame(Checks::PASS, $this->rowStatus('PCL-25'));
        config([Checks::MARKETING_SEGMENT_SWITCH => true]);
        $this->assertSame(Checks::FAIL, $this->rowStatus('PCL-25'));
        $this->assertSame(Checks::INFO, $this->rowStatus('PCL-25', Checks::STAGING));
    }

    // ---- entries nothing can read ----

    public function test_entries_code_cannot_read_are_not_checkable_and_carry_the_ledger_note(): void
    {
        $rows = $this->check()['rows'];
        $manual = array_keys(array_filter(Checks::LEDGER, fn (array $entry): bool => $entry['check'] === null));
        $this->assertSame(['PCL-04', 'PCL-15', 'PCL-17', 'PCL-27', 'PCL-28', 'PCL-29'], $manual);
        foreach ($manual as $id) {
            $this->assertSame(Checks::NOT_CHECKABLE, $rows[$id]['status']);
            $this->assertSame([], $rows[$id]['checked']);
            $this->assertNotEmpty($rows[$id]['manual']);
        }
        foreach (Checks::LEDGER as $id => $entry) {
            if ($entry['check'] !== null) {
                $this->assertNotSame(Checks::NOT_CHECKABLE, $rows[$id]['status'], $id);
                $this->assertNotEmpty($rows[$id]['checked'], $id);
            }
        }
        $this->assertSame(array_keys(Checks::LEDGER), array_keys($rows));
    }

    // ---- output, exit codes, read-only ----

    public function test_exit_codes_follow_failures_and_the_profile_defaults_to_the_environment(): void
    {
        // A fresh testing installation is a legitimate staging state: nothing fails.
        $run = $this->check(Checks::STAGING);
        $this->assertSame(0, $run['exit'], json_encode(array_filter($run['rows'], fn (array $row): bool => $row['status'] === Checks::FAIL)));
        $this->assertSame(0, $run['report']['counts'][Checks::FAIL]);
        $default = $this->check(null);
        $this->assertSame([0, Checks::STAGING, 'testing'], [$default['exit'], $default['report']['profile'], $default['report']['environment']]);

        // The same installation is not a production one.
        $production = $this->check(Checks::PRODUCTION);
        $this->assertSame(1, $production['exit']);
        $this->assertGreaterThan(0, $production['report']['counts'][Checks::FAIL]);
        config(['app.env' => 'production']);
        $default = $this->check(null);
        $this->assertSame([1, Checks::PRODUCTION, 'production'], [$default['exit'], $default['report']['profile'], $default['report']['environment']]);
        config(['app.env' => 'testing']);

        // One failing checkable item is enough.
        config(['production-runtime.live_stripe_admitted' => true]);
        $this->assertSame(1, $this->check(Checks::STAGING)['exit']);

        foreach (['pre-launch', '', 'Production'] as $unknown) {
            $this->assertSame(2, Artisan::call('vasey:cutover-check', ['--expect' => $unknown]));
            $this->assertStringContainsString('--expect must be one of: production, staging', Artisan::output());
        }
    }

    public function test_the_plain_output_has_one_line_per_ledger_id_and_says_what_it_does_not_prove(): void
    {
        $exit = Artisan::call('vasey:cutover-check', ['--expect' => Checks::PRODUCTION]);
        $output = Artisan::output();
        $this->assertSame(1, $exit);
        foreach (array_keys(Checks::LEDGER) as $id) {
            $this->assertSame(1, preg_match_all('/^'.preg_quote($id, '/').'\s+(PASS|FAIL|INFO|NOT_CHECKABLE)\s/m', $output), $id);
        }
        $this->assertMatchesRegularExpression('/^PCL-04 NOT_CHECKABLE Publishing details and exclusive disclosures\n\s+by hand: Sean reviews/m', $output);
        $this->assertMatchesRegularExpression('/^FAIL=\d+ PASS=\d+ INFO=\d+ NOT_CHECKABLE=6$/m', $output);
        $this->assertStringContainsString('Profile: production; APP_ENV: testing (the profile differs from the environment)', $output);
        $this->assertStringContainsString('A clean run closes no ledger entry and authorizes no activation.', $output);
    }

    public function test_no_secret_path_or_record_text_reaches_the_output(): void
    {
        $secrets = [
            'payments.stripe.secret_key' => 'sk_'.'test_PLANTEDsecretAAAA1111bbbb2222',
            'production_checkout.secret_key' => 'sk_'.'live_PLANTEDsecretCCCC3333dddd4444',
            'payments.stripe.webhook_secret' => 'whsec'.'_PLANTEDwebhookEEEE5555',
            'payments.stripe.account_id' => 'acct_PLANTED6666',
            'production_checkout.account_id' => 'acct_PLANTED7777',
            'mail.mailers.smtp.password' => 'PLANTED-smtp-password-8888',
            'mail.mailers.smtp.host' => 'planted-smtp-host.example.test',
            'services.fixture.publishable' => 'pk_'.'live_PLANTEDpublishable9999',
            'services.fixture.restricted' => 'rk_'.'test_PLANTEDrestricted0000',
            'media.tag_path' => 'branding/PLANTED-token-aaaa/STAGING-synthetic-seller-tag.wav',
            'media.tag_sha256' => str_repeat('e', 64),
            'contracts.vasey_license_v1.signature_path' => 'contracts/PLANTED-token-bbbb/specimen.png',
            'contracts.vasey_license_v1.signature_sha256' => str_repeat('f', 64),
            'inquiries.privacy_notice' => 'STAGING PLACEHOLDER PLANTED-notice-text at 1 Planted Street',
            'customer-data-rights.retention.policy_reference' => 'PLANTED-retention-reference',
            'production-memberships.approved_policy_hash' => str_repeat('9', 64),
            'production-tax-checkout.account_id' => 'acct_PLANTEDtax',
            'production-tax-checkout.return_origin' => 'https://planted-origin.example.test',
            'production-free-grants.storage_root' => '/srv/PLANTED-storage-root',
            'legacy-redirects.enabled' => 'PLANTED-not-a-boolean',
            'mail.default' => 'PLANTED-mailer-name',
            'payments.stripe.mode' => 'PLANTED-mode',
            'app.env' => 'PLANTED-environment',
        ];
        config($secrets);
        $this->privateFile('branding/PLANTED-token-aaaa/STAGING-synthetic-seller-tag.wav', 'PLANTED-tag-bytes');
        $this->privateFile('contracts/PLANTED-token-bbbb/specimen.png', 'PLANTED-signature-bytes');
        $customer = User::factory()->create(['name' => 'Synthetic PLANTED Person', 'email' => 'planted-person@example.test']);
        $this->licenseTemplate('[SYNTHETIC] TEST PRICE PLANTED-license-name', 'synthetic-rehearsal-planted');
        $this->track('synthetic-rehearsal-planted-slug', '[SYNTHETIC] PLANTED-track-title');
        $this->publishRaw($this->legalContent('STAGING PLACEHOLDER PLANTED-legal-text [PLANTED-bracket]'));
        $this->freeDefinition(Crypt::encryptString(json_encode(['title' => 'STAGING PLACEHOLDER PLANTED-free-text'])));
        $file = $this->scratch.'/PLANTED-directory.htpasswd';
        file_put_contents($file, 'PLANTED-user:PLANTED-hash');
        $this->bindChecks($file);
        $root = rtrim(Storage::disk('local')->path(''), '/');

        foreach (Checks::PROFILES as $profile) {
            foreach ([[], ['--json' => true]] as $format) {
                Artisan::call('vasey:cutover-check', ['--expect' => $profile] + $format);
                $output = Artisan::output();
                $this->assertStringContainsString('PCL-12', $output);
                $this->assertStringNotContainsStringIgnoringCase('PLANTED', $output, $profile.' '.json_encode($format));
                foreach ([...array_filter($secrets, 'is_string'), $root, $this->scratch, (string) config('app.key'), $customer->email, 'sk_live_', 'sk_test_', 'whsec_'] as $secret) {
                    $this->assertStringNotContainsString($secret, $output, $profile.' '.json_encode($format));
                }
            }
        }
    }

    public function test_a_check_that_throws_fails_with_the_exception_class_only(): void
    {
        config(['media.tag_path' => 'branding/tag.wav']);
        $this->app->bind(PrivateMediaFiles::class, fn () => throw new RuntimeException('mysql://root:PLANTED-password@db.internal/vasey'));
        $run = $this->check();
        $this->assertSame(1, $run['exit']);
        $this->assertSame([Checks::FAIL, ['the check could not run (RuntimeException)']], [$run['rows']['PCL-01']['status'], $run['rows']['PCL-01']['checked']]);
        $this->assertStringNotContainsString('PLANTED', $run['output']);
        // The other entries still report.
        $this->assertSame(Checks::PASS, $run['rows']['PCL-02']['status']);
        $this->assertSame(Checks::FAIL, $this->check(Checks::STAGING)['rows']['PCL-01']['status'], 'A check that cannot run fails in either profile.');
    }

    public function test_the_command_only_selects_and_writes_no_row_or_file(): void
    {
        // A populated installation, so every query path runs.
        $admin = LicenseFixtures::admin();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $tag = $this->privateFile('branding/seller-tag.wav', 'RIFF fixture approved tone');
        $signature = $this->privateFile('contracts/signature.png', 'PNG fixture approved');
        config(['media.tag_path' => 'branding/seller-tag.wav', 'media.tag_sha256' => $tag,
            'contracts.vasey_license_v1.signature_path' => 'contracts/signature.png', 'contracts.vasey_license_v1.signature_sha256' => $signature]);
        $this->licenseTemplate('[SYNTHETIC] TEST PRICE MP3 license', 'synthetic-rehearsal-mp3');
        $this->track('synthetic-rehearsal-01', '[SYNTHETIC] Rehearsal tone 01');
        $this->publishRaw($this->legalContent('STAGING PLACEHOLDER — not in effect'));
        $this->freeDefinition(Crypt::encryptString(json_encode(['title' => 'STAGING PLACEHOLDER fixture'])));

        // A storage directory of its own, so a file the command left behind cannot hide among files earlier tests wrote.
        $storage = $this->scratch.'/storage';
        foreach (['logs', 'framework/cache/data', 'app'] as $directory) {
            mkdir($storage.'/'.$directory, 0700, true);
        }
        $this->app->useStoragePath($storage);
        $counts = fn (): array => collect(DB::getSchemaBuilder()->getTables())->mapWithKeys(fn (array $table): array => [$table['name'] => DB::table($table['name'])->count()])->all();
        $files = function (): array {
            $seen = [];
            foreach ([rtrim(Storage::disk('local')->path(''), '/'), $this->scratch] as $directory) {
                if (! is_dir($directory)) {
                    continue;
                }
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    $seen[$file->getPathname()] = [$file->getSize(), $file->getMTime()];
                }
            }
            ksort($seen);

            return $seen;
        };
        $rowsBefore = $counts();
        $filesBefore = $files();
        $level = DB::transactionLevel();

        $queries = [];
        $transactions = 0;
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
            $transactions++;
        });
        foreach (Checks::PROFILES as $profile) {
            foreach ([[], ['--json' => true]] as $format) {
                Artisan::call('vasey:cutover-check', ['--expect' => $profile] + $format);
            }
        }
        $captured = $queries;

        $this->assertGreaterThan(40, count($captured));
        foreach ($captured as $sql) {
            $this->assertMatchesRegularExpression('/\Aselect\s/i', ltrim($sql), $sql);
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|create|alter|drop|truncate|lock|for\s+update|for\s+share|pragma\s+\w+\s*=)\b/i', $sql, $sql);
        }
        $this->assertSame(0, $transactions);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($rowsBefore, $counts());
        $this->assertSame($filesBefore, $files());
    }
}
