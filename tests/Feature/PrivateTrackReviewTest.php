<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\ReadPrivateTrackReview;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\VerifiedMedia;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class PrivateTrackReviewTest extends TestCase
{
    // The reader deliberately refuses caller-owned transactions. These fixtures are committed.
    use DatabaseMigrations;

    private const TRACK_FIELDS = ['id', 'metadata_version', 'status', 'title', 'slug', 'artist', 'bpm',
        'musical_key', 'genre', 'mood', 'tags', 'description'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function draft(User $actor, array $metadata = []): Track
    {
        return app(SaveTrackMetadata::class)->handle(null, $metadata + [
            'title' => 'Synthetic private review', 'slug' => 'synthetic-private-review', 'artist' => 'Synthetic private artist',
            'bpm' => 92, 'musical_key' => 'D minor', 'genre' => 'Synthetic genre', 'mood' => 'Reflective',
            'tags' => ['Last', 'First', 'last'], 'description' => 'Private synthetic working note',
        ], $actor);
    }

    private function review(Track $track, User $actor): array
    {
        $this->assertSame(0, DB::transactionLevel(), 'Private review must own its complete read transaction.');

        return app(ReadPrivateTrackReview::class)->handle($track->id, $actor);
    }

    private function evidence(): array
    {
        $tables = ['tracks', 'media_assets', 'media_processing_runs', 'rights_declarations', 'license_templates', 'license_versions',
            'offers', 'offer_revisions', 'quotes', 'quote_lines', 'quote_pricings', 'inventory_reservations', 'inventory_claims',
            'orders', 'order_lines', 'order_attempts', 'verified_payments', 'license_grants', 'grant_contracts',
            'site_releases', 'site_publications', 'site_publication_revisions', 'site_publication_schedules', 'audit_events'];
        $rows = array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
        $files = Storage::disk('local')->allFiles();
        sort($files, SORT_STRING);

        return [$rows, array_map(fn ($path) => [$path, hash_file('sha256', Storage::disk('local')->path($path))], $files)];
    }

    private function keysAre(array $expected, array $value): void
    {
        $this->assertEqualsCanonicalizing($expected, array_keys($value));
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unauthorized actor received a private track projection.');
        } catch (AuthorizationException) {
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_exact_minimized_projection_preserves_literal_metadata_and_matches_current_readiness_without_any_write(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        DB::table('tracks')->where('id', $track->id)->update(['duration_seconds' => 999, 'waveform' => '[0.9,0.1]']);
        $track->refresh();
        $before = $this->evidence();
        $projection = $this->review($track, $actor);
        $this->keysAre(['track', 'readiness', 'artwork', 'preview_tagged'], $projection);
        $this->keysAre(self::TRACK_FIELDS, $projection['track']);
        $expected = $track->only(self::TRACK_FIELDS);
        ksort($expected, SORT_STRING);
        $actual = $projection['track'];
        ksort($actual, SORT_STRING);
        $this->assertSame($expected, $actual);
        $this->assertSame(['Last', 'First', 'last'], $projection['track']['tags']);
        $blockers = app(PublicationReadiness::class)->blockers($track->fresh());
        $this->assertNotEmpty($blockers);
        $this->assertSame(['ready' => false, 'blockers' => $blockers], $projection['readiness']);
        foreach (['artwork', 'preview_tagged'] as $role) {
            $this->assertSame(['state' => 'missing', 'asset_id' => null, 'url' => null], $projection[$role]);
        }
        foreach (['duration_seconds', 'waveform', 'storage_path', 'sha256', 'license_version_id', 'price_minor', 'rights_declarations', 'published_at'] as $excluded) {
            $this->assertArrayNotHasKey($excluded, $projection['track']);
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
        $this->get('/tracks/'.$track->slug)->assertNotFound();
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
    }

    public function test_legacy_nullable_metadata_is_projected_without_read_time_normalization_or_revision_change(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        DB::table('tracks')->where('id', $track->id)->update(['artist' => '  Legacy artist  ', 'tags' => null, 'mood' => null]);
        $before = $this->evidence();
        $projection = $this->review($track, $actor);
        $this->assertSame('  Legacy artist  ', $projection['track']['artist']);
        $this->assertNull($projection['track']['tags']);
        $this->assertNull($projection['track']['mood']);
        $this->assertSame(1, $projection['track']['metadata_version']);
        $this->assertSame($before, $this->evidence());
    }

    public function test_service_refuses_ambient_transactions_before_private_queries_and_keeps_the_callers_transaction_open(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $before = $this->evidence();
        $queries = [];
        $inspect = false;
        DB::listen(function ($query) use (&$queries, &$inspect): void {
            if ($inspect && (str_contains($query->sql, 'tracks') || str_contains($query->sql, 'users'))) {
                $queries[] = $query->sql;
            }
        });
        DB::beginTransaction();
        try {
            $inspect = true;
            try {
                app(ReadPrivateTrackReview::class)->handle($track->id, $actor);
                $this->fail('Caller-owned transaction was accepted for private review.');
            } catch (LogicException $error) {
                $this->assertSame('Private track review requires a standalone transaction.', $error->getMessage());
            }
            $this->assertSame([], $queries);
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            $inspect = false;
            DB::rollBack();
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_missing_deleted_and_nonpositive_track_ids_cannot_return_a_projection_or_change_evidence(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $id = $track->id;
        $track->delete();
        $before = $this->evidence();
        foreach ([$id, 0, -1, PHP_INT_MAX] as $missing) {
            try {
                app(ReadPrivateTrackReview::class)->handle($missing, $actor);
                $this->fail('An unavailable track returned private metadata.');
            } catch (ModelNotFoundException $error) {
                $this->assertSame(Track::class, $error->getModel());
            }
            $this->assertSame($before, $this->evidence());
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function test_real_filament_cannot_retain_a_deleted_review_or_mount_a_forged_missing_track(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $this->actingAs($actor);
        $page = Livewire::test(ManageTracks::class)->mountTableAction('reviewTrack', $track)->call('forceRender')
            ->assertSee($track->description);
        $track->delete();
        $before = $this->evidence();
        $page->call('forceRender')->assertSet('mountedActions', [])->assertDontSee($track->description)
            ->assertDontSee('Ready to publish');
        foreach ([$track->id, PHP_INT_MAX] as $missing) {
            $page->mountTableAction('reviewTrack', (string) $missing)->call('forceRender')
                ->assertSet('mountedActions', [])->assertDontSee($track->description)->assertDontSee('Ready to publish');
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_customers_unverified_deleted_and_locally_elevated_actor_models_cannot_receive_private_metadata(): void
    {
        $author = LicenseFixtures::admin();
        $track = $this->draft($author);
        $customer = User::factory()->create();
        $forged = User::factory()->create();
        $forged->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        $unverified = LicenseFixtures::admin();
        User::whereKey($unverified->id)->update(['email_verified_at' => null]);
        $deleted = LicenseFixtures::admin();
        $deleted->delete();
        $unsaved = new User;
        $unsaved->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        $before = $this->evidence();
        foreach ([$customer, $forged, $unverified, $deleted, $unsaved] as $denied) {
            $this->assertDenied(fn () => app(ReadPrivateTrackReview::class)->handle($track->id, $denied));
        }
        $this->assertSame($before, $this->evidence());
        foreach ([$customer, $forged, $unverified] as $denied) {
            $this->actingAs($denied);
            Livewire::test(ManageTracks::class)->assertForbidden();
        }
        $this->assertSame($before, $this->evidence());
    }

    public static function authorityWithdrawals(): array
    {
        return ['staff role' => ['is_admin', false], 'email verification' => ['email_verified_at', null]];
    }

    #[DataProvider('authorityWithdrawals')]
    public function test_mounted_review_and_direct_service_recheck_persisted_authority_before_each_private_return(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $this->actingAs($actor);
        $page = Livewire::test(ManageTracks::class)->mountTableAction('reviewTrack', $track)->call('forceRender')
            ->assertSee('Private track review')->assertSee($track->description);
        User::whereKey($actor->id)->update([$field => $value]);
        $before = $this->evidence();
        $this->assertDenied(fn () => $this->review($track, $actor));
        $page->call('forceRender')->assertForbidden();
        $this->assertSame($before, $this->evidence());
    }

    public function test_required_mfa_uses_current_persisted_enrollment_and_mounted_review_cannot_reuse_removed_enrollment(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $this->actingAs($actor);
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $this->assertDenied(fn () => $this->review($track, $actor));
            Livewire::test(ManageTracks::class)->assertForbidden();
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $this->assertSame($track->id, $this->review($track, $actor)['track']['id']);
            $actor->forceFill(['is_admin' => false, 'email_verified_at' => null, 'app_authentication_secret' => null]);
            $this->assertSame($track->id, $this->review($track, $actor)['track']['id'], 'Persisted current authority must outrank stale local actor fields.');
            $page = Livewire::test(ManageTracks::class)->mountTableAction('reviewTrack', $track)->call('forceRender')->assertSee('Private track review');
            User::findOrFail($actor->id)->saveAppAuthenticationSecret(null);
            $before = $this->evidence();
            $this->assertDenied(fn () => $this->review($track, $actor));
            $page->call('forceRender')->assertForbidden();
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_real_filament_review_is_close_only_escapes_malicious_metadata_and_refreshes_current_rows_without_writes(): void
    {
        $actor = LicenseFixtures::admin();
        $title = '<svg onload="window.syntheticPrivateTitle=true">Synthetic title</svg>';
        $note = '<script>window.syntheticPrivateNote=true</script><img src=x onerror="window.syntheticPrivateImage=true">';
        $track = $this->draft($actor, ['title' => $title, 'description' => $note]);
        $this->actingAs($actor);
        $before = $this->evidence();
        $page = Livewire::test(ManageTracks::class)->mountTableAction('reviewTrack', $track)->call('forceRender')
            ->assertSee('Private track review')->assertSee($title)->assertSee($note);
        $action = $page->instance()->getMountedAction();
        $this->assertNull($action->getModalSubmitAction());
        $this->assertSame('Close', $action->getModalCancelActionLabel());
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($page->html());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $this->assertCount(0, $xpath->query('//script[contains(text(), "syntheticPrivateNote")]'));
        $this->assertCount(0, $xpath->query('//*[@onload="window.syntheticPrivateTitle=true" or @onerror="window.syntheticPrivateImage=true"]'));
        $this->assertSame($before, $this->evidence());
        $track = app(SaveTrackMetadata::class)->handle($track, ['metadata_version' => 1, 'title' => 'Fresh private title', 'description' => 'Fresh private note'], $actor);
        $before = $this->evidence();
        $page->call('forceRender')->assertSee('Fresh private title')->assertSee('Fresh private note');
        $this->assertSame(2, $this->review($track, $actor)['track']['metadata_version']);
        $page->unmountAction()->assertSet('mountedActions', []);
        $this->assertSame($before, $this->evidence());
        $this->get('/tracks/'.$track->slug)->assertNotFound();
    }

    public function test_missing_media_and_incomplete_readiness_render_unavailable_without_fabricated_ready_or_media_urls(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor, ['bpm' => null, 'musical_key' => null, 'genre' => null]);
        $this->actingAs($actor);
        $before = $this->evidence();
        $page = Livewire::test(ManageTracks::class)->mountTableAction('reviewTrack', $track)->call('forceRender')
            ->assertSee('Artwork unavailable')->assertSee('Tagged preview unavailable');
        foreach (app(PublicationReadiness::class)->blockers($track->fresh()) as $blocker) {
            $page->assertSee($blocker);
        }
        $this->assertSame(false, $this->review($track, $actor)['readiness']['ready']);
        $this->assertStringNotContainsString('/admin/media/', $page->html());
        $this->assertSame($before, $this->evidence());
    }

    public function test_real_verified_derivatives_use_existing_relative_protected_routes_and_all_original_deliverable_roles_stay_private(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $media = MediaFixtures::readyTrackMedia($track, $actor);
        $source = MediaFixtures::source($track, 'stems_zip', StemsFixtures::zip([['name' => 'Parts/Synthetic.wav']]));
        $run = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $actor)->id);
        $media['stems_zip'] = $run->outputs()->sole();
        $this->assertTrue(app(VerifiedMedia::class)->available($media['stems_zip']));
        $before = $this->evidence();
        $projection = $this->review($track, $actor);
        foreach (['artwork', 'preview_tagged'] as $role) {
            $this->assertTrue(app(VerifiedMedia::class)->available($media[$role]));
            $this->assertSame(['state' => 'available', 'asset_id' => $media[$role]->id, 'url' => '/admin/media/'.$media[$role]->id.'/preview'], $projection[$role]);
            $this->get($projection[$role]['url'])->assertRedirect('/admin/login');
            $this->actingAs(User::factory()->create())->get($projection[$role]['url'])->assertForbidden();
            $response = $this->actingAs($actor)->get($projection[$role]['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertSame(Storage::disk('local')->path($media[$role]->storage_path), $response->baseResponse->getFile()->getPathname());
            $this->get('/media/'.$media[$role]->id)->assertNotFound();
            auth()->logout();
        }
        $this->actingAs($actor);
        foreach (['master_wav', 'download_mp3', 'stems_zip'] as $role) {
            $this->get('/admin/media/'.$media[$role]->id.'/preview')->assertNotFound();
            $this->get('/media/'.$media[$role]->id)->assertNotFound();
            $this->assertArrayNotHasKey($role, $projection);
        }
        $encoded = json_encode($projection, JSON_THROW_ON_ERROR);
        foreach ($media as $asset) {
            $this->assertStringNotContainsString($asset->storage_path, $encoded);
            $this->assertStringNotContainsString($asset->sha256, $encoded);
        }
        $this->assertStringNotContainsString('quarantine/', $encoded);
        $this->assertStringNotContainsString('media/revisions/', $encoded);
        $this->assertSame($before, $this->evidence());
    }

    public function test_invalid_latest_ready_assets_never_fall_back_to_an_older_valid_revision_or_expose_private_source_paths(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $media = MediaFixtures::readyTrackMedia($track, $actor);
        $bad = [];
        foreach (['artwork', 'preview_tagged'] as $role) {
            $attributes = $media[$role]->getAttributes();
            unset($attributes['id']);
            // Adversarial database-only ready row; no completed processing proof is fabricated.
            $attributes['processing_run_id'] = null;
            $attributes['storage_path'] = 'private/SYNTHETIC-UNSAFE-'.$role;
            $bad[$role] = DB::table('media_assets')->insertGetId($attributes);
            $this->assertTrue(app(VerifiedMedia::class)->available($media[$role]), 'The historical derivative remains valid and must not be silently selected.');
        }
        $before = $this->evidence();
        $projection = $this->review($track, $actor);
        foreach (['artwork', 'preview_tagged'] as $role) {
            $this->assertSame(['state' => 'unavailable', 'asset_id' => $bad[$role], 'url' => null], $projection[$role]);
        }
        $this->assertFalse($projection['readiness']['ready']);
        $this->assertSame(app(PublicationReadiness::class)->blockers($track->fresh()), $projection['readiness']['blockers']);
        $this->actingAs($actor);
        $page = Livewire::test(ManageTracks::class)->mountTableAction('reviewTrack', $track)->call('forceRender')
            ->assertSee('Artwork unavailable')->assertSee('Tagged preview unavailable');
        foreach (['artwork', 'preview_tagged'] as $role) {
            $this->assertStringNotContainsString('/admin/media/'.$media[$role]->id.'/preview', $page->html());
            $this->assertStringNotContainsString('/admin/media/'.$bad[$role].'/preview', $page->html());
        }
        $page->assertDontSee('SYNTHETIC-UNSAFE');
        $this->assertSame($before, $this->evidence());
    }

    public function test_missing_or_damaged_current_files_become_unavailable_and_do_not_change_database_evidence(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $media = MediaFixtures::readyTrackMedia($track, $actor);
        $this->assertSame('available', $this->review($track, $actor)['artwork']['state']);
        Storage::disk('local')->delete($media['artwork']->storage_path);
        chmod(Storage::disk('local')->path($media['preview_tagged']->storage_path), 0600);
        Storage::disk('local')->put($media['preview_tagged']->storage_path, 'Synthetic damaged short bytes');
        $before = $this->evidence();
        $projection = $this->review($track, $actor);
        foreach (['artwork', 'preview_tagged'] as $role) {
            $this->assertSame(['state' => 'unavailable', 'asset_id' => $media[$role]->id, 'url' => null], $projection[$role]);
        }
        $this->assertSame(false, $projection['readiness']['ready']);
        $this->assertSame($before, $this->evidence());
    }

    public function test_current_readiness_can_be_ready_without_changing_publication_or_retained_commerce_evidence(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = $fixture['track'];
        app(CreateQuote::class)->handle(str_repeat('a', 64), 'synthetic-private-track-retained-quote', $fixture['items']);
        $before = $this->evidence();
        $projection = $this->review($track, $actor);
        $this->assertSame('published', $projection['track']['status']);
        $this->assertSame(['ready' => true, 'blockers' => []], $projection['readiness']);
        $this->assertSame([], app(PublicationReadiness::class)->blockers($track->fresh()));
        $this->keysAre(self::TRACK_FIELDS, $projection['track']);
        $this->assertArrayNotHasKey('offers', $projection);
        $this->assertArrayNotHasKey('rights', $projection);
        $this->actingAs($actor);
        Livewire::test(ManageTracks::class)->mountTableAction('reviewTrack', $track)->call('forceRender')->assertSee('Private track review');
        $this->assertSame($before, $this->evidence());
        $this->assertSame($track->published_slug, $track->fresh()->published_slug);
        $this->get('/tracks/'.$track->slug)->assertOk();
    }

    public function test_unexpected_readiness_failure_cannot_render_ready_or_return_sensitive_exception_details(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $this->actingAs($actor);
        $before = $this->evidence();
        $page = Livewire::test(ManageTracks::class)->mountTableAction('reviewTrack', $track)->call('forceRender')
            ->assertSee($track->description);
        $readiness = new class extends PublicationReadiness
        {
            public int $calls = 0;

            public function blockers(Track $track): array
            {
                $this->calls++;

                throw new RuntimeException('SYNTHETIC-PRIVATE-READINESS-DETAILS private/master.wav');
            }
        };
        $this->app->instance(PublicationReadiness::class, $readiness);
        // Livewire's helper deliberately rethrows unexpected exceptions. Send its valid mounted
        // snapshot through the actual HTTP route so the production exception response is exercised.
        $response = $this->withHeader('X-Livewire', 'true')->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => json_encode($page->snapshot, JSON_THROW_ON_ERROR),
                'updates' => [],
                'calls' => [['method' => 'forceRender', 'params' => [], 'path' => '']],
            ]],
        ])->assertStatus(503)->assertContent('Private track review is unavailable.')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDontSee('Ready to publish')->assertDontSee($track->description)
            ->assertDontSee('SYNTHETIC-PRIVATE-READINESS-DETAILS')->assertDontSee('private/master.wav');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
        $this->assertGreaterThan(0, $readiness->calls, 'The actual mounted review must reach the injected readiness failure.');
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }
}
