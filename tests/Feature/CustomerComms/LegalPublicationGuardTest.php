<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\SiteBuilder\LegalPublicationGuard;
use App\Domain\SiteBuilder\LegalPublicationRefused;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationSchedule;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FilamentNotifications;
use Tests\Support\LegalPageFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

/**
 * FP-005: a release whose legal pages still show a placeholder or review marker is refused by the domain on every path
 * that makes it public. The fixtures quote the marker forms of docs/legal; no draft text is published or completed here.
 */
class LegalPublicationGuardTest extends TestCase
{
    use RefreshDatabase;

    private const TITLES = ['privacy' => 'Privacy policy', 'terms' => 'Terms', 'licenses' => 'License comparison'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:30', 'UTC'));
    }

    private function release(array $content, User $actor, string $label = 'Synthetic guarded release'): SiteRelease
    {
        return app(SiteContent::class)->create($content, $label, $actor);
    }

    private function refused(callable $attempt): LegalPublicationRefused
    {
        try {
            $attempt();
        } catch (LegalPublicationRefused $refusal) {
            return $refusal;
        }
        $this->fail('A release with unresolved legal markers was accepted.');
    }

    /** Marker text as it stands in docs/legal (PRIVACY, TERMS, LICENSE-TIERS), with the class each must be reported as. */
    public static function markers(): array
    {
        $case = fn (string $class, string $text, string $excerpt, string $document = 'terms', string $field = 'sections.1.paragraphs.0'): array => [$class, $text, $excerpt, $document, $field];

        return [
            'legal review marker on a heading (TERMS 15)' => $case(LegalPublicationGuard::LEGAL_REVIEW, '15. Disclaimers [For legal review]', '[For legal review]', 'terms', 'sections.0.heading'),
            'legal review marker with a full stop (LICENSE-TIERS 4.14)' => $case(LegalPublicationGuard::LEGAL_REVIEW, 'Neither party is liable for indirect losses. [For legal review.]', '[For legal review.]', 'licenses'),
            'legal review marker without brackets' => $case(LegalPublicationGuard::LEGAL_REVIEW, 'Limitation of liability, for legal review', 'for legal review'),
            'owner decision: venue county (TERMS 18)' => $case(LegalPublicationGuard::OWNER_DECISION, 'courts located in Iowa [county — Sean to confirm], and you agree', '[county — Sean to confirm]'),
            'owner decision: provider choice (PRIVACY 5)' => $case(LegalPublicationGuard::OWNER_DECISION, 'Email delivery provider: [name of email/SMTP provider — Sean to choose]', '[name of email/SMTP provider — Sean to choose]', 'privacy'),
            'owner decision: DMCA agent (TERMS 13)' => $case(LegalPublicationGuard::OWNER_DECISION, '[Sean to decide whether to register a DMCA designated agent with the U.S. Copyright Office.]', '[Sean to decide whether to register a DMCA designated agent with the U.S. Copyr…'),
            'owner decision without brackets: price cell (LICENSE-TIERS 1)' => $case(LegalPublicationGuard::OWNER_DECISION, 'Price: Sean to set', 'Sean to set', 'licenses'),
            'owner decision without brackets: proposed figure (LICENSE-TIERS)' => $case(LegalPublicationGuard::OWNER_DECISION, 'All caps above are proposed — Sean to confirm.', 'Sean to confirm', 'licenses', 'description'),
            'publication value: effective date (PRIVACY header)' => $case(LegalPublicationGuard::PUBLICATION_VALUE, 'Effective date: [set on publication]', '[set on publication]', 'privacy'),
            'publication value: postal address (PRIVACY 1, TERMS 21)' => $case(LegalPublicationGuard::PUBLICATION_VALUE, 'Postal address: [registered postal address — inserted at publication]', '[registered postal address — inserted at publication]', 'privacy', 'sections.0.paragraphs.1'),
            'publication value without brackets' => $case(LegalPublicationGuard::PUBLICATION_VALUE, 'Last updated: set on publication', 'set on publication'),
            'draft notice: banner (PRIVACY, TERMS)' => $case(LegalPublicationGuard::DRAFT_NOTICE, 'Approved by Sean Vasey on 2026-11-07 — not yet published — not legal advice', 'not yet published', 'privacy', 'description'),
            'draft notice: banner (LICENSE-TIERS)' => $case(LegalPublicationGuard::DRAFT_NOTICE, 'DRAFT for Sean Vasey\'s approval', 'DRAFT for Sean Vasey\'s approval', 'licenses', 'title'),
            'draft notice: reviewer appendix heading' => $case(LegalPublicationGuard::DRAFT_NOTICE, 'Reviewer appendix', 'Reviewer appendix', 'terms', 'sections.1.heading'),
            'draft notice: removal instruction' => $case(LegalPublicationGuard::DRAFT_NOTICE, 'This section is removed before publication.', 'removed before publication', 'privacy'),
            'draft notice: version label' => $case(LegalPublicationGuard::DRAFT_NOTICE, 'Draft version label: terms-draft-2026-10-24', 'Draft version label'),
            'template variable (LICENSE-TIERS 6.3)' => $case(LegalPublicationGuard::TEMPLATE_VARIABLE, 'Files and scope {{deliverables}}', '{{deliverables}}', 'licenses'),
            'template variable with a dotted name' => $case(LegalPublicationGuard::TEMPLATE_VARIABLE, '{{usage.audio_streams}}', '{{usage.audio_streams}}', 'licenses'),
            'bracketed placeholder: template slot (LICENSE-TIERS 6.3)' => $case(LegalPublicationGuard::BRACKETED_PLACEHOLDER, '[Tier name] — VASEY.AUDIO license', '[Tier name]', 'licenses', 'title'),
            'bracketed placeholder: any other bracketed text' => $case(LegalPublicationGuard::BRACKETED_PLACEHOLDER, 'You must be at least [18] to buy a license.', '[18]'),
            'bracketed placeholder: empty brackets' => $case(LegalPublicationGuard::BRACKETED_PLACEHOLDER, 'Our address is [].', '[]', 'privacy'),
            'bracketed placeholder: unpaired opening bracket' => $case(LegalPublicationGuard::BRACKETED_PLACEHOLDER, 'courts located in Iowa [county', '['),
            'bracketed placeholder: unpaired closing bracket' => $case(LegalPublicationGuard::BRACKETED_PLACEHOLDER, 'to be confirmed] and you agree', ']'),
        ];
    }

    #[DataProvider('markers')]
    public function test_each_marker_class_refuses_publication_scheduling_and_restoring(string $class, string $text, string $excerpt, string $document, string $field): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $content = LegalPageFixtures::content('GUARDED LEGAL', ['privacy', 'terms', 'licenses']);
        data_set($content, 'legal.'.$document.'.'.$field, $text);
        // The draft itself is retained: staff still review unfinished text in the private preview.
        $release = $this->release($content, $actor);
        $expected = [['document' => $document, 'field' => $field, 'class' => $class, 'excerpt' => $excerpt]];
        $this->assertSame($expected, LegalPublicationGuard::findings($release->content));

        $attempts = [
            'publish' => fn () => $site->publish($release->id, 0, $actor),
            'rollback' => fn () => $site->rollback($release->id, 0, $actor),
            'schedule' => fn () => $site->schedule($release->id, CarbonImmutable::parse('2026-11-10 12:05:00', 'UTC'), 0, $actor),
        ];
        foreach ($attempts as $operation => $attempt) {
            $refusal = $this->refused($attempt);
            $this->assertSame($expected, $refusal->findings(), $operation);
            $this->assertSame(['legal'], array_keys($refusal->errors()), $operation);
            $message = $refusal->errors()['legal'][0];
            $this->assertStringContainsString('('.LegalPublicationGuard::REASON.')', $message);
            $this->assertStringContainsString('('.$class.')', $message);
            $this->assertStringContainsString('“'.$excerpt.'”', $message);
            $this->assertStringContainsString(self::TITLES[$document], $message);
        }
        $this->assertSame('legal_unresolved_markers', LegalPublicationRefused::REASON);
        $this->assertSame([0, null], [SitePublication::findOrFail(1)->revision, SitePublication::findOrFail(1)->active_release_id]);
        $this->assertSame(0, SitePublicationSchedule::count());
        $this->assertDatabaseCount('site_publication_revisions', 0);
        foreach (array_keys(self::TITLES) as $path) {
            $this->get('/legal/'.$path)->assertNotFound()->assertDontSee('GUARDED LEGAL', false);
        }
        // The private preview still shows the draft, marker included.
        $this->actingAs($actor)->get('/admin/site-releases/'.$release->id.'/preview/legal/'.$document)->assertOk();
    }

    public function test_every_marker_is_reported_once_per_page_and_field_and_the_message_is_bounded(): void
    {
        $actor = LicenseFixtures::admin();
        $content = LegalPageFixtures::content('MANY MARKERS', ['privacy', 'terms']);
        $content['legal']['privacy']['sections'][0]['paragraphs'][0] = 'Effective date: [set on publication]. Last updated: [set on publication]. Servers in [the United States — Sean to confirm the production server region].';
        $content['legal']['terms']['sections'][0]['heading'] = '16. Limitation of liability [For legal review]';
        $content['legal']['terms']['sections'][1]['paragraphs'][0] = str_repeat('[x] ', 9);
        $release = $this->release($content, $actor);
        $findings = LegalPublicationGuard::findings($release->content);
        $this->assertSame([
            ['privacy', 'sections.0.paragraphs.0', LegalPublicationGuard::PUBLICATION_VALUE],
            ['privacy', 'sections.0.paragraphs.0', LegalPublicationGuard::PUBLICATION_VALUE],
            ['privacy', 'sections.0.paragraphs.0', LegalPublicationGuard::OWNER_DECISION],
            ['terms', 'sections.0.heading', LegalPublicationGuard::LEGAL_REVIEW],
            ...array_fill(0, 9, ['terms', 'sections.1.paragraphs.0', LegalPublicationGuard::BRACKETED_PLACEHOLDER]),
        ], array_map(fn (array $finding): array => [$finding['document'], $finding['field'], $finding['class']], $findings));
        $this->assertSame([
            'privacy.owner_decision' => 1, 'privacy.publication_value' => 2,
            'terms.bracketed_placeholder' => 9, 'terms.legal_review_marker' => 1,
        ], LegalPublicationGuard::summary($findings));
        $message = $this->refused(fn () => app(SiteContent::class)->publish($release->id, 0, $actor))->errors()['legal'][0];
        $this->assertStringContainsString('still hold 13 unresolved markers', $message);
        $this->assertStringContainsString('Privacy policy, section 1 paragraph 1: value still to be entered at publication “[set on publication]”', $message);
        $this->assertStringContainsString('Terms, section 1 heading: legal-review marker “[For legal review]”', $message);
        $this->assertStringContainsString('; and 5 more.', $message);
        $this->assertStringContainsString('nothing was published', $message);
    }

    public function test_clean_legal_pages_publish_schedule_and_restore_and_near_miss_wording_is_not_a_marker(): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $content = LegalPageFixtures::content('CLEAN LEGAL', ['privacy', 'terms', 'licenses']);
        // Ordinary legal wording that shares words with the markers, and the inert template text of the existing escape test.
        $content['legal']['terms']['sections'][0]['paragraphs'] = [
            'We may ask a court to review a decision; this is not legal advice and a legal review of your own use is your responsibility.',
            'Changes take effect on publication of the revised terms (see section 19). Sean Vasey is the licensor.',
            'A draft of your recording is yours to review. Prices are set at checkout and confirmed by email.',
            'Parentheses (like these) and braces {like these} are plain text, as is {{ $secret }} @php echo 1; @endphp.',
        ];
        // The approval reference is staff evidence, never public text, so the guard does not read it.
        $content['legal']['terms']['approval_reference'] = 'Sean, 2026-11-07 [decision register] — Sean to confirm nothing further';
        $release = $this->release($content, $actor);
        $this->assertSame([], LegalPublicationGuard::findings($release->content));
        LegalPublicationGuard::assertPublishable($release->content);

        $site->publish($release->id, 0, $actor);
        $this->assertSame([1, $release->id], [SitePublication::findOrFail(1)->revision, SitePublication::findOrFail(1)->active_release_id]);
        foreach (array_keys(self::TITLES) as $path) {
            $this->get('/legal/'.$path)->assertOk()->assertSee('CLEAN LEGAL FIRST HEADING', false);
        }
        // A release without legal pages is never held back by the legal rule, and the clean one can be scheduled and restored.
        $older = $this->release(SiteEditorialFixtures::content('NO LEGAL'), $actor, 'No legal pages');
        $this->assertSame([], LegalPublicationGuard::findings($older->content));
        $site->publish($older->id, 1, $actor);
        $schedule = $site->schedule($release->id, CarbonImmutable::parse('2026-11-10 12:05:00', 'UTC'), 2, $actor);
        $this->assertSame('pending', $schedule->state);
        $site->rollback($release->id, 2, $actor, $schedule->id);
        $this->assertSame([3, $release->id], [SitePublication::findOrFail(1)->revision, SitePublication::findOrFail(1)->active_release_id]);
    }

    public function test_the_approved_drafts_as_they_stand_cannot_be_published(): void
    {
        // The drafts are read, never changed: this pins that every placeholder they still carry is one the guard reports.
        foreach (['privacy' => 'PRIVACY.md', 'terms' => 'TERMS.md', 'licenses' => 'LICENSE-TIERS.md'] as $document => $file) {
            $draft = file_get_contents(base_path('docs/legal/'.$file));
            $this->assertIsString($draft);
            $sections = [];
            foreach (preg_split('/^(?=#{1,6} )/m', $draft) as $block) {
                $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn (string $line): bool => $line !== ''));
                if ($lines !== []) {
                    $sections[] = ['heading' => array_shift($lines), 'paragraphs' => $lines ?: ['—']];
                }
            }
            $content = ['legal' => [$document => ['title' => 'T', 'description' => 'D', 'sections' => $sections]]];
            $findings = LegalPublicationGuard::findings($content);
            $classes = array_count_values(array_column($findings, 'class'));
            $excerpts = array_column($findings, 'excerpt');
            // Every square-bracketed span of the draft, Markdown or not, is one finding; the draft says such text must be filled.
            $this->assertSame(preg_match_all('/\[[^\[\]]*\]/u', $draft), count(array_filter($excerpts, fn (string $excerpt): bool => str_starts_with($excerpt, '['))), $file);
            // PRIVACY carries no "[For legal review]" marker; TERMS sections 15 to 17 and LICENSE-TIERS 4.14 do.
            $this->assertSame($document !== 'privacy', ($classes[LegalPublicationGuard::LEGAL_REVIEW] ?? 0) > 0, $file.' legal review');
            $this->assertGreaterThan(0, $classes[LegalPublicationGuard::OWNER_DECISION] ?? 0, $file.' owner decision');
            $this->assertGreaterThan(0, $classes[LegalPublicationGuard::DRAFT_NOTICE] ?? 0, $file.' draft notice');
            if ($document !== 'licenses') {
                $this->assertSame(4, count(array_keys($excerpts, '[set on publication]', true)) + count(array_keys($excerpts, '[registered postal address — inserted at publication]', true)), $file);
            } else {
                $this->assertContains('[Tier name]', $excerpts);
                $this->assertContains('{{deliverables}}', $excerpts);
            }
        }
        $terms = LegalPublicationGuard::findings(['legal' => ['terms' => ['title' => 'T', 'description' => 'D', 'sections' => [
            ['heading' => '## 15. Disclaimers [For legal review]', 'paragraphs' => ['x']],
            ['heading' => '## 16. Limitation of liability [For legal review]', 'paragraphs' => ['x']],
            ['heading' => '## 17. Indemnity [For legal review]', 'paragraphs' => ['x']],
        ]]]]);
        $this->assertSame(array_fill(0, 3, LegalPublicationGuard::LEGAL_REVIEW), array_column($terms, 'class'));
    }

    public function test_admin_reports_the_refusal_with_its_reason_code_and_warns_when_a_draft_is_saved(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $content = LegalPageFixtures::content('ADMIN GUARD', ['terms']);
        $content['legal']['terms']['sections'][0]['heading'] = '17. Indemnity [For legal review]';
        $release = $this->release($content, $actor, 'Marked admin release');

        Livewire::test(ListSiteReleases::class)->callTableAction('publish', $release);
        $blocked = FilamentNotifications::sole('Publication blocked', 'danger');
        $this->assertStringContainsString('(legal_unresolved_markers)', $blocked['body']);
        $this->assertStringContainsString('Terms, section 1 heading: legal-review marker “[For legal review]” (legal_review_marker)', $blocked['body']);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);

        Livewire::test(ListSiteReleases::class)->callTableAction('schedulePublication', $release, data: ['publish_at' => '2026-11-10 12:05']);
        $this->assertStringContainsString('(legal_unresolved_markers)', FilamentNotifications::sole('Scheduling blocked', 'danger')['body']);
        $this->assertSame(0, SitePublicationSchedule::count());

        // Saving such a draft succeeds and says immediately why it cannot be published.
        $document = $content['legal']['terms'];
        $document['sections'] = array_map(fn (array $section): array => ['heading' => $section['heading'],
            'paragraphs' => array_map(fn (string $text): array => ['text' => $text], $section['paragraphs'])], $document['sections']);
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: [
            'label' => 'Marked editor draft', 'enabled_legal' => ['privacy' => false, 'terms' => true, 'licenses' => false],
            'content.legal.terms' => $document,
        ])->assertHasNoActionErrors();
        $this->assertSame(2, SiteRelease::count());
        $warning = FilamentNotifications::sole('This draft cannot be published yet', 'warning');
        $this->assertStringContainsString('(legal_unresolved_markers)', $warning['body']);
        $this->assertStringContainsString('“[For legal review]”', $warning['body']);
    }

    public function test_saving_a_clean_draft_shows_no_legal_warning(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $document = LegalPageFixtures::document('CLEAN EDITOR');
        $document['sections'] = array_map(fn (array $section): array => ['heading' => $section['heading'],
            'paragraphs' => array_map(fn (string $text): array => ['text' => $text], $section['paragraphs'])], $document['sections']);
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: [
            'label' => 'Clean editor draft', 'enabled_legal' => ['privacy' => true, 'terms' => false, 'licenses' => false],
            'content.legal.privacy' => $document,
        ])->assertHasNoActionErrors();
        $this->assertSame(['Private draft saved'], array_column(FilamentNotifications::flashed(), 'title'));
        $release = SiteRelease::sole();
        Livewire::test(ListSiteReleases::class)->callTableAction('publish', $release);
        $this->assertSame($release->id, SitePublication::findOrFail(1)->active_release_id);
    }
}
