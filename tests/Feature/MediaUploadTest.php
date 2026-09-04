<?php

namespace Tests\Feature;

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\Models\MediaAsset;
use App\Filament\Resources\MediaAssetResource\Pages\ManageMediaAssets;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function operator(): User
    {
        $actor = User::factory()->create();
        $actor->forceFill(['is_admin' => true])->save();

        return $actor;
    }

    private function artwork(): UploadedFile
    {
        // Genuine 1x1 PNG bytes; no customer assets in test fixtures.
        return UploadedFile::fake()->createWithContent('image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    public function test_upload_captures_server_evidence_in_private_quarantine(): void
    {
        $actor = $this->operator();
        $track = Track::create(['title' => 'Upload test', 'slug' => 'upload-test']);
        $upload = $this->artwork();
        $asset = app(IngestMediaUpload::class)->handle($track, $upload, 'artwork', $actor);
        $this->assertSame('quarantined', $asset->status);
        $this->assertStringStartsWith('quarantine/', $asset->storage_path);
        $this->assertSame(hash_file('sha256', $upload->getRealPath()), $asset->sha256);
        $this->assertSame($upload->getSize(), $asset->size_bytes);
        $this->assertSame('image/png', $asset->mime_type);
        Storage::disk('local')->assertExists($asset->storage_path);
        $this->assertDatabaseHas('audit_events', ['action' => 'media.upload.quarantined', 'actor_id' => $actor->id]);
        $this->get('/media/'.$asset->id)->assertNotFound();
    }

    public function test_client_cannot_reference_an_existing_private_file(): void
    {
        Storage::disk('local')->put('private/master.wav', 'private bytes');
        $track = Track::create(['title' => 'Upload test', 'slug' => 'upload-test']);
        try {
            app(IngestMediaUpload::class)->handle($track, 'private/master.wav', 'master_wav', $this->operator());
            $this->fail('Client path was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('media_assets', 0);
            $this->assertSame('private bytes', Storage::disk('local')->get('private/master.wav'));
        }
    }

    public function test_non_operator_cannot_ingest_even_a_valid_file(): void
    {
        $track = Track::create(['title' => 'Upload test', 'slug' => 'upload-test']);
        $this->expectException(AuthorizationException::class);
        app(IngestMediaUpload::class)->handle($track, $this->artwork(), 'artwork', User::factory()->create());
    }

    public function test_forged_file_type_and_unsupported_role_cannot_create_assets(): void
    {
        $track = Track::create(['title' => 'Upload test', 'slug' => 'upload-test']);
        $actor = $this->operator();
        foreach ([['master_wav', UploadedFile::fake()->createWithContent('master.wav', '<script>unsafe</script>')], ['preview_tagged', $this->artwork()]] as [$role, $file]) {
            try {
                app(IngestMediaUpload::class)->handle($track, $file, $role, $actor);
                $this->fail('Invalid intake was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('media_assets', 0);
            }
        }
    }

    public function test_admin_create_ignores_client_readiness_and_private_path_fields(): void
    {
        $actor = $this->operator();
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $track = Track::create(['title' => 'Upload test', 'slug' => 'upload-test']);
        Livewire::test(ManageMediaAssets::class)->callAction('create', data: [
            'track_id' => $track->id, 'role' => 'artwork', 'upload' => $this->artwork(),
            'status' => 'ready', 'storage_path' => 'existing/private.wav', 'sha256' => str_repeat('a', 64), 'verified_by' => $actor->id,
        ])->assertHasNoActionErrors();
        $asset = MediaAsset::sole();
        $this->assertSame('quarantined', $asset->status);
        $this->assertNull($asset->verified_by);
        $this->assertStringStartsWith('quarantine/', $asset->storage_path);
    }

    public function test_intake_rejects_a_publicly_served_local_disk_before_copying(): void
    {
        $track = Track::create(['title' => 'Upload test', 'slug' => 'upload-test']);
        config(['filesystems.disks.local.serve' => true]);
        try {
            app(IngestMediaUpload::class)->handle($track, $this->artwork(), 'artwork', $this->operator());
            $this->fail('Unsafe disk was accepted.');
        } catch (MediaFailure $error) {
            $this->assertSame('unsafe_storage', $error->failureCode);
            $this->assertDatabaseCount('media_assets', 0);
            $this->assertSame([], Storage::disk('local')->allFiles('quarantine'));
        }
    }
}
