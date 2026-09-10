<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\LicenseDiff;
use App\Domain\Rights\LicensePreview;
use App\Domain\Rights\LicenseTerms;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\TypedLicensePreview;
use App\Domain\Rights\UpdateLicenseDraft;
use App\Domain\Rights\VerifiedLicense;
use App\Filament\Resources\LicenseVersionResource\Pages\ManageLicenseVersions;
use App\Models\User;
use App\Support\CanonicalJson;
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
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

class TypedLicenseTest extends TestCase
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
        $approved = app(ReviewLicense::class)->approve($submitted, LicenseFixtures::admin(), ['approval_reference' => 'SYNTHETIC TYPED REVIEW', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);

        return app(PublishLicense::class)->handle($approved, $author);
    }

    public function test_typed_review_pins_generated_summary_source_and_renderer_with_immutable_evidence(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = TypedLicenseFixtures::draft($actor);
        $preview = app(LicensePreview::class)->render($draft);
        $this->assertSame($preview, app(LicensePreview::class)->render($draft->fresh()));
        $this->assertSame(TypedLicensePreview::VERSION, $preview['renderer_version']);
        foreach ($draft->features() as $feature) {
            $this->assertStringContainsString(htmlspecialchars($feature, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $preview['html']);
        }
        $published = $this->publish($draft, $actor);
        $this->assertSame(2, $published->terms_schema_version);
        $this->assertSame($preview['sha256'], $published->render_fixture_hash);
        $this->assertSame(CanonicalJson::hash($published->structured_terms), $published->model_hash);
        $this->assertTrue(app(VerifiedLicense::class)->available($published));
        $this->assertArrayNotHasKey('features', $published->structured_terms);
        $before = $published->fresh()->getAttributes();
        foreach ([['terms_schema_version' => 1], ['renderer_version' => LicensePreview::VERSION], ['structured_terms' => ['schema_version' => 2]], ['authored_source' => 'changed']] as $change) {
            try {
                $published->fresh()->update($change);
                $this->fail('Model rewrote published typed evidence.');
            } catch (ValidationException) {
            }
            if (is_array($change['structured_terms'] ?? null)) {
                $change['structured_terms'] = json_encode($change['structured_terms']);
            }
            try {
                DB::table('license_versions')->where('id', $published->id)->update($change);
                $this->fail('SQL rewrote published typed evidence.');
            } catch (QueryException) {
            }
        }
        $this->assertSame($before, $published->fresh()->getAttributes());
    }

    public function test_historical_v1_preview_bytes_and_review_hashes_are_preserved_under_current_schema(): void
    {
        $actor = LicenseFixtures::admin();
        $template = LicenseTemplate::create(['name' => 'NONBINDING LEGACY FIXTURE', 'slug' => 'legacy-review-fixture', 'type' => 'non-exclusive']);
        $draft = app(CreateLicenseDraft::class)->handle($template, ['authored_source' => 'SYNTHETIC LEGACY SOURCE {{buyer}}', 'structured_terms' => ['schema_version' => 1, 'features' => ['Synthetic WAV'], 'required_asset_roles' => ['master_wav']]], $actor);
        $golden = file_get_contents(base_path('tests/Fixtures/license-review-v1.html'));
        $this->assertSame($golden, app(LicensePreview::class)->render($draft)['html']);
        $published = $this->publish($draft, $actor);
        $before = $published->fresh()->getAttributes();
        $this->publish(TypedLicenseFixtures::draft($actor), $actor);
        $this->assertSame(2, LicenseTerms::SCHEMA_VERSION);
        $this->assertSame(1, $published->terms_schema_version);
        $this->assertSame(hash('sha256', $golden), $published->render_fixture_hash);
        $this->assertSame($before, $published->fresh()->getAttributes());
        $this->assertTrue(app(VerifiedLicense::class)->available($published));
    }

    public function test_v2_preview_escapes_source_and_credit_and_keeps_the_existing_private_route_policy(): void
    {
        $actor = LicenseFixtures::admin();
        $terms = TypedLicenseFixtures::terms();
        $terms['credit']['text'] = '<img src=x onerror=alert(1)> & SYNTHETIC';
        $draft = LicenseFixtures::draft($actor, $terms, ['authored_source' => '<script>alert(1)</script>'.TypedLicenseFixtures::source()]);
        $url = route('filament.admin.licenses.preview', $draft);
        $this->get($url)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($actor)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'")
            ->assertSee('&lt;script&gt;', false)->assertSee('&lt;img', false)->assertDontSee('<script>', false)->assertDontSee('<img', false)
            ->assertSee('Source with terms substituted')->assertSee('Generated license-card summaries');
    }

    public function test_missing_source_coverage_and_unreviewed_policy_changes_cannot_reach_publication(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = TypedLicenseFixtures::draft($actor);
        DB::table('license_versions')->where('id', $draft->id)->update(['authored_source' => 'Incomplete synthetic draft']);
        try {
            app(ReviewLicense::class)->submit($draft, $actor);
            $this->fail('Incomplete variables reached review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authored_source', $exception->errors());
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->submission_hash);
        $draft = app(UpdateLicenseDraft::class)->handle($draft, ['authored_source' => TypedLicenseFixtures::source(), 'structured_terms' => TypedLicenseFixtures::terms()], $actor);
        $submitted = app(ReviewLicense::class)->submit($draft, $actor);
        foreach ([[$actor, $submitted->submission_hash], [LicenseFixtures::admin(), str_repeat('a', 64)]] as [$reviewer, $hash]) {
            try {
                app(ReviewLicense::class)->approve($submitted, $reviewer, ['approval_reference' => 'SYNTHETIC', 'review_hash' => $hash, 'summary_consistency_confirmed' => true]);
                $this->fail('Invalid typed review was accepted.');
            } catch (ValidationException) {
            }
        }
        $this->assertDatabaseCount('license_review_evidence', 0);
        try {
            app(PublishLicense::class)->handle($submitted, $actor);
            $this->fail('Typed license published without independent review.');
        } catch (ValidationException) {
        }
        $this->assertFalse(app(VerifiedLicense::class)->available($submitted));
    }

    public function test_database_rejects_mismatched_and_unknown_schema_renderer_pairs(): void
    {
        $actor = LicenseFixtures::admin();
        $submitted = app(ReviewLicense::class)->submit(TypedLicenseFixtures::draft($actor), $actor);
        $proof = Arr::only($submitted->getAttributes(), ['status', 'submission_payload', 'submission_hash', 'canonicalization_version', 'terms_schema_version', 'submitted_by', 'submitted_at', 'source_hash', 'model_hash', 'renderer_version', 'render_fixture_hash']);
        foreach ([[1, TypedLicensePreview::VERSION], [2, LicensePreview::VERSION], [3, TypedLicensePreview::VERSION], [null, TypedLicensePreview::VERSION]] as [$schema, $renderer]) {
            $draft = TypedLicenseFixtures::draft($actor);
            try {
                DB::table('license_versions')->where('id', $draft->id)->update(array_replace($proof, ['terms_schema_version' => $schema, 'renderer_version' => $renderer]));
                $this->fail('SQL accepted an unsupported schema/renderer pair.');
            } catch (QueryException) {
                $this->assertSame('draft', $draft->fresh()->status);
            }
        }
    }

    public function test_operator_editor_saves_integer_caps_reports_source_errors_and_retains_schema_in_successors(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $template = LicenseTemplate::create(['name' => 'SYNTHETIC ADMIN', 'slug' => 'typed-admin', 'type' => 'non-exclusive']);
        $terms = TypedLicenseFixtures::terms();
        $terms['usage']['audio_releases']['limit'] = '2';
        $data = ['license_template_id' => $template->id, 'authored_source' => TypedLicenseFixtures::source(), 'structured_terms' => $terms];
        Livewire::test(ManageLicenseVersions::class)->callAction('create', data: array_replace($data, ['authored_source' => 'Missing variables']))->assertHasActionErrors(['authored_source']);
        $this->assertDatabaseCount('license_versions', 0);
        Livewire::test(ManageLicenseVersions::class)->callAction('create', data: $data)->assertHasNoActionErrors();
        $draft = LicenseVersion::sole();
        $this->assertSame(2, $draft->structured_terms['schema_version']);
        $this->assertSame(2, $draft->structured_terms['usage']['audio_releases']['limit']);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('edit', $draft, data: ['authored_source' => 'Missing variables'])->assertHasTableActionErrors(['authored_source']);
        $this->assertSame(TypedLicenseFixtures::source(), $draft->fresh()->authored_source);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('edit', $draft, data: ['structured_terms.usage.audio_releases.limit' => '1.5'])->assertHasTableActionErrors();
        $changed = $draft->structured_terms;
        $changed['usage']['audio_releases'] = ['mode' => 'unlimited'];
        $changed['credit'] = ['mode' => 'not_required'];
        Livewire::test(ManageLicenseVersions::class)->callTableAction('edit', $draft, data: ['authored_source' => TypedLicenseFixtures::source(), 'structured_terms' => $changed])->assertHasNoTableActionErrors();
        $this->assertSame(['mode' => 'unlimited'], $draft->fresh()->structured_terms['usage']['audio_releases']);
        $published = $this->publish($draft->fresh(), $actor);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('successor', $published)->assertHasNoTableActionErrors();
        $successor = LicenseVersion::where('predecessor_id', $published->id)->sole();
        $this->assertSame(2, $successor->structured_terms['schema_version']);
        $this->assertSame(CanonicalJson::hash($published->structured_terms), CanonicalJson::hash($successor->structured_terms));
        $this->assertSame([], app(LicenseDiff::class)->between($published, $successor));
    }

    public function test_typed_editor_and_commands_deny_non_staff_and_mounted_actor_changes(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = TypedLicenseFixtures::draft($actor);
        $this->actingAs($actor);
        $form = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft);
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $form->callMountedTableAction()->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(UpdateLicenseDraft::class)->handle($draft, ['authored_source' => TypedLicenseFixtures::source(), 'structured_terms' => TypedLicenseFixtures::terms()], $customer);
    }

    public function test_operator_maps_a_v1_license_into_a_typed_successor_without_changing_prior_evidence(): void
    {
        $actor = LicenseFixtures::admin();
        $legacy = LicenseFixtures::published($actor);
        $before = $legacy->fresh()->getAttributes();
        $this->actingAs($actor);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('typed_successor', $legacy)->assertHasTableActionErrors();
        $this->assertDatabaseCount('license_versions', 1);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('typed_successor', $legacy, data: [
            'authored_source' => TypedLicenseFixtures::source(), 'structured_terms' => TypedLicenseFixtures::terms(),
        ])->assertHasNoTableActionErrors();
        $successor = LicenseVersion::where('predecessor_id', $legacy->id)->sole();
        $this->assertSame(2, $successor->structured_terms['schema_version']);
        $this->assertSame('draft', $successor->status);
        $this->assertNull($successor->submission_hash);
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertTrue(app(VerifiedLicense::class)->available($legacy));
    }

    public function test_new_typed_offers_use_generated_values_while_previous_quote_and_license_snapshots_remain_unchanged(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $offer = $fixture['offer'];
        $oldQuote = app(CreateQuote::class)->handle(str_repeat('a', 64), 'legacy-review', $fixture['items']);
        $oldSnapshot = $oldQuote->fresh()->snapshot;
        $typed = $this->publish(TypedLicenseFixtures::draft($actor), $actor);
        app(SaveOfferDraft::class)->handle($offer, ['license_version_id' => $typed->id], $actor);
        $revision = app(PublishOffer::class)->handle($offer, $actor);
        $features = $typed->features();
        $this->assertSame($features, $revision->snapshot['license']['features']);
        $this->getJson('/api/catalog')->assertJsonPath('licenseTiers.0.features', $features);
        $items = [['trackId' => $fixture['track']->id, 'offerId' => $offer->id, 'offerRevisionId' => $revision->id, 'licenseVersionId' => $typed->id]];
        $typedQuote = app(CreateQuote::class)->handle(str_repeat('a', 64), 'typed-review', $items);
        $typedSnapshot = $typedQuote->fresh()->snapshot;
        $terms = $typed->structured_terms;
        $terms['usage']['audio_releases']['limit'] = 3;
        $successor = app(CreateLicenseDraft::class)->handle($typed->template, ['authored_source' => $typed->authored_source, 'structured_terms' => $terms], $actor, $typed);
        $this->assertSame(['structured_terms'], array_keys(app(LicenseDiff::class)->between($typed, $successor)));
        $newer = $this->publish($successor, $actor);
        $this->assertNotSame($typed->render_fixture_hash, $newer->render_fixture_hash);
        app(SaveOfferDraft::class)->handle($offer, ['license_version_id' => $newer->id], $actor);
        app(PublishOffer::class)->handle($offer, $actor);
        $this->assertSame($oldSnapshot, $oldQuote->fresh()->snapshot);
        $this->assertSame($typedSnapshot, $typedQuote->fresh()->snapshot);
        $this->assertSame(2, $typedQuote->fresh()->snapshot['lines'][0]['offer_snapshot']['license']['structured_terms']['usage']['audio_releases']['limit']);
        $this->assertTrue(app(VerifiedLicense::class)->available($typed));
    }
}
