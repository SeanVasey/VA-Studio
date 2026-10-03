<?php

namespace Tests\Feature;

use App\Application\Media\MediaIntegrity;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\ReadTrackPublicationManifest;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Catalog\TrackPublicationManifest;
use App\Domain\Catalog\VerifyTrackPublicationFiles;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\VerifiedMedia;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class TrackPublicationApplyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function fixture(): array
    {
        $fixture = QuoteFixtures::selection();
        $fixture['track'] = app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);

        return $fixture;
    }

    private function state(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), [
            'tracks', 'offers', 'offer_revisions', 'rights_declarations', 'license_versions', 'media_assets',
            'media_processing_runs', 'rights_scopes', 'exclusive_sales', 'orders', 'license_grants', 'audit_events',
        ]);
    }

    private function rejected(callable $operation): void
    {
        $before = $this->state();
        try {
            $operation();
            $this->fail('Changed or malformed publication evidence was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('publication', $error->errors());
        }
        $this->assertSame($before, $this->state());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_read_only_schema_two_review_applies_exact_current_evidence_once_with_atomic_hash_audit(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture();
        $publisher = app(PublishTrack::class);
        $before = $this->state();
        $review = $publisher->reviewManifest($track, $actor);
        $this->assertSame($before, $this->state());
        $this->assertSame(['schema_version', 'actor_id', 'track_id', 'intent', 'metadata_version', 'publication_version', 'status', 'manifest_hash'], array_keys($review));
        $this->assertSame(2, $review['schema_version']);
        $this->assertSame($actor->id, $review['actor_id']);
        $this->assertSame(app(ReadTrackPublicationManifest::class)->handle($track->id, $actor)->hash(), $review['manifest_hash']);
        $this->freezeSecond();
        $published = $publisher->publishManifestReviewed($review, $actor);
        $this->assertSame('published', $published->status);
        $this->assertSame($track->metadata_version, $published->metadata_version);
        $this->assertSame($track->publication_version + 1, $published->publication_version);
        $this->assertSame(now()->toISOString(), $published->published_at->toISOString());
        $audit = DB::table('audit_events')->latest('id')->first();
        $this->assertSame('catalog.track.published', $audit->action);
        $this->assertSame($actor->id, (int) $audit->actor_id);
        $data = json_decode($audit->context, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame($review['manifest_hash'], $data['manifest_hash']);
        $this->assertSame(1, $data['manifest_schema_version']);
        $this->rejected(fn () => $publisher->publishManifestReviewed($review, $actor));
    }

    public function test_only_exact_schema_two_shape_is_accepted_and_review_cannot_be_transferred_to_another_operator(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture();
        $publisher = app(PublishTrack::class);
        $review = $publisher->reviewManifest($track, $actor);
        $old = $publisher->review($track, $actor, 'publish');
        $this->rejected(fn () => $publisher->publishManifestReviewed($old, $actor));
        foreach (['schema_version' => 1, 'actor_id' => (string) $actor->id, 'track_id' => (string) $track->id,
            'intent' => 'unpublish', 'metadata_version' => -1, 'publication_version' => 1.0, 'status' => 'published',
            'manifest_hash' => strtoupper($review['manifest_hash']), 'unexpected' => true] as $key => $value) {
            $this->rejected(fn () => $publisher->publishManifestReviewed([...$review, $key => $value], $actor));
        }
        foreach (array_keys($review) as $key) {
            $missing = $review;
            unset($missing[$key]);
            $this->rejected(fn () => $publisher->publishManifestReviewed($missing, $actor));
        }
        $other = LicenseFixtures::admin();
        $before = $this->state();
        try {
            $publisher->publishManifestReviewed($review, $other);
            $this->fail('A second operator applied another operator review.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, $this->state());
    }

    public static function changes(): array
    {
        return ['metadata' => ['metadata'], 'publication ABA' => ['aba'], 'commercial successor' => ['offer']];
    }

    #[DataProvider('changes')]
    public function test_changed_ready_evidence_and_publication_aba_need_a_new_review(string $change): void
    {
        ['actor' => $actor, 'track' => $track, 'offer' => $offer] = $this->fixture();
        $publisher = app(PublishTrack::class);
        $review = $publisher->reviewManifest($track, $actor);
        if ($change === 'metadata') {
            app(SaveTrackMetadata::class)->handle($track, ['mood' => 'Reviewed successor', 'metadata_version' => $track->metadata_version], $actor);
        } elseif ($change === 'aba') {
            $this->freezeSecond();
            $publisher->handle($track, $actor);
            $publisher->unpublish($track, $actor);
        } else {
            app(SaveOfferDraft::class)->handle($offer, ['price_minor' => $offer->price_minor + 1], $actor);
            app(PublishOffer::class)->handle($offer, $actor);
            $this->assertSame($review['metadata_version'], $track->fresh()->metadata_version);
            $this->assertSame($review['publication_version'], $track->fresh()->publication_version);
        }
        $this->rejected(fn () => $publisher->publishManifestReviewed($review, $actor));
        $fresh = $publisher->reviewManifest($track, $actor);
        $this->assertNotSame($review['manifest_hash'], $fresh['manifest_hash']);
        $this->assertSame('published', $publisher->publishManifestReviewed($fresh, $actor)->status);
    }

    public function test_scope_block_and_unblock_aba_changes_the_reviewed_control_version(): void
    {
        ExclusiveSelectionFixtures::configure();
        $fixture = ExclusiveSelectionFixtures::active();
        $publisher = app(PublishTrack::class);
        $track = $publisher->unpublish($fixture['track'], $fixture['actor']);
        $review = $publisher->reviewManifest($track, $fixture['actor']);
        $scopes = app(ManageRightsScope::class);
        $scopes->block($fixture['scope']->id, true, 0, 'TEST-PUBLICATION-BLOCK', $fixture['actor']);
        $this->rejected(fn () => $publisher->publishManifestReviewed($review, $fixture['actor']));
        $scopes->block($fixture['scope']->id, false, 1, 'TEST-PUBLICATION-UNBLOCK', $fixture['actor']);
        $this->rejected(fn () => $publisher->publishManifestReviewed($review, $fixture['actor']));
        $fresh = $publisher->reviewManifest($track, $fixture['actor']);
        $this->assertNotSame($review['manifest_hash'], $fresh['manifest_hash']);
        $this->assertSame('published', $publisher->publishManifestReviewed($fresh, $fixture['actor'])->status);
    }

    public static function revocations(): array
    {
        return ['role' => ['is_admin', false], 'email' => ['email_verified_at', null], 'MFA' => ['app_authentication_secret', null]];
    }

    #[DataProvider('revocations')]
    public function test_both_commands_require_current_persisted_staff_and_mfa(string $field, mixed $value): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture();
        $publisher = app(PublishTrack::class);
        $review = $publisher->reviewManifest($track, $actor);
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        DB::table('users')->where('id', $actor->id)->update([$field => $value]);
        $before = $this->state();
        try {
            foreach ([fn () => $publisher->reviewManifest($track, $actor), fn () => $publisher->publishManifestReviewed($review, $actor)] as $call) {
                try {
                    $call();
                    $this->fail('A stale eligible caller bypassed fresh authority.');
                } catch (AuthorizationException) {
                }
                $this->assertSame($before, $this->state());
            }
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public function test_ambient_transactions_are_rejected_before_any_private_read(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture();
        $publisher = app(PublishTrack::class);
        $review = $publisher->reviewManifest($track, $actor);
        DB::beginTransaction();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            foreach ([fn () => $publisher->reviewManifest($track, $actor), fn () => $publisher->publishManifestReviewed($review, $actor)] as $call) {
                try {
                    $call();
                    $this->fail('A reviewed publication command inherited a caller transaction.');
                } catch (LogicException) {
                }
                $this->assertSame([], DB::getQueryLog());
                $this->assertSame(1, DB::transactionLevel());
            }
        } finally {
            DB::disableQueryLog();
            DB::rollBack();
        }
    }

    public function test_audit_failure_rolls_back_the_publication_and_preserves_review_for_explicit_recovery(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture();
        $publisher = app(PublishTrack::class);
        $review = $publisher->reviewManifest($track, $actor);
        $before = $this->state();
        $fail = true;
        DB::connection()->beforeExecuting(function ($query) use (&$fail): void {
            if ($fail && preg_match('/\Ainsert\s+into\s+["`]?audit_events/i', $query)) {
                throw new RuntimeException('Synthetic publication audit failure');
            }
        });
        try {
            $publisher->publishManifestReviewed($review, $actor);
            $this->fail('An audit failure allowed the publication write.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic publication audit failure', $error->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame($before, $this->state());
        $this->assertSame('published', $publisher->publishManifestReviewed($publisher->reviewManifest($track, $actor), $actor)->status);
    }

    public static function roles(): array
    {
        return ['artwork' => ['artwork'], 'preview' => ['preview_tagged'], 'deliverable' => ['master_wav']];
    }

    #[DataProvider('roles')]
    public function test_a_warm_positive_integrity_cache_never_replaces_fresh_publication_hashing(string $role): void
    {
        $fixture = $this->fixture();
        $publisher = app(PublishTrack::class);
        $review = $publisher->reviewManifest($fixture['track'], $fixture['actor']);
        $asset = $fixture['media'][$role];
        $path = Storage::disk('local')->path($asset->storage_path);
        $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        chmod($path, 0600);
        file_put_contents($path, $bytes);
        clearstatcache(true, $path);
        $identity = array_intersect_key(lstat($path), array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
        $key = 'media-integrity:'.hash('sha256', json_encode([$asset->id, $path, $asset->sha256, $identity], JSON_THROW_ON_ERROR));
        Cache::put($key, true, MediaIntegrity::CACHE_SECONDS);
        $this->assertTrue(app(VerifiedMedia::class)->available($asset), 'This fixture must demonstrate a warm positive cache entry.');
        $this->rejected(fn () => $publisher->reviewManifest($fixture['track'], $fixture['actor']));
        $this->rejected(fn () => $publisher->publishManifestReviewed($review, $fixture['actor']));
    }

    public function test_stems_only_offer_still_hashes_the_attested_recording_master(): void
    {
        $fixture = $this->fixture();
        $actor = $fixture['actor'];
        $track = $fixture['track'];
        $source = MediaFixtures::source($track, 'stems_zip', StemsFixtures::zip([['name' => 'Parts/Synthetic.wav']]));
        $run = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $actor)->id);
        $stems = $run->outputs()->sole();
        app(BindStemsToRecording::class)->handle($stems, ['master_asset_id' => $fixture['media']['master_wav']->id,
            'preview_asset_id' => $fixture['media']['preview_tagged']->id,
            'verification_reference' => 'SYNTHETIC-PUBLICATION-STEMS', 'same_recording_confirmed' => true], $actor);
        $license = LicenseFixtures::published($actor, terms: ['schema_version' => 1,
            'features' => ['Synthetic stems'], 'required_asset_roles' => ['stems_zip']]);
        app(SaveOfferDraft::class)->handle($fixture['offer'], ['license_version_id' => $license->id, 'deliverable_asset_ids' => [$stems->id]], $actor);
        app(PublishOffer::class)->handle($fixture['offer'], $actor);
        $manifest = app(ReadTrackPublicationManifest::class)->handle($track->id, $actor);
        $this->assertSame(['stems_zip'], array_column($manifest->payload()['offers'][0]['deliverables'], 'role'));
        $publisher = app(PublishTrack::class);
        $review = $publisher->reviewManifest($track, $actor);
        $asset = $fixture['media']['master_wav'];
        $path = Storage::disk('local')->path($asset->storage_path);
        $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        chmod($path, 0600);
        file_put_contents($path, $bytes);
        clearstatcache(true, $path);
        $identity = array_intersect_key(lstat($path), array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
        $key = 'media-integrity:'.hash('sha256', json_encode([$asset->id, $path, $asset->sha256, $identity], JSON_THROW_ON_ERROR));
        Cache::put($key, true, MediaIntegrity::CACHE_SECONDS);
        $this->assertTrue(app(VerifiedMedia::class)->available($asset));
        $this->assertSame($manifest->hash(), app(ReadTrackPublicationManifest::class)->handle($track->id, $actor)->hash());
        $this->rejected(fn () => $publisher->publishManifestReviewed($review, $actor));
    }

    public function test_missing_actor_and_capacity_exhaustion_do_not_reach_publication(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture();
        $publisher = app(PublishTrack::class);
        $review = $publisher->reviewManifest($track, $actor);
        $missing = new User(['is_admin' => true]);
        foreach ([fn () => $publisher->reviewManifest($track, $missing), fn () => $publisher->publishManifestReviewed($review, $missing)] as $call) {
            try {
                $call();
                $this->fail('An unpersisted actor reached publication.');
            } catch (AuthorizationException) {
            }
        }
        DB::table('tracks')->where('id', $track->id)->update(['publication_version' => 2147483647]);
        $this->rejected(fn () => $publisher->reviewManifest($track, $actor));
        $this->rejected(fn () => $publisher->publishManifestReviewed([...$review, 'publication_version' => 2147483647], $actor));
    }

    public function test_license_expiry_during_hashing_is_checked_at_one_post_hash_decision_instant(): void
    {
        $this->freezeSecond();
        $fixture = $this->fixture();
        $license = LicenseFixtures::published($fixture['actor'], content: ['effective_from' => now()->subMinute(), 'effective_until' => now()->addMinute()]);
        app(SaveOfferDraft::class)->handle($fixture['offer'], ['license_version_id' => $license->id], $fixture['actor']);
        app(PublishOffer::class)->handle($fixture['offer'], $fixture['actor']);
        $publisher = app(PublishTrack::class);
        $review = $publisher->reviewManifest($fixture['track'], $fixture['actor']);
        $this->app->instance(VerifyTrackPublicationFiles::class, new class($license->effective_until->toISOString()) extends VerifyTrackPublicationFiles
        {
            public function __construct(private string $boundary) {}

            public function handle(TrackPublicationManifest $manifest): void
            {
                parent::handle($manifest);
                CarbonImmutable::setTestNow(CarbonImmutable::parse($this->boundary));
                Carbon::setTestNow(CarbonImmutable::parse($this->boundary));
            }
        });
        $this->rejected(fn () => $publisher->publishManifestReviewed($review, $fixture['actor']));
        $this->assertSame($license->effective_until->toISOString(), now()->toISOString());
    }

    public function test_replacing_the_inspected_path_after_hashing_is_rejected_even_with_identical_bytes(): void
    {
        $fixture = $this->fixture();
        $manifest = app(ReadTrackPublicationManifest::class)->handle($fixture['track']->id, $fixture['actor']);
        $asset = collect($fixture['media'])->sortBy('id')->first();
        $relative = $asset->storage_path;
        $files = new class($relative) extends PrivateMediaFiles
        {
            private int $calls = 0;

            public bool $replaced = false;

            public function __construct(private string $target) {}

            public function resolve(string $relative): string
            {
                $path = parent::resolve($relative);
                if ($relative === $this->target && ++$this->calls === 2) {
                    $bytes = file_get_contents($path);
                    rename($path, $path.'.retained');
                    file_put_contents($path, $bytes);
                    $this->replaced = true;
                }

                return $path;
            }
        };
        $this->app->instance(PrivateMediaFiles::class, $files);
        $this->rejected(fn () => app(VerifyTrackPublicationFiles::class)->handle($manifest));
        $this->assertTrue($files->replaced);
    }
}
