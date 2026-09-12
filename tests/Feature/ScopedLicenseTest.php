<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\LicensePreview;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\ScopedLicensePreview;
use App\Domain\Rights\TypedLicensePreview;
use App\Domain\Rights\VerifiedLicense;
use App\Filament\Resources\LicenseVersionResource\Pages\ManageLicenseVersions;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\ScopedLicenseFixtures;
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

class ScopedLicenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function publish(LicenseVersion $draft, User $author): LicenseVersion
    {
        $submitted = app(ReviewLicense::class)->submit($draft, $author);
        $approved = app(ReviewLicense::class)->approve($submitted, LicenseFixtures::admin(), [
            'approval_reference' => 'SYNTHETIC SCOPE REVIEW', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true,
        ]);

        return app(PublishLicense::class)->handle($approved, $author);
    }

    public function test_scoped_review_freezes_source_summaries_schema_and_renderer_evidence(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = ScopedLicenseFixtures::draft($actor);
        $preview = app(LicensePreview::class)->render($draft);
        $this->assertSame(ScopedLicensePreview::VERSION, $preview['renderer_version']);
        $this->assertSame($preview, app(LicensePreview::class)->render($draft->fresh()));
        $this->assertContains('Territory: CA, US (ISO 3166-1 alpha-2).', $draft->features());
        $this->assertContains('License duration: 120 calendar months from the grant timestamp (UTC; end-of-month clamping).', $draft->features());
        foreach ($draft->features() as $statement) {
            $this->assertStringContainsString(htmlspecialchars($statement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $preview['html']);
        }
        $published = $this->publish($draft, $actor);
        $this->assertSame(3, $published->terms_schema_version);
        $this->assertSame($preview['sha256'], $published->render_fixture_hash);
        $this->assertSame(CanonicalJson::hash($published->structured_terms), $published->model_hash);
        $this->assertSame(hash('sha256', $published->authored_source), $published->source_hash);
        $this->assertTrue(app(VerifiedLicense::class)->available($published));
        $before = $published->fresh()->getAttributes();
        $changed = $published->structured_terms;
        $changed['territory'] = ['mode' => 'worldwide'];
        foreach ([['structured_terms' => json_encode($changed)], ['renderer_version' => TypedLicensePreview::VERSION], ['terms_schema_version' => 2], ['status' => 'draft']] as $change) {
            try {
                DB::table('license_versions')->where('id', $published->id)->update($change);
                $this->fail('Published scope or its review evidence changed through SQL.');
            } catch (QueryException) {
            }
        }
        $this->assertSame($before, $published->fresh()->getAttributes());
    }

    public function test_historical_v2_preview_matches_prechange_golden_bytes_after_v3_publication(): void
    {
        $actor = LicenseFixtures::admin();
        $template = LicenseTemplate::create(['name' => 'NONBINDING V2 GOLDEN FIXTURE', 'slug' => 'v2-golden-fixture', 'type' => 'non-exclusive']);
        $terms = TypedLicenseFixtures::terms();
        $terms['credit']['text'] = '<SYNTHETIC> & PRODUCER';
        $draft = app(CreateLicenseDraft::class)->handle($template, [
            'authored_source' => '<script>synthetic</script>'.TypedLicenseFixtures::source(), 'structured_terms' => $terms,
            'effective_from' => '2026-09-01T00:00:00Z', 'effective_until' => '2027-09-01T00:00:00Z',
        ], $actor);
        // Captured from the merged PR #32 renderer before the schema-3 implementation.
        $golden = file_get_contents(base_path('tests/Fixtures/license-review-v2.html'));
        $this->assertSame($golden, app(LicensePreview::class)->render($draft)['html']);
        $legacy = $this->publish($draft, $actor);
        $attributes = $legacy->fresh()->getAttributes();
        $evidence = $legacy->reviewEvidence()->sole()->getAttributes();
        $this->publish(ScopedLicenseFixtures::draft($actor), $actor);
        $this->assertSame($golden, app(LicensePreview::class)->render($legacy->fresh())['html']);
        $this->assertSame(hash('sha256', $golden), $legacy->render_fixture_hash);
        $this->assertSame($attributes, $legacy->fresh()->getAttributes());
        $this->assertSame($evidence, $legacy->reviewEvidence()->sole()->getAttributes());
        $this->assertTrue(app(VerifiedLicense::class)->available($legacy, CarbonImmutable::parse('2026-09-11T00:00:00Z')));
    }

    public function test_database_rejects_cross_version_and_unknown_scope_renderer_pairs(): void
    {
        $actor = LicenseFixtures::admin();
        $submitted = app(ReviewLicense::class)->submit(ScopedLicenseFixtures::draft($actor), $actor);
        $proof = Arr::only($submitted->getAttributes(), ['status', 'submission_payload', 'submission_hash', 'canonicalization_version', 'terms_schema_version', 'submitted_by', 'submitted_at', 'source_hash', 'model_hash', 'renderer_version', 'render_fixture_hash']);
        foreach ([
            [1, ScopedLicensePreview::VERSION], [2, ScopedLicensePreview::VERSION], [3, LicensePreview::VERSION], [3, TypedLicensePreview::VERSION],
            [1, TypedLicensePreview::VERSION], [2, LicensePreview::VERSION], [4, ScopedLicensePreview::VERSION], [null, ScopedLicensePreview::VERSION], [3, null],
        ] as [$schema, $renderer]) {
            $draft = ScopedLicenseFixtures::draft($actor);
            try {
                DB::table('license_versions')->where('id', $draft->id)->update(array_replace($proof, ['terms_schema_version' => $schema, 'renderer_version' => $renderer]));
                $this->fail('SQL accepted a mismatched or unknown scope renderer.');
            } catch (QueryException) {
                $this->assertSame('draft', $draft->fresh()->status);
            }
        }
    }

    public function test_missing_scope_variables_prevent_review_even_after_direct_draft_writes(): void
    {
        $actor = LicenseFixtures::admin();
        foreach (['{{territory}}', '{{duration}}'] as $missing) {
            $draft = ScopedLicenseFixtures::draft($actor);
            DB::table('license_versions')->where('id', $draft->id)->update(['authored_source' => str_replace($missing, '', ScopedLicenseFixtures::source())]);
            try {
                app(ReviewLicense::class)->submit($draft, $actor);
                $this->fail('A missing scope statement reached review.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('authored_source', $exception->errors());
            }
            $this->assertSame('draft', $draft->fresh()->status);
            $this->assertNull($draft->fresh()->submission_hash);
        }
        $this->assertDatabaseCount('license_review_evidence', 0);
    }

    public function test_operator_maps_v2_to_v3_deliberately_and_clears_fields_when_modes_change(): void
    {
        $actor = LicenseFixtures::admin();
        $legacy = LicenseFixtures::published($actor, terms: TypedLicenseFixtures::terms(), content: [
            'authored_source' => TypedLicenseFixtures::source(), 'effective_from' => '2026-09-01T00:00:00Z', 'effective_until' => '2027-09-01T00:00:00Z',
        ]);
        $before = $legacy->fresh()->getAttributes();
        $this->actingAs($actor);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('scoped_successor', $legacy)->assertHasTableActionErrors();
        Livewire::test(ManageLicenseVersions::class)->callTableAction('scoped_successor', $legacy, data: ['authored_source' => ScopedLicenseFixtures::source()])->assertHasTableActionErrors();
        $this->assertDatabaseCount('license_versions', 1);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('scoped_successor', $legacy, data: [
            'authored_source' => ScopedLicenseFixtures::source(), 'structured_terms' => ScopedLicenseFixtures::terms(),
        ])->assertHasNoTableActionErrors();
        $successor = LicenseVersion::where('predecessor_id', $legacy->id)->sole();
        $this->assertSame(3, $successor->structured_terms['schema_version']);
        $this->assertSame('draft', $successor->status);
        $this->assertNull($successor->submission_hash);
        $this->assertSame($legacy->effective_from->toIso8601ZuluString(), $successor->effective_from->toIso8601ZuluString());
        $this->assertSame($legacy->effective_until->toIso8601ZuluString(), $successor->effective_until->toIso8601ZuluString());
        $this->assertSame(CanonicalJson::hash($legacy->structured_terms['usage']), CanonicalJson::hash($successor->structured_terms['usage']));
        $changed = $successor->structured_terms;
        $changed['territory'] = ['mode' => 'worldwide'];
        $changed['duration'] = ['mode' => 'perpetual', 'starts_at' => 'grant'];
        Livewire::test(ManageLicenseVersions::class)->callTableAction('edit', $successor, data: ['structured_terms' => $changed])->assertHasNoTableActionErrors();
        $this->assertSame(['mode' => 'worldwide'], $successor->fresh()->structured_terms['territory']);
        $this->assertSame(CanonicalJson::hash(['mode' => 'perpetual', 'starts_at' => 'grant']), CanonicalJson::hash($successor->fresh()->structured_terms['duration']));
        $this->assertSame($before, $legacy->fresh()->getAttributes());
    }

    public function test_new_admin_drafts_use_v3_coerce_only_whole_months_and_revisions_retain_the_schema(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $template = LicenseTemplate::create(['name' => 'SYNTHETIC SCOPE ADMIN', 'slug' => 'scope-admin', 'type' => 'non-exclusive']);
        Livewire::test(ManageLicenseVersions::class)->mountAction('create')
            ->assertSet('mountedActions.0.data.structured_terms.schema_version', 4)
            ->assertSet('mountedActions.0.data.structured_terms.territory.mode', null)
            ->assertSet('mountedActions.0.data.structured_terms.duration.mode', null)
            ->assertSet('mountedActions.0.data.structured_terms.duration.starts_at', 'grant');
        $terms = ScopedLicenseFixtures::terms();
        $terms['duration']['months'] = '120';
        $data = ['license_template_id' => $template->id, 'authored_source' => ScopedLicenseFixtures::source(), 'structured_terms' => $terms];
        Livewire::test(ManageLicenseVersions::class)->callAction('create', data: $data)->assertHasNoActionErrors();
        $draft = LicenseVersion::sole();
        $this->assertSame(3, $draft->structured_terms['schema_version']);
        $this->assertSame(120, $draft->structured_terms['duration']['months']);
        $invalid = $draft->structured_terms;
        $invalid['duration']['months'] = '1.5';
        Livewire::test(ManageLicenseVersions::class)->callTableAction('edit', $draft, data: ['structured_terms' => $invalid])->assertHasTableActionErrors();
        $this->assertSame(120, $draft->fresh()->structured_terms['duration']['months']);
        $published = $this->publish($draft->fresh(), $actor);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('successor', $published)->assertHasNoTableActionErrors();
        $successor = LicenseVersion::where('predecessor_id', $published->id)->sole();
        $this->assertSame(3, $successor->structured_terms['schema_version']);
        $this->assertSame(CanonicalJson::hash($published->structured_terms), CanonicalJson::hash($successor->structured_terms));
        $this->assertSame($published->authored_source, $successor->authored_source);
    }

    public function test_scope_mapping_rechecks_staff_authorization_when_the_mounted_actor_changes(): void
    {
        $actor = LicenseFixtures::admin();
        $legacy = LicenseFixtures::published($actor, terms: TypedLicenseFixtures::terms(), content: ['authored_source' => TypedLicenseFixtures::source()]);
        $this->actingAs($actor);
        $form = Livewire::test(ManageLicenseVersions::class)->mountTableAction('scoped_successor', $legacy);
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $form->callMountedTableAction()->assertForbidden();
        $this->assertDatabaseCount('license_versions', 1);
        $this->expectException(AuthorizationException::class);
        app(CreateLicenseDraft::class)->handle($legacy->template, ['authored_source' => ScopedLicenseFixtures::source(), 'structured_terms' => ScopedLicenseFixtures::terms()], $customer, $legacy);
    }

    public function test_availability_window_is_independent_of_licensed_use_duration(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($actor, ScopedLicenseFixtures::terms(), [
            'authored_source' => ScopedLicenseFixtures::source(), 'effective_from' => '2026-09-11T00:00:00Z', 'effective_until' => '2026-09-12T00:00:00Z',
        ]);
        $published = $this->publish($draft, $actor);
        $before = $published->fresh()->getAttributes();
        $verification = app(VerifiedLicense::class);
        $this->assertFalse($verification->available($published, CarbonImmutable::parse('2026-09-10T23:59:59Z')));
        $this->assertTrue($verification->available($published, CarbonImmutable::parse('2026-09-11T00:00:00Z')));
        $this->assertTrue($verification->available($published, CarbonImmutable::parse('2026-09-11T23:59:59Z')));
        $this->assertFalse($verification->available($published, CarbonImmutable::parse('2026-09-12T00:00:00Z')));
        $this->assertSame($before, $published->fresh()->getAttributes());
        $this->assertSame(CanonicalJson::hash(ScopedLicenseFixtures::terms()['duration']), CanonicalJson::hash($published->fresh()->structured_terms['duration']));
        $this->assertContains('License duration: 120 calendar months from the grant timestamp (UTC; end-of-month clamping).', $published->features());
        $this->assertArrayNotHasKey('grant_ends_at', $published->submission_payload);
    }

    public function test_scope_changes_preserve_v1_v2_and_v3_offer_and_quote_snapshots(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $offer = $fixture['offer'];
        $items = $fixture['items'];
        $retained = [];
        $versions = [
            LicenseVersion::findOrFail($items[0]['licenseVersionId']),
            LicenseFixtures::published($actor, terms: TypedLicenseFixtures::terms(), content: ['authored_source' => TypedLicenseFixtures::source()]),
            $this->publish(ScopedLicenseFixtures::draft($actor), $actor),
        ];
        foreach ($versions as $version) {
            app(SaveOfferDraft::class)->handle($offer, ['license_version_id' => $version->id], $actor);
            $revision = app(PublishOffer::class)->handle($offer, $actor);
            $items = [['trackId' => $fixture['track']->id, 'offerId' => $offer->id, 'offerRevisionId' => $revision->id, 'licenseVersionId' => $version->id]];
            $quote = app(CreateQuote::class)->handle(str_repeat('a', 64), 'scope-history-'.$version->terms_schema_version, $items);
            $retained[] = [$version, $version->fresh()->getAttributes(), $version->reviewEvidence()->sole()->getAttributes(), $revision, $revision->fresh()->snapshot, $quote, $quote->fresh()->snapshot];
        }
        $scoped = $versions[2];
        $this->getJson('/api/catalog')->assertJsonPath('licenseTiers.0.features', $scoped->features());
        $snapshot = $retained[2][6]['lines'][0]['offer_snapshot']['license'];
        $this->assertSame(CanonicalJson::hash($scoped->structured_terms), CanonicalJson::hash($snapshot['structured_terms']));
        $this->assertSame($scoped->features(), $snapshot['features']);
        $changed = $scoped->structured_terms;
        $changed['territory'] = ['mode' => 'worldwide'];
        $changed['duration']['months'] = 12;
        $successor = app(CreateLicenseDraft::class)->handle($scoped->template, ['authored_source' => ScopedLicenseFixtures::source(), 'structured_terms' => $changed], $actor, $scoped);
        $newer = $this->publish($successor, $actor);
        $this->assertNotSame($scoped->model_hash, $newer->model_hash);
        $this->assertNotSame($scoped->render_fixture_hash, $newer->render_fixture_hash);
        app(SaveOfferDraft::class)->handle($offer, ['license_version_id' => $newer->id], $actor);
        app(PublishOffer::class)->handle($offer, $actor);
        foreach ($retained as [$version, $attributes, $evidence, $revision, $offerSnapshot, $quote, $quoteSnapshot]) {
            $this->assertSame($attributes, $version->fresh()->getAttributes());
            $this->assertSame($evidence, $version->reviewEvidence()->sole()->getAttributes());
            $this->assertSame($offerSnapshot, $revision->fresh()->snapshot);
            $this->assertSame($quoteSnapshot, $quote->fresh()->snapshot);
            $this->assertTrue(app(VerifiedLicense::class)->available($version));
        }
    }
}
