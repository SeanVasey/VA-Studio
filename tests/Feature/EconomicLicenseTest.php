<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\EconomicLicensePreview;
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
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\ScopedLicenseFixtures;
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

class EconomicLicenseTest extends TestCase
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
            'approval_reference' => 'SYNTHETIC ECONOMIC REVIEW',
            'review_hash' => $submitted->submission_hash,
            'summary_consistency_confirmed' => true,
        ]);

        return app(PublishLicense::class)->handle($approved, $author);
    }

    public function test_review_retains_exact_policy_text_hashes_and_economic_fields_with_escaped_output(): void
    {
        $actor = LicenseFixtures::admin();
        $terms = EconomicLicenseFixtures::terms();
        $terms['policies'][0]['text'] .= "\n<script>synthetic & 'quoted'</script>";
        $draft = LicenseFixtures::draft($actor, $terms, ['authored_source' => EconomicLicenseFixtures::source()]);
        $preview = app(LicensePreview::class)->render($draft);
        $policy = $terms['policies'][0];
        $this->assertSame(EconomicLicensePreview::VERSION, $preview['renderer_version']);
        $this->assertSame($preview, app(LicensePreview::class)->render($draft->fresh()));
        $this->assertStringContainsString(htmlspecialchars($policy['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $preview['html']);
        $this->assertStringContainsString(hash('sha256', $policy['text']), $preview['html']);
        $this->assertStringNotContainsString('<script>', $preview['html']);
        $this->assertStringContainsString('12.34%', $preview['html']);
        $this->assertStringContainsString('5.67%', $preview['html']);
        foreach ($draft->features() as $statement) {
            $this->assertStringContainsString(htmlspecialchars($statement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $preview['html']);
            $this->assertStringNotContainsString($policy['text'], $statement);
        }
        $published = $this->publish($draft, $actor);
        $this->assertSame(4, $published->terms_schema_version);
        $this->assertSame($preview['sha256'], $published->render_fixture_hash);
        $this->assertSame(CanonicalJson::hash($terms), $published->model_hash);
        $this->assertSame(hash('sha256', $published->authored_source), $published->source_hash);
        $this->assertSame($policy['text'], $published->submission_payload['structured_terms']['policies'][0]['text']);
        $this->assertTrue(app(VerifiedLicense::class)->available($published));
        $before = $published->fresh()->getAttributes();
        $evidence = $published->reviewEvidence()->sole()->getAttributes();
        $changed = $terms;
        $changed['policies'][0]['text'] = 'SYNTHETIC altered policy';
        foreach ([['structured_terms' => json_encode($changed)], ['renderer_version' => ScopedLicensePreview::VERSION], ['terms_schema_version' => 3], ['status' => 'draft']] as $change) {
            try {
                DB::table('license_versions')->where('id', $published->id)->update($change);
                $this->fail('Published economic policy or its review evidence changed through SQL.');
            } catch (QueryException) {
            }
        }
        $this->assertSame($before, $published->fresh()->getAttributes());
        $this->assertSame($evidence, $published->reviewEvidence()->sole()->getAttributes());
    }

    public function test_historical_v3_preview_matches_frozen_bytes_after_v4_publication(): void
    {
        $actor = LicenseFixtures::admin();
        $template = LicenseTemplate::create(['name' => 'NONBINDING V3 GOLDEN FIXTURE', 'slug' => 'v3-golden-fixture', 'type' => 'non-exclusive']);
        $terms = ScopedLicenseFixtures::terms();
        $terms['credit']['text'] = '<SYNTHETIC> & PRODUCER';
        $draft = app(CreateLicenseDraft::class)->handle($template, [
            'authored_source' => '<script>synthetic</script>'.ScopedLicenseFixtures::source(),
            'structured_terms' => $terms,
            'effective_from' => '2026-09-01T00:00:00Z', 'effective_until' => '2027-09-01T00:00:00Z',
        ], $actor);
        // Static expected bytes assembled from the merged PR #33 renderer before v4.
        $golden = file_get_contents(base_path('tests/Fixtures/license-review-v3.html'));
        $this->assertSame($golden, app(LicensePreview::class)->render($draft)['html']);
        $legacy = $this->publish($draft, $actor);
        $attributes = $legacy->fresh()->getAttributes();
        $evidence = $legacy->reviewEvidence()->sole()->getAttributes();
        $this->publish(EconomicLicenseFixtures::draft($actor), $actor);
        $this->assertSame($golden, app(LicensePreview::class)->render($legacy->fresh())['html']);
        $this->assertSame(hash('sha256', $golden), $legacy->render_fixture_hash);
        $this->assertSame($attributes, $legacy->fresh()->getAttributes());
        $this->assertSame($evidence, $legacy->reviewEvidence()->sole()->getAttributes());
        $this->assertTrue(app(VerifiedLicense::class)->available($legacy, CarbonImmutable::parse('2026-09-12T00:00:00Z')));
    }

    public function test_database_rejects_every_cross_version_economic_renderer_pair(): void
    {
        $actor = LicenseFixtures::admin();
        $submitted = app(ReviewLicense::class)->submit(EconomicLicenseFixtures::draft($actor), $actor);
        $proof = Arr::only($submitted->getAttributes(), ['status', 'submission_payload', 'submission_hash', 'canonicalization_version', 'terms_schema_version', 'submitted_by', 'submitted_at', 'source_hash', 'model_hash', 'renderer_version', 'render_fixture_hash']);
        $renderers = [1 => LicensePreview::VERSION, 2 => TypedLicensePreview::VERSION, 3 => ScopedLicensePreview::VERSION, 4 => EconomicLicensePreview::VERSION];
        $invalid = [[5, EconomicLicensePreview::VERSION], [null, EconomicLicensePreview::VERSION], [4, null]];
        foreach ($renderers as $schema => $matching) {
            foreach ($renderers as $renderer) {
                if ($renderer !== $matching) {
                    $invalid[] = [$schema, $renderer];
                }
            }
        }
        foreach ($invalid as [$schema, $renderer]) {
            $draft = EconomicLicenseFixtures::draft($actor);
            try {
                DB::table('license_versions')->where('id', $draft->id)->update(array_replace($proof, ['terms_schema_version' => $schema, 'renderer_version' => $renderer]));
                $this->fail('SQL accepted an unknown or mismatched economic renderer.');
            } catch (QueryException) {
                $this->assertSame('draft', $draft->fresh()->status);
            }
        }
    }

    public function test_missing_economic_variables_and_dangling_policy_references_cannot_reach_review(): void
    {
        $actor = LicenseFixtures::admin();
        foreach (['ownership.source_recording', 'ownership.source_composition', 'ownership.resulting_recording', 'ownership.resulting_composition', 'publishing_income', 'recording_royalty', 'policy_texts'] as $key) {
            $draft = EconomicLicenseFixtures::draft($actor);
            DB::table('license_versions')->where('id', $draft->id)->update(['authored_source' => str_replace('{{'.$key.'}}', '', EconomicLicenseFixtures::source())]);
            try {
                app(ReviewLicense::class)->submit($draft, $actor);
                $this->fail('A missing economic source variable reached review.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('authored_source', $exception->errors());
            }
            $this->assertSame('draft', $draft->fresh()->status);
            $this->assertNull($draft->fresh()->submission_hash);
        }
        $draft = EconomicLicenseFixtures::draft($actor);
        $terms = $draft->structured_terms;
        $terms['ownership']['source_recording']['policy_key'] = 'missing-policy';
        DB::table('license_versions')->where('id', $draft->id)->update(['structured_terms' => json_encode($terms)]);
        try {
            app(ReviewLicense::class)->submit($draft, $actor);
            $this->fail('A dangling economic policy reference reached review.');
        } catch (ValidationException) {
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->submission_hash);
        $this->assertDatabaseCount('license_review_evidence', 0);
    }

    public function test_operator_deliberately_maps_v3_to_v4_and_mode_changes_remove_stale_rates(): void
    {
        $actor = LicenseFixtures::admin();
        $legacy = LicenseFixtures::published($actor, terms: ScopedLicenseFixtures::terms(), content: [
            'authored_source' => ScopedLicenseFixtures::source(), 'effective_from' => '2026-09-01T00:00:00Z', 'effective_until' => '2027-09-01T00:00:00Z',
        ]);
        $before = $legacy->fresh()->getAttributes();
        $this->actingAs($actor);
        Livewire::test(ManageLicenseVersions::class)->mountTableAction('economic_successor', $legacy)
            ->assertSet('mountedActions.0.data.structured_terms.schema_version', 4)
            ->assertSet('mountedActions.0.data.structured_terms.ownership.source_recording.policy_key', null)
            ->assertSet('mountedActions.0.data.structured_terms.publishing_income.mode', null)
            ->assertSet('mountedActions.0.data.structured_terms.recording_royalty.mode', null);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('economic_successor', $legacy)->assertHasTableActionErrors();
        Livewire::test(ManageLicenseVersions::class)->callTableAction('economic_successor', $legacy, data: ['structured_terms' => EconomicLicenseFixtures::terms()])->assertHasTableActionErrors();
        $this->assertDatabaseCount('license_versions', 1);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('economic_successor', $legacy, data: [
            'authored_source' => EconomicLicenseFixtures::source(), 'structured_terms' => EconomicLicenseFixtures::terms(),
        ])->assertHasNoTableActionErrors();
        $successor = LicenseVersion::where('predecessor_id', $legacy->id)->sole();
        $this->assertSame(4, $successor->structured_terms['schema_version']);
        $this->assertSame('draft', $successor->status);
        $this->assertNull($successor->submission_hash);
        $this->assertSame($legacy->effective_from->toIso8601ZuluString(), $successor->effective_from->toIso8601ZuluString());
        $this->assertSame($legacy->effective_until->toIso8601ZuluString(), $successor->effective_until->toIso8601ZuluString());
        foreach (['required_asset_roles', 'usage', 'permissions', 'credit', 'territory', 'duration'] as $key) {
            $this->assertSame(CanonicalJson::hash($legacy->structured_terms[$key]), CanonicalJson::hash($successor->structured_terms[$key]));
        }
        $changed = $successor->structured_terms;
        // Deliberately retain stale form values; selecting none must not submit them.
        $changed['publishing_income']['mode'] = 'none';
        $changed['recording_royalty']['mode'] = 'none';
        Livewire::test(ManageLicenseVersions::class)->callTableAction('edit', $successor, data: ['structured_terms' => $changed])->assertHasNoTableActionErrors();
        $this->assertSame(CanonicalJson::hash(['mode' => 'none', 'policy_key' => 'economic-fixture']), CanonicalJson::hash($successor->fresh()->structured_terms['publishing_income']));
        $this->assertSame(CanonicalJson::hash(['mode' => 'none', 'policy_key' => 'economic-fixture']), CanonicalJson::hash($successor->fresh()->structured_terms['recording_royalty']));
        $this->assertSame($before, $legacy->fresh()->getAttributes());
    }

    public function test_current_admin_drafts_require_economic_choices_and_only_coerce_integer_basis_points(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $template = LicenseTemplate::create(['name' => 'SYNTHETIC ECONOMIC ADMIN', 'slug' => 'economic-admin', 'type' => 'non-exclusive']);
        Livewire::test(ManageLicenseVersions::class)->mountAction('create')
            ->assertSet('mountedActions.0.data.structured_terms.schema_version', 4)
            ->assertSet('mountedActions.0.data.structured_terms.ownership.source_recording.policy_key', null)
            ->assertSet('mountedActions.0.data.structured_terms.publishing_income.mode', null)
            ->assertSet('mountedActions.0.data.structured_terms.recording_royalty.mode', null);
        $terms = EconomicLicenseFixtures::terms();
        $terms['publishing_income']['licensor_bps'] = '1234';
        $terms['recording_royalty']['rate_bps'] = '567';
        Livewire::test(ManageLicenseVersions::class)->callAction('create', data: [
            'license_template_id' => $template->id, 'authored_source' => EconomicLicenseFixtures::source(), 'structured_terms' => $terms,
        ])->assertHasNoActionErrors();
        $draft = LicenseVersion::sole();
        $this->assertSame(1234, $draft->structured_terms['publishing_income']['licensor_bps']);
        $this->assertSame(567, $draft->structured_terms['recording_royalty']['rate_bps']);
        foreach (['publishing_income.licensor_bps', 'recording_royalty.rate_bps'] as $field) {
            $invalid = $draft->structured_terms;
            Arr::set($invalid, $field, '1.5');
            Livewire::test(ManageLicenseVersions::class)->callTableAction('edit', $draft, data: ['structured_terms' => $invalid])->assertHasTableActionErrors();
            $this->assertSame(CanonicalJson::hash($draft->structured_terms), CanonicalJson::hash($draft->fresh()->structured_terms));
        }
        $published = $this->publish($draft->fresh(), $actor);
        Livewire::test(ManageLicenseVersions::class)->callTableAction('successor', $published)->assertHasNoTableActionErrors();
        $successor = LicenseVersion::where('predecessor_id', $published->id)->sole();
        $this->assertSame(4, $successor->structured_terms['schema_version']);
        $this->assertSame(CanonicalJson::hash($published->structured_terms), CanonicalJson::hash($successor->structured_terms));
        $this->assertSame($published->authored_source, $successor->authored_source);
    }

    public function test_policy_byte_limit_errors_attach_to_the_mounted_repeater_row_and_preserve_the_draft(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = EconomicLicenseFixtures::draft($actor);
        $before = $draft->fresh()->getAttributes();
        $this->actingAs($actor);
        $form = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft);
        $rows = $form->get('mountedActions.0.data.structured_terms.policies');
        $rowKey = array_key_first($rows);
        $this->assertIsString($rowKey);
        $this->assertFalse(ctype_digit($rowKey));
        $field = 'structured_terms.policies.'.$rowKey.'.text';
        $form->set('mountedActions.0.data.'.$field, str_repeat('x', 20001))
            ->callMountedTableAction()
            ->assertHasTableActionErrors([$field])
            ->assertSee('Retain complete plain UTF-8 buyer-facing policy text');
        $this->assertSame($before, $draft->fresh()->getAttributes());
    }

    public function test_economic_mapping_rechecks_staff_authorization_after_mounting(): void
    {
        $actor = LicenseFixtures::admin();
        $legacy = LicenseFixtures::published($actor, terms: ScopedLicenseFixtures::terms(), content: ['authored_source' => ScopedLicenseFixtures::source()]);
        $this->actingAs($actor);
        $form = Livewire::test(ManageLicenseVersions::class)->mountTableAction('economic_successor', $legacy);
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $form->callMountedTableAction()->assertForbidden();
        $this->assertDatabaseCount('license_versions', 1);
        $this->expectException(AuthorizationException::class);
        app(CreateLicenseDraft::class)->handle($legacy->template, ['authored_source' => EconomicLicenseFixtures::source(), 'structured_terms' => EconomicLicenseFixtures::terms()], $customer, $legacy);
    }

    public function test_economic_policy_revisions_preserve_all_earlier_license_offer_and_quote_snapshots(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $offer = $fixture['offer'];
        $retained = [];
        $versions = [
            LicenseVersion::findOrFail($fixture['items'][0]['licenseVersionId']),
            LicenseFixtures::published($actor, terms: TypedLicenseFixtures::terms(), content: ['authored_source' => TypedLicenseFixtures::source()]),
            LicenseFixtures::published($actor, terms: ScopedLicenseFixtures::terms(), content: ['authored_source' => ScopedLicenseFixtures::source()]),
            $this->publish(EconomicLicenseFixtures::draft($actor), $actor),
        ];
        foreach ($versions as $version) {
            app(SaveOfferDraft::class)->handle($offer, ['license_version_id' => $version->id], $actor);
            $revision = app(PublishOffer::class)->handle($offer, $actor);
            $items = [['trackId' => $fixture['track']->id, 'offerId' => $offer->id, 'offerRevisionId' => $revision->id, 'licenseVersionId' => $version->id]];
            $quote = app(CreateQuote::class)->handle(str_repeat('a', 64), 'economic-history-'.$version->terms_schema_version, $items);
            $retained[] = [$version, $version->fresh()->getAttributes(), $version->reviewEvidence()->sole()->getAttributes(), $revision, $revision->fresh()->snapshot, $quote, $quote->fresh()->snapshot];
        }
        $economic = $versions[3];
        $this->getJson('/api/catalog')->assertJsonPath('licenseTiers.0.features', $economic->features());
        $snapshot = $retained[3][6]['lines'][0]['offer_snapshot']['license'];
        $this->assertSame(CanonicalJson::hash($economic->structured_terms), CanonicalJson::hash($snapshot['structured_terms']));
        $this->assertSame($economic->features(), $snapshot['features']);
        $this->assertSame('test-v1', $snapshot['structured_terms']['policies'][0]['version']);
        $changed = $economic->structured_terms;
        $changed['policies'][0]['version'] = 'test-v2';
        $changed['policies'][0]['text'] .= "\nSYNTHETIC successor policy text only.";
        $changed['publishing_income']['licensor_bps'] = 2345;
        $successor = app(CreateLicenseDraft::class)->handle($economic->template, ['authored_source' => EconomicLicenseFixtures::source(), 'structured_terms' => $changed], $actor, $economic);
        $newer = $this->publish($successor, $actor);
        $this->assertNotSame($economic->model_hash, $newer->model_hash);
        $this->assertNotSame($economic->render_fixture_hash, $newer->render_fixture_hash);
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
