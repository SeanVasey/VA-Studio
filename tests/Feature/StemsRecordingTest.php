<?php

namespace Tests\Feature;

use App\Application\Media\MediaIntegrity;
use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\OfferSnapshot;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteSelection;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\StemsRecording;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\RecordingAssociation;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Filament\Resources\MediaAssetResource\Pages\ManageMediaAssets;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\RecordingFixtures;
use Tests\TestCase;

class StemsRecordingTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->fixture = RecordingFixtures::draft();
    }

    private function bind(array $changes = []): StemsRecording
    {
        return app(BindStemsToRecording::class)->handle($this->fixture['stems'], $changes + $this->fixture['data'], $this->fixture['actor']);
    }

    private function offer(): Offer
    {
        ['actor' => $actor, 'track' => $track, 'stems' => $stems] = $this->fixture;
        RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC RIGHTS', 'sample_disclosure' => 'Synthetic source', 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
        $license = LicenseFixtures::published($actor, terms: ['schema_version' => 1, 'features' => ['SYNTHETIC STEMS ONLY'], 'required_asset_roles' => ['stems_zip']]);

        return app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id, 'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$stems->id]], $actor);
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An invalid recording association was accepted.');
        } catch (ValidationException) {
        }
    }

    public function test_admin_confirmation_preserves_processing_ancestry_and_freezes_audited_evidence(): void
    {
        ['actor' => $actor, 'stems' => $stems, 'data' => $data, 'media' => $media] = $this->fixture;
        $before = $stems->getAttributes();
        $masterBefore = $media['master_wav']->getAttributes();
        $this->actingAs($actor);
        Livewire::test(ManageMediaAssets::class)->callTableAction('associate_recording', $stems, data: $data)->assertHasNoTableActionErrors();
        $binding = StemsRecording::sole();
        $this->assertSame($before, $stems->fresh()->getAttributes());
        $this->assertSame($masterBefore, $media['master_wav']->fresh()->getAttributes());
        $this->assertSame($media['master_wav']->parent_asset_id, $binding->recording_source_id);
        $this->assertNotSame($stems->parent_asset_id, $binding->recording_source_id);
        $this->assertSame($actor->id, $binding->verified_by);
        $this->assertSame($data['verification_reference'], $binding->evidence['attestation']['reference']);
        $this->assertSame(CanonicalJson::hash($binding->evidence), $binding->evidence_hash);
        $this->assertSame($binding->id, app(RecordingAssociation::class)->verified($stems)?->id);
        $audit = AuditEvent::where('action', 'media.stems.recording_associated')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertStringNotContainsString($data['verification_reference'], json_encode($audit->context));
        Livewire::test(ManageMediaAssets::class)->assertSee('Master #'.$binding->master_asset_id)->assertTableActionHidden('associate_recording', $stems);
        $this->get('/admin/media/'.$stems->id.'/preview')->assertNotFound();
        $this->get('/media/'.$stems->id)->assertNotFound();
    }

    public function test_explicit_binding_unblocks_stems_offer_and_is_frozen_in_quote_evidence_without_public_leakage(): void
    {
        $offer = $this->offer();
        $this->rejected(fn () => app(PublishOffer::class)->handle($offer, $this->fixture['actor']));
        $binding = $this->bind();
        $revision = app(PublishOffer::class)->handle($offer, $this->fixture['actor']);
        $track = app(PublishTrack::class)->handle($this->fixture['track'], $this->fixture['actor']);
        $this->assertSame($binding->evidence_hash, $revision->snapshot['assets'][0]['recording_binding']['evidence_hash']);
        $items = [['trackId' => $track->id, 'offerId' => $offer->id, 'offerRevisionId' => $revision->id, 'licenseVersionId' => $offer->license_version_id]];
        $lines = DB::transaction(fn () => app(QuoteSelection::class)->resolve($items, true));
        $this->assertSame($revision->snapshot_hash, $lines[0]['offer_snapshot_hash']);
        $this->assertSame($binding->id, $lines[0]['offer_snapshot']['assets'][0]['recording_binding']['id']);
        $response = $this->getJson('/api/catalog')->assertOk()->assertJsonCount(1, 'tracks');
        foreach ([$binding->verification_reference, $this->fixture['stems']->storage_path, 'recording_binding'] as $private) {
            $response->assertDontSee($private, false);
        }
        // A stems-only offer still promises the integrity of its associated master.
        $this->corruptMasterWithCachedRead();
        try {
            DB::transaction(fn () => app(QuoteSelection::class)->resolve($items, true));
            $this->fail('Quote accepted changed association evidence.');
        } catch (QuoteException $exception) {
            $this->assertSame('SELECTION_CHANGED', $exception->errorCode);
        }
        $this->assertSame($binding->evidence_hash, $revision->fresh()->snapshot['assets'][0]['recording_binding']['evidence_hash']);
    }

    public function test_confirmation_validation_and_direct_role_reauthorization_prevent_forged_evidence(): void
    {
        foreach ([['same_recording_confirmed' => false], ['verification_reference' => '  '], ['verification_reference' => str_repeat('x', 241)], ['evidence_hash' => str_repeat('a', 64)], ['verified_by' => 999], ['preview_asset_id' => 0]] as $change) {
            $this->rejected(fn () => $this->bind($change));
        }
        $unverified = LicenseFixtures::admin();
        $unverified->forceFill(['email_verified_at' => null])->save();
        $revoked = LicenseFixtures::admin();
        User::whereKey($revoked->id)->update(['is_admin' => false]);
        foreach ([User::factory()->create(), $unverified, $revoked] as $actor) {
            try {
                app(BindStemsToRecording::class)->handle($this->fixture['stems'], $this->fixture['data'], $actor);
                $this->fail('Unauthorized operator created a recording association.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertDatabaseCount('stems_recordings', 0);
    }

    public function test_recording_action_shows_domain_integrity_errors_at_the_master_field(): void
    {
        ['actor' => $actor, 'stems' => $stems, 'data' => $data] = $this->fixture;
        $this->actingAs($actor);
        $form = Livewire::test(ManageMediaAssets::class)->mountTableAction('associate_recording', $stems)->setTableActionData($data);
        $this->corruptMasterWithCachedRead();
        $form->callMountedTableAction()->assertHasTableActionErrors(['master_asset_id']);
        $this->assertDatabaseCount('stems_recordings', 0);
    }

    public function test_mounted_action_reauthorizes_and_stale_preview_is_rejected(): void
    {
        ['actor' => $actor, 'stems' => $stems, 'data' => $data, 'track' => $track] = $this->fixture;
        $this->actingAs($actor);
        $form = Livewire::test(ManageMediaAssets::class)->mountTableAction('associate_recording', $stems)->setTableActionData($data);
        $this->actingAs(User::factory()->create());
        $form->callMountedTableAction()->assertForbidden();
        $this->actingAs($actor);
        $stale = Livewire::test(ManageMediaAssets::class)->mountTableAction('associate_recording', $stems)->setTableActionData($data);
        $source = MediaFixtures::source($track, bytes: MediaFixtures::wav(1.2, 880));
        app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $actor)->id);
        $stale->callMountedTableAction()->assertHasTableActionErrors();
        $this->rejected(fn () => $this->bind());
        $this->assertDatabaseCount('stems_recordings', 0);
    }

    public function test_wrong_roles_other_tracks_quarantine_and_published_tracks_cannot_be_associated(): void
    {
        ['actor' => $actor, 'track' => $track, 'media' => $media] = $this->fixture;
        $other = $track->replicate();
        $other->slug = 'other-recording';
        $other->save();
        $otherMedia = MediaFixtures::readyTrackMedia($other, $actor);
        foreach ([$otherMedia['master_wav']->id, $media['download_mp3']->id, $media['master_wav']->parent_asset_id] as $masterId) {
            $this->rejected(fn () => $this->bind(['master_asset_id' => $masterId]));
        }
        foreach ([$media['master_wav'], $this->fixture['stems']->parent()->firstOrFail()] as $wrongStems) {
            $this->rejected(fn () => app(BindStemsToRecording::class)->handle($wrongStems, $this->fixture['data'], $actor));
        }
        // A synthetic DB state tests the status guard without inventing a published offer.
        DB::table('tracks')->where('id', $track->id)->update(['published_slug' => $track->slug, 'status' => 'published']);
        $this->rejected(fn () => $this->bind());
        $this->assertDatabaseCount('stems_recordings', 0);
    }

    public function test_duplicate_confirmation_is_idempotent_but_corrections_cannot_rewrite_evidence(): void
    {
        $binding = $this->bind();
        $this->assertSame($binding->id, $this->bind()->id);
        $this->rejected(fn () => $this->bind(['verification_reference' => 'A conflicting correction']));
        $this->assertDatabaseCount('stems_recordings', 1);
        $this->assertSame(1, AuditEvent::where('action', 'media.stems.recording_associated')->count());
        $this->rejected(fn () => $binding->update(['verification_reference' => 'changed']));
        $this->rejected(fn () => $binding->delete());
        foreach ([fn () => DB::table('stems_recordings')->where('id', $binding->id)->update(['verification_reference' => 'changed']), fn () => DB::table('stems_recordings')->where('id', $binding->id)->delete(), fn () => DB::table('stems_recordings')->insert(collect($binding->getAttributes())->except('id')->all())] as $mutation) {
            try {
                $mutation();
                $this->fail('Database allowed immutable evidence to be rewritten or duplicated.');
            } catch (QueryException) {
            }
        }
        $this->assertSame($binding->evidence_hash, $binding->fresh()->evidence_hash);
    }

    public function test_invalid_existing_binding_is_rejected_without_being_treated_as_absent(): void
    {
        ['actor' => $actor, 'track' => $track, 'stems' => $stems, 'media' => $media, 'data' => $data] = $this->fixture;
        $binding = new StemsRecording([
            'track_id' => $track->id, 'stems_asset_id' => $stems->id, 'master_asset_id' => $media['master_wav']->id,
            'preview_asset_id' => $media['preview_tagged']->id, 'recording_source_id' => $media['master_wav']->parent_asset_id,
            'verified_by' => $actor->id, 'verified_at' => now()->startOfSecond(),
            'verification_reference' => $data['verification_reference'], 'canonicalization_version' => CanonicalJson::VERSION,
        ]);
        $associations = app(RecordingAssociation::class);
        $binding->evidence = $associations->evidence($binding, $stems, $media['master_wav'], $media['preview_tagged']);
        // Malformed initial evidence; never disable the immutable update/delete guards.
        $binding->evidence_hash = str_repeat('0', 64);
        $binding->save();
        $before = $binding->fresh()->getAttributes();
        $inspection = DB::transaction(function () use ($track, $stems, $associations): array {
            Track::query()->lockForUpdate()->findOrFail($track->id);

            return $associations->inspectCurrentBinding($stems);
        });
        $this->assertSame($binding->id, $inspection['binding']->id);
        $this->assertFalse($inspection['verified']);
        $this->assertNull($associations->retained($stems));
        $this->assertNull($associations->verified($stems));
        try {
            $this->bind();
            $this->fail('Invalid existing evidence was accepted or replaced.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already associated', $exception->errors()['master_asset_id'][0]);
        }
        $this->assertSame($before, $binding->fresh()->getAttributes());
        $this->assertDatabaseCount('stems_recordings', 1);
        $this->assertSame(0, AuditEvent::where('action', 'media.stems.recording_associated')->count());
    }

    public function test_current_binding_query_failure_propagates_without_creating_a_binding_or_audit(): void
    {
        $failure = new QueryException(DB::connection()->getName(), 'select synthetic recording binding', [],
            new RuntimeException('Synthetic current-binding query failure.'));
        $armed = true;
        $raised = false;
        DB::connection()->beforeExecuting(function ($query) use ($failure, &$armed, &$raised): void {
            if ($armed && preg_match('/\Aselect\b/i', $query) && str_contains($query, 'stems_recordings')) {
                $raised = true;
                throw $failure;
            }
        });
        try {
            $this->bind();
            $this->fail('A current-binding query failure was treated as absence.');
        } catch (QueryException $exception) {
            $this->assertSame($failure, $exception);
        } finally {
            $armed = false;
        }
        $this->assertTrue($raised);
        $this->assertDatabaseCount('stems_recordings', 0);
        $this->assertSame(0, AuditEvent::where('action', 'media.stems.recording_associated')->count());
    }

    public function test_tag_regeneration_preserves_recording_match_but_new_source_requires_new_stems_revision(): void
    {
        $binding = $this->bind();
        $offer = $this->offer();
        $first = app(PublishOffer::class)->handle($offer, $this->fixture['actor']);
        $before = $first->fresh()->snapshot;
        $source = $this->fixture['media']['master_wav']->parent()->firstOrFail();
        config(['media.tag_interval_seconds' => 2]);
        $run = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $this->fixture['actor'])->id);
        $preview = $run->outputs()->where('role', 'preview_tagged')->sole();
        $this->assertTrue(app(RecordingAssociation::class)->matches($this->fixture['stems'], $preview));
        $this->assertNotEmpty(app(PublicationReadiness::class)->revisionBlockers($offer->fresh(), $first));
        $second = app(PublishOffer::class)->handle($offer, $this->fixture['actor']);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($binding->evidence_hash, $second->snapshot['assets'][0]['recording_binding']['evidence_hash']);
        $newSource = MediaFixtures::source($this->fixture['track'], bytes: MediaFixtures::wav(1.2, 990));
        app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($newSource, $this->fixture['actor'])->id);
        $this->rejected(fn () => app(PublishOffer::class)->handle($offer, $this->fixture['actor']));
        $this->assertSame($before, $first->fresh()->snapshot);
        $this->assertSame($binding->id, app(RecordingAssociation::class)->verified($this->fixture['stems'])?->id);
    }

    private function corruptMasterWithCachedRead(): void
    {
        // Force a normal-read cache hit without replacing the final integrity service.
        Cache::partialMock()->shouldReceive('get')->withArgs(fn (string $key) => str_starts_with($key, 'media-integrity:'))->andReturn(true);
        $master = $this->fixture['media']['master_wav'];
        $path = Storage::disk('local')->path($master->storage_path);
        chmod($path, 0600);
        file_put_contents($path, str_repeat('x', $master->size_bytes));
        clearstatcache(true, $path);
        $this->assertTrue(app(MediaIntegrity::class)->matches($master, $path));
    }

    public function test_new_binding_and_offer_recheck_bytes_even_when_public_integrity_reads_are_cached(): void
    {
        $master = $this->fixture['media']['master_wav'];
        $path = Storage::disk('local')->path($master->storage_path);
        $original = file_get_contents($path);
        $this->corruptMasterWithCachedRead();
        $this->rejected(fn () => $this->bind());
        $this->assertDatabaseCount('stems_recordings', 0);
        file_put_contents($path, $original);
        $this->bind();
        $offer = $this->offer();
        $this->corruptMasterWithCachedRead();
        $this->rejected(fn () => app(PublishOffer::class)->handle($offer, $this->fixture['actor']));
        $this->assertDatabaseCount('offer_revisions', 0);
    }

    public function test_audit_failure_rolls_back_binding_and_existing_non_stems_snapshot_shape_is_unchanged(): void
    {
        $master = $this->fixture['media']['master_wav'];
        $this->assertSame($master->only(['id', 'role', 'sha256', 'mime_type', 'size_bytes', 'original_name', 'parent_asset_id', 'processing_run_id']), app(OfferSnapshot::class)->asset($master));
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
        try {
            $this->bind();
            $this->fail('Association committed without its audit event.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('stems_recordings', 0);
    }
}
