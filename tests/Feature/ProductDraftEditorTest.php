<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\ProductDraft;
use App\Domain\Catalog\Models\ProductDraftVersion;
use App\Domain\Catalog\ProductDrafts;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Filament\Resources\ProductDraftResource;
use App\Filament\Resources\ProductDraftResource\Pages\ManageProductDrafts;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class ProductDraftEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private array $tracks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = LicenseFixtures::admin();
        $this->tracks = [];
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $this->tracks[] = app(SaveTrackMetadata::class)->handle(null, [
                'title' => 'Synthetic '.$name, 'slug' => 'synthetic-product-'.strtolower($name),
            ], $this->actor);
        }
        $this->actingAs($this->actor);
    }

    public function test_real_editor_creates_reorders_removes_tracks_and_preserves_prior_versions(): void
    {
        $before = $this->unchangedStore();
        Livewire::test(ManageProductDrafts::class)
            ->assertSee('Collection and album drafts')
            ->callAction('create', data: $this->form([$this->tracks[1]->id, $this->tracks[0]->id, $this->tracks[2]->id]))
            ->assertHasNoActionErrors();
        $draft = ProductDraft::sole();
        $first = app(ProductDrafts::class)->snapshot($draft->id, $this->actor);
        $this->assertSame('collection', $draft->kind);
        $this->assertSame(1, $draft->version);
        $this->assertSame([$this->tracks[1]->id, $this->tracks[0]->id, $this->tracks[2]->id], $first['track_ids']);
        $firstManifest = ProductDraftVersion::sole()->manifest_sha256;
        Livewire::test(ManageProductDrafts::class)
            ->callTableAction('edit', $draft, data: $this->form([$this->tracks[2]->id, $this->tracks[1]->id], [
                'title' => 'Synthetic revised collection', 'description' => 'Retained revised description',
            ]))->assertHasNoTableActionErrors();
        $current = app(ProductDrafts::class)->snapshot($draft->id, $this->actor);
        $this->assertSame(2, $current['version']);
        $this->assertSame([$this->tracks[2]->id, $this->tracks[1]->id], $current['track_ids']);
        $this->assertSame('Synthetic revised collection', $draft->fresh()->title);
        $this->assertSame($first['members'], $current['history'][1]['members']);
        $this->assertSame($firstManifest, $current['history'][1]['manifest_sha256']);
        $this->assertSame($before, $this->unchangedStore());
        $this->assertSame(1, AuditEvent::where('action', 'catalog.product_draft.created')->count());
        $this->assertSame(1, AuditEvent::where('action', 'catalog.product_draft.version_saved')->count());
        $this->assertFalse(ProductDraftResource::canDelete($draft));
        $this->assertFalse(ProductDraftResource::canDeleteAny());
    }

    public function test_album_creation_and_unchanged_edit_use_the_service_without_duplicate_versions(): void
    {
        Livewire::test(ManageProductDrafts::class)->callAction('create', data: $this->form([$this->tracks[0]->id], ['kind' => 'album']))
            ->assertHasNoActionErrors();
        $draft = ProductDraft::sole();
        $this->assertSame('album', $draft->kind);
        $before = $this->evidence();
        Livewire::test(ManageProductDrafts::class)->callTableAction('edit', $draft)->assertHasNoTableActionErrors();
        $this->assertSame($before, $this->evidence());
    }

    public function test_history_review_and_retained_selection_copy_the_exact_historical_descriptive_snapshot(): void
    {
        $draft = $this->draft();
        $old = app(ProductDrafts::class)->snapshot($draft->id, $this->actor);
        $oldVersionId = $old['history'][0]['id'];
        app(SaveTrackMetadata::class)->handle($this->tracks[0], [
            'metadata_version' => $this->tracks[0]->metadata_version, 'title' => 'Synthetic Alpha now renamed',
        ], $this->actor);
        $draft = app(ProductDrafts::class)->save($draft, ['version' => 1, 'kind' => 'collection',
            'title' => 'Synthetic later title', 'description' => 'Later notes', 'track_ids' => [$this->tracks[1]->id, $this->tracks[0]->id]], $this->actor);
        $before = $this->evidence();
        $history = Livewire::test(ManageProductDrafts::class)->mountTableAction('history', $draft);
        $this->assertStringContainsString('Synthetic Alpha now renamed', $history->get('mountedActions.0.data.current'));
        $this->assertStringContainsString('Version 1 — Synthetic collection', $history->get('mountedActions.0.data.history'));
        $this->assertStringContainsString('1. Synthetic Alpha (track #', $history->get('mountedActions.0.data.history'));
        $this->assertSame($before, $this->evidence());

        $selection = Livewire::test(ManageProductDrafts::class)->mountTableAction('selectVersion', $draft)
            ->assertSet('expectedProductId', $draft->id)->assertSet('expectedProductVersion', 2);
        $this->assertSame($old['members'], $selection->get('retainedVersions')[$oldVersionId]['members']);
        $selection->set('mountedActions.0.data.retained_version_id', (string) $oldVersionId);
        $this->assertStringContainsString('1. Synthetic Alpha (track #', $selection->get('mountedActions.0.data.retained_contents'));
        $this->assertStringNotContainsString('now renamed', $selection->get('mountedActions.0.data.retained_contents'));
        $this->assertSame($before, $this->evidence());
        $selection->callMountedTableAction()->assertHasNoTableActionErrors()
            ->assertNotified('Retained version copied to the current draft')
            ->assertSet('expectedProductId', null)->assertSet('expectedProductVersion', null)->assertSet('retainedVersions', []);
        $current = app(ProductDrafts::class)->snapshot($draft->id, $this->actor);
        $this->assertSame(3, $current['version']);
        $this->assertSame($old['title'], $current['title']);
        $this->assertSame($old['description'], $current['description']);
        $this->assertSame($old['members'], $current['members']);
        $this->assertSame($old['history'][0]['manifest_sha256'], $current['history'][0]['manifest_sha256']);
        $this->assertSame($oldVersionId, ProductDraftVersion::where('number', 3)->sole()->source_version_id);
        $this->assertSame('Synthetic Alpha now renamed', $this->tracks[0]->fresh()->title);
        $this->assertSame($oldVersionId, AuditEvent::where('action', 'catalog.product_draft.version_selected')->sole()->context['source_version_id']);
    }

    public function test_stale_editor_and_mounted_selection_preserve_the_newer_winning_version(): void
    {
        $draft = $this->draft();
        $versionId = ProductDraftVersion::sole()->id;
        $editor = Livewire::test(ManageProductDrafts::class)->mountTableAction('edit', $draft)
            ->setTableActionData(['title' => 'Synthetic losing edit']);
        $selection = Livewire::test(ManageProductDrafts::class)->mountTableAction('selectVersion', $draft)
            ->setTableActionData(['retained_version_id' => (string) $versionId]);
        app(ProductDrafts::class)->save($draft, ['version' => 1, 'title' => 'Synthetic winning edit',
            'description' => '', 'track_ids' => [$this->tracks[2]->id]], $this->actor);
        $before = $this->evidence();
        $editor->callMountedTableAction()->assertHasTableActionErrors(['title']);
        $selection->callMountedTableAction()->assertNotified('Draft version could not be selected');
        $this->assertSame($before, $this->evidence());
        $this->assertSame('Synthetic winning edit', $draft->fresh()->title);
    }

    public function test_invalid_and_duplicate_members_fail_without_direct_orm_changes(): void
    {
        Livewire::test(ManageProductDrafts::class)->callAction('create', data: $this->form([$this->tracks[0]->id], ['title' => '']))
            ->assertHasActionErrors(['title']);
        Livewire::test(ManageProductDrafts::class)->callAction('create', data: $this->form([]))->assertHasActionErrors();
        Livewire::test(ManageProductDrafts::class)->callAction('create', data: $this->form([$this->tracks[0]->id, $this->tracks[0]->id]))
            ->assertHasActionErrors();
        $this->assertDatabaseCount('product_drafts', 0);
        $this->assertDatabaseCount('product_draft_versions', 0);
        $draft = $this->draft();
        $before = $this->evidence();
        Livewire::test(ManageProductDrafts::class)->callTableAction('edit', $draft, data: ['kind' => 'album'])
            ->assertHasTableActionErrors(['kind']);
        $this->assertSame($before, $this->evidence());
    }

    public static function invalidIds(): array
    {
        return array_map(fn (string $case): array => [$case], ['boolean', 'float', 'leading-zero', 'exponent', 'overflow', 'array', 'null']);
    }

    #[DataProvider('invalidIds')]
    public function test_malformed_track_ids_are_not_coerced_into_valid_choices(string $case): void
    {
        $id = $this->tracks[0]->id;
        $value = match ($case) {
            'boolean' => true,
            'float' => (float) $id,
            'leading-zero' => '0'.$id,
            'exponent' => $id.'e0',
            'overflow' => (string) PHP_INT_MAX.'0',
            'array' => ['id' => $id],
            'null' => null,
        };
        $component = Livewire::test(ManageProductDrafts::class)->mountAction('create')->setActionData($this->form([$id]));
        $members = $component->get('mountedActions.0.data.track_ids');
        $key = array_key_first($members);
        $component->update(calls: [['method' => 'callMountedAction', 'params' => [[]], 'path' => '']], updates: [
            'mountedActions.0.data.track_ids.'.$key.'.track' => $value,
        ])->assertHasActionErrors();
        $this->assertDatabaseCount('product_drafts', 0);
    }

    public function test_selection_context_is_locked_cleared_on_cancel_and_rejects_foreign_versions(): void
    {
        $draft = $this->draft();
        $foreign = app(ProductDrafts::class)->save(null, ['kind' => 'album', 'title' => 'Synthetic other album',
            'description' => '', 'track_ids' => [$this->tracks[2]->id]], $this->actor);
        $foreignVersionId = $foreign->versions()->sole()->id;
        $before = $this->evidence();
        foreach (['expectedProductId' => $foreign->id, 'expectedProductVersion' => 999, 'retainedVersions' => []] as $field => $value) {
            $selection = Livewire::test(ManageProductDrafts::class)->mountTableAction('selectVersion', $draft);
            try {
                $selection->set($field, $value);
                $this->fail('A client changed locked version-selection context.');
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
        Livewire::test(ManageProductDrafts::class)->mountTableAction('selectVersion', $draft)->unmountAction()
            ->assertSet('expectedProductId', null)->assertSet('expectedProductVersion', null)->assertSet('retainedVersions', []);
        Livewire::test(ManageProductDrafts::class)->callTableAction('selectVersion', $draft, data: ['retained_version_id' => (string) $foreignVersionId])
            ->assertHasTableActionErrors();
        $this->assertSame($before, $this->evidence());
    }

    public static function withdrawals(): array
    {
        return ['staff removed' => ['is_admin', false], 'email verification removed' => ['email_verified_at', null]];
    }

    #[DataProvider('withdrawals')]
    public function test_every_mounted_action_rechecks_persisted_staff_authority(string $field, mixed $value): void
    {
        $draft = $this->draft();
        $versionId = ProductDraftVersion::sole()->id;
        $create = Livewire::test(ManageProductDrafts::class)->mountAction('create')->setActionData($this->form([$this->tracks[0]->id]));
        $edit = Livewire::test(ManageProductDrafts::class)->mountTableAction('edit', $draft)->setTableActionData(['title' => 'Denied edit']);
        $selection = Livewire::test(ManageProductDrafts::class)->mountTableAction('selectVersion', $draft)
            ->setTableActionData(['retained_version_id' => (string) $versionId]);
        $history = Livewire::test(ManageProductDrafts::class)->mountTableAction('history', $draft);
        User::whereKey($this->actor->id)->update([$field => $value]);
        $before = $this->evidence();
        $create->callMountedAction()->assertForbidden();
        $edit->callMountedTableAction()->assertForbidden();
        $selection->callMountedTableAction()->assertForbidden();
        $history->call('forceRender')->assertForbidden();
        $this->assertSame($before, $this->evidence());
    }

    public function test_mounted_actions_and_retained_history_recheck_current_mfa_enrollment(): void
    {
        $draft = $this->draft();
        $versionId = ProductDraftVersion::sole()->id;
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $create = Livewire::test(ManageProductDrafts::class)->mountAction('create')->setActionData($this->form([$this->tracks[0]->id]));
            $edit = Livewire::test(ManageProductDrafts::class)->mountTableAction('edit', $draft)->setTableActionData(['title' => 'Denied edit']);
            $selection = Livewire::test(ManageProductDrafts::class)->mountTableAction('selectVersion', $draft)
                ->setTableActionData(['retained_version_id' => (string) $versionId]);
            $history = Livewire::test(ManageProductDrafts::class)->mountTableAction('history', $draft);
            User::findOrFail($this->actor->id)->saveAppAuthenticationSecret(null);
            $before = $this->evidence();
            $create->callMountedAction()->assertForbidden();
            $edit->callMountedTableAction()->assertForbidden();
            $selection->callMountedTableAction()->assertForbidden();
            $history->call('forceRender')->assertForbidden();
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_guest_and_nonstaff_requests_cannot_open_private_drafts_or_discover_them_in_catalog(): void
    {
        $draft = $this->draft();
        $url = ProductDraftResource::getUrl();
        $this->get($url)->assertOk();
        $this->post('/admin/logout')->assertRedirect();
        $this->get($url)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get($url)->assertForbidden();
        Livewire::test(ManageProductDrafts::class)->assertForbidden();
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->assertDatabaseCount('product_drafts', 1);
        $this->assertSame(1, $draft->fresh()->version);
    }

    private function draft(): ProductDraft
    {
        return app(ProductDrafts::class)->save(null, ['kind' => 'collection', 'title' => 'Synthetic collection',
            'description' => 'Synthetic initial notes', 'track_ids' => [$this->tracks[0]->id, $this->tracks[1]->id]], $this->actor);
    }

    private function form(array $ids, array $changes = []): array
    {
        return array_replace(['kind' => 'collection', 'title' => 'Synthetic collection', 'description' => 'Synthetic initial notes',
            'track_ids' => array_map(fn (mixed $id): array => ['track' => (string) $id], $ids)], $changes);
    }

    private function evidence(): array
    {
        return array_map(fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(),
            ['product_drafts', 'product_draft_versions', 'product_draft_members', 'tracks', 'audit_events']);
    }

    private function unchangedStore(): array
    {
        return array_map(fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(),
            ['tracks', 'media_assets', 'offers', 'offer_revisions', 'license_versions', 'orders', 'license_grants']);
    }
}
