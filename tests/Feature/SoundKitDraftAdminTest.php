<?php

namespace Tests\Feature;

use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitProcessor;
use App\Filament\Resources\SoundKitDraftResource;
use App\Filament\Resources\SoundKitDraftResource\Pages\ManageSoundKitDrafts;
use App\Jobs\ProcessSoundKit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class SoundKitDraftAdminTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = LicenseFixtures::admin();
        $this->actingAs($this->actor);
    }

    private function values(array $changes = []): array
    {
        return array_replace(['title' => 'SYNTHETIC WAV kit', 'description' => 'Synthetic private kit description.',
            'provenance' => 'SYNTHETIC test-generated samples; no commercial rights approval.'], $changes);
    }

    private function draft(): SoundKitDraft
    {
        return app(SoundKitDrafts::class)->save(null, $this->values(), $this->actor);
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic-kit.zip', StemsFixtures::zip([
            ['name' => 'Kicks/Kick.wav'], ['name' => 'Textures/Long.wav', 'bytes' => MediaFixtures::wav(0.4)],
        ]));
    }

    public function test_only_current_verified_staff_can_open_the_page_and_there_is_no_delete_or_commerce_action(): void
    {
        auth()->logout();
        $this->get(SoundKitDraftResource::getUrl())->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get(SoundKitDraftResource::getUrl())->assertForbidden();
        $this->actingAs($this->actor)->get(SoundKitDraftResource::getUrl())->assertOk()->assertSee('Sound kit drafts')->assertSee('Create kit draft');
        $draft = $this->draft();
        $this->assertFalse(SoundKitDraftResource::canDelete($draft));
        $this->assertFalse(SoundKitDraftResource::canDeleteAny());
        $this->assertSame(['index', 'resumable-upload'], array_keys(SoundKitDraftResource::getPages()));
        $page = Livewire::test(ManageSoundKitDrafts::class)->assertCanSeeTableRecords([$draft]);
        foreach (['publish', 'price', 'license', 'download', 'delete'] as $action) {
            $page->assertTableActionDoesNotExist($action);
        }
    }

    public function test_real_create_edit_and_validation_use_versioned_domain_saves(): void
    {
        Livewire::test(ManageSoundKitDrafts::class)->callAction('create', data: $this->values(['title' => '']))->assertHasActionErrors(['title']);
        Livewire::test(ManageSoundKitDrafts::class)->callAction('create', data: $this->values(['provenance' => '']))->assertHasActionErrors(['provenance']);
        $this->assertDatabaseCount('sound_kit_drafts', 0);
        Livewire::test(ManageSoundKitDrafts::class)->callAction('create', data: $this->values(['description' => null, 'version' => 999, 'status' => 'published']))->assertHasNoActionErrors();
        $draft = SoundKitDraft::sole();
        $this->assertSame(['SYNTHETIC WAV kit', '', 1], [$draft->title, $draft->description, $draft->version]);
        Livewire::test(ManageSoundKitDrafts::class)->callTableAction('edit', $draft, data: $this->values(['title' => 'SYNTHETIC edited kit']))->assertHasNoTableActionErrors();
        $this->assertSame(['SYNTHETIC edited kit', 2], [$draft->fresh()->title, $draft->fresh()->version]);
        $this->assertDatabaseCount('sound_kit_revisions', 0);
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_real_zip_upload_and_processor_result_are_inspectable_without_private_paths_or_rights_claims(): void
    {
        $draft = $this->draft();
        Livewire::test(ManageSoundKitDrafts::class)->callTableAction('upload', $draft, data: ['upload' => $this->upload(), 'status' => 'ready', 'source_path' => '/forged'])
            ->assertHasNoTableActionErrors()->assertNotified('Kit archive retained privately');
        $revision = SoundKitRevision::sole();
        $this->assertSame('quarantined', $revision->status);
        $this->assertSame(2, $draft->fresh()->version);
        Queue::assertPushed(ProcessSoundKit::class);
        $waiting = Livewire::test(ManageSoundKitDrafts::class)->mountTableAction('inspect', $draft);
        $this->assertStringContainsString('Waiting for verification', $waiting->get('mountedActions.0.data.details'));
        $this->assertStringNotContainsString($revision->source_path, $waiting->html());

        $ready = app(SoundKitProcessor::class)->handle($revision->id);
        $this->assertSame('ready', $ready->status);
        Livewire::test(ManageSoundKitDrafts::class)->callTableAction('edit', $draft->fresh(), data: $this->values(['title' => 'SYNTHETIC newer title']))->assertHasNoTableActionErrors();
        $inspection = Livewire::test(ManageSoundKitDrafts::class)->mountTableAction('inspect', $draft->fresh());
        $text = $inspection->get('mountedActions.0.data.details');
        foreach (['Technically verified', 'Kicks/Kick.wav', 'Textures/Long.wav', 'Saved title: SYNTHETIC WAV kit', 'does not approve rights', $ready->manifest_sha256] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        $this->assertStringNotContainsString('SYNTHETIC newer title', $text);
        $this->assertStringNotContainsString($ready->source_path, $inspection->html());
        $this->assertStringNotContainsString($ready->archive_path, $inspection->html());
        $this->assertDatabaseCount('sound_kit_revisions', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_stale_editor_and_upload_keep_newer_draft_and_ignore_caller_revision_fields(): void
    {
        $draft = $this->draft();
        $edit = Livewire::test(ManageSoundKitDrafts::class)->mountTableAction('edit', $draft)->setTableActionData($this->values(['title' => 'Losing edit', 'version' => 2]));
        $upload = Livewire::test(ManageSoundKitDrafts::class)->mountTableAction('upload', $draft)->setTableActionData(['upload' => $this->upload(), 'version' => 2]);
        app(SoundKitDrafts::class)->save($draft, $this->values(['title' => 'Winning edit', 'version' => 1]), $this->actor);
        $edit->callMountedTableAction()->assertHasTableActionErrors(['title']);
        $upload->callMountedTableAction()->assertHasTableActionErrors(['upload']);
        $this->assertSame(['Winning edit', 2], [$draft->fresh()->title, $draft->fresh()->version]);
        $this->assertDatabaseCount('sound_kit_revisions', 0);
    }

    public function test_upload_field_does_not_describe_or_accept_a_stored_path_and_unsafe_zip_is_a_visible_error(): void
    {
        $draft = $this->draft();
        $page = Livewire::test(ManageSoundKitDrafts::class)->mountTableAction('upload', $draft)
            ->set('mountedActions.0.data.upload', ['forged' => 'private/sound-kit/source.zip']);
        $this->assertSame(['forged' => null], $page->instance()->callSchemaComponentMethod('mountedActionSchema0.upload', 'getUploadedFiles'));
        $page->callMountedTableAction()->assertHasTableActionErrors(['upload']);
        $unsafe = UploadedFile::fake()->createWithContent('unsafe.zip', StemsFixtures::zip([['name' => '../Escape.wav']]));
        Livewire::test(ManageSoundKitDrafts::class)->callTableAction('upload', $draft, data: ['upload' => $unsafe])->assertHasTableActionErrors(['upload']);
        $this->assertDatabaseCount('sound_kit_revisions', 0);
    }

    public function test_retry_queues_only_a_retained_waiting_revision_from_this_kit(): void
    {
        $draft = $this->draft();
        Livewire::test(ManageSoundKitDrafts::class)->callTableAction('upload', $draft, data: ['upload' => $this->upload()])->assertHasNoTableActionErrors();
        $revision = SoundKitRevision::sole();
        Livewire::test(ManageSoundKitDrafts::class)->callTableAction('retry', $draft->fresh(), data: ['revision_id' => (string) $revision->id])
            ->assertHasNoTableActionErrors()->assertNotified('Kit verification requested');
        Queue::assertPushed(ProcessSoundKit::class, 2);
        $other = $this->draft();
        Livewire::test(ManageSoundKitDrafts::class)->callTableAction('retry', $other, data: ['revision_id' => (string) $revision->id])->assertHasTableActionErrors(['revision_id']);
        app(SoundKitProcessor::class)->handle($revision->id);
        Livewire::test(ManageSoundKitDrafts::class)->callTableAction('retry', $draft->fresh(), data: ['revision_id' => (string) $revision->id])->assertHasTableActionErrors(['revision_id']);
        Queue::assertPushed(ProcessSoundKit::class, 2);
        $this->assertDatabaseCount('sound_kit_revisions', 1);
    }

    public function test_action_identity_revision_and_history_cannot_be_changed_from_the_caller(): void
    {
        $draft = $this->draft();
        foreach (['expectedKitId' => 999, 'expectedKitVersion' => 999, 'kitRevisions' => []] as $field => $value) {
            $page = Livewire::test(ManageSoundKitDrafts::class)->mountTableAction('upload', $draft);
            try {
                $page->set($field, $value);
                $this->fail('Caller changed locked kit action context.');
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseCount('sound_kit_revisions', 0);
    }

    public function test_mounted_upload_and_retained_inspection_recheck_current_authority_and_mfa(): void
    {
        $draft = $this->draft();
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $upload = Livewire::test(ManageSoundKitDrafts::class)->mountTableAction('upload', $draft)->setTableActionData(['upload' => $this->upload()]);
            $inspection = Livewire::test(ManageSoundKitDrafts::class)->mountTableAction('inspect', $draft);
            $this->actor->fresh()->saveAppAuthenticationSecret(null);
            $upload->callMountedTableAction()->assertForbidden();
            $inspection->call('$refresh')->assertForbidden();
            $this->assertDatabaseCount('sound_kit_revisions', 0);
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
        $page = Livewire::test(ManageSoundKitDrafts::class)->mountTableAction('edit', $draft);
        $this->actor->forceFill(['is_admin' => false])->save();
        $page->callMountedTableAction()->assertForbidden();
    }
}
