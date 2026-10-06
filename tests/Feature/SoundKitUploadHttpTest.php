<?php

namespace Tests\Feature;

use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\Models\SoundKitUploadSession;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitUploads;
use App\Filament\Resources\SoundKitDraftResource\Pages\ManageSoundKitDrafts;
use App\Filament\Resources\SoundKitDraftResource\Pages\UploadSoundKit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class SoundKitUploadHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $actor;

    private SoundKitDraft $kit;

    private string $bytes;

    private const BASE = '/admin/sound-kit-uploads';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('u', 32)), 'app.debug' => true]);
        $this->actor = User::factory()->create(['is_admin' => true]);
        $this->kit = app(SoundKitDrafts::class)->save(null, ['title' => 'Private resumable kit', 'description' => '', 'provenance' => 'SYNTHETIC test only'], $this->actor);
        MediaFixtures::configure();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->bytes = StemsFixtures::zip([['name' => 'Kick.wav']]);
    }

    private function payload(): array
    {
        return ['kitId' => $this->kit->id, 'expectedVersion' => $this->kit->version, 'sizeBytes' => strlen($this->bytes),
            'sha256' => hash('sha256', $this->bytes), 'originalName' => 'private-kit.zip'];
    }

    private function start(): array
    {
        return app(SoundKitUploads::class)->start($this->kit, $this->kit->version, strlen($this->bytes), hash('sha256', $this->bytes), 'private-kit.zip', $this->actor);
    }

    private function chunk(string $id, array $fields = ['offset' => '0'], ?UploadedFile $chunk = null, array $server = [])
    {
        return $this->call('POST', self::BASE.'/'.$id.'/chunks', $fields, [],
            ['chunk' => $chunk ?? UploadedFile::fake()->createWithContent('chunk.bin', $this->bytes)],
            [...$server, 'CONTENT_TYPE' => 'multipart/form-data; boundary=fixture', 'HTTP_ACCEPT' => 'application/json']);
    }

    private function postEmpty(string $path)
    {
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}');
    }

    private function assertPrivate($response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
        $body = $response->getContent();
        foreach (['source_path', 'storage_path', 'quarantine/', 'private-upload-parts', 'exception', 'stack', 'trace'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
    }

    public function test_real_authenticated_round_trip_and_retry_return_only_allowlisted_status(): void
    {
        $this->actingAs($this->actor);
        $start = $this->postJson(self::BASE, $this->payload())->assertOk();
        $id = $start->json('session.id');
        $this->assertPrivate($start);
        $again = $this->postJson(self::BASE, $this->payload())->assertOk();
        $this->assertSame($id, $again->json('session.id'));
        $this->assertDatabaseCount('sound_kit_upload_sessions', 1);
        $part = $this->chunk($id)->assertOk()->assertJsonPath('session.receivedBytes', strlen($this->bytes));
        $this->assertPrivate($part);
        $finish = $this->postEmpty(self::BASE.'/'.$id.'/complete')->assertOk()->assertJsonPath('session.status', 'completed');
        $revisionId = $finish->json('session.revisionId');
        $this->assertPrivate($finish);
        $this->postEmpty(self::BASE.'/'.$id.'/complete')->assertOk()->assertJsonPath('session.revisionId', $revisionId);
        $this->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('session.status', 'completed');
        $this->assertDatabaseCount('sound_kit_revisions', 1);
        $this->assertSame('quarantined', SoundKitRevision::findOrFail($revisionId)->status);
        $this->get('/media/'.$revisionId)->assertNotFound();
        $this->assertSame(['chunkBytes', 'cleanupPending', 'expectedVersion', 'expiresAt', 'id', 'kitId', 'originalName', 'receivedBytes', 'revisionId', 'sha256', 'sizeBytes', 'status'],
            tap(array_keys($finish->json('session')), fn (&$keys) => sort($keys)));
    }

    public function test_guest_and_non_operator_requests_never_create_sessions(): void
    {
        $guest = $this->postJson(self::BASE, $this->payload())->assertUnauthorized();
        $this->assertPrivate($guest);
        $this->actingAs(User::factory()->create());
        $denied = $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->assertPrivate($denied);
        $this->assertDatabaseCount('sound_kit_upload_sessions', 0);
    }

    public function test_another_verified_operator_cannot_inspect_append_finish_or_cancel_a_session(): void
    {
        $id = $this->start()['id'];
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([
            $this->getJson(self::BASE.'/'.$id), $this->chunk($id),
            $this->postEmpty(self::BASE.'/'.$id.'/complete'), $this->postEmpty(self::BASE.'/'.$id.'/cancel'),
        ] as $response) {
            $response->assertForbidden();
            $this->assertPrivate($response);
            $this->assertStringNotContainsString('private-kit.zip', $response->getContent());
        }
        $this->assertSame(0, SoundKitUploadSession::sole()->received_bytes);
        $this->assertDatabaseCount('sound_kit_revisions', 0);
    }

    public function test_persisted_authority_withdrawal_blocks_a_retained_authenticated_actor(): void
    {
        $id = $this->start()['id'];
        $this->actingAs($this->actor);
        User::whereKey($this->actor->id)->update(['is_admin' => false]);
        $this->assertPrivate($this->getJson(self::BASE.'/'.$id)->assertForbidden());
        $this->assertPrivate($this->chunk($id)->assertForbidden());
        $this->assertPrivate($this->postEmpty(self::BASE.'/'.$id.'/complete')->assertForbidden());
        $this->assertSame(0, SoundKitUploadSession::sole()->received_bytes);
    }

    public function test_required_mfa_without_enrollment_is_denied_at_the_http_boundary(): void
    {
        $id = $this->start()['id'];
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $this->actingAs($this->actor);
            $this->assertPrivate($this->getJson(self::BASE.'/'.$id)->assertForbidden());
            $this->assertPrivate($this->postJson(self::BASE, $this->payload())->assertForbidden());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: false);
        }
    }

    public function test_csrf_is_required_even_for_an_authorized_operator(): void
    {
        $this->actingAs($this->actor);
        $this->app->instance('env', 'local'); // Exercise actual CSRF instead of the framework's testing bypass.
        try {
            $this->assertPrivate($this->postJson(self::BASE, $this->payload())->assertStatus(419));
            $this->withSession(['_token' => 'upload-test-token'])->withHeader('X-CSRF-TOKEN', 'upload-test-token')
                ->postJson(self::BASE, $this->payload())->assertOk();
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertDatabaseCount('sound_kit_upload_sessions', 1);
    }

    public function test_body_method_origin_and_caller_path_rejections_are_private_and_nonmutating(): void
    {
        $this->actingAs($this->actor);
        $id = $this->start()['id'];
        $responses = [
            $this->postJson(self::BASE, [...$this->payload(), 'storage_path' => '/private/source.wav']),
            $this->postJson(self::BASE.'?extra=1', $this->payload()),
            $this->postJson(self::BASE, [...$this->payload(), '_method' => 'DELETE']),
            $this->postJson(self::BASE, $this->payload(), ['Origin' => 'https://outside.invalid']),
            $this->postJson(self::BASE, $this->payload(), ['Content-Length' => '4097']),
            $this->postJson(self::BASE.'/'.$id.'/chunks', ['offset' => '0', 'chunk' => '/private/source.wav']),
            $this->chunk($id, ['offset' => '0', 'path' => '/private/source.wav']),
            $this->postJson(self::BASE.'/'.$id.'/complete', ['path' => '/private/source.wav']),
        ];
        foreach ($responses as $response) {
            $this->assertGreaterThanOrEqual(400, $response->status());
            $this->assertPrivate($response);
            $this->assertStringNotContainsString('/private/source.wav', $response->getContent());
        }
        $this->assertDatabaseCount('sound_kit_upload_sessions', 1);
        $this->assertSame(0, SoundKitUploadSession::sole()->received_bytes);
    }

    public function test_metadata_requires_a_strict_typed_json_object_and_never_accepts_track_roles(): void
    {
        $this->actingAs($this->actor);
        foreach (['kitId' => '1', 'expectedVersion' => true, 'sizeBytes' => '20', 'sha256' => [], 'originalName' => null] as $field => $value) {
            $this->assertPrivate($this->postJson(self::BASE, [...$this->payload(), $field => $value])->assertUnprocessable());
        }
        $this->assertPrivate($this->postJson(self::BASE, [...$this->payload(), 'trackId' => $this->kit->id, 'role' => 'stems_zip'])->assertUnprocessable());
        $this->assertPrivate($this->postJson(self::BASE, array_values($this->payload()))->assertUnprocessable());
        $this->assertDatabaseCount('sound_kit_upload_sessions', 0);
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_uncaught_debug_exception_does_not_expose_private_paths_or_payloads(): void
    {
        $this->actingAs($this->actor);
        Log::spy();
        $raised = false;
        DB::connection()->beforeExecuting(function () use (&$raised): void {
            if (! $raised) {
                $raised = true;
                throw new RuntimeException('Sensitive /private/stems/path and upload identity');
            }
        });
        $response = $this->postJson(self::BASE, $this->payload())->assertStatus(503);
        $this->assertPrivate($response);
        $this->assertStringNotContainsString('Sensitive', $response->getContent());
        Log::shouldHaveReceived('error')->with('Resumable upload failed.', ['exception_class' => RuntimeException::class]);
    }

    public function test_full_eight_mib_part_is_accepted_with_bounded_multipart_overhead(): void
    {
        // Model the documented 9 MiB host setting while exercising Laravel's real
        // size middleware. The native browser fixture separately sets PHP's actual
        // multipart limits; this in-process request does not run PHP's body parser.
        $this->app->bind(ValidatePostSize::class, fn () => new class extends ValidatePostSize
        {
            protected function getPostMaxSize()
            {
                return 9 * 1024 * 1024;
            }
        });
        $this->bytes .= str_repeat("\0", 8 * 1024 * 1024 + 1 - strlen($this->bytes));
        $id = $this->start()['id'];
        $this->actingAs($this->actor);
        $chunk = UploadedFile::fake()->createWithContent('chunk.bin', substr($this->bytes, 0, 8 * 1024 * 1024));
        $this->chunk($id, ['offset' => '0'], $chunk, ['CONTENT_LENGTH' => (string) (8 * 1024 * 1024 + 65536)])
            ->assertOk()->assertJsonPath('session.receivedBytes', 8 * 1024 * 1024);
        $this->assertPrivate($this->chunk($id, ['offset' => (string) (8 * 1024 * 1024)],
            UploadedFile::fake()->createWithContent('last.bin', "\0"),
            ['CONTENT_LENGTH' => (string) (8 * 1024 * 1024 + 65537)])->assertStatus(413));
        $this->assertSame(8 * 1024 * 1024, SoundKitUploadSession::sole()->received_bytes);
    }

    public function test_livewire_context_cannot_outlive_persisted_role_withdrawal(): void
    {
        $this->actingAs($this->actor);
        $component = Livewire::test(UploadSoundKit::class, ['record' => $this->kit->id]);
        User::whereKey($this->actor->id)->update(['is_admin' => false]);
        $component->call('prepareUpload')->assertForbidden()->assertNotDispatched('resumable-kit-upload-context');
        $this->assertDatabaseCount('sound_kit_upload_sessions', 0);
    }

    public function test_livewire_context_rechecks_required_persisted_mfa_enrollment(): void
    {
        $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->actor);
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $component = Livewire::test(UploadSoundKit::class, ['record' => $this->kit->id]);
            User::findOrFail($this->actor->id)->saveAppAuthenticationSecret(null);
            $component->call('prepareUpload')->assertForbidden()->assertNotDispatched('resumable-kit-upload-context');
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: false);
        }
        $this->assertDatabaseCount('sound_kit_upload_sessions', 0);
    }

    public function test_filament_page_preserves_whole_file_action_and_dispatches_only_locked_kit_context(): void
    {
        $this->actingAs($this->actor);
        $this->get('/admin/sound-kit-drafts/'.$this->kit->id.'/resumable-upload')->assertOk()->assertSee('Resumable kit upload')->assertSee('Continue upload');
        Livewire::test(ManageSoundKitDrafts::class)->assertTableActionExists('upload')->assertTableActionExists('resumable_upload');
        Livewire::test(UploadSoundKit::class, ['record' => $this->kit->id])->call('prepareUpload')
            ->assertDispatched('resumable-kit-upload-context', kitId: $this->kit->id, expectedVersion: $this->kit->version);
        $this->assertDatabaseCount('sound_kit_upload_sessions', 0);
    }

    public function test_stale_kit_version_cannot_start_or_complete_but_can_inspect_and_cancel(): void
    {
        $this->actingAs($this->actor);
        $id = $this->start()['id'];
        $this->chunk($id)->assertOk();
        app(SoundKitDrafts::class)->save($this->kit, ['title' => 'New kit title', 'description' => '', 'provenance' => 'SYNTHETIC', 'version' => $this->kit->version], $this->actor);
        $this->assertPrivate($this->postJson(self::BASE, [...$this->payload(), 'originalName' => 'new-kit.zip'])->assertUnprocessable());
        $this->assertPrivate($this->postEmpty(self::BASE.'/'.$id.'/complete')->assertUnprocessable());
        $this->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('session.status', 'uploading');
        $this->postEmpty(self::BASE.'/'.$id.'/cancel')->assertOk()->assertJsonPath('session.status', 'cancelled');
        $this->assertDatabaseCount('sound_kit_revisions', 0);
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_signed_kit_page_update_is_private_when_real_csrf_rejects_before_boot(): void
    {
        $this->actingAs($this->actor);
        $snapshot = Livewire::test(UploadSoundKit::class, ['record' => $this->kit->id])->snapshot;
        $hydrations = 0;
        $stop = \Livewire\on('snapshot-verified', function () use (&$hydrations): void {
            $hydrations++;
        });
        $this->app->instance('env', 'local');
        try {
            $response = $this->withSession(['_token' => 'expected-kit-page-token'])->post(app('livewire')->getUpdateUri(), ['components' => [[
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updates' => [],
                'calls' => [['method' => 'prepareUpload', 'params' => [], 'path' => '']],
            ]]], ['X-Livewire' => 'true', 'Accept' => 'text/html'])->assertStatus(419);
            $this->assertPrivate($response);
            $response->assertDontSee($this->kit->title);
            $snapshot['checksum'] = str_repeat('0', 64);
            $this->withSession(['_token' => 'expected-kit-page-token'])->post(app('livewire')->getUpdateUri(), ['components' => [[
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updates' => [], 'calls' => [],
            ]]], ['X-Livewire' => 'true', 'Accept' => 'text/html'])->assertStatus(419)->assertHeaderMissing('X-Robots-Tag');
        } finally {
            $stop();
            $this->app->instance('env', 'testing');
        }
        $this->assertSame(0, $hydrations);
        $this->assertDatabaseCount('sound_kit_upload_sessions', 0);
    }

    public function test_kit_identity_and_mounted_version_are_locked_livewire_properties(): void
    {
        $this->actingAs($this->actor);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(UploadSoundKit::class, ['record' => $this->kit->id])->set('expectedVersion', 999);
    }
}
