<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\ReadTrackPublicationManifest;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\EconomicLicensePreview;
use App\Domain\Rights\LicensePreview;
use App\Domain\Rights\LicenseSourceVariables;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\TieredLicensePreview;
use App\Domain\Rights\VerifiedLicense;
use App\Filament\Resources\LicenseVersionResource\Pages\ManageLicenseVersions;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\TieredLicenseFixtures;
use Tests\TestCase;

class TieredLicenseTest extends TestCase
{
    use FinalizationDatabaseMigrations;

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
            'approval_reference' => 'SYNTHETIC TIER REVIEW',
            'review_hash' => $submitted->submission_hash,
            'summary_consistency_confirmed' => true,
        ]);

        return app(PublishLicense::class)->handle($approved, $author);
    }

    public function test_review_binds_every_tier_limit_to_the_v5_preview_and_publication_evidence(): void
    {
        $actor = LicenseFixtures::admin();
        $terms = TieredLicenseFixtures::terms();
        $terms['credit']['text'] = '<img src=x> & SYNTHETIC';
        $draft = LicenseFixtures::draft($actor, $terms, ['authored_source' => '<script>synthetic</script>'.TieredLicenseFixtures::source()]);
        $preview = app(LicensePreview::class)->render($draft);
        $this->assertSame(TieredLicensePreview::VERSION, $preview['renderer_version']);
        $this->assertSame('vasey-license-review-html-v5', $preview['renderer_version']);
        $this->assertSame($preview, app(LicensePreview::class)->render($draft->fresh()));
        $this->assertStringNotContainsString('<script>', $preview['html']);
        $this->assertStringNotContainsString('<img', $preview['html']);
        $this->assertCount(24, $draft->features());
        foreach ($draft->features() as $statement) {
            $this->assertStringContainsString(htmlspecialchars($statement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $preview['html']);
        }
        foreach (TieredLicenseFixtures::USAGE_VARIABLES as $variable) {
            $this->assertStringContainsString('{{'.$variable.'}}', $preview['html']);
        }
        $this->assertStringContainsString('Free downloads: up to 3,000.', $preview['html']);
        $this->assertStringContainsString('For-profit performances: not permitted.', $preview['html']);
        $published = $this->publish($draft, $actor);
        $this->assertSame(5, $published->terms_schema_version);
        $this->assertSame(TieredLicensePreview::VERSION, $published->renderer_version);
        $this->assertSame($preview['sha256'], $published->render_fixture_hash);
        $this->assertSame(CanonicalJson::hash($terms), $published->model_hash);
        $this->assertTrue(app(VerifiedLicense::class)->available($published));
        $before = $published->fresh()->getAttributes();
        $changed = $terms;
        $changed['usage']['free_downloads']['limit'] = 3001;
        foreach ([['structured_terms' => json_encode($changed)], ['renderer_version' => EconomicLicensePreview::VERSION], ['terms_schema_version' => 4], ['status' => 'draft']] as $change) {
            try {
                DB::table('license_versions')->where('id', $published->id)->update($change);
                $this->fail('A published tier limit or its review evidence changed through SQL.');
            } catch (QueryException) {
            }
        }
        $this->assertSame($before, $published->fresh()->getAttributes());
    }

    public function test_historical_v4_preview_and_review_evidence_match_frozen_bytes_after_v5_publication(): void
    {
        $actor = LicenseFixtures::admin();
        $template = LicenseTemplate::create(['name' => 'NONBINDING V4 GOLDEN FIXTURE', 'slug' => 'v4-golden-fixture', 'type' => 'non-exclusive']);
        $terms = EconomicLicenseFixtures::terms();
        $terms['credit']['text'] = '<SYNTHETIC> & PRODUCER';
        $terms['policies'][0]['text'] .= "\n<script>synthetic & 'quoted'</script>";
        $draft = app(CreateLicenseDraft::class)->handle($template, [
            'authored_source' => '<script>synthetic</script>'.EconomicLicenseFixtures::source(), 'structured_terms' => $terms,
            'effective_from' => '2026-09-01T00:00:00Z', 'effective_until' => '2027-09-01T00:00:00Z',
        ], $actor);
        // Static expected bytes rendered by origin/main f578e10c before schema 5 existed.
        $golden = file_get_contents(base_path('tests/Fixtures/license-review-v4.html'));
        $this->assertSame($golden, app(LicensePreview::class)->render($draft)['html']);
        $legacy = $this->publish($draft, $actor);
        $attributes = $legacy->fresh()->getAttributes();
        $evidence = $legacy->reviewEvidence()->sole()->getAttributes();
        $features = $legacy->features();
        $this->publish(TieredLicenseFixtures::draft($actor), $actor);
        $this->assertSame($golden, app(LicensePreview::class)->render($legacy->fresh())['html']);
        $this->assertSame(hash('sha256', $golden), $legacy->render_fixture_hash);
        $this->assertSame(EconomicLicensePreview::VERSION, $legacy->renderer_version);
        $this->assertSame($features, $legacy->fresh()->features());
        $this->assertSame($attributes, $legacy->fresh()->getAttributes());
        $this->assertSame($evidence, $legacy->reviewEvidence()->sole()->getAttributes());
        $this->assertTrue(app(VerifiedLicense::class)->available($legacy, CarbonImmutable::parse('2026-09-12T00:00:00Z')));
    }

    public function test_database_rejects_every_cross_version_v5_renderer_pair(): void
    {
        $actor = LicenseFixtures::admin();
        $submitted = app(ReviewLicense::class)->submit(TieredLicenseFixtures::draft($actor), $actor);
        $this->assertSame(5, $submitted->terms_schema_version);
        $proof = Arr::only($submitted->getAttributes(), ['status', 'submission_payload', 'submission_hash', 'canonicalization_version', 'terms_schema_version', 'submitted_by', 'submitted_at', 'source_hash', 'model_hash', 'renderer_version', 'render_fixture_hash']);
        $invalid = [[5, EconomicLicensePreview::VERSION], [4, TieredLicensePreview::VERSION], [6, TieredLicensePreview::VERSION],
            [null, TieredLicensePreview::VERSION], [5, null], [5, 'vasey-license-review-html-v6'], [1, TieredLicensePreview::VERSION]];
        foreach ($invalid as [$schema, $renderer]) {
            $draft = TieredLicenseFixtures::draft($actor);
            try {
                DB::table('license_versions')->where('id', $draft->id)->update(array_replace($proof, ['terms_schema_version' => $schema, 'renderer_version' => $renderer]));
                $this->fail('SQL accepted an unknown or mismatched v5 renderer.');
            } catch (QueryException) {
                $this->assertSame('draft', $draft->fresh()->status);
            }
        }
    }

    public function test_missing_tier_variables_and_retired_usage_variables_cannot_reach_review(): void
    {
        $actor = LicenseFixtures::admin();
        $sources = array_map(fn ($key) => str_replace('{{'.$key.'}}', '', TieredLicenseFixtures::source()), [...TieredLicenseFixtures::USAGE_VARIABLES, 'policy_texts']);
        $sources[] = TieredLicenseFixtures::source()."\n{{usage.live_performances}}";
        $sources[] = EconomicLicenseFixtures::source();
        foreach ($sources as $source) {
            $draft = TieredLicenseFixtures::draft($actor);
            DB::table('license_versions')->where('id', $draft->id)->update(['authored_source' => $source]);
            try {
                app(ReviewLicense::class)->submit($draft, $actor);
                $this->fail('An incomplete or stale v5 source reached review.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('authored_source', $exception->errors());
            }
            $this->assertSame('draft', $draft->fresh()->status);
            $this->assertNull($draft->fresh()->submission_hash);
        }
        $this->assertDatabaseCount('license_review_evidence', 0);
    }

    public function test_operator_maps_v4_to_v5_copying_only_unchanged_declarations(): void
    {
        $actor = LicenseFixtures::admin();
        $legacy = LicenseFixtures::published($actor, terms: EconomicLicenseFixtures::terms(), content: [
            'authored_source' => EconomicLicenseFixtures::source(), 'effective_from' => '2026-09-01T00:00:00Z', 'effective_until' => '2027-09-01T00:00:00Z',
        ]);
        $before = $legacy->fresh()->getAttributes();
        $this->actingAs($actor);
        $form = Livewire::test(ManageLicenseVersions::class)->mountTableAction('tiered_successor', $legacy)
            ->assertSet('mountedActions.0.data.structured_terms.schema_version', 5)
            ->assertSet('mountedActions.0.data.authored_source', EconomicLicenseFixtures::source());
        foreach (['audio_releases', 'copies_downloads', 'radio_stations'] as $copied) {
            $form->assertSet('mountedActions.0.data.structured_terms.usage.'.$copied.'.mode', $legacy->structured_terms['usage'][$copied]['mode'])
                ->assertSet('mountedActions.0.data.structured_terms.usage.'.$copied.'.limit', $legacy->structured_terms['usage'][$copied]['limit'] ?? null);
        }
        // No split, narrowed or new tier limit is inferred from the combined v4 usage.
        foreach (['audio_streams', 'monetized_video_streams', 'non_monetized_video_streams', 'music_videos', 'free_downloads', 'for_profit_performances', 'not_for_profit_performances'] as $chosen) {
            $form->assertSet('mountedActions.0.data.structured_terms.usage.'.$chosen.'.mode', null);
        }
        foreach (['monetized_streams', 'non_monetized_streams', 'live_performances'] as $retired) {
            $form->assertSet('mountedActions.0.data.structured_terms.usage.'.$retired.'.mode', null);
        }
        Livewire::test(ManageLicenseVersions::class)->callTableAction('tiered_successor', $legacy)->assertHasTableActionErrors();
        Livewire::test(ManageLicenseVersions::class)->callTableAction('tiered_successor', $legacy, data: ['structured_terms' => TieredLicenseFixtures::terms()])->assertHasTableActionErrors(['authored_source']);
        $this->assertDatabaseCount('license_versions', 1);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('tiered_successor', $legacy, data: [
            'authored_source' => TieredLicenseFixtures::source(), 'structured_terms' => TieredLicenseFixtures::terms(),
        ])->assertHasNoTableActionErrors();
        $successor = LicenseVersion::where('predecessor_id', $legacy->id)->sole();
        $this->assertSame(5, $successor->structured_terms['schema_version']);
        $this->assertSame('draft', $successor->status);
        $this->assertNull($successor->submission_hash);
        $this->assertSame(CanonicalJson::hash(TieredLicenseFixtures::terms()), CanonicalJson::hash($successor->structured_terms));
        $this->assertSame($legacy->effective_from->toIso8601ZuluString(), $successor->effective_from->toIso8601ZuluString());
        $this->assertSame($legacy->effective_until->toIso8601ZuluString(), $successor->effective_until->toIso8601ZuluString());
        foreach (['required_asset_roles', 'permissions', 'credit', 'territory', 'duration', 'ownership', 'publishing_income', 'recording_royalty', 'policies'] as $key) {
            $this->assertSame(CanonicalJson::hash($legacy->structured_terms[$key]), CanonicalJson::hash($successor->structured_terms[$key]));
        }
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        // Only v4 versions offer the mapping; v5 revisions use the ordinary successor and keep the schema.
        $published = $this->publish($successor->fresh(), $actor);
        Livewire::test(ManageLicenseVersions::class)->assertTableActionHidden('tiered_successor', $published)->assertTableActionVisible('tiered_successor', $legacy);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('successor', $published)->assertHasNoTableActionErrors();
        $revision = LicenseVersion::where('predecessor_id', $published->id)->sole();
        $this->assertSame(CanonicalJson::hash($published->structured_terms), CanonicalJson::hash($revision->structured_terms));
    }

    public function test_new_admin_drafts_author_v5_coerce_integer_limits_and_drop_stale_v4_usage(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $template = LicenseTemplate::create(['name' => 'SYNTHETIC TIER ADMIN', 'slug' => 'tier-admin', 'type' => 'non-exclusive']);
        $form = Livewire::test(ManageLicenseVersions::class)->mountAction('create')
            ->assertSet('mountedActions.0.data.structured_terms.schema_version', 5)
            ->assertSet('mountedActions.0.data.structured_terms.publishing_income.mode', null);
        foreach (TieredLicenseFixtures::USAGE_VARIABLES as $variable) {
            $form->assertSet('mountedActions.0.data.structured_terms.'.$variable.'.mode', null)
                ->assertMountedActionModalSee('Source variable: {{'.$variable.'}}');
        }
        $form->assertMountedActionModalSee('Monetized music videos')->assertMountedActionModalDontSee('Live performances')
            ->assertMountedActionModalDontSee('{{usage.monetized_streams}}');
        $terms = TieredLicenseFixtures::terms();
        $terms['usage']['free_downloads']['limit'] = '3000';
        // A stale v4 value in the submitted state must not be saved into a v5 version.
        $terms['usage']['live_performances'] = ['mode' => 'unlimited'];
        Livewire::test(ManageLicenseVersions::class)->callAction('create', data: [
            'license_template_id' => $template->id, 'authored_source' => TieredLicenseFixtures::source(), 'structured_terms' => $terms,
        ])->assertHasNoActionErrors();
        $draft = LicenseVersion::sole();
        $this->assertSame(CanonicalJson::hash(TieredLicenseFixtures::terms()), CanonicalJson::hash($draft->structured_terms));
        $this->assertSame(3000, $draft->structured_terms['usage']['free_downloads']['limit']);
        $invalid = $draft->structured_terms;
        $invalid['usage']['audio_streams']['limit'] = '1.5';
        Livewire::test(ManageLicenseVersions::class)->callTableAction('edit', $draft, data: ['structured_terms' => $invalid])->assertHasTableActionErrors();
        $unset = $draft->structured_terms;
        $unset['usage']['for_profit_performances'] = ['mode' => null];
        Livewire::test(ManageLicenseVersions::class)->callTableAction('edit', $draft, data: ['structured_terms' => $unset])->assertHasTableActionErrors();
        $this->assertSame(CanonicalJson::hash(TieredLicenseFixtures::terms()), CanonicalJson::hash($draft->fresh()->structured_terms));
    }

    public function test_tier_mapping_rechecks_staff_authorization_after_mounting(): void
    {
        $actor = LicenseFixtures::admin();
        $legacy = LicenseFixtures::published($actor, terms: EconomicLicenseFixtures::terms(), content: ['authored_source' => EconomicLicenseFixtures::source()]);
        $this->actingAs($actor);
        $form = Livewire::test(ManageLicenseVersions::class)->mountTableAction('tiered_successor', $legacy);
        $this->actingAs(User::factory()->create());
        $form->callMountedTableAction()->assertForbidden();
        $this->assertDatabaseCount('license_versions', 1);
        $this->expectException(AuthorizationException::class);
        app(CreateLicenseDraft::class)->handle($legacy->template, ['authored_source' => TieredLicenseFixtures::source(), 'structured_terms' => TieredLicenseFixtures::terms()], User::factory()->create(), $legacy);
    }

    public function test_v5_offer_reaches_catalog_quote_disclosure_and_publication_manifest_unchanged(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $offer = $fixture['offer'];
        $earlier = $fixture['revision'];
        $earlierSnapshot = $earlier->fresh()->snapshot;
        $tiered = $this->publish(TieredLicenseFixtures::draft($actor), $actor);
        app(SaveOfferDraft::class)->handle($offer, ['license_version_id' => $tiered->id], $actor);
        $revision = app(PublishOffer::class)->handle($offer, $actor);
        $license = $revision->snapshot['license'];
        $this->assertSame(CanonicalJson::hash($tiered->structured_terms), CanonicalJson::hash($license['structured_terms']));
        $this->assertSame($tiered->features(), $license['features']);
        $this->assertSame([], app(PublicationReadiness::class)->blockers($fixture['track']->fresh()));
        $manifest = app(ReadTrackPublicationManifest::class)->handle($fixture['track']->id, $actor)->payload();
        $this->assertStringContainsString($tiered->model_hash, json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString(TieredLicensePreview::VERSION, json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->getJson('/api/catalog')->assertOk()->assertJsonPath('licenseTiers.0.features', $tiered->features());
        $items = [['trackId' => $fixture['track']->id, 'offerId' => $offer->id, 'offerRevisionId' => $revision->id, 'licenseVersionId' => $tiered->id]];
        $quote = app(CreateQuote::class)->handle(str_repeat('b', 64), 'tier-history-v5', $items);
        $this->assertSame(CanonicalJson::hash($license), CanonicalJson::hash($quote->snapshot['lines'][0]['offer_snapshot']['license']));
        $terms = $this->getJson('/tracks/'.$fixture['track']->slug.'/offers/'.$revision->id.'/license')->assertOk()->json('termsText');
        $this->assertSame(app(LicenseSourceVariables::class)->render($tiered->authored_source, $tiered->structured_terms), $terms);
        foreach ($tiered->features() as $statement) {
            $this->assertStringContainsString($statement, $terms);
        }
        $this->assertSame($earlierSnapshot, $earlier->fresh()->snapshot);
    }
}
