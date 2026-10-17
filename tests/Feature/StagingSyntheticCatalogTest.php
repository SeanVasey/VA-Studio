<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalCatalog;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MediaFixtures;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

/**
 * The labelled synthetic staging rehearsal catalog: admission refusals before any write, and a real run through the
 * domain services. Media uses real ffmpeg generation, real derivatives and the real pipeline; only the malware engine is
 * the existing test-only scanner, and the seller tag is the existing test-only fixture tag.
 */
class StagingSyntheticCatalogTest extends TestCase
{
    // The domain services under test own standalone transactions, so no test transaction may wrap them.
    use FinalizationDatabaseMigrations;

    private const OPERATOR = 'synthetic-catalog-operator@example.test';

    private const REVIEWER = 'synthetic-catalog-reviewer@example.test';

    private const COMMERCE_TABLES = ['quotes', 'orders', 'license_grants', 'verified_payments', 'stripe_webhook_receipts'];

    private TestOnlyMediaScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->scanner = MediaFixtures::configure();
        config(['staging-synthetic-catalog.enabled' => true, 'payments.stripe.mode' => 'test', 'payments.stripe.secret_key' => null,
            'production_checkout.funds_mode' => null, 'production_checkout.secret_key' => null]);
    }

    private function operators(string $operatorName = 'Synthetic Catalog Operator', string $reviewerName = 'Synthetic Catalog Reviewer'): array
    {
        foreach ([[$operatorName, self::OPERATOR], [$reviewerName, self::REVIEWER]] as [$name, $email]) {
            $this->artisan('vasey:create-admin')
                ->expectsQuestion('Operator name', $name)
                ->expectsQuestion('Email', $email)
                ->expectsQuestion('Password (at least 16 characters)', 'SyntheticRehearsal'.random_int(100000, 999999).'Pass')
                ->assertSuccessful();
        }

        return [User::where('email', self::OPERATOR)->sole(), User::where('email', self::REVIEWER)->sole()];
    }

    private function catalog(array $options = []): PendingCommand
    {
        return $this->artisan('vasey:staging-synthetic-catalog', $options + ['--operator' => self::OPERATOR, '--reviewer' => self::REVIEWER]);
    }

    /** Every table a synthetic run could write, so a refusal can prove that it wrote nothing. */
    private function census(): array
    {
        $tables = ['users', 'audit_events', 'tracks', 'rights_declarations', 'media_assets', 'media_processing_runs',
            'license_templates', 'license_versions', 'offers', 'offer_revisions', ...self::COMMERCE_TABLES];

        return array_combine($tables, array_map(fn (string $table): int => DB::table($table)->count(), $tables));
    }

    private function assertRefusedWithoutWrites(array $options, string $message): void
    {
        $before = $this->census();
        // One expectation per output line: the refusal is a single line ending "Nothing was written."; the census proves it.
        $this->catalog($options)->expectsOutputToContain($message)->assertExitCode(1);
        $this->assertSame($before, $this->census());
    }

    public function test_production_is_refused_unconditionally_even_with_the_flag_and_test_mode(): void
    {
        $this->operators();
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->assertRefusedWithoutWrites([], 'refused in production');
        $this->assertRefusedWithoutWrites(['--check' => true], 'refused in production');
        $this->app->detectEnvironment(fn (): string => 'testing'); // The disposable database teardown confirms in production.
    }

    public function test_the_default_off_flag_refuses_before_any_write(): void
    {
        $this->operators();
        foreach ([false, null, 'true', 1] as $value) {
            config(['staging-synthetic-catalog.enabled' => $value]);
            $this->assertRefusedWithoutWrites([], 'VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED=true');
        }
        $this->assertFalse((require base_path('config/staging-synthetic-catalog.php'))['enabled']);
    }

    public function test_environments_outside_local_testing_and_staging_are_refused(): void
    {
        $this->operators();
        foreach (['development', 'Staging', 'staging-eu', 'prod', ''] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);
            $this->assertRefusedWithoutWrites([], 'runs only when APP_ENV is local, testing or staging');
        }
        $this->app->detectEnvironment(fn (): string => 'testing');
    }

    public function test_stripe_must_be_in_test_mode(): void
    {
        $this->operators();
        foreach ([['payments.stripe.mode' => 'live'], ['payments.stripe.mode' => null],
            ['payments.stripe.secret_key' => 'sk_live_'.str_repeat('a', 24)], ['payments.stripe.secret_key' => 'rk_test_'.str_repeat('a', 24)],
            ['production_checkout.funds_mode' => 'live'], ['production_checkout.secret_key' => 'sk_live_'.str_repeat('b', 24)]] as $override) {
            config($override);
            $this->assertRefusedWithoutWrites([], 'requires Stripe test mode');
            config(['payments.stripe.mode' => 'test', 'payments.stripe.secret_key' => null, 'production_checkout.funds_mode' => null,
                'production_checkout.secret_key' => null]);
        }
        config(['payments.stripe.secret_key' => 'sk_test_'.str_repeat('c', 24)]);
        $this->catalog(['--check' => true])->expectsOutputToContain('Admission passed')->assertSuccessful();
    }

    public function test_operators_must_be_named_synthetic_distinct_and_satisfy_the_panel_mfa_rule(): void
    {
        $this->assertRefusedWithoutWrites(['--operator' => null], '--operator=EMAIL and --reviewer=EMAIL');
        $this->assertRefusedWithoutWrites([], 'Create it first with `php artisan vasey:create-admin`');
        [$operator, $reviewer] = $this->operators('Real Operator', 'Synthetic Catalog Reviewer');
        $this->assertRefusedWithoutWrites([], 'name must start with "Synthetic "');
        $operator->forceFill(['name' => 'Synthetic Catalog Operator', 'is_admin' => false])->save();
        $this->assertRefusedWithoutWrites([], 'lacks catalog authority');
        $operator->forceFill(['is_admin' => true])->save();
        $this->assertRefusedWithoutWrites(['--reviewer' => self::OPERATOR], 'must be different accounts');

        // Staging requires the panel's staff MFA (evaluated on demand): unenrolled synthetic operators are refused,
        // and enrollment through the panel's own provider is what admits them. No check is relaxed for this command.
        $this->app->detectEnvironment(fn (): string => 'staging');
        $this->assertRefusedWithoutWrites([], "does not satisfy the admin panel's MFA rule");
        $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
        $this->assertInstanceOf(AppAuthentication::class, $provider);
        $operator->saveAppAuthenticationSecret($provider->generateSecret());
        $this->assertRefusedWithoutWrites([], "does not satisfy the admin panel's MFA rule");
        $reviewer->saveAppAuthenticationSecret($provider->generateSecret());
        $before = $this->census();
        $this->catalog(['--check' => true])->expectsOutputToContain('Admission passed')->expectsOutputToContain('Environment: staging')->assertSuccessful();
        $this->assertSame($before, $this->census());
        $this->app->detectEnvironment(fn (): string => 'testing');
    }

    public function test_a_missing_or_mismatched_seller_tag_is_refused_and_never_generated(): void
    {
        $this->operators();
        $configured = [config('media.tag_path'), config('media.tag_sha256')];
        config(['media.tag_path' => null]);
        $this->assertRefusedWithoutWrites([], 'tag_not_configured');
        config(['media.tag_path' => $configured[0], 'media.tag_sha256' => str_repeat('0', 64)]);
        $this->assertRefusedWithoutWrites([], 'tag_hash_mismatch');
        config(['media.tag_path' => 'approved-tags/absent.wav', 'media.tag_sha256' => $configured[1]]);
        $this->assertRefusedWithoutWrites([], 'seller tag file is unavailable');
    }

    public function test_a_run_creates_the_labelled_published_catalog_through_the_domain_services(): void
    {
        [$operator, $reviewer] = $this->operators();
        $auditStart = (int) AuditEvent::max('id');
        $this->catalog()->expectsOutputToContain(SyntheticRehearsalCatalog::LABEL)->expectsOutputToContain('created and published')->assertSuccessful();

        $definitions = SyntheticRehearsalCatalog::tracks();
        $this->assertCount(6, $definitions);
        $this->assertSame(6, Track::count());
        foreach ($definitions as $definition) {
            $track = Track::where('slug', $definition['slug'])->sole();
            $this->assertStringStartsWith(SyntheticRehearsalCatalog::MARKER.'-', $track->slug);
            $this->assertStringStartsWith('[SYNTHETIC] ', $track->title);
            $this->assertSame('Synthetic Test Content', $track->artist);
            $this->assertStringContainsString(SyntheticRehearsalCatalog::LABEL, $track->description);
            $this->assertContains(SyntheticRehearsalCatalog::MARKER, $track->tags);
            $this->assertSame('published', $track->status);
            $this->assertSame([], app(PublicationReadiness::class)->blockers($track));
            $this->assertSame($track->slug, $track->published_slug);

            $rights = $track->rightsDeclarations()->sole();
            $this->assertSame('verified', $rights->status);
            $this->assertSame($operator->id, $rights->verified_by);
            $this->assertStringStartsWith(SyntheticRehearsalCatalog::PROVENANCE_PREFIX.$definition['number'].':', $rights->provenance_reference);
            $this->assertStringContainsString(SyntheticRehearsalCatalog::LABEL, $rights->sample_disclosure);

            $ready = $track->assets()->where('status', 'ready')->pluck('role')->sort()->values()->all();
            $this->assertSame(['artwork', 'download_mp3', 'master_wav', 'preview_tagged'], $ready);
            foreach ($track->assets()->whereNull('parent_asset_id')->get() as $source) {
                $this->assertSame('processed', $source->status);
                $this->assertStringStartsWith($definition['slug'].'-', $source->original_name);
                $this->assertSame($operator->id, $source->technical_metadata['acquired_by']);
            }
            $preview = $track->assets()->where('role', 'preview_tagged')->sole();
            $this->assertEqualsWithDelta($definition['duration'], $preview->technical_metadata['duration_seconds'], 0.15);
            $this->assertNotEmpty($preview->technical_metadata['waveform']);

            $offers = Offer::where('track_id', $track->id)->orderBy('id')->get();
            $this->assertCount(2, $offers);
            foreach ($offers as $offer) {
                $this->assertTrue($offer->is_active);
                $revision = $offer->currentRevision;
                $this->assertSame('USD', $revision->currency);
                $this->assertIsInt($revision->price_minor);
                $this->assertContains($revision->price_minor, [111, 222]);
                $this->assertStringContainsString('TEST PRICE', $revision->snapshot['license']['name']);
                $this->assertStringContainsString(SyntheticRehearsalCatalog::LABEL, $revision->snapshot['license']['name']);
                $this->assertSame(SyntheticRehearsalCatalog::LABEL, $revision->snapshot['license']['features'][0]);
                $this->assertSame($operator->id, $revision->published_by);
            }
        }
        $this->assertSame(2, LicenseTemplate::count());
        foreach (LicenseVersion::with('template')->get() as $license) {
            $this->assertStringStartsWith(SyntheticRehearsalCatalog::MARKER.'-', $license->template->slug);
            $this->assertStringContainsString(SyntheticRehearsalCatalog::LABEL, $license->template->name);
            $this->assertStringContainsString('TEST PRICE', $license->template->name);
            $this->assertStringContainsString(SyntheticRehearsalCatalog::LABEL, $license->authored_source);
            $this->assertContains(SyntheticRehearsalCatalog::LABEL, $license->structured_terms['features']);
            $this->assertSame('published', $license->status);
            $this->assertSame($operator->id, $license->author_id);
            $this->assertSame($reviewer->id, $license->approved_by);
            $this->assertSame(SyntheticRehearsalCatalog::APPROVAL_REFERENCE, $license->approval_reference);
        }
        $this->assertSame(12, OfferRevision::count());
        $this->assertSame([111, 222], OfferRevision::distinct()->orderBy('price_minor')->pluck('price_minor')->all());
        foreach (self::COMMERCE_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        // Every write is an ordinary domain command attributed to one of the two named synthetic accounts.
        $audits = AuditEvent::where('id', '>', $auditStart)->get();
        $this->assertSame([$operator->id, $reviewer->id], $audits->pluck('actor_id')->unique()->sort()->values()->all());
        $this->assertSame(['rights.license.approved'], $audits->where('actor_id', $reviewer->id)->pluck('action')->unique()->values()->all());
        $counts = $audits->countBy('action')->all();
        ksort($counts);
        $this->assertSame([
            'catalog.offer.draft_saved' => 12, 'catalog.offer.revision_published' => 12, 'catalog.track.created' => 6,
            'catalog.track.published' => 6, 'media.processing.completed' => 12, 'media.processing.queued' => 12,
            'media.upload.quarantined' => 12, 'rights.declaration.created' => 6, 'rights.declaration.verified' => 6,
            'rights.license.approved' => 2, 'rights.license.draft_created' => 2, 'rights.license.published' => 2,
            'rights.license.review_requested' => 2, 'rights.license_template.created' => 2,
        ], $counts);
        $this->assertSame(6, $audits->where('action', 'catalog.track.published')->filter(fn (AuditEvent $audit): bool => is_string($audit->context['manifest_hash'] ?? null))->count());
        $this->assertSame([], glob(rtrim(config('filesystems.disks.local.root'), '/').'/processing/*') ?: []);
    }

    public function test_a_rerun_reports_already_present_and_duplicates_nothing_and_a_staff_withdrawal_is_kept(): void
    {
        [$operator] = $this->operators();
        $this->catalog()->assertSuccessful();
        $before = $this->census();
        $pending = $this->catalog();
        foreach (SyntheticRehearsalCatalog::tracks() as $definition) {
            $pending->expectsOutputToContain('track   '.$definition['slug'].'  already present');
        }
        foreach (SyntheticRehearsalCatalog::licenses() as $license) {
            $pending->expectsOutputToContain('license '.$license['slug'].'  already present');
        }
        $this->assertSame(0, $pending->assertSuccessful()->run());
        $this->assertSame($before, $this->census());

        // Unpublishing through the ordinary staff action is the supported way to hide a synthetic track; a re-run keeps it hidden.
        $first = Track::where('slug', SyntheticRehearsalCatalog::tracks()[0]['slug'])->sole();
        app(PublishTrack::class)->unpublish($first, $operator);
        $withdrawn = $this->census();
        $this->catalog()->expectsOutputToContain('withdrawn by staff; left unpublished')->assertExitCode(1);
        $this->assertSame($withdrawn, $this->census());
        $this->assertSame('draft', $first->fresh()->status);
    }

    public function test_an_interrupted_run_resumes_and_a_marked_row_that_differs_is_refused_unchanged(): void
    {
        [$operator] = $this->operators();
        $definitions = SyntheticRehearsalCatalog::tracks();
        // An earlier run stopped after creating track 01's metadata.
        app(SaveTrackMetadata::class)->handle(null, SyntheticRehearsalCatalog::metadata($definitions[0]), $operator);
        // Someone edited track 02's metadata: it carries the marker but no longer matches the definition.
        $edited = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Edited synthetic row'] + SyntheticRehearsalCatalog::metadata($definitions[1]), $operator);
        $this->catalog()->expectsOutputToContain('track   '.$definitions[0]['slug'].'  resumed and published')
            ->expectsOutputToContain('metadata differs from the definition; it was left unchanged')->assertExitCode(1);
        $this->assertSame('published', Track::where('slug', $definitions[0]['slug'])->sole()->status);
        $this->assertSame(['draft', 1, 'Edited synthetic row'], [$edited->fresh()->status, $edited->fresh()->metadata_version, $edited->fresh()->title]);
        $this->assertSame(0, $edited->rightsDeclarations()->count() + $edited->assets()->count() + Offer::where('track_id', $edited->id)->count());
        $this->assertSame(1, Track::where('status', 'published')->count());
        $this->assertSame(2, LicenseVersion::where('status', 'published')->count());
    }

    public function test_a_failed_media_step_stops_the_run_and_a_rerun_retries_the_same_source(): void
    {
        $this->operators();
        $this->scanner->reject = true;
        $this->catalog()->expectsOutputToContain('media processing failed: scan_not_clean')->assertExitCode(1);
        $track = Track::where('slug', SyntheticRehearsalCatalog::tracks()[0]['slug'])->sole();
        $this->assertSame(['draft', 1], [$track->status, Track::count()]);
        $this->assertSame('verified', $track->rightsDeclarations()->sole()->status);
        $source = $track->assets()->whereNull('parent_asset_id')->sole();
        $this->assertSame(['master_wav', 'quarantined', 'failed'], [$source->role, $source->status, $source->runs()->sole()->status]);
        $this->assertSame(0, Offer::count());

        $this->scanner->reject = false;
        $this->catalog()->expectsOutputToContain('track   '.$track->slug.'  resumed and published')->assertSuccessful();
        $this->assertSame(6, Track::where('status', 'published')->count());
        $masters = $track->assets()->whereNull('parent_asset_id')->where('role', 'master_wav')->get();
        $this->assertSame([$source->id], $masters->pluck('id')->all());
        $this->assertSame(['processed', 'completed'], [$masters[0]->status, $source->runs()->sole()->status]);
        $this->assertSame(1, AuditEvent::where('action', 'media.processing.failed')->count());
        $this->assertSame(1, $track->rightsDeclarations()->count());
    }

    public function test_the_storefront_player_and_a_quote_use_the_labelled_synthetic_offers(): void
    {
        $this->operators();
        $this->catalog()->assertSuccessful();

        $catalog = $this->getJson('/api/catalog')->assertOk()->assertJsonCount(6, 'tracks');
        foreach ($catalog->json('tracks') as $track) {
            $this->assertStringStartsWith('[SYNTHETIC] ', $track['title']);
            $this->assertSame('Synthetic Test Content', $track['artist']);
            $this->assertContains(SyntheticRehearsalCatalog::MARKER, $track['tags']);
            $this->assertCount(2, $track['offers']);
            foreach ($track['offers'] as $offer) {
                $this->assertStringContainsString(SyntheticRehearsalCatalog::LABEL, $offer['licenseName']);
                $this->assertStringContainsString('TEST PRICE', $offer['licenseName']);
                $this->assertSame('USD', $offer['currency']);
            }
            $this->assertNotEmpty($track['waveform']);
        }
        $this->assertContains(SyntheticRehearsalCatalog::LABEL, $catalog->json('licenseTiers.0.features'));

        $first = $catalog->json('tracks.0');
        $this->get('/tracks/'.$first['slug'], ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.selectedTrack.title', $first['title']);
        $preview = $this->get(parse_url($first['previewUrl'], PHP_URL_PATH))->assertOk();
        $this->assertSame('audio/mpeg', $preview->headers->get('Content-Type'));
        $license = $this->getJson(parse_url($first['offers'][0]['licenseUrl'], PHP_URL_PATH))->assertOk()
            ->assertJsonPath('features.0', SyntheticRehearsalCatalog::LABEL);
        $this->assertStringContainsString(SyntheticRehearsalCatalog::LABEL, $license->json('termsText'));
        $this->assertStringContainsString('TEST PRICE', $license->json('name'));

        $offer = Offer::findOrFail((int) $first['offers'][0]['id']);
        $quote = app(CreateQuote::class)->handle(str_repeat('a', 64), 'synthetic-rehearsal-quote', [[
            'trackId' => (int) $first['id'], 'offerId' => $offer->id, 'licenseVersionId' => $offer->license_version_id,
            'offerRevisionId' => $offer->current_revision_id]]);
        $this->assertSame($offer->currentRevision->price_minor, $quote->subtotal_minor);
        $this->assertContains($quote->subtotal_minor, [111, 222]);
        $this->assertSame('USD', $quote->currency);
        $this->assertStringContainsString(SyntheticRehearsalCatalog::LABEL, $quote->snapshot['lines'][0]['offer_snapshot']['license']['name']);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_generated_sources_are_real_media_and_never_reuse_a_brand_asset(): void
    {
        [$operator] = $this->operators();
        $this->catalog()->assertSuccessful();
        $masters = MediaAsset::whereNull('parent_asset_id')->where('role', 'master_wav')->orderBy('id')->get();
        $this->assertCount(6, $masters);
        $this->assertCount(6, $masters->pluck('sha256')->unique());
        foreach ($masters as $master) {
            $this->assertSame('audio/x-wav', $master->mime_type);
        }
        foreach (MediaAsset::where('role', 'artwork')->whereNotNull('parent_asset_id')->get() as $artwork) {
            $this->assertSame(['width' => 600, 'height' => 600, 'sanitized' => true], $artwork->technical_metadata);
        }
        $this->assertSame([$operator->id], RightsDeclaration::distinct()->pluck('verified_by')->all());
    }
}
