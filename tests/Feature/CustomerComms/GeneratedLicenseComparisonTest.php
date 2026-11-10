<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishedLicenseComparison;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\LegalPublicationGuard;
use App\Domain\SiteBuilder\LegalPublicationRefused;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationSchedule;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\LegalPageFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\Support\TieredLicenseFixtures;
use Tests\TestCase;

/**
 * BL-38 (DD-31): /legal/licenses is generated from the license versions that are published and in their availability
 * window, with the lines the track cards carry. The site release only decides whether the page exists and supplies its
 * title and introduction; a hand-typed comparison is never made public. Synthetic, nonbinding license terms only.
 */
class GeneratedLicenseComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));
    }

    private function headers(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    private function template(string $name, string $type = 'non-exclusive'): LicenseTemplate
    {
        return LicenseTemplate::create(['name' => $name, 'slug' => 'comparison-'.Str::uuid(), 'type' => $type]);
    }

    /** Draft, independent approval and publication of one typed version, exactly as staff do it. */
    private function publishVersion(LicenseTemplate $template, User $author, array $terms = [], array $content = [], ?LicenseVersion $predecessor = null, bool $publish = true): LicenseVersion
    {
        $draft = app(CreateLicenseDraft::class)->handle($template, $content + [
            'authored_source' => TieredLicenseFixtures::source(), 'structured_terms' => $terms ?: TieredLicenseFixtures::terms(),
        ], $author, $predecessor);
        $submitted = app(ReviewLicense::class)->submit($draft, $author);
        $approved = app(ReviewLicense::class)->approve($submitted, LicenseFixtures::admin(), [
            'approval_reference' => 'SYNTHETIC COMPARISON REVIEW', 'review_hash' => $submitted->submission_hash,
            'summary_consistency_confirmed' => true,
        ]);

        return $publish ? app(PublishLicense::class)->handle($approved, $author) : $approved;
    }

    /** The public entry a listed version must produce: the stored version's own generated lines. */
    private function entry(LicenseVersion $version): array
    {
        $version = LicenseVersion::findOrFail($version->id);
        $template = $version->template()->firstOrFail();

        return [
            'id' => (string) $version->id, 'name' => $template->name, 'version' => $version->version, 'type' => $template->type,
            'features' => $version->features(), 'requiredAssetRoles' => $version->requiredAssetRoles(),
        ];
    }

    private function publishRelease(array $content, User $actor, int $expectedRevision = 0): SiteRelease
    {
        $release = app(SiteContent::class)->create($content, 'Synthetic comparison release', $actor);
        app(SiteContent::class)->publish($release->id, $expectedRevision, $actor);

        return $release;
    }

    private function refused(callable $attempt): LegalPublicationRefused
    {
        try {
            $attempt();
        } catch (LegalPublicationRefused $refusal) {
            return $refusal;
        }
        $this->fail('A hand-typed license comparison was made public.');
    }

    public function test_the_page_lists_exactly_the_published_in_window_typed_versions_in_a_stable_order(): void
    {
        $actor = LicenseFixtures::admin();
        $release = $this->publishRelease(LegalPageFixtures::content('GENERATED', ['privacy', 'licenses']), $actor);
        $this->assertSame(SiteContentSchema::GENERATED_LICENSES_VERSION, $release->schema_version);
        $this->assertSame(['title' => 'GENERATED License comparison', 'description' => 'GENERATED DESCRIPTION'], $release->content['legal']['licenses']);

        // No version is published yet: the page is absent and the footer does not link it.
        $this->get('/legal/licenses')->assertNotFound()->assertDontSee('GENERATED License comparison', false);
        $this->get('/legal/privacy', $this->headers())->assertOk()
            ->assertJsonPath('props.siteContent.legal_links', [['title' => 'GENERATED Privacy policy', 'path' => '/legal/privacy']]);

        // Versions that must never be listed: unpublished, untyped (schema 1), not yet available and no longer available.
        $later = $this->template('SYNTHETIC LATER TIER');
        $earlier = $this->template('SYNTHETIC EARLIER TIER', 'exclusive');
        $this->publishVersion($this->template('SYNTHETIC APPROVED ONLY'), $actor, publish: false);
        LicenseFixtures::draft($actor, TieredLicenseFixtures::terms(), ['authored_source' => TieredLicenseFixtures::source()]);
        $untyped = LicenseFixtures::published($actor);
        $this->assertSame(1, $untyped->terms_schema_version);
        $future = $this->publishVersion($this->template('SYNTHETIC FUTURE TIER'), $actor, content: ['effective_from' => '2026-11-12T00:00:00Z']);
        $expiring = $this->publishVersion($this->template('SYNTHETIC EXPIRING TIER'), $actor, content: ['effective_from' => '2026-11-01T00:00:00Z', 'effective_until' => '2026-11-11T00:00:00Z']);
        // Listed: created in an order that differs from the expected order, which is template identity, then version identity.
        $second = $this->publishVersion($later, $actor, terms: array_replace(TieredLicenseFixtures::terms(), ['required_asset_roles' => ['stems_zip', 'download_mp3']]));
        $first = $this->publishVersion($earlier, $actor);
        // Template order (LATER, EARLIER, EXPIRING) differs from both name order and version order (EXPIRING, LATER, EARLIER).
        $this->assertSame([$later->id, $earlier->id], [$later->id, $later->id + 1]);
        $this->assertLessThan($second->id, $expiring->id);
        $expected = [$this->entry($second), $this->entry($first), $this->entry($expiring)];
        $response = $this->get('/legal/licenses', $this->headers())->assertOk();
        $response->assertJsonPath('component', 'Editorial')
            ->assertJsonPath('props.editorial.path', '/legal/licenses')
            ->assertJsonPath('props.editorial.title', 'GENERATED License comparison')
            ->assertJsonPath('props.editorial.description', 'GENERATED DESCRIPTION')
            ->assertJsonPath('props.editorial.legal', ['document' => 'licenses', 'generated' => true, 'licenses' => $expected])
            ->assertJsonPath('props.metadata.canonicalUrl', 'https://audio.example.test/legal/licenses')
            ->assertJsonPath('props.siteContent.legal_links', [
                ['title' => 'GENERATED Privacy policy', 'path' => '/legal/privacy'],
                ['title' => 'GENERATED License comparison', 'path' => '/legal/licenses'],
            ]);
        // Every line is a typed statement; the stems-and-MP3 version names its files in the canonical label order (DD-30).
        $this->assertContains('Deliverables: MP3, Stems ZIP.', collect($expected)->firstWhere('id', (string) $second->id)['features']);
        $html = $this->get('/legal/licenses')->assertOk();
        $html->assertDontSee('SYNTHETIC COMPARISON REVIEW', false)->assertDontSee('SYNTHETIC NONBINDING REVIEW FIXTURE', false);
        foreach (['SYNTHETIC APPROVED ONLY', 'SYNTHETIC FUTURE TIER', 'NONBINDING TEST FIXTURE'] as $absent) {
            $html->assertDontSee($absent, false);
        }

        // The window is read at request time: the future version appears at its start, the expiring one leaves at its end.
        $this->travelTo(CarbonImmutable::parse('2026-11-11 00:00:00', 'UTC'));
        $ids = fn (): array => array_column($this->get('/legal/licenses', $this->headers())->assertOk()->json('props.editorial.legal.licenses'), 'id');
        $this->assertNotContains((string) $expiring->id, $ids());
        $this->assertNotContains((string) $future->id, $ids());
        $this->travelTo(CarbonImmutable::parse('2026-11-12 00:00:00', 'UTC'));
        $this->assertContains((string) $future->id, $ids());
        $this->assertSame(array_column(app(PublishedLicenseComparison::class)->versions(), 'id'), $ids());
        $this->assertSame(1, SitePublication::findOrFail(1)->revision);
    }

    public function test_publishing_a_successor_changes_the_page_with_no_site_release_edit(): void
    {
        $actor = LicenseFixtures::admin();
        $release = $this->publishRelease(LegalPageFixtures::content('SUCCESSOR', ['licenses']), $actor);
        $template = $this->template('SYNTHETIC SUCCESSOR TIER');
        $original = $this->publishVersion($template, $actor);
        $before = $this->get('/legal/licenses', $this->headers())->assertOk()->json('props.editorial.legal.licenses');
        $this->assertSame([$this->entry($original)], $before);

        $terms = TieredLicenseFixtures::terms();
        $terms['usage']['audio_releases'] = ['mode' => 'limited', 'limit' => 3];
        $successor = $this->publishVersion($template, $actor, terms: $terms, predecessor: $original);
        $this->assertSame([$original->id, 'published'], [$successor->predecessor_id, $successor->status]);

        $after = $this->get('/legal/licenses', $this->headers())->assertOk()->json('props.editorial.legal.licenses');
        $this->assertSame([$this->entry($original), $this->entry($successor)], $after);
        $this->assertContains('Audio releases: up to 3.', $after[1]['features']);
        $this->assertContains('Audio releases: up to 2.', $after[0]['features']);
        // Nothing about the site changed: same active release, same revision, same retained bytes.
        $this->assertSame([1, $release->id], [SitePublication::findOrFail(1)->revision, SitePublication::findOrFail(1)->active_release_id]);
        $this->assertSame($release->content_hash, SiteRelease::findOrFail($release->id)->content_hash);
        $this->assertSame(1, SiteRelease::query()->where('label', '!=', 'Original site content')->count());
        // Ending the original's availability is a successor's job (published versions are immutable), so it stays listed.
        $this->assertSame('published', LicenseVersion::findOrFail($original->id)->status);
    }

    public function test_each_section_is_the_storefront_license_tier_of_the_same_version(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $version = $this->publishVersion($this->template('SYNTHETIC CARD TIER'), $actor);
        app(SaveOfferDraft::class)->handle($fixture['offer'], ['license_version_id' => $version->id], $actor);
        app(PublishOffer::class)->handle($fixture['offer'], $actor);
        $this->publishRelease(LegalPageFixtures::content('CARDS', ['licenses']), $actor);

        $tier = collect($this->getJson('/api/catalog')->assertOk()->json('licenseTiers'))->where('id', (string) $version->id)->sole();
        $page = collect($this->get('/legal/licenses', $this->headers())->assertOk()->json('props.editorial.legal.licenses'))->where('id', (string) $version->id)->sole();
        // Same generator, same projection: the card's tier and the page's section are identical, field for field.
        $this->assertSame($tier, $page);
        // The quote fixture's schema 1 license is sold too, but its prose is not typed, so the page leaves it out.
        $this->assertCount(1, $this->get('/legal/licenses', $this->headers())->json('props.editorial.legal.licenses'));
    }

    public function test_a_version_whose_review_evidence_no_longer_verifies_is_not_listed(): void
    {
        $actor = LicenseFixtures::admin();
        $this->publishRelease(LegalPageFixtures::content('EVIDENCE', ['licenses']), $actor);
        $intact = $this->publishVersion($this->template('SYNTHETIC INTACT TIER'), $actor);
        $damaged = $this->publishVersion($this->template('SYNTHETIC DAMAGED TIER'), $actor);
        // Damage outside the application, such as a restore without its triggers: the evidence no longer binds the approval.
        DB::unprepared('DROP TRIGGER license_evidence_update_guard');
        DB::table('license_review_evidence')->where('license_version_id', $damaged->id)->update(['approval_reference' => 'ALTERED OUTSIDE THE APPLICATION']);
        $listed = $this->get('/legal/licenses', $this->headers())->assertOk()->json('props.editorial.legal.licenses');
        $this->assertSame([$this->entry($intact)], $listed);
    }

    public function test_the_page_is_absent_when_the_release_omits_it_even_with_published_versions(): void
    {
        $actor = LicenseFixtures::admin();
        $this->publishVersion($this->template('SYNTHETIC OMITTED TIER'), $actor);
        $this->get('/legal/licenses')->assertNotFound();
        $this->publishRelease(SiteEditorialFixtures::content('NO LEGAL'), $actor);
        $this->get('/legal/licenses')->assertNotFound();
        $this->publishRelease(LegalPageFixtures::content('PRIVACY ONLY', ['privacy']), $actor, 1);
        $this->get('/legal/licenses')->assertNotFound()->assertDontSee('SYNTHETIC OMITTED TIER', false);
        $this->get('/legal/privacy', $this->headers())->assertOk()
            ->assertJsonPath('props.siteContent.legal_links', [['title' => 'PRIVACY ONLY Privacy policy', 'path' => '/legal/privacy']]);
    }

    public function test_a_hand_typed_comparison_is_refused_at_publication_and_never_rendered(): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $this->publishVersion($this->template('SYNTHETIC SOLD TIER'), $actor);
        // An older release still loads: a schema 5 draft with typed comparison sections is retained for review.
        $release = $site->create(LegalPageFixtures::handTyped('HAND TYPED', ['privacy', 'licenses']), 'Hand-typed comparison', $actor);
        $this->assertSame(5, $release->schema_version);
        $expected = [['document' => 'licenses', 'field' => 'sections', 'class' => LegalPublicationGuard::HAND_TYPED_COMPARISON, 'excerpt' => '2 typed sections']];
        $this->assertSame($expected, LegalPublicationGuard::findings($release->content));
        foreach ([
            'publish' => fn () => $site->publish($release->id, 0, $actor),
            'rollback' => fn () => $site->rollback($release->id, 0, $actor),
            'schedule' => fn () => $site->schedule($release->id, CarbonImmutable::parse('2026-11-10 12:05:00', 'UTC'), 0, $actor),
        ] as $operation => $attempt) {
            $refusal = $this->refused($attempt);
            $this->assertSame($expected, $refusal->findings(), $operation);
            $this->assertStringContainsString('License comparison sections: hand-typed license comparison', $refusal->errors()['legal'][0], $operation);
            $this->assertStringContainsString('('.LegalPublicationGuard::HAND_TYPED_COMPARISON.')', $refusal->errors()['legal'][0], $operation);
        }
        $this->assertSame([0, null, 0], [SitePublication::findOrFail(1)->revision, SitePublication::findOrFail(1)->active_release_id, SitePublicationSchedule::count()]);

        // The staff preview shows the privacy text but never the typed comparison, and the projection drops it everywhere.
        $this->actingAs($actor);
        $this->get('/admin/site-releases/'.$release->id.'/preview/legal/privacy')->assertOk();
        $this->get('/admin/site-releases/'.$release->id.'/preview/legal/licenses')->assertNotFound()->assertDontSee('HAND TYPED FIRST HEADING', false);
        $content = $site->preview($release->id, $actor);
        $this->assertNull(app(EditorialContent::class)->legal($content, 'licenses'));
        $this->assertSame([['title' => 'HAND TYPED Privacy policy', 'path' => '/legal/privacy']], app(EditorialContent::class)->chrome($content)['legal_links']);

        // A schema 5 release without the comparison stays publishable, and so does its schema 6 successor.
        $privacy = $site->create(LegalPageFixtures::handTyped('PRIVACY FIVE', ['privacy']), 'Schema 5 privacy', $actor);
        $site->publish($privacy->id, 0, $actor);
        $generated = $site->create(LegalPageFixtures::content('SIX', ['privacy', 'licenses']), 'Schema 6', $actor);
        $site->publish($generated->id, 1, $actor);
        $this->get('/legal/licenses')->assertOk()->assertSee('SYNTHETIC SOLD TIER', false);
        $site->rollback($privacy->id, 2, $actor);
        $this->get('/legal/privacy')->assertOk()->assertSee('PRIVACY FIVE FIRST HEADING', false);
        $this->get('/legal/licenses')->assertNotFound();
    }

    public function test_the_legal_guard_still_reads_the_title_and_introduction(): void
    {
        $actor = LicenseFixtures::admin();
        $this->publishVersion($this->template('SYNTHETIC GUARDED TIER'), $actor);
        foreach ([
            'title' => ['[Tier name] comparison', LegalPublicationGuard::BRACKETED_PLACEHOLDER, '[Tier name]'],
            'description' => ['All caps are proposed — Sean to confirm.', LegalPublicationGuard::OWNER_DECISION, 'Sean to confirm'],
        ] as $field => [$text, $class, $excerpt]) {
            $content = LegalPageFixtures::content('GUARDED FRAME', ['licenses']);
            $content['legal']['licenses'][$field] = $text;
            $release = app(SiteContent::class)->create($content, 'Guarded frame', $actor);
            $refusal = $this->refused(fn () => app(SiteContent::class)->publish($release->id, 0, $actor));
            $this->assertSame([['document' => 'licenses', 'field' => $field, 'class' => $class, 'excerpt' => $excerpt]], $refusal->findings());
        }
        $this->get('/legal/licenses')->assertNotFound();
    }

    public function test_schema_six_accepts_only_the_frame_and_older_shapes_are_unchanged(): void
    {
        $actor = LicenseFixtures::admin();
        $refuse = function (array $content, string $case) use ($actor): void {
            try {
                app(SiteContent::class)->create($content, 'Refused shape', $actor);
                $this->fail('Accepted: '.$case);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        };
        $base = LegalPageFixtures::content('SHAPE', ['privacy', 'licenses']);
        foreach (['sections' => [['heading' => 'H', 'paragraphs' => ['P']]], 'version' => '1', 'effective_date' => '2026-11-01', 'approval_reference' => 'A'] as $key => $value) {
            $typed = $base;
            $typed['legal']['licenses'][$key] = $value;
            $refuse($typed, 'v6 comparison with '.$key);
        }
        foreach (['title' => '', 'description' => '<b>intro</b>'] as $key => $value) {
            $bad = $base;
            $bad['legal']['licenses'][$key] = $value;
            $refuse($bad, 'v6 comparison '.$key);
        }
        $missing = $base;
        unset($missing['legal']['licenses']['description']);
        $refuse($missing, 'v6 comparison without an introduction');
        $without = $base;
        $without['legal']['licenses'] = null;
        $refuse($without, 'v6 without the comparison');
        $frameInFive = $base;
        $frameInFive['schema_version'] = 5;
        $refuse($frameInFive, 'v5 with a frame-only comparison');
        // Navigation may target the comparison only when the release includes it.
        $navigation = $base;
        $navigation['navigation'][] = ['label' => 'Licenses', 'href' => '/legal/licenses'];
        $this->assertSame(6, app(SiteContent::class)->create($navigation, 'With navigation', $actor)->schema_version);
        $navigation['legal']['licenses'] = null;
        $navigation['schema_version'] = 5;
        $refuse($navigation, 'v5 navigation to an absent comparison');
        // Images and related tracks carry over to schema 6 unchanged.
        $this->assertContains(6, SiteContentSchema::IMAGE_VERSIONS);
        $this->assertContains(6, SiteContentSchema::RELATED_TRACK_VERSIONS);
    }

    public function test_editor_saves_the_comparison_frame_as_schema_six_and_copies_drop_typed_sections(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: [
            'label' => 'Generated comparison draft', 'enabled_legal' => ['privacy' => false, 'terms' => false, 'licenses' => true],
            'content.legal.licenses' => ['title' => 'EDITOR License comparison', 'description' => 'EDITOR introduction'],
        ])->assertHasNoActionErrors();
        $release = SiteRelease::sole();
        $this->assertSame(SiteContentSchema::GENERATED_LICENSES_VERSION, $release->schema_version);
        $this->assertSame(['privacy' => null, 'terms' => null, 'licenses' => ['title' => 'EDITOR License comparison', 'description' => 'EDITOR introduction']], $release->content['legal']);

        // A schema 5 release with a typed comparison is published; a new draft from it keeps the frame, not the sections.
        $legacy = app(SiteContent::class)->create(LegalPageFixtures::handTyped('LEGACY', ['privacy']), 'Legacy schema 5', $actor);
        app(SiteContent::class)->publish($legacy->id, 0, $actor);
        $old = LegalPageFixtures::handTyped('LEGACY', ['licenses'])['legal']['licenses'];
        $form = Livewire::test(ListSiteReleases::class)->mountAction('createDraft');
        $this->assertSame(['title' => '', 'description' => ''], $form->get('mountedActions.0.data.content.legal.licenses'));
        $form->set('mountedActions.0.data.label', 'From legacy')
            ->set('mountedActions.0.data.enabled_legal.licenses', true)
            ->set('mountedActions.0.data.content.legal.licenses', $old)
            ->callMountedAction()->assertHasNoActionErrors();
        $copy = SiteRelease::query()->where('label', 'From legacy')->sole();
        $this->assertSame(6, $copy->schema_version);
        $this->assertSame(['title' => $old['title'], 'description' => $old['description']], $copy->content['legal']['licenses']);
        $this->assertSame([], LegalPublicationGuard::findings($copy->content));
    }
}
