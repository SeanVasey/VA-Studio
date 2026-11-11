<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublicCatalog;
use App\Domain\Media\AudioDerivatives;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\MediaProfile;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\PreviewTag\PreviewTagPolicy;
use App\Domain\Media\PreviewTag\PreviewTagResolver;
use App\Domain\Media\PreviewTag\PreviewTagSetting;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\RetagPreviews;
use App\Domain\Media\VerifiedMedia;
use App\Filament\Pages\PreviewTagSettingsPage;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/**
 * The preview-tag setting (Sean, 2026-11-11): processing keeps a tagged and an untagged public preview from one master; a
 * store default (on) and a per-track override choose which one the public hears, at once; a missing or unverified untagged
 * variant falls back to the tagged preview, never to the full MP3; the switch never touches an offer or publication.
 */
class PreviewTagTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
    }

    private function requireMfa(bool $required): bool
    {
        $panel = Filament::getPanel('admin');
        $was = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);

        return $was;
    }

    /** A published track with real media, a verified license and an active offer. */
    private function selling(): array
    {
        $fixture = QuoteFixtures::selection();
        $assets = fn (string $role) => $fixture['track']->assets()->where('role', $role)->where('status', 'ready')->latest('id')->first();

        return $fixture + ['tagged' => $assets('preview_tagged'), 'untagged' => $assets('preview_untagged')];
    }

    /** A draft track with processed media only. */
    private function draft(string $slug = 'preview-tag-draft'): array
    {
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        MediaFixtures::configure();
        $track = Track::create(['title' => 'Synthetic '.$slug, 'slug' => $slug]);
        $media = MediaFixtures::readyTrackMedia($track, $actor);

        return ['actor' => $actor, 'track' => $track->fresh(), 'media' => $media];
    }

    /** Turns a processed master into what a run made before the untagged preview existed: three outputs, the older profile. */
    private function makeLegacy(Track $track): void
    {
        $untagged = MediaAsset::query()->where('track_id', $track->id)->where('role', 'preview_untagged')->orderByDesc('id')->firstOrFail();
        $run = MediaProcessingRun::query()->findOrFail($untagged->processing_run_id);
        foreach (['media_assets', 'media_processing_runs'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable_'.$operation);
            }
        }
        DB::table('media_assets')->where('id', $untagged->id)->delete();
        $profile = $run->profile;
        unset($profile['untagged_preview']);
        DB::table('media_processing_runs')->where('id', $run->id)->update([
            'output_asset_ids' => json_encode(array_values(array_diff($run->output_asset_ids, [$untagged->id]))),
            'profile' => json_encode($profile), 'profile_fingerprint' => app(MediaProfile::class)->fingerprint($profile),
        ]);
        Storage::disk('local')->delete($untagged->storage_path);
    }

    private function bytes(MediaAsset $asset): string
    {
        return file_get_contents(Storage::disk('local')->path($asset->storage_path));
    }

    private function served(MediaAsset $asset): string
    {
        $response = $this->get(route('media.public', $asset->id, false));
        $response->assertOk();

        return file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    private function catalogPreviewId(Track $track): string
    {
        return basename(parse_url(app(PublicCatalog::class)->track($track->slug)['tracks'][0]['previewUrl'], PHP_URL_PATH));
    }

    private function decode(MediaAsset $asset): array
    {
        $process = new Process([config('media.ffmpeg'), '-v', 'error', '-f', 'mp3', '-i', Storage::disk('local')->path($asset->storage_path), '-ac', '1', '-ar', '8000', '-f', 's16le', 'pipe:1']);
        $process->setTimeout(15)->mustRun();

        return array_values(array_map(fn ($value) => ($value >= 32768 ? $value - 65536 : $value) / 32768, unpack('v*', $process->getOutput())));
    }

    private function toneEnergy(array $samples, float $start, int $frequency): float
    {
        $window = array_slice($samples, (int) ($start * 8000), 400);
        $real = $imag = 0.0;
        foreach ($window as $index => $value) {
            $phase = 2 * M_PI * $frequency * $index / 8000;
            $real += $value * cos($phase);
            $imag += $value * sin($phase);
        }

        return 2 * sqrt($real ** 2 + $imag ** 2) / count($window);
    }

    public function test_processing_makes_both_previews_from_one_master_with_the_tag_hash_only_on_the_tagged_one(): void
    {
        ['media' => $media, 'track' => $track] = $this->draft();
        $tagged = $media['preview_tagged'];
        $untagged = $media['preview_untagged'];
        $this->assertSame($tagged->parent_asset_id, $untagged->parent_asset_id);
        $this->assertSame($tagged->processing_run_id, $untagged->processing_run_id);
        $this->assertCount(4, MediaProcessingRun::findOrFail($tagged->processing_run_id)->outputs()->get());
        $this->assertSame(config('media.tag_sha256'), $tagged->technical_metadata['tag_sha256']);
        $this->assertArrayNotHasKey('tag_sha256', $untagged->technical_metadata);
        $this->assertArrayNotHasKey('tag_interval_seconds', $untagged->technical_metadata);
        $this->assertFalse($untagged->technical_metadata['tagged']);
        $this->assertSame('192k', app(MediaProfile::class)->current('master_wav')['preview_bitrate']);
        $this->assertSame('audio/mpeg', $untagged->mime_type);
        $this->assertStringEndsWith('/preview-untagged.mp3', $untagged->storage_path);
        $this->assertNotSame($tagged->sha256, $untagged->sha256);
        // Same length and the same waveform source: the track shows one waveform whichever variant plays.
        $this->assertEqualsWithDelta($tagged->technical_metadata['duration_seconds'], $untagged->technical_metadata['duration_seconds'], 0.1);
        $this->assertSame($tagged->technical_metadata['waveform'], $untagged->technical_metadata['waveform']);
        $this->assertSame($tagged->technical_metadata['waveform_sha256'], $untagged->technical_metadata['waveform_sha256']);
        $this->assertSame($tagged->technical_metadata['waveform'], $track->waveform);
        foreach ([$tagged, $untagged] as $asset) {
            $this->assertTrue(app(VerifiedMedia::class)->available($asset));
        }
        // Decode both files: the synthetic 1800 Hz tag is in the tagged one only.
        $withTag = $this->toneEnergy($this->decode($tagged), 0.06, 1800);
        $withoutTag = $this->toneEnergy($this->decode($untagged), 0.06, 1800);
        $this->assertGreaterThan(0.04, $withTag);
        $this->assertLessThan($withTag * 0.1, $withoutTag);
        // The music itself is intact in the untagged file.
        $this->assertGreaterThan(0.05, $this->toneEnergy($this->decode($untagged), 0.06, 440));
    }

    public function test_a_run_with_a_wrong_role_set_or_a_tagged_looking_untagged_preview_is_not_verified(): void
    {
        ['media' => $media] = $this->draft();
        $untagged = $media['preview_untagged'];
        $this->assertNotNull(app(VerifiedMedia::class)->evidence($untagged));
        DB::unprepared('DROP TRIGGER IF EXISTS media_assets_immutable_update');
        DB::table('media_assets')->where('id', $untagged->id)->update(['technical_metadata' => json_encode($media['preview_tagged']->technical_metadata)]);
        $this->assertNull(app(VerifiedMedia::class)->evidence($untagged->fresh()));
    }

    public function test_a_run_missing_a_required_output_or_holding_an_extra_one_is_not_verified(): void
    {
        ['media' => $media] = $this->draft('role-set');
        $run = MediaProcessingRun::findOrFail($media['preview_tagged']->processing_run_id);
        $this->assertNotNull(app(VerifiedMedia::class)->evidence($media['preview_tagged']));
        foreach (['media_assets', 'media_processing_runs'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable_'.$operation);
            }
        }
        // The delivery MP3 is gone from the run (ids kept consistent): neither the three- nor the four-output set is complete.
        DB::table('media_assets')->where('id', $media['download_mp3']->id)->delete();
        DB::table('media_processing_runs')->where('id', $run->id)->update(['output_asset_ids' => json_encode(array_values(array_diff($run->output_asset_ids, [$media['download_mp3']->id])))]);
        foreach (['preview_tagged', 'preview_untagged', 'master_wav'] as $role) {
            $this->assertNull(app(VerifiedMedia::class)->evidence($media[$role]->fresh()), $role);
        }
    }

    public function test_the_untagged_sibling_must_come_from_the_same_run_as_the_current_tagged_preview(): void
    {
        ['media' => $media, 'track' => $track, 'actor' => $actor] = $this->draft('same-run');
        $source = MediaAsset::findOrFail($media['master_wav']->parent_asset_id);
        config(['media.profile_version' => 'wav-preview-v2']);
        $second = app(QueueMediaProcessing::class)->handle($source, $actor);
        $this->assertSame('completed', app(MediaProcessor::class)->handle($second->id)->status);
        // The newer run is a legacy one without an untagged output; the older run still has its own.
        $this->makeLegacy($track);
        $this->assertSame(1, MediaAsset::where('track_id', $track->id)->where('role', 'preview_untagged')->count());
        $resolver = app(PreviewTagResolver::class);
        $anchor = $resolver->anchor($track->fresh());
        $this->assertSame($second->id, $anchor->processing_run_id);
        $this->assertNull($resolver->sibling($anchor), 'An untagged preview of an older recording must never stand in for the current one.');
        $this->assertNull($resolver->verifiedUntagged($track->fresh()));
        $this->expectException(ValidationException::class);
        app(PreviewTagPolicy::class)->setTrack($track->fresh(), false, 0, $actor);
    }

    public function test_kit_demos_keep_a_single_tagged_preview(): void
    {
        MediaFixtures::configure();
        $files = app(PrivateMediaFiles::class);
        $workspace = $files->workspace();
        file_put_contents($workspace.'/master.wav', MediaFixtures::wav(1.2, 440));
        file_put_contents($workspace.'/tag.wav', MediaFixtures::wav(0.2, 1800));
        $profile = app(MediaProfile::class)->current('master_wav');
        $roles = array_column(app(AudioDerivatives::class)->build($workspace.'/master.wav', $workspace.'/tag.wav', $profile, $workspace, false), 'role');
        $this->assertSame(['master_wav', 'download_mp3', 'preview_tagged'], $roles);
        $this->assertFileDoesNotExist($workspace.'/preview-untagged.mp3');
        $files->cleanup($workspace);
    }

    public function test_default_on_serves_the_tagged_preview_and_nothing_else_is_public(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        $this->assertTrue(app(PreviewTagResolver::class)->effectiveTagged($track->id));
        $this->assertSame((string) $fixture['tagged']->id, $this->catalogPreviewId($track));
        $this->assertSame($this->bytes($fixture['tagged']), $this->served($fixture['tagged']));
        // The untagged id is asked for while the tag is on: it is sent to the tagged preview, never served.
        $redirect = $this->get(route('media.public', $fixture['untagged']->id, false));
        $redirect->assertRedirect(route('media.public', $fixture['tagged']->id));
        $this->assertStringContainsString('no-store', $redirect->headers->get('Cache-Control'));
    }

    public function test_a_track_off_serves_untagged_everywhere_the_public_hears_a_preview(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        $result = app(PreviewTagPolicy::class)->setTrack($track, false, 0, $fixture['actor']);
        $this->assertSame(['tagged' => false, 'revision' => 1, 'effective_tagged' => false], $result);
        $projection = app(PublicCatalog::class)->track($track->slug)['tracks'][0];
        $this->assertSame((string) $fixture['untagged']->id, $this->catalogPreviewId($track));
        $this->assertSame($fixture['tagged']->technical_metadata['waveform'], $projection['waveform']);
        $this->assertSame((int) ceil($fixture['tagged']->technical_metadata['duration_seconds']), (int) ceil($projection['durationSeconds']));
        $this->assertSame($this->bytes($fixture['untagged']), $this->served($fixture['untagged']));
        $this->assertNotSame($this->bytes($fixture['tagged']), $this->bytes($fixture['untagged']));
        // A page loaded before the switch still names the tagged file: it is redirected, not served.
        $this->get(route('media.public', $fixture['tagged']->id, false))->assertRedirect(route('media.public', $fixture['untagged']->id));
        // The embed follows the setting too, and a copied old embed URL stays refused.
        $embed = $this->get('/embed/tracks/'.$track->slug);
        $embed->assertOk();
        $this->assertStringContainsString(route('embeds.preview', ['slug' => $track->slug, 'asset' => $fixture['untagged']->id], false), $embed->getContent());
        $this->get(route('embeds.preview', ['slug' => $track->slug, 'asset' => $fixture['untagged']->id], false))->assertOk();
        $this->get(route('embeds.preview', ['slug' => $track->slug, 'asset' => $fixture['tagged']->id], false))->assertNotFound();
        // And back on, instantly.
        app(PreviewTagPolicy::class)->setTrack($track, null, 1, $fixture['actor']);
        $this->assertSame((string) $fixture['tagged']->id, $this->catalogPreviewId($track));
        $this->assertSame($this->bytes($fixture['tagged']), $this->served($fixture['tagged']));
    }

    public function test_the_store_default_off_applies_to_tracks_without_an_override_and_a_track_on_wins(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        $policy = app(PreviewTagPolicy::class);
        $policy->setDefault(false, 0, $fixture['actor']);
        $this->assertSame((string) $fixture['untagged']->id, $this->catalogPreviewId($track));
        $policy->setTrack($track, true, 0, $fixture['actor']);
        $this->assertSame((string) $fixture['tagged']->id, $this->catalogPreviewId($track));
        $this->assertSame($this->bytes($fixture['tagged']), $this->served($fixture['tagged']));
        // Following the default again: the default (off) applies.
        $policy->setTrack($track, null, 1, $fixture['actor']);
        $this->assertSame((string) $fixture['untagged']->id, $this->catalogPreviewId($track));
        $policy->setDefault(true, 1, $fixture['actor']);
        $this->assertSame((string) $fixture['tagged']->id, $this->catalogPreviewId($track));
    }

    public function test_off_without_an_untagged_variant_is_refused_and_a_default_off_falls_back_to_tagged(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        $this->makeLegacy($track);
        $policy = app(PreviewTagPolicy::class);
        try {
            $policy->setTrack($track, false, 0, $fixture['actor']);
            $this->fail('A track without an untagged preview must not be switched off.');
        } catch (ValidationException $refusal) {
            $this->assertSame(PreviewTagPolicy::NO_UNTAGGED, $refusal->errors()['tagged'][0]);
        }
        $this->assertSame(0, PreviewTagSetting::count());
        $this->assertSame(0, AuditEvent::where('action', 'like', 'media.preview_tag.%')->count());
        // The legacy track stays fully publishable, and an off default cannot leave it silent.
        $this->assertSame([], app(PublicationReadiness::class)->blockers($track));
        $policy->setDefault(false, 0, $fixture['actor']);
        $this->assertSame((string) $fixture['tagged']->id, $this->catalogPreviewId($track));
        $this->assertSame($this->bytes($fixture['tagged']), $this->served($fixture['tagged']));
        $this->assertSame($fixture['tagged']->id, app(PreviewTagResolver::class)->publicPreview($track->fresh())->id);
        $this->assertFalse(app(PreviewTagResolver::class)->describe($track)['untagged_recorded']);
        // Turning the tag ON for the track is always allowed.
        $this->assertTrue($policy->setTrack($track, true, 0, $fixture['actor'])['effective_tagged']);
    }

    public function test_an_unverified_untagged_file_falls_back_to_tagged_after_the_switch(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        app(PreviewTagPolicy::class)->setTrack($track, false, 0, $fixture['actor']);
        $this->assertSame((string) $fixture['untagged']->id, $this->catalogPreviewId($track));
        // The stored bytes no longer match their recorded digest.
        Storage::disk('local')->put($fixture['untagged']->storage_path, 'not the verified bytes');
        $this->assertFalse(app(VerifiedMedia::class)->available($fixture['untagged']));
        $this->assertSame((string) $fixture['tagged']->id, $this->catalogPreviewId($track));
        $this->assertSame($this->bytes($fixture['tagged']), $this->served($fixture['tagged']));
        $this->get(route('media.public', $fixture['untagged']->id, false))->assertRedirect(route('media.public', $fixture['tagged']->id));
        // A switch to untagged is refused while the variant is unverified.
        app(PreviewTagPolicy::class)->setTrack($track, null, 1, $fixture['actor']);
        $this->expectException(ValidationException::class);
        app(PreviewTagPolicy::class)->setTrack($track, false, 2, $fixture['actor']);
    }

    public function test_the_full_mp3_and_the_master_are_never_served_whatever_the_setting(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        $policy = app(PreviewTagPolicy::class);
        $resolver = app(PreviewTagResolver::class);
        $secret = [$this->bytes($fixture['media']['download_mp3']), $this->bytes($fixture['media']['master_wav'])];
        $states = [
            fn () => null,
            fn () => $policy->setDefault(false, 0, $fixture['actor']),
            fn () => $policy->setTrack($track, true, 0, $fixture['actor']),
        ];
        foreach ($states as $apply) {
            $apply();
            $this->assertContains($resolver->publicPreview($track->fresh())->role, ['preview_tagged', 'preview_untagged']);
            foreach (['download_mp3', 'master_wav'] as $role) {
                $this->get(route('media.public', $fixture['media'][$role]->id, false))->assertNotFound();
            }
        }
        // Both previews unavailable: the public gets nothing, not the delivery MP3.
        Storage::disk('local')->put($fixture['tagged']->storage_path, 'tampered');
        Storage::disk('local')->put($fixture['untagged']->storage_path, 'tampered');
        $this->assertSame($fixture['tagged']->id, $resolver->publicPreview($track->fresh())->id);
        foreach ([$fixture['tagged'], $fixture['untagged']] as $asset) {
            $response = $this->get(route('media.public', $asset->id, false));
            $this->assertContains($response->getStatusCode(), [302, 404]);
            $this->assertNotContains($response->getContent(), $secret);
        }
        $this->get(route('media.public', $fixture['tagged']->id, false))->assertNotFound();
    }

    public function test_switching_never_voids_an_offer_or_blocks_publication(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        $offer = $fixture['offer'];
        $revision = $fixture['revision'];
        $readiness = app(PublicationReadiness::class);
        $evidence = fn () => [
            $track->fresh()->only(['status', 'publication_version', 'metadata_version']), DB::table('offer_revisions')->count(),
            $offer->fresh()->current_revision_id, $revision->fresh()->snapshot_hash, $revision->fresh()->snapshot['preview'],
            DB::table('media_assets')->count(), DB::table('media_processing_runs')->count(),
        ];
        $before = $evidence();
        $this->assertSame([], $readiness->blockers($track->fresh()));
        $policy = app(PreviewTagPolicy::class);
        $steps = [
            fn () => $policy->setTrack($track, false, 0, $fixture['actor']),
            fn () => $policy->setDefault(false, 0, $fixture['actor']),
            fn () => $policy->setTrack($track, true, 1, $fixture['actor']),
            fn () => $policy->setTrack($track, null, 2, $fixture['actor']),
            fn () => $policy->setDefault(true, 1, $fixture['actor']),
        ];
        foreach ($steps as $step) {
            $step();
            $this->assertSame([], $readiness->blockers($track->fresh()));
            $this->assertSame([], $readiness->offerBlockers($offer->fresh()));
            $this->assertSame($before, $evidence());
            // The frozen snapshot still pins the tagged preview, which is the preview identity.
            $this->assertSame($fixture['tagged']->id, $revision->fresh()->snapshot['preview']['id']);
        }
        $this->assertCount(1, app(PublicCatalog::class)->track($track->slug)['tracks'][0]['offers']);
        // A draft track can be switched as well: the setting touches no publication state.
        ['track' => $draft, 'actor' => $actor] = $this->draft('switch-on-a-draft');
        $this->assertSame('draft', $draft->status);
        $this->assertFalse($policy->setTrack($draft, false, 0, $actor)['effective_tagged']);
    }

    public function test_only_catalog_staff_with_enrolled_mfa_may_change_the_setting_and_authority_is_read_under_lock(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        $policy = app(PreviewTagPolicy::class);
        $customer = User::factory()->create();
        $unverified = LicenseFixtures::admin();
        $unverified->forceFill(['email_verified_at' => null])->save();
        foreach ([$customer, $unverified] as $who) {
            foreach ([fn () => $policy->setTrack($track, false, 0, $who), fn () => $policy->setDefault(false, 0, $who)] as $attempt) {
                try {
                    $attempt();
                    $this->fail('A user without catalog authority changed the setting.');
                } catch (AuthorizationException) {
                    $this->assertTrue(true);
                }
            }
        }
        $was = $this->requireMfa(true);
        try {
            $noMfa = LicenseFixtures::admin();
            foreach ([fn () => $policy->setTrack($track, false, 0, $noMfa), fn () => $policy->setDefault(false, 0, $noMfa)] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Staff without enrolled MFA changed the setting.');
                } catch (AuthorizationException $refusal) {
                    $this->assertSame('Admin multi-factor authentication is required.', $refusal->getMessage());
                }
            }
            // The same model instance, enrolled when loaded, loses its enrollment: the lock re-reads it.
            $stale = User::findOrFail($fixture['actor']->id);
            DB::table('users')->where('id', $stale->id)->update(['app_authentication_secret' => null]);
            $this->expectException(AuthorizationException::class);
            $policy->setTrack($track, false, 0, $stale);
        } finally {
            $this->requireMfa($was);
            $this->assertSame(0, PreviewTagSetting::count());
            $this->assertSame(0, AuditEvent::where('action', 'like', 'media.preview_tag.%')->count());
        }
    }

    public function test_a_stale_expected_revision_is_refused_and_every_change_advances_the_revision(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        $policy = app(PreviewTagPolicy::class);
        $policy->setDefault(false, 0, $fixture['actor']);
        $this->assertSame(1, app(PreviewTagResolver::class)->revision());
        foreach ([fn () => $policy->setDefault(true, 0, $fixture['actor']), fn () => $policy->setTrack($track, false, 5, $fixture['actor'])] as $stale) {
            try {
                $stale();
                $this->fail('A stale revision was accepted.');
            } catch (ValidationException $refusal) {
                $this->assertStringContainsString('changed after you opened it', $refusal->errors()['tagged'][0]);
            }
        }
        $this->assertFalse(app(PreviewTagResolver::class)->defaultTagged());
        $policy->setTrack($track, false, 0, $fixture['actor']);
        $this->assertSame(1, app(PreviewTagResolver::class)->revision($track->id));
        $policy->setTrack($track, null, 1, $fixture['actor']);
        $this->assertSame(2, app(PreviewTagResolver::class)->revision($track->id));
        // Saving the value already in force writes nothing.
        $before = AuditEvent::count();
        $policy->setTrack($track, null, 2, $fixture['actor']);
        $policy->setDefault(false, 1, $fixture['actor']);
        $this->assertSame($before, AuditEvent::count());
    }

    public function test_the_loser_of_a_first_write_race_for_the_same_setting_is_refused_with_no_audit_event(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        // The competing first write lands after this one has read "no row" and before its own insert.
        PreviewTagSetting::creating(function (PreviewTagSetting $row): void {
            DB::table('preview_tag_settings')->insert(['scope_key' => $row->scope_key, 'track_id' => $row->track_id, 'tagged' => true, 'revision' => 1,
                'updated_by' => $row->updated_by, 'created_at' => now(), 'updated_at' => now()]);
            PreviewTagSetting::flushEventListeners();
        });
        try {
            app(PreviewTagPolicy::class)->setTrack($track, true, 0, $fixture['actor']);
            $this->fail('The loser of a first-write race must be refused.');
        } catch (ValidationException $refusal) {
            $this->assertStringContainsString('changed after you opened it', $refusal->errors()['tagged'][0]);
        }
        // The loser's transaction (here sharing one connection with the simulated winner) rolled back whole: no audit event, no second row.
        $this->assertSame(0, AuditEvent::where('action', 'like', 'media.preview_tag.%')->count());
        $this->assertLessThanOrEqual(1, PreviewTagSetting::count());
    }

    public function test_audit_events_carry_actor_track_and_old_new_values_and_no_paths(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        $policy = app(PreviewTagPolicy::class);
        $policy->setDefault(false, 0, $fixture['actor']);
        $policy->setTrack($track, true, 0, $fixture['actor']);
        $policy->setTrack($track, null, 1, $fixture['actor']);
        $events = AuditEvent::where('action', 'like', 'media.preview_tag.%')->orderBy('id')->get();
        $this->assertSame(['media.preview_tag.default_changed', 'media.preview_tag.track_changed', 'media.preview_tag.track_changed'], $events->pluck('action')->all());
        $this->assertSame([$fixture['actor']->id], $events->pluck('actor_id')->unique()->values()->all());
        $default = $events[0]->context;
        $this->assertSame(['default', null, true, false, 0, 1], [$default['scope'], $default['track_id'], $default['old'], $default['new'], $default['revision_before'], $default['revision_after']]);
        $on = $events[1]->context;
        $this->assertSame(['track', $track->id, null, true, false, true], [$on['scope'], $on['track_id'], $on['old'], $on['new'], $on['effective_before'], $on['effective_after']]);
        $follow = $events[2]->context;
        $this->assertSame([true, null, true, false], [$follow['old'], $follow['new'], $follow['effective_before'], $follow['effective_after']]);
        $this->assertSame(PreviewTagSetting::class, $events[1]->subject_type);
        $json = json_encode($events->pluck('context')->all());
        foreach (['storage_path', 'media/', 'quarantine', 'sha256', '.mp3'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_retagging_adds_the_untagged_preview_to_a_draft_made_before_it_existed(): void
    {
        ['track' => $track, 'actor' => $actor, 'media' => $media] = $this->draft('legacy-draft');
        $this->makeLegacy($track);
        $this->assertNull(app(PreviewTagResolver::class)->verifiedUntagged($track));
        $this->assertNotNull(app(VerifiedMedia::class)->evidence($media['preview_tagged']->fresh()), 'A run from before the untagged preview stays valid.');
        $retag = app(RetagPreviews::class);
        Queue::fake();
        $plan = $retag->plan(config('media.tag_sha256'), true);
        $this->assertSame('add_untagged', $plan['mode']);
        $this->assertSame([$track->id], array_column($plan['stale'], 'track_id'));
        $this->assertSame('new', $plan['stale'][0]['state']);
        // The default question (stale tag) has nothing to do for this track.
        $this->assertSame([], $retag->plan(config('media.tag_sha256'))['stale']);
        $queued = $retag->queue($actor, $plan);
        $this->assertCount(1, $queued['queued']);
        $this->assertSame('completed', app(MediaProcessor::class)->handle($queued['queued'][0])->status);
        $fresh = $track->fresh();
        $this->assertNotNull(app(PreviewTagResolver::class)->verifiedUntagged($fresh));
        $again = $retag->plan(config('media.tag_sha256'), true);
        $this->assertSame([], $again['stale']);
        $this->assertSame(1, $again['current']);
        $this->assertTrue(app(PreviewTagPolicy::class)->setTrack($fresh, false, 0, $actor)['tagged'] === false);
        $this->assertSame('add_untagged', AuditEvent::where('action', 'media.retag_previews.requested')->latest('id')->first()->context['mode']);
    }

    public function test_a_live_track_is_not_reprocessed_to_gain_the_variant(): void
    {
        $fixture = $this->selling();
        $this->makeLegacy($fixture['track']);
        $plan = app(RetagPreviews::class)->plan(config('media.tag_sha256'), true);
        $this->assertSame([], $plan['stale']);
        $this->assertSame([$fixture['track']->id], $plan['skipped']['live']);
    }

    public function test_store_default_page_saves_audits_and_refuses_customers(): void
    {
        $admin = LicenseFixtures::admin();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin);
        $page = Livewire::test(PreviewTagSettingsPage::class)->assertSee('Public previews carry the seller tag')->assertSet('defaultTagged', true)->assertSet('revision', 0);
        $page->set('defaultTagged', false)->call('save')->assertSet('defaultTagged', false)->assertSet('revision', 1);
        $this->assertFalse(app(PreviewTagResolver::class)->defaultTagged());
        $this->assertSame(1, AuditEvent::where('action', 'media.preview_tag.default_changed')->where('actor_id', $admin->id)->count());
        // A second editor holding revision 0 is refused and reloaded.
        $stale = Livewire::test(PreviewTagSettingsPage::class);
        app(PreviewTagPolicy::class)->setDefault(true, 1, $admin);
        $stale->set('defaultTagged', false)->call('save')->assertSet('defaultTagged', true)->assertSet('revision', 2);
        $this->assertTrue(app(PreviewTagResolver::class)->defaultTagged());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(PreviewTagSettingsPage::canAccess());
        Livewire::test(PreviewTagSettingsPage::class)->assertForbidden();
    }

    public function test_the_track_action_shows_the_effective_state_and_applies_default_on_off(): void
    {
        $fixture = $this->selling();
        $track = $fixture['track'];
        $this->actingAs($fixture['actor']);
        $page = Livewire::test(ManageTracks::class)->assertTableColumnStateSet('preview_tag', 'Public hears: tagged (store default); untagged variant exists', $track);
        $page->callTableAction('previewTag', $track, ['choice' => 'off', 'revision' => 0])->assertNotified('Preview tag saved');
        $this->assertFalse(app(PreviewTagResolver::class)->effectiveTagged($track->id));
        Livewire::test(ManageTracks::class)->assertTableColumnStateSet('preview_tag', 'Public hears: untagged (this track); untagged variant exists', $track);
        // A stale form is refused with the domain message.
        Livewire::test(ManageTracks::class)->callTableAction('previewTag', $track, ['choice' => 'on', 'revision' => 0])->assertNotified('Preview tag not changed');
        $this->assertFalse(app(PreviewTagResolver::class)->effectiveTagged($track->id));
        Livewire::test(ManageTracks::class)->callTableAction('previewTag', $track, ['choice' => 'default', 'revision' => 1])->assertNotified('Preview tag saved');
        $this->assertTrue(app(PreviewTagResolver::class)->effectiveTagged($track->id));
        // A track without an untagged variant: Off is refused with the reason.
        $this->makeLegacy($track);
        Livewire::test(ManageTracks::class)->callTableAction('previewTag', $track, ['choice' => 'off', 'revision' => 2])->assertNotified('Preview tag not changed');
        $this->assertTrue(app(PreviewTagResolver::class)->effectiveTagged($track->id));
    }
}
