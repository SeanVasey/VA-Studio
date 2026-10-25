<?php

namespace Tests\Feature;

use App\Console\Commands\StagingSyntheticCatalog;
use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalCatalog;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Domain\Rights\SaveRightsDeclaration;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Closure;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

/**
 * The labelled synthetic staging rehearsal catalog: admission refusals before any write, and a real run through the
 * domain services. Media uses real ffmpeg generation, real derivatives and the real pipeline. Two inputs are fixtures
 * here and are not exercised by the command itself: the malware verdict comes from the test-only scanner, and the seller
 * tag is the test-only fixture tag.
 */
class StagingSyntheticCatalogTest extends TestCase
{
    // The domain services under test own standalone transactions, so no test transaction may wrap them.
    use FinalizationDatabaseMigrations;

    private const OPERATOR = 'synthetic-catalog-operator@example.test';

    private const REVIEWER = 'synthetic-catalog-reviewer@example.test';

    private const COMMERCE_TABLES = ['quotes', 'orders', 'license_grants', 'verified_payments', 'stripe_webhook_receipts'];

    private TestOnlyMediaScanner $scanner;

    /** The two accounts' passwords, answered at the command's hidden prompts and never printed. */
    private array $passwords = [];

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
        foreach ([[$operatorName, self::OPERATOR], [$reviewerName, self::REVIEWER]] as $index => [$name, $email]) {
            $this->passwords[$index] = 'SyntheticRehearsal'.random_int(100000, 999999).'Pass';
            $this->artisan('vasey:create-admin')
                ->expectsQuestion('Operator name', $name)
                ->expectsQuestion('Email', $email)
                ->expectsQuestion('Password (at least 16 characters)', $this->passwords[$index])
                ->assertSuccessful();
        }

        return [User::where('email', self::OPERATOR)->sole(), User::where('email', self::REVIEWER)->sole()];
    }

    /**
     * A write run answers both hidden password prompts by default; `--check` asks none. `$answers` replaces them for
     * runs that stop before or between the prompts.
     */
    private function catalog(array $options = [], ?array $answers = null): PendingCommand
    {
        $pending = $this->artisan('vasey:staging-synthetic-catalog', $options + ['--operator' => self::OPERATOR, '--reviewer' => self::REVIEWER]);
        $answers ??= isset($options['--check']) ? [] : [StagingSyntheticCatalog::OPERATOR_PROMPT => $this->passwords[0],
            StagingSyntheticCatalog::REVIEWER_PROMPT => $this->passwords[1]];
        foreach ($answers as $prompt => $answer) {
            $pending->expectsQuestion($prompt, $answer)->doesntExpectOutputToContain($answer);
        }

        return $pending;
    }

    /** Runs the probe under one environment, configured and detected alike unless told otherwise, and always restores testing. */
    private function inEnvironment(string $detected, Closure $probe, ?string $configured = null): void
    {
        $this->app->detectEnvironment(fn (): string => $detected);
        config(['app.env' => $configured ?? $detected]);
        try {
            $probe();
        } finally {
            // The disposable database teardown would ask for confirmation in production.
            $this->app->detectEnvironment(fn (): string => 'testing');
            config(['app.env' => 'testing']);
        }
    }

    /** Every table a synthetic run could write, so a refusal can prove that it wrote nothing. */
    private function census(): array
    {
        $tables = ['users', 'audit_events', 'tracks', 'rights_declarations', 'media_assets', 'media_processing_runs',
            'license_templates', 'license_versions', 'offers', 'offer_revisions', ...self::COMMERCE_TABLES];

        return array_combine($tables, array_map(fn (string $table): int => DB::table($table)->count(), $tables));
    }

    private function assertRefusedWithoutWrites(array $options, string $message, array $answers = []): void
    {
        $before = $this->census();
        // One expectation per output line: the refusal is a single line ending "Nothing was written."; the census proves it.
        $this->catalog($options, $answers)->expectsOutputToContain($message)->assertExitCode(1);
        $this->assertSame($before, $this->census());
    }

    private function attestations(): Collection
    {
        return AuditEvent::where('action', StaffWriteAuthority::ATTESTATION_ACTION)->orderBy('id')->get();
    }

    private function enroll(User ...$accounts): void
    {
        $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
        $this->assertInstanceOf(AppAuthentication::class, $provider);
        foreach ($accounts as $account) {
            $account->saveAppAuthenticationSecret($provider->generateSecret());
        }
    }

    public function test_production_is_refused_even_with_the_flag_and_test_mode(): void
    {
        $this->operators();
        $this->inEnvironment('production', function (): void {
            $this->assertRefusedWithoutWrites([], 'refused in production');
            $this->assertRefusedWithoutWrites(['--check' => true], 'refused in production');
        });
    }

    public function test_a_production_configuration_is_refused_when_the_running_environment_says_staging(): void
    {
        // Artisan's --env switch changes the running environment only; a cached or loaded APP_ENV=production still refuses.
        [$operator, $reviewer] = $this->operators();
        $this->enroll($operator, $reviewer);
        $this->inEnvironment('staging', function (): void {
            $this->assertRefusedWithoutWrites([], 'refused in production');
            $this->assertRefusedWithoutWrites(['--check' => true], 'refused in production');
        }, 'production');
    }

    public function test_a_running_environment_that_differs_from_the_configuration_is_refused(): void
    {
        [$operator, $reviewer] = $this->operators();
        $this->enroll($operator, $reviewer);
        foreach ([['staging', 'testing'], ['testing', 'staging'], ['local', 'staging']] as [$detected, $configured]) {
            $this->inEnvironment($detected, function (): void {
                $this->assertRefusedWithoutWrites([], 'running environment differ');
                $this->assertRefusedWithoutWrites(['--check' => true], 'running environment differ');
            }, $configured);
        }
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
            $this->inEnvironment($environment, function (): void {
                $this->assertRefusedWithoutWrites([], 'runs only when APP_ENV is local, testing or staging');
            });
        }
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
        $this->inEnvironment('staging', function () use ($operator, $reviewer): void {
            $this->assertRefusedWithoutWrites([], "does not satisfy the admin panel's MFA rule");
            $this->enroll($operator);
            $this->assertRefusedWithoutWrites([], "does not satisfy the admin panel's MFA rule");
            $this->enroll($reviewer);
            $before = $this->census();
            $this->catalog(['--check' => true])->expectsOutputToContain('Admission passed')->expectsOutputToContain('Environment: staging')->assertSuccessful();
            $this->assertSame($before, $this->census());
        });
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

        // A staff edit that strips a published synthetic track's label is reported, never silently accepted or reverted.
        $second = Track::where('slug', SyntheticRehearsalCatalog::tracks()[1]['slug'])->sole();
        app(SaveTrackMetadata::class)->handle($second, ['title' => 'Edited published row', 'metadata_version' => $second->metadata_version], $operator);
        $edited = $this->census();
        $this->catalog()->expectsOutputToContain('track   '.$second->slug.'  present, metadata differs from the definition; left unchanged')
            ->assertExitCode(1);
        $this->assertSame($edited, $this->census());
        $this->assertSame(['published', 'Edited published row'], [$second->fresh()->status, $second->fresh()->title]);

        // A published synthetic track whose offers no longer match the two TEST PRICE offers is reported, not repaired.
        $third = Track::where('slug', SyntheticRehearsalCatalog::tracks()[2]['slug'])->sole();
        app(DeactivateOffer::class)->handle(Offer::where('track_id', $third->id)->orderBy('id')->firstOrFail(), $operator);
        $deactivated = $this->census();
        $this->catalog()->expectsOutputToContain('track   '.$third->slug.'  present, offers differ from the definition; left unchanged')
            ->assertExitCode(1);
        $this->assertSame($deactivated, $this->census());
        $this->assertSame(['published', 1], [$third->fresh()->status, Offer::where('track_id', $third->id)->where('is_active', true)->count()]);
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

    public function test_an_unexpected_failure_in_the_write_phase_reports_an_unconfirmed_result_and_how_to_resume(): void
    {
        $this->operators();
        // A model hook throws a plain exception inside the first domain write, after the run lock is held.
        LicenseTemplate::creating(function (): void {
            throw new RuntimeException('Unexpected failure at /private/path.');
        });
        $this->catalog()->expectsOutputToContain('Stopped: the result is unconfirmed. Re-run the command to resume; finished steps are kept and nothing is duplicated.')
            ->doesntExpectOutputToContain('/private/path')->assertExitCode(1);
        $this->assertSame(0, LicenseTemplate::count());
    }

    public function test_a_check_run_that_fails_unexpectedly_does_not_claim_a_resumable_write(): void
    {
        $this->operators();
        // --check is an admission run, so its generic failure must not talk about resuming or finished steps. A model hook
        // throws a plain exception while the command resolves its two accounts.
        User::retrieved(function (): void {
            throw new RuntimeException('Unexpected at /private/path.');
        });

        $this->catalog(['--check' => true])->expectsOutputToContain('nothing was written. Re-run it.')
            ->doesntExpectOutputToContain('finished steps are kept')->doesntExpectOutputToContain('/private/path')->assertExitCode(1);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function editedRightsFields(): array
    {
        return ['sample disclosure' => ['sample_disclosure', 'No samples. (Edited by staff.)'],
            'provenance after the marker prefix' => ['provenance_reference', ' Also uses a purchased sample pack.']];
    }

    #[DataProvider('editedRightsFields')]
    public function test_a_resumed_rights_declaration_must_match_the_definition_in_both_evidence_fields(string $field, string $edit): void
    {
        [$operator] = $this->operators();
        $definition = SyntheticRehearsalCatalog::tracks()[0];
        $track = app(SaveTrackMetadata::class)->handle(null, SyntheticRehearsalCatalog::metadata($definition), $operator);
        // Staff edited one field of the interrupted draft's pending declaration. The marker prefix is still there.
        $rights = SyntheticRehearsalCatalog::rights($definition, $track->id);
        $rights[$field] = $field === 'provenance_reference' ? $rights[$field].$edit : $edit;
        app(SaveRightsDeclaration::class)->create($rights, $operator);
        $this->assertStringStartsWith(SyntheticRehearsalCatalog::PROVENANCE_PREFIX.$definition['number'].':', $rights['provenance_reference']);

        $this->catalog()->expectsOutputToContain($definition['slug'].' has a rights declaration that differs from the synthetic definition; it was left unchanged.')
            ->assertExitCode(1);

        $this->assertSame(['pending'], $track->rightsDeclarations()->pluck('status')->all(), 'the edited claim was not verified');
        $this->assertSame($rights[$field], $track->rightsDeclarations()->sole()->{$field}, 'and not changed back');
        $this->assertSame(0, $track->assets()->count() + Offer::where('track_id', $track->id)->count());
        $this->assertSame(0, Track::where('status', 'published')->count());
    }

    public function test_an_interrupted_source_that_is_not_the_generated_media_is_refused_unchanged(): void
    {
        [$operator] = $this->operators();
        $definitions = SyntheticRehearsalCatalog::tracks();
        // Staff left their own upload under the name the run looks for; its hash is not the generated master's.
        $track = app(SaveTrackMetadata::class)->handle(null, SyntheticRehearsalCatalog::metadata($definitions[0]), $operator);
        $bytes = MediaFixtures::wav(1.2, 330);
        $key = 'quarantine/'.Str::uuid().'/source.bin';
        Storage::disk('local')->put($key, $bytes);
        $source = MediaAsset::create(['track_id' => $track->id, 'role' => 'master_wav', 'disk' => 'local', 'storage_path' => $key,
            'original_name' => $definitions[0]['slug'].'-master.wav', 'mime_type' => 'audio/x-wav', 'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'status' => 'quarantined']);
        $this->catalog()->expectsOutputToContain($definitions[0]['slug'].' has master_wav media that differs from the synthetic definition; it was left unchanged.')
            ->assertExitCode(1);
        $this->assertSame(['quarantined', 0], [$source->fresh()->status, $source->runs()->count()], 'it was not processed');
        $this->assertSame([$source->id], $track->assets()->pluck('id')->all(), 'nothing was uploaded beside it');
        $this->assertSame(0, Offer::where('track_id', $track->id)->count());
        $this->assertSame('draft', $track->fresh()->status);

    }

    public function test_a_ready_preview_that_derives_from_another_source_is_refused_unchanged(): void
    {
        [$operator] = $this->operators();
        $definition = SyntheticRehearsalCatalog::tracks()[0];
        // A ready preview and artwork exist, but they derive from other sources than the generated media.
        $track = app(SaveTrackMetadata::class)->handle(null, SyntheticRehearsalCatalog::metadata($definition), $operator);
        MediaFixtures::readyTrackMedia($track, $operator);
        $assets = $track->assets()->count();
        $this->catalog()->expectsOutputToContain($definition['slug'].' has master_wav media that differs from the synthetic definition; it was left unchanged.')
            ->assertExitCode(1);
        $this->assertSame($assets, $track->assets()->count(), 'no asset was added or replaced');
        $this->assertSame(0, Offer::where('track_id', $track->id)->count());
        $this->assertSame('draft', $track->fresh()->status);
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

        // No generated file, source or derivative, is byte-identical to any brand or public file in the repository.
        $generated = MediaAsset::whereNotNull('sha256')->pluck('sha256')->unique()->all();
        $this->assertGreaterThan(12, count($generated));
        $brand = [];
        // `assets/` holds source marks once the official identity lands; compare against whichever of the two exist.
        foreach (array_filter(['assets', 'public'], fn (string $directory): bool => is_dir(base_path($directory))) as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && ! $file->isLink() && $file->getSize() < 50_000_000) {
                    $brand[hash_file('sha256', $file->getPathname())] = $directory.'/'.substr($file->getPathname(), strlen(base_path($directory)) + 1);
                }
            }
        }
        $this->assertNotEmpty($brand, 'The repository has brand or public files to compare against.');
        $this->assertSame([], array_values(array_intersect_key($brand, array_flip($generated))));
    }

    public function test_writes_need_an_interactive_console_both_passwords_and_the_lock(): void
    {
        $this->operators();
        $this->assertRefusedWithoutWrites(['--no-interaction' => true], 'interactive session (not --no-interaction) to confirm both staff passwords at hidden prompts');
        $this->assertRefusedWithoutWrites(['--check' => true, '--attest' => true], '--attest does not apply to --check');
        $this->assertRefusedWithoutWrites([], 'password was not confirmed', [StagingSyntheticCatalog::OPERATOR_PROMPT => 'wrong-password']);
        $this->assertRefusedWithoutWrites([], 'password was not confirmed', [StagingSyntheticCatalog::OPERATOR_PROMPT => $this->passwords[0],
            StagingSyntheticCatalog::REVIEWER_PROMPT => 'wrong-password']);
        // The reviewer's password cannot stand in for the operator's, or the reverse.
        $this->assertRefusedWithoutWrites([], 'password was not confirmed', [StagingSyntheticCatalog::OPERATOR_PROMPT => $this->passwords[1]]);

        $held = Cache::lock('vasey:staging-synthetic-catalog', 60);
        $this->assertTrue($held->get());
        try {
            $this->assertRefusedWithoutWrites([], 'Another synthetic catalog run holds the lock', [StagingSyntheticCatalog::OPERATOR_PROMPT => $this->passwords[0],
                StagingSyntheticCatalog::REVIEWER_PROMPT => $this->passwords[1]]);
        } finally {
            $held->release();
        }
        $this->assertCount(0, $this->attestations());
    }

    public function test_the_password_prompt_never_falls_back_to_visible_input_and_failures_stay_generic(): void
    {
        $this->operators();
        $command = app(StagingSyntheticCatalog::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput(['--operator' => self::OPERATOR, '--reviewer' => self::REVIEWER], $command->getDefinition());
        $input->setInteractive(true);
        $buffer = new BufferedOutput;
        $output = Mockery::mock(OutputStyle::class.'[askQuestion]', [$input, $buffer]);
        $observed = null;
        $output->shouldReceive('askQuestion')->once()->andReturnUsing(function (Question $question) use (&$observed): void {
            $observed = ['hidden' => $question->isHidden(), 'fallback' => $question->isHiddenFallback()];
            throw new RuntimeException('Unable to hide response at /private/path.');
        });
        Log::shouldReceive('warning')->once()->with('Synthetic rehearsal catalog could not confirm the result.', ['exception_class' => RuntimeException::class]);
        $before = $this->census();

        $this->assertSame(1, $command->run($input, $output));
        $this->assertSame(['hidden' => true, 'fallback' => false], $observed);
        $this->assertSame($before, $this->census());
        $text = $buffer->fetch();
        // The failure came at the password prompt, before the write phase: the resume wording would be false here.
        $this->assertStringContainsString('Stopped: the command could not complete before its write phase began, so nothing was written. Re-run it.', $text);
        $this->assertStringNotContainsString('result is unconfirmed', $text);
        $this->assertStringNotContainsString('Unable to hide', $text);
        $this->assertStringNotContainsString('/private/path', $text);
    }

    public function test_staging_requires_an_attestation_and_then_runs_the_whole_chain_with_enrolled_accounts(): void
    {
        [$operator, $reviewer] = $this->operators();
        $this->enroll($operator, $reviewer);
        // Staging accepts only ClamAV scan evidence, so the media carry the existing synthetic host-scan stand-in pattern.
        $this->app->instance(MalwareScanner::class, new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                return ['engine' => 'clamav', 'version' => 'synthetic-host-scan-stand-in', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
            }
        });
        $this->inEnvironment('staging', function () use ($operator, $reviewer): void {
            $this->assertRefusedWithoutWrites([], 'Re-run with --attest to record a console attestation for both accounts',
                [StagingSyntheticCatalog::OPERATOR_PROMPT => $this->passwords[0], StagingSyntheticCatalog::REVIEWER_PROMPT => $this->passwords[1]]);
            $this->assertCount(0, $this->attestations());

            $auditStart = (int) AuditEvent::max('id');
            $this->catalog(['--attest' => true])->expectsOutputToContain('attestation=recorded audit_event=')
                ->expectsOutputToContain('created and published')->assertSuccessful();
            $attestations = $this->attestations();
            $this->assertSame([[$operator->id, 'synthetic_catalog.operator'], [$reviewer->id, 'synthetic_catalog.reviewer']],
                $attestations->map(fn (AuditEvent $row): array => [$row->actor_id, $row->context['action']])->all());
            foreach ($attestations as $row) {
                $this->assertSame('vasey:staging-synthetic-catalog', $row->context['command']);
                $this->assertSame('synthetic-rehearsal operator='.$operator->id.' reviewer='.$reviewer->id, $row->context['scope_key']);
                $this->assertSame(['staging', true, true], [$row->context['environment'], $row->context['mfa_required'], $row->context['mfa_enrolled']]);
            }
            $this->assertSame($attestations->pluck('id')->all(), AuditEvent::where('id', '>', $auditStart)->orderBy('id')->limit(2)->pluck('id')->all(),
                'Both attestations precede the first catalog write.');
            $this->assertSame(6, Track::where('status', 'published')->count());
            foreach (Track::all() as $track) {
                $this->assertSame([], app(PublicationReadiness::class)->blockers($track));
            }
            $this->assertSame([$reviewer->id], LicenseVersion::distinct()->pluck('approved_by')->all());
        });
    }

    public function test_a_catalog_with_a_published_or_once_published_real_track_is_refused(): void
    {
        [$operator] = $this->operators();
        // A real draft that was never published does not block a rehearsal catalog.
        $real = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Real draft', 'slug' => 'real-draft', 'artist' => 'Real artist'], $operator);
        $this->catalog(['--check' => true])->expectsOutputToContain('Admission passed')->assertSuccessful();
        // Simulate a real track that was published and later withdrawn: its reserved URL marks a real catalog.
        DB::table('tracks')->where('id', $real->id)->update(['published_slug' => 'real-draft']);
        $this->assertRefusedWithoutWrites([], 'runs only on an empty or synthetic-only catalog');
        $this->assertRefusedWithoutWrites(['--check' => true], 'runs only on an empty or synthetic-only catalog');
    }

    public function test_a_catalog_with_an_approved_real_license_is_refused(): void
    {
        [$operator, $reviewer] = $this->operators();
        // A real license template with only a draft does not block; an approved version does.
        $template = app(SaveLicenseTemplate::class)->create(['name' => 'Real lease', 'slug' => 'real-lease', 'type' => 'non-exclusive'], $operator);
        $this->assertSame('real-lease', $template->slug);
        $this->catalog(['--check' => true])->expectsOutputToContain('Admission passed')->assertSuccessful();
        LicenseFixtures::approved($operator, $reviewer);
        $this->assertRefusedWithoutWrites([], 'runs only on an empty or synthetic-only catalog');
    }
}
