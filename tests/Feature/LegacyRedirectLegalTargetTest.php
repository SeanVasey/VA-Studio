<?php

namespace Tests\Feature;

use App\Domain\Migration\LegacyRedirects\LegacyRedirectMap;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectMapInvalid;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectValidator;
use App\Domain\Migration\LegacyRedirects\LegacyRouteInventory;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LegacyRedirectFixtures as F;
use Tests\Support\LegalPageFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\TieredLicenseFixtures;
use Tests\TestCase;

/** The `legal` redirect target type: URL-013, URL-014 and URL-015 resolve to /legal/licenses, /legal/terms and /legal/privacy. */
class LegacyRedirectLegalTargetTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.url' => 'https://audio.example.test', 'app.debug' => false]);
        $this->directory = sys_get_temp_dir().'/legacy-redirect-legal-'.Str::uuid();
    }

    protected function tearDown(): void
    {
        F::remove($this->directory);
        parent::tearDown();
    }

    /** @return array{errors: list<string>, warnings: list<string>, redirects: list<array<string, mixed>>} */
    private function validate(array $entries): array
    {
        $files = F::write($this->directory, $entries);

        return app(LegacyRedirectValidator::class)->validate(LegacyRedirectMap::fromFile($files['map']), LegacyRouteInventory::read($files['inventory']));
    }

    private function activate(array $entries): void
    {
        $files = F::write($this->directory, $entries);
        config(['legacy-redirects.enabled' => true, 'legacy-redirects.map_path' => $files['map']]);
    }

    private function legal(string $page, string $path = '/legacy-fixture/legal', string $id = 'URL-001'): array
    {
        return F::redirect($id, $path, ['type' => 'legal', 'page' => $page]);
    }

    /** One approved, published license version, so the generated /legal/licenses comparison has something to list. */
    private function publishLicenseVersion(): void
    {
        $author = LicenseFixtures::admin();
        $template = LicenseTemplate::create(['name' => 'Legal target license', 'slug' => 'legal-target-'.Str::uuid(), 'type' => 'non-exclusive']);
        $draft = app(CreateLicenseDraft::class)->handle($template, ['authored_source' => TieredLicenseFixtures::source(), 'structured_terms' => TieredLicenseFixtures::terms()], $author);
        $submitted = app(ReviewLicense::class)->submit($draft, $author);
        $approved = app(ReviewLicense::class)->approve($submitted, LicenseFixtures::admin(), [
            'approval_reference' => 'SYNTHETIC LEGAL TARGET REVIEW', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true,
        ]);
        app(PublishLicense::class)->handle($approved, $author);
    }

    /** @param  list<string>  $documents */
    private function publishRelease(array $documents): void
    {
        $actor = LicenseFixtures::admin();
        $release = app(SiteContent::class)->create(LegalPageFixtures::content('LEGAL TARGET', $documents), 'Synthetic legal target release', $actor);
        app(SiteContent::class)->publish($release->id, 0, $actor);
    }

    /** @return array<string, array{string}> */
    public static function legalPages(): array
    {
        return ['licenses' => ['licenses'], 'terms' => ['terms'], 'privacy' => ['privacy']];
    }

    #[DataProvider('legalPages')]
    public function test_the_grammar_accepts_each_legal_page(string $page): void
    {
        $map = LegacyRedirectMap::fromArray(F::map([$this->legal($page)]));
        $this->assertSame(['type' => 'legal', 'page' => $page], $map->entries[0]['target']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badLegalTargets(): array
    {
        return [
            'unknown page' => [['type' => 'legal', 'page' => 'cookies']],
            'upper case page' => [['type' => 'legal', 'page' => 'Terms']],
            'page with a path' => [['type' => 'legal', 'page' => 'terms/extra']],
            'traversal' => [['type' => 'legal', 'page' => '../admin']],
            'empty page' => [['type' => 'legal', 'page' => '']],
            'null page' => [['type' => 'legal', 'page' => null]],
            'non-string page' => [['type' => 'legal', 'page' => ['terms']]],
            'missing page' => [['type' => 'legal']],
            'extra key' => [['type' => 'legal', 'page' => 'terms', 'slug' => 'x']],
            'extra url key' => [['type' => 'legal', 'page' => 'terms', 'url' => 'https://example.test/']],
            'document key instead of page' => [['type' => 'legal', 'document' => 'terms']],
        ];
    }

    #[DataProvider('badLegalTargets')]
    public function test_the_grammar_rejects_unknown_pages_and_extra_keys(array $target): void
    {
        $this->expectException(LegacyRedirectMapInvalid::class);
        LegacyRedirectMap::fromArray(F::map([F::redirect('URL-001', '/legacy-fixture/legal', $target)]));
    }

    public function test_the_allowed_pages_are_the_ones_the_application_routes(): void
    {
        $constraint = Route::getRoutes()->getByName('legal.show')->wheres['document'];
        $this->assertEqualsCanonicalizing(SiteContentSchema::LEGAL_DOCUMENTS, explode('|', $constraint));
        $this->assertEqualsCanonicalizing(['licenses', 'terms', 'privacy'], SiteContentSchema::LEGAL_DOCUMENTS);
    }

    public function test_each_page_resolves_to_its_canonical_legal_path_and_nothing_else_is_an_error(): void
    {
        $report = $this->validate([
            $this->legal('licenses', '/legacy-fixture/a', 'URL-001'),
            $this->legal('terms', '/legacy-fixture/b', 'URL-002'),
            $this->legal('privacy', '/legacy-fixture/c', 'URL-003'),
        ]);
        $this->assertSame([], $report['errors']);
        $this->assertSame(['/legal/licenses', '/legal/terms', '/legal/privacy'], array_column($report['redirects'], 'target'));
    }

    public function test_pages_that_are_not_public_warn_and_never_error(): void
    {
        $report = $this->validate([$this->legal('licenses', '/legacy-fixture/a', 'URL-001'), $this->legal('terms', '/legacy-fixture/b', 'URL-002')]);
        $this->assertSame([], $report['errors']);
        $this->assertSame([
            'URL-001 target /legal/licenses is not public right now; requests answer 404 until it is published.',
            'URL-002 target /legal/terms is not public right now; requests answer 404 until it is published.',
        ], $report['warnings']);

        // Terms published, license comparison listed in the release but with no published license version: still a warning.
        $this->publishRelease(['terms', 'licenses']);
        $report = $this->validate([$this->legal('licenses', '/legacy-fixture/a', 'URL-001'), $this->legal('terms', '/legacy-fixture/b', 'URL-002'), $this->legal('privacy', '/legacy-fixture/c', 'URL-003')]);
        $this->assertSame([
            'URL-001 target /legal/licenses is not public right now; requests answer 404 until it is published.',
            'URL-003 target /legal/privacy is not public right now; requests answer 404 until it is published.',
        ], $report['warnings']);

        $this->publishLicenseVersion();
        $report = $this->validate([$this->legal('licenses', '/legacy-fixture/a', 'URL-001')]);
        $this->assertSame([], $report['warnings']);
    }

    public function test_the_request_redirects_in_one_hop_to_the_canonical_origin_dropping_the_query_once_the_page_is_public(): void
    {
        $this->activate([
            $this->legal('licenses', '/legacy-fixture/licensing', 'URL-001'),
            $this->legal('terms', '/legacy-fixture/terms', 'URL-002'),
            $this->legal('privacy', '/legacy-fixture/privacy', 'URL-003'),
        ]);
        $this->publishRelease(['terms', 'licenses']);
        $this->publishLicenseVersion();

        $response = $this->get('/legacy-fixture/terms?utm_source=old&page=2')->assertStatus(301)
            ->assertHeader('Location', 'https://audio.example.test/legal/terms')->assertHeader('Cache-Control', 'max-age=3600, public');
        $this->assertSame([], $response->headers->getCookies());
        $this->get('/legacy-fixture/licensing/')->assertStatus(301)->assertHeader('Location', 'https://audio.example.test/legal/licenses');
        $this->call('HEAD', '/legacy-fixture/terms')->assertStatus(301);
        $this->get('https://attacker.example/legacy-fixture/terms')->assertStatus(301)->assertHeader('Location', 'https://audio.example.test/legal/terms');
        // The target page itself is the one the redirect names.
        $this->get('/legal/terms')->assertOk();
        // A page the release does not carry answers 404, not a redirect to a 404.
        $this->get('/legacy-fixture/privacy')->assertNotFound()->assertHeaderMissing('Location');
        $this->post('/legacy-fixture/terms')->assertNotFound();
    }

    public function test_a_page_that_is_not_public_answers_404_without_a_location_and_a_published_page_flips_it_to_301(): void
    {
        $this->activate([
            $this->legal('terms', '/legacy-fixture/terms', 'URL-001'),
            $this->legal('licenses', '/legacy-fixture/licensing', 'URL-002'),
        ]);
        // No site release carries legal text yet (the default release).
        $this->get('/legacy-fixture/terms')->assertNotFound()->assertHeaderMissing('Location');
        $this->get('/legacy-fixture/licensing')->assertNotFound()->assertHeaderMissing('Location');

        // A draft release is not enough.
        $actor = LicenseFixtures::admin();
        $draft = app(SiteContent::class)->create(LegalPageFixtures::content('LEGAL TARGET DRAFT', ['terms', 'licenses']), 'Draft', $actor);
        $this->get('/legacy-fixture/terms')->assertNotFound()->assertHeaderMissing('Location')->assertDontSee('LEGAL TARGET DRAFT', false);

        app(SiteContent::class)->publish($draft->id, 0, $actor);
        $this->get('/legacy-fixture/terms')->assertStatus(301)->assertHeader('Location', 'https://audio.example.test/legal/terms');
        // The license comparison needs a published license version as well (DD-31).
        $this->get('/legacy-fixture/licensing')->assertNotFound()->assertHeaderMissing('Location');
        $this->publishLicenseVersion();
        $this->get('/legacy-fixture/licensing')->assertStatus(301)->assertHeader('Location', 'https://audio.example.test/legal/licenses');
    }

    public function test_chains_and_loops_through_a_legal_target_are_still_refused(): void
    {
        $report = $this->validate([
            // The target is another entry's source: a chain. (That entry is also shadowed by the real legal route.)
            $this->legal('terms', '/legacy-fixture/a', 'URL-001'),
            F::gone('URL-002', '/legal/terms'),
            // Its own source: a loop, and a served path.
            F::redirect('URL-003', '/legal/privacy', ['type' => 'legal', 'page' => 'privacy']),
        ]);
        $this->assertContains('URL-001 target: target /legal/terms is the source of URL-002 (redirect chain).', $report['errors']);
        $this->assertContains('URL-003 target: target is the entry\'s own source (loop).', $report['errors']);
        $this->assertNotEmpty(array_filter($report['errors'], fn (string $error) => str_starts_with($error, 'URL-002 /legal/terms is served by route legal.show')));
        $this->assertNotEmpty(array_filter($report['errors'], fn (string $error) => str_starts_with($error, 'URL-003 /legal/privacy is served by route legal.show')));

        // At request time a chain never acts.
        $this->activate([$this->legal('terms', '/legacy-fixture/a', 'URL-001'), F::redirect('URL-002', '/legal/terms', ['type' => 'route', 'name' => 'home'])]);
        $this->publishRelease(['terms']);
        $this->get('/legacy-fixture/a')->assertNotFound()->assertHeaderMissing('Location');
    }
}
