<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LegalPageFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

/** FP-005: legal pages are public only from the active published release; drafts stay in the staff preview. */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PATHS = ['/legal/privacy', '/legal/terms', '/legal/licenses'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test']);
    }

    private function headers(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    private function create(array $content, ?User $actor = null): SiteRelease
    {
        return app(SiteContent::class)->create($content, 'Synthetic legal release', $actor ?? LicenseFixtures::admin());
    }

    private function assertAbsent(string $path, string $marker): void
    {
        $this->get($path)->assertNotFound()->assertDontSee($marker, false);
        $this->get($path, $this->headers())->assertNotFound()->assertDontSee($marker, false);
    }

    public function test_no_publication_unpublished_drafts_and_older_schemas_never_expose_legal_pages(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = $this->create(LegalPageFixtures::content('UNPUBLISHED LEGAL', ['privacy', 'terms', 'licenses']), $actor);
        $this->assertSame(5, $draft->schema_version);
        foreach (self::PATHS as $path) {
            foreach (['', '?release='.$draft->id.'&preview=1'] as $query) {
                $this->assertAbsent($path.$query, 'UNPUBLISHED LEGAL');
            }
        }
        // An active v2 release (no legal key at all) and an active v1 release answer 404 too.
        $older = $this->create(SiteEditorialFixtures::content('OLDER EDITORIAL'), $actor);
        app(SiteContent::class)->publish($older->id, 0, $actor);
        foreach (self::PATHS as $path) {
            $this->assertAbsent($path, 'UNPUBLISHED LEGAL');
        }
        $this->get('/about')->assertOk();
        foreach (['/legal', '/legal/', '/legal/cookies', '/legal/PRIVACY', '/legal/privacy/extra'] as $path) {
            $this->get($path)->assertNotFound()->assertDontSee('UNPUBLISHED LEGAL', false);
        }
        $this->assertSame(1, SitePublication::findOrFail(1)->revision);
    }

    public function test_published_page_projects_text_version_and_date_but_never_the_approval_reference(): void
    {
        $actor = LicenseFixtures::admin();
        $release = $this->create(LegalPageFixtures::content('PUBLIC LEGAL', ['privacy', 'licenses']), $actor);
        app(SiteContent::class)->publish($release->id, 0, $actor);

        $response = $this->get('/legal/privacy', $this->headers())->assertOk();
        $response->assertJsonPath('component', 'Editorial')
            ->assertJsonPath('props.editorial.section', 'legal')
            ->assertJsonPath('props.editorial.kind', 'legal')
            ->assertJsonPath('props.editorial.path', '/legal/privacy')
            ->assertJsonPath('props.editorial.title', 'PUBLIC LEGAL Privacy policy')
            ->assertJsonPath('props.editorial.legal', [
                'document' => 'privacy', 'version' => '1', 'effectiveDate' => '2026-11-01',
                'sections' => [
                    ['heading' => 'PUBLIC LEGAL FIRST HEADING', 'paragraphs' => ['PUBLIC LEGAL FIRST PARAGRAPH', "Second line\nwith a line break."]],
                    ['heading' => 'PUBLIC LEGAL SECOND HEADING', 'paragraphs' => ['PUBLIC LEGAL SECOND PARAGRAPH']],
                ],
            ])
            ->assertJsonPath('props.metadata.canonicalUrl', 'https://audio.example.test/legal/privacy')
            ->assertJsonPath('props.sitePreview', false)
            ->assertJsonPath('props.siteContent.legal_links', [
                ['title' => 'PUBLIC LEGAL Privacy policy', 'path' => '/legal/privacy'],
                ['title' => 'PUBLIC LEGAL License comparison', 'path' => '/legal/licenses'],
            ]);
        $this->assertArrayNotHasKey('legal', $response->json('props.siteContent'));
        $this->assertArrayNotHasKey('images', $response->json('props.siteContent'));
        foreach ([$response, $this->get('/legal/privacy')->assertOk(), $this->get('/legal/licenses')->assertOk(), $this->get('/')->assertOk()] as $page) {
            $page->assertDontSee(LegalPageFixtures::APPROVAL, false)->assertDontSee('approval_reference', false)
                ->assertDontSee($release->content_hash, false);
        }
        $this->assertAbsent('/legal/terms', 'PUBLIC LEGAL Terms');
        // Other pages carry the footer links, never the legal text.
        $home = $this->get('/', $this->headers())->assertOk();
        $home->assertJsonPath('props.siteContent.legal_links.0.path', '/legal/privacy')->assertDontSee('PUBLIC LEGAL FIRST PARAGRAPH', false);
    }

    public function test_replacing_or_restoring_an_older_release_withdraws_legal_pages_and_republishing_restores_exact_text(): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $legal = $this->create(LegalPageFixtures::content('ROLLBACK LEGAL', ['terms']), $actor);
        $site->publish($legal->id, 0, $actor);
        $this->get('/legal/terms')->assertOk()->assertSee('ROLLBACK LEGAL FIRST PARAGRAPH', false);
        $older = $this->create(SiteEditorialFixtures::content('REPLACEMENT'), $actor);
        $site->publish($older->id, 1, $actor);
        $this->assertAbsent('/legal/terms', 'ROLLBACK LEGAL');
        $site->rollback($legal->id, 2, $actor);
        $this->get('/legal/terms')->assertOk()->assertSee('ROLLBACK LEGAL FIRST PARAGRAPH', false);
        $this->assertSame(CanonicalJson::hash($legal->content), $legal->fresh()->content_hash);
        // The original code-default baseline (v1) is restorable and also withdraws the page.
        $baseline = SiteRelease::where('label', 'Original site content')->sole();
        $site->rollback($baseline->id, 3, $actor);
        $this->assertAbsent('/legal/terms', 'ROLLBACK LEGAL');
    }

    public static function unsafeLegalInput(): array
    {
        $field = fn (string $path, mixed $value): array => [$path, $value];

        return [
            'script tag in paragraph' => $field('sections.0.paragraphs.0', '<script>alert(1)</script>'),
            'markup in heading' => $field('sections.0.heading', 'Heading <b>bold</b>'),
            'angle bracket only' => $field('title', 'Terms > draft'),
            'control character' => $field('sections.1.paragraphs.0', "Bell \x07 character"),
            'nul byte' => $field('description', "Nul \x00 byte"),
            'blank paragraph' => $field('sections.0.paragraphs.0', '   '),
            'missing approval reference' => $field('approval_reference', ''),
            'markup in approval reference' => $field('approval_reference', '<a href="x">'),
            'missing version' => $field('version', ''),
            'version with space' => $field('version', 'v 1'),
            'version with slash' => $field('version', '1/2'),
            'version leading dot' => $field('version', '.1'),
            'version too long' => $field('version', str_repeat('1', 33)),
            'integer version' => $field('version', 1),
            'impossible date' => $field('effective_date', '2026-02-30'),
            'two digit year' => $field('effective_date', '26-11-01'),
            'date with time' => $field('effective_date', '2026-11-01T00:00:00Z'),
            'out of range year' => $field('effective_date', '1999-12-31'),
            'empty sections' => $field('sections', []),
            'section without paragraphs' => $field('sections.0.paragraphs', []),
            'keyed sections' => $field('sections', ['a' => ['heading' => 'H', 'paragraphs' => ['P']]]),
            'unknown section key' => $field('sections.0.link', 'https://example.test'),
            'unknown document key' => $field('html', '<p>raw</p>'),
            'oversize paragraph' => $field('sections.0.paragraphs.0', str_repeat('a', 4001)),
            'oversize heading' => $field('sections.0.heading', str_repeat('a', 161)),
            'too many sections' => $field('sections', array_fill(0, 61, ['heading' => 'H', 'paragraphs' => ['P']])),
            'over the document character limit' => $field('sections', array_fill(0, 31, ['heading' => 'H', 'paragraphs' => [str_repeat('a', 3999)]])),
        ];
    }

    #[DataProvider('unsafeLegalInput')]
    public function test_unsafe_or_incomplete_legal_input_is_refused_without_retaining_a_draft(string $path, mixed $value): void
    {
        $content = LegalPageFixtures::content('UNSAFE LEGAL');
        data_set($content, 'legal.privacy.'.$path, $value);
        $this->expectRefusal($content);
    }

    public function test_schema_shape_rules_keep_legal_text_in_version_five_only(): void
    {
        $none = LegalPageFixtures::content('SHAPE LEGAL', []);
        $this->expectRefusal($none, 'content.legal');
        $missing = LegalPageFixtures::content('SHAPE LEGAL');
        unset($missing['legal']);
        $this->expectRefusal($missing);
        $missingSlot = LegalPageFixtures::content('SHAPE LEGAL');
        unset($missingSlot['legal']['terms']);
        $this->expectRefusal($missingSlot);
        $extraSlot = LegalPageFixtures::content('SHAPE LEGAL');
        $extraSlot['legal']['cookies'] = LegalPageFixtures::document();
        $this->expectRefusal($extraSlot);
        // Older schemas never carry legal text.
        foreach ([2, 3, 4] as $version) {
            $older = LegalPageFixtures::content('SHAPE LEGAL');
            $older['schema_version'] = $version;
            $this->expectRefusal($older);
        }
        // Navigation may target only an included legal page.
        $navigation = LegalPageFixtures::content('SHAPE LEGAL', ['privacy']);
        $navigation['navigation'][] = ['label' => 'Terms', 'href' => '/legal/terms'];
        $this->expectRefusal($navigation);
        $navigation['navigation'][array_key_last($navigation['navigation'])]['href'] = '/legal/privacy';
        $release = $this->create($navigation);
        $this->assertSame(5, $release->schema_version);
        $saved = $release->content['navigation'];
        $this->assertSame('/legal/privacy', end($saved)['href']);
    }

    public function test_script_like_plain_text_is_retained_exactly_and_rendered_escaped(): void
    {
        $actor = LicenseFixtures::admin();
        $content = LegalPageFixtures::content('ESCAPE LEGAL');
        $payloads = ['&lt;img src=x onerror=alert(1)&gt;', 'javascript:alert(document.cookie)', '"onmouseover="alert(1)', '{{ $secret }} @php echo 1; @endphp', '&#60;script&#62;'];
        $content['legal']['privacy']['sections'][0]['paragraphs'] = $payloads;
        $release = $this->create($content, $actor);
        app(SiteContent::class)->publish($release->id, 0, $actor);

        $this->get('/legal/privacy', $this->headers())->assertOk()
            ->assertJsonPath('props.editorial.legal.sections.0.paragraphs', $payloads);
        $html = $this->get('/legal/privacy')->assertOk()->getContent();
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('"onmouseover="alert', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        // Template syntax stays inert text inside the JSON page data, and that JSON escapes "/" so no "</" sequence can close
        // its script element even if markup ever reached it.
        $this->assertStringContainsString('"{{ $secret }} @php echo 1; @endphp"', $html);
        $this->assertSame(1, substr_count($html, '</script>'));
    }

    public function test_staff_preview_shows_draft_legal_text_privately_and_denies_guests_customers_and_missing_slots(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = $this->create(LegalPageFixtures::content('PREVIEW LEGAL', ['privacy']), $actor);
        $url = '/admin/site-releases/'.$draft->id.'/preview/legal/privacy';
        $guest = $this->get($url)->assertRedirect('/admin/login')->assertDontSee('PREVIEW LEGAL', false);
        $this->assertPrivate($guest);
        $this->assertPrivate($this->actingAs(User::factory()->create())->get($url)->assertForbidden()->assertDontSee('PREVIEW LEGAL', false));

        $this->actingAs($actor);
        $response = $this->get($url, $this->headers())->assertOk();
        $this->assertPrivate($response);
        $response->assertJsonPath('props.sitePreview', true)
            ->assertJsonPath('props.editorial.legal.version', '1')
            ->assertJsonPath('props.metadata.robots', 'noindex, nofollow')
            ->assertDontSee(LegalPageFixtures::APPROVAL, false);
        foreach (['/legal/terms', '/legal', '/legal/cookies'] as $missing) {
            $this->assertPrivate($this->get('/admin/site-releases/'.$draft->id.'/preview'.$missing)->assertNotFound()->assertDontSee('PREVIEW LEGAL', false));
        }
        $older = $this->create(SiteEditorialFixtures::content('OLDER PREVIEW'), $actor);
        $this->assertPrivate($this->get('/admin/site-releases/'.$older->id.'/preview/legal/privacy')->assertNotFound());
        // Preview never publishes.
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertAbsent('/legal/privacy', 'PREVIEW LEGAL');
    }

    public function test_older_releases_stay_exact_and_editing_a_legal_release_keeps_its_legal_text(): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $legacy = $site->create(SiteEditorialFixtures::legacy(), 'Legacy', $actor);
        $this->assertSame('2dbb6cd65de0435534c7280b19083c03723fd7ea13d0658e1c4f78e731e0eb81', $legacy->content_hash);
        $editing = SiteContentSchema::forEditing($site->preview($legacy->id, $actor));
        $this->assertArrayNotHasKey('legal', $editing);
        $this->assertSame(2, $editing['schema_version']);
        $legal = $this->create(LegalPageFixtures::content('EDIT LEGAL', ['privacy', 'terms']), $actor);
        $this->assertSame($legal->content, SiteContentSchema::forEditing($site->preview($legal->id, $actor)));
        $this->assertNull(app(EditorialContent::class)->legal($site->preview($legacy->id, $actor), 'privacy'));
        $this->assertNull(app(EditorialContent::class)->page($site->preview($legal->id, $actor), 'legal', null));
        $this->assertSame('/legal/terms', app(EditorialContent::class)->page($site->preview($legal->id, $actor), 'legal', 'terms')['path']);
        $this->assertSame([1, 5], SiteRelease::orderBy('id')->pluck('schema_version')->all());
    }

    private function expectRefusal(array $content, ?string $field = null): void
    {
        $before = SiteRelease::count();
        try {
            $this->create($content);
            $this->fail('Unsafe or incomplete legal content was retained.');
        } catch (ValidationException $exception) {
            if ($field !== null) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        $this->assertSame($before, SiteRelease::count());
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
