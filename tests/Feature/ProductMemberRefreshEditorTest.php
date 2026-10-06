<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\ProductDraft;
use App\Domain\Catalog\Models\ProductDraftVersion;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\ProductDrafts;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Filament\Resources\ProductDraftResource;
use App\Filament\Resources\ProductDraftResource\Pages\ManageProductDrafts;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class ProductMemberRefreshEditorTest extends TestCase
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
                'title' => 'Synthetic '.$name, 'slug' => 'synthetic-refresh-'.strtolower($name),
            ], $this->actor);
        }
        $this->actingAs($this->actor);
    }

    public static function kinds(): array
    {
        return ['collection' => ['collection'], 'album' => ['album']];
    }

    #[DataProvider('kinds')]
    public function test_review_shows_exact_saved_and_current_snapshots_without_writing_and_confirmation_preserves_history(string $kind): void
    {
        $draft = $this->draft($kind);
        $original = app(ProductDrafts::class)->snapshot($draft->id, $this->actor);
        $this->rename($this->tracks[0], 'Synthetic Alpha revised');
        Track::whereKey($this->tracks[1]->id)->increment('publication_version');
        $before = $this->evidence();
        $unchanged = $this->unrelatedEvidence();
        $page = $this->review($draft)
            ->assertSet('memberRefreshProductId', $draft->id)
            ->assertSet('memberRefreshVersion', 1);
        $this->assertStringContainsString('Refresh descriptive track snapshots only.', $page->instance()->getMountedAction()->getModalDescription());
        $this->assertStringContainsString('This does not assess product readiness, set a price or license, or publish a product.', $page->instance()->getMountedAction()->getModalDescription());
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $page->get('memberRefreshHash'));
        $this->assertSame(app(ProductDrafts::class)->reviewMembers($draft->id, $this->actor)['review_hash'], $page->get('memberRefreshHash'));
        $this->assertSame($before, $this->evidence());
        $saved = $page->get('mountedActions.0.data.saved_members');
        $current = $page->get('mountedActions.0.data.current_members');
        $this->assertStringContainsString('1. Synthetic Beta (track #'.$this->tracks[1]->id.')', $saved);
        $this->assertStringContainsString('2. Synthetic Alpha (track #'.$this->tracks[0]->id.')', $saved);
        $this->assertStringNotContainsString('Alpha revised', $saved);
        $this->assertStringContainsString('2. Synthetic Alpha revised (track #'.$this->tracks[0]->id.')', $current);
        $this->assertStringContainsString('Metadata revision '.$this->tracks[0]->fresh()->metadata_version, $current);
        $this->assertStringContainsString('publication revision '.$this->tracks[1]->fresh()->publication_version, $current);
        $this->assertStringContainsString('2 of 2 track snapshots changed.', $page->get('mountedActions.0.data.changes'));
        $this->assertStringContainsString('1. Track #'.$this->tracks[1]->id.': publication revision', $page->get('mountedActions.0.data.changes'));
        $this->assertStringContainsString('2. Track #'.$this->tracks[0]->id.': title, metadata revision', $page->get('mountedActions.0.data.changes'));
        $page->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Track snapshots saved as a new draft version');
        $this->assertCleared($page);
        $after = app(ProductDrafts::class)->snapshot($draft->id, $this->actor);
        $this->assertSame(2, $after['version']);
        $this->assertSame($kind, $after['kind']);
        $this->assertSame($original['title'], $after['title']);
        $this->assertSame($original['description'], $after['description']);
        $this->assertSame($original['track_ids'], $after['track_ids']);
        $this->assertSame($original['history'][0], $after['history'][1]);
        $this->assertSame('Synthetic Alpha revised', $after['members'][1]['title']);
        $this->assertSame($unchanged, $this->unrelatedEvidence());
        $history = Livewire::test(ManageProductDrafts::class)->mountTableAction('history', $draft->fresh());
        $this->assertStringContainsString('Version 2', $history->get('mountedActions.0.data.current'));
        $this->assertStringContainsString('2. Synthetic Alpha (track #', $history->get('mountedActions.0.data.history'));
    }

    public function test_unchanged_confirmation_is_an_explicit_no_op(): void
    {
        $draft = $this->draft();
        $before = $this->evidence();
        $page = $this->review($draft);
        $this->assertSame('All track snapshots are current. Confirming this review will not add a draft version.', $page->get('mountedActions.0.data.changes'));
        $this->assertSame($page->get('mountedActions.0.data.saved_members'), $page->get('mountedActions.0.data.current_members'));
        $page->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Track snapshots are already current');
        $this->assertCleared($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_display_only_field_edits_and_extra_form_payload_cannot_change_the_reviewed_contents(): void
    {
        $draft = $this->draft();
        $this->rename($this->tracks[0], 'Synthetic reviewed title');
        $page = $this->review($draft)->setTableActionData([
            'changes' => 'No changes', 'saved_members' => 'Forged saved contents', 'current_members' => 'Forged current contents',
            'track_ids' => [$this->tracks[2]->id], 'title' => 'Forged product title', 'version' => 999,
            'review_hash' => str_repeat('f', 64),
        ]);
        $page->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertCleared($page);
        $after = app(ProductDrafts::class)->snapshot($draft->id, $this->actor);
        $this->assertSame('Synthetic private collection', $after['title']);
        $this->assertSame([$this->tracks[1]->id, $this->tracks[0]->id], $after['track_ids']);
        $this->assertSame('Synthetic reviewed title', $after['members'][1]['title']);
        $this->assertSame(2, $after['version']);
    }

    public static function staleChanges(): array
    {
        return ['source title' => ['title'], 'source metadata' => ['metadata'], 'source publication' => ['publication'], 'product draft' => ['draft']];
    }

    #[DataProvider('staleChanges')]
    public function test_stale_review_refuses_new_source_or_product_changes_and_consumes_context(string $change): void
    {
        $draft = $this->draft();
        $this->rename($this->tracks[0], 'Synthetic first change');
        $page = $this->review($draft);
        match ($change) {
            'title' => $this->rename($this->tracks[0], 'Synthetic newer change'),
            'metadata' => app(SaveTrackMetadata::class)->handle($this->tracks[0]->fresh(), [
                'metadata_version' => $this->tracks[0]->fresh()->metadata_version, 'genre' => 'Synthetic newer genre',
            ], $this->actor),
            'publication' => Track::whereKey($this->tracks[0]->id)->increment('publication_version'),
            'draft' => app(ProductDrafts::class)->save($draft, ['version' => 1, 'title' => 'Synthetic winning draft',
                'description' => 'New description', 'track_ids' => [$this->tracks[2]->id]], $this->actor),
        };
        $before = $this->evidence();
        $page->callMountedTableAction()->assertNotified('Track snapshots could not be refreshed');
        $this->assertCleared($page);
        $page->assertSet('mountedActions', []);
        $this->assertSame($before, $this->evidence());
        $reopened = $this->review($draft->fresh());
        $this->assertNotNull($reopened->get('memberRefreshHash'));
        $reopened->callMountedTableAction()->assertHasNoTableActionErrors();
    }

    public function test_locked_review_identity_version_and_hash_reject_client_overrides(): void
    {
        $draft = $this->draft();
        $before = $this->evidence();
        foreach (['memberRefreshProductId' => $draft->id + 1, 'memberRefreshVersion' => 999,
            'memberRefreshHash' => str_repeat('0', 64)] as $property => $value) {
            try {
                $this->review($draft)->set($property, $value);
                $this->fail('A client changed locked member-refresh context.');
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_a_foreign_action_record_cannot_reuse_the_original_review(): void
    {
        $draft = $this->draft();
        $foreign = $this->draft('album');
        $this->rename($this->tracks[0], 'Synthetic pending change');
        $before = $this->evidence();
        $page = $this->review($draft)->set('mountedActions.0.context.recordKey', (string) $foreign->id);
        $this->assertCleared($page);
        $page->callMountedTableAction()->assertStatus(409);
        $this->assertSame($before, $this->evidence());
    }

    public function test_confirmation_without_review_context_cannot_refresh_or_regenerate_a_review(): void
    {
        $draft = $this->draft();
        $this->rename($this->tracks[0], 'Synthetic pending change');
        $before = $this->evidence();
        $page = $this->review($draft)->call('clearMemberRefresh');
        $this->assertCleared($page);
        $page->callMountedTableAction()->assertStatus(409);
        $this->assertSame($before, $this->evidence());
    }

    public function test_review_from_another_authenticated_operator_cannot_be_confirmed(): void
    {
        $draft = $this->draft();
        $this->rename($this->tracks[0], 'Synthetic pending change');
        $page = $this->review($draft);
        $this->actingAs(LicenseFixtures::admin());
        $before = $this->evidence();
        $page->callMountedTableAction()->assertNotified('Track snapshots could not be refreshed');
        $this->assertCleared($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_cancel_new_action_and_changed_action_intent_clear_member_refresh_context(): void
    {
        $draft = $this->draft();
        $before = $this->evidence();
        $this->assertCleared($this->review($draft)->unmountAction());
        $this->assertCleared($this->review($draft)->mountAction('create'));
        $this->assertCleared($this->review($draft)->mountTableAction('history', $draft));
        $this->assertCleared($this->review($draft)->mountTableAction('selectVersion', $draft));
        $selection = Livewire::test(ManageProductDrafts::class)->mountTableAction('selectVersion', $draft);
        $selection->assertSet('expectedProductId', $draft->id);
        $review = $selection->mountTableAction('refreshMembers', $draft);
        $review->assertSet('expectedProductId', null)->assertSet('expectedProductVersion', null)->assertSet('retainedVersions', []);
        foreach (['mountedActions.0.name' => 'history', 'mountedActions.0.arguments.unreviewed' => true] as $property => $value) {
            $this->assertCleared($this->review($draft)->set($property, $value));
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_unexpected_submit_error_clears_the_consumed_review(): void
    {
        $draft = $this->draft();
        $this->rename($this->tracks[0], 'Synthetic pending change');
        $page = $this->review($draft);
        $before = $this->evidence();
        $this->partialMock(ProductDrafts::class)->shouldReceive('refreshMembers')->once()->andThrow(new RuntimeException('Synthetic submit failure'));
        try {
            $page->instance()->callMountedAction();
            $this->fail('Expected the synthetic service failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic submit failure', $exception->getMessage());
        }
        $this->assertNull($page->instance()->memberRefreshProductId);
        $this->assertNull($page->instance()->memberRefreshVersion);
        $this->assertNull($page->instance()->memberRefreshHash);
        $this->assertSame($before, $this->evidence());
    }

    public static function withdrawals(): array
    {
        return ['staff role removed' => ['is_admin', false], 'verification removed' => ['email_verified_at', null]];
    }

    #[DataProvider('withdrawals')]
    public function test_mounted_refresh_rechecks_persisted_operator_authority(string $field, mixed $value): void
    {
        $draft = $this->draft();
        $this->rename($this->tracks[0], 'Synthetic pending change');
        $page = $this->review($draft);
        User::whereKey($this->actor->id)->update([$field => $value]);
        $before = $this->evidence();
        $page->callMountedTableAction()->assertForbidden();
        Livewire::test(ManageProductDrafts::class)->assertForbidden();
        $this->assertSame($before, $this->evidence());
    }

    public function test_mounted_refresh_rechecks_required_mfa_enrollment(): void
    {
        $draft = $this->draft();
        $this->rename($this->tracks[0], 'Synthetic pending change');
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $page = $this->review($draft);
            User::findOrFail($this->actor->id)->saveAppAuthenticationSecret(null);
            $before = $this->evidence();
            $page->callMountedTableAction()->assertForbidden();
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public function test_review_is_private_escaped_and_contains_only_descriptive_evidence(): void
    {
        $draft = $this->draft();
        $title = 'Synthetic <script>window.privateTitle = true</script>';
        $this->rename($this->tracks[0], $title);
        $page = $this->review($draft);
        $component = $page->instance();
        $field = $component->getSchema($component->getMountedActionSchemaName())->getComponent('current_members');
        $this->assertTrue($field->isReadOnly());
        $this->assertFalse($field->isDehydrated());
        $html = $field->toHtml();
        // Livewire binds the plain string to the textarea value; it is never interpolated as HTML.
        $this->assertStringContainsString('x-model="state"', $html);
        $this->assertStringNotContainsString('<script>window.privateTitle = true</script>', $html);
        $this->assertStringContainsString($title, $page->get('mountedActions.0.data.current_members'));
        foreach (['storage/', 'quarantine/', 'private/', 's3://', 'master_wav', 'license_terms', 'amount_minor'] as $privatePath) {
            $this->assertStringNotContainsString($privatePath, json_encode($page->get('mountedActions.0.data'), JSON_THROW_ON_ERROR));
        }
        $page->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks')->assertDontSee($title)->assertDontSee($draft->title);
        $this->post('/admin/logout')->assertRedirect();
        $this->get(ProductDraftResource::getUrl())->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get(ProductDraftResource::getUrl())->assertForbidden();
        Livewire::test(ManageProductDrafts::class)->assertForbidden();
        $this->assertSame(2, ProductDraftVersion::where('product_draft_id', $draft->id)->count());
    }

    private function draft(string $kind = 'collection'): ProductDraft
    {
        return app(ProductDrafts::class)->save(null, ['kind' => $kind, 'title' => 'Synthetic private '.$kind,
            'description' => 'Synthetic retained description', 'track_ids' => [$this->tracks[1]->id, $this->tracks[0]->id]], $this->actor);
    }

    private function rename(Track $track, string $title): Track
    {
        return app(SaveTrackMetadata::class)->handle($track->fresh(), [
            'metadata_version' => $track->fresh()->metadata_version, 'title' => $title,
        ], $this->actor);
    }

    private function review(ProductDraft $draft): Testable
    {
        return Livewire::test(ManageProductDrafts::class)->mountTableAction('refreshMembers', $draft);
    }

    private function assertCleared(Testable $page): void
    {
        $page->assertSet('memberRefreshProductId', null)->assertSet('memberRefreshVersion', null)->assertSet('memberRefreshHash', null);
    }

    private function evidence(): array
    {
        return array_map(fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(),
            ['product_drafts', 'product_draft_versions', 'product_draft_members', 'tracks', 'audit_events']);
    }

    private function unrelatedEvidence(): array
    {
        return array_map(fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(),
            ['tracks', 'media_assets', 'offers', 'offer_revisions', 'license_versions', 'orders', 'license_grants']);
    }
}
