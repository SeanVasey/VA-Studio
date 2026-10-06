<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\ProductDraftMember;
use App\Domain\Catalog\Models\ProductDraftVersion;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\ProductDrafts;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class ProductDraftTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $title): Track
    {
        return Track::create(['title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title))]);
    }

    private function payload(array $ids, array $changes = []): array
    {
        return array_replace(['kind' => 'collection', 'title' => 'Synthetic private collection', 'description' => 'Private descriptive note', 'track_ids' => $ids], $changes);
    }

    private function rejects(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected the invalid product operation to be refused.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['product_drafts', 'product_draft_versions', 'product_draft_members', 'tracks', 'audit_events']);
    }

    public function test_ordered_collection_edits_append_immutable_versions_with_minimum_audit_and_no_commerce(): void
    {
        $actor = LicenseFixtures::admin();
        $a = $this->track('Synthetic A');
        $b = $this->track('Synthetic B');
        $service = app(ProductDrafts::class);
        $draft = $service->save(null, $this->payload([(string) $b->id, (string) $a->id]), $actor);
        $original = ProductDraftVersion::sole()->getAttributes();
        $snapshot = $service->snapshot($draft->id, $actor);
        $this->assertSame(1, $snapshot['version']);
        $this->assertSame([$b->id, $a->id], $snapshot['track_ids']);
        $this->assertSame(['Synthetic B', 'Synthetic A'], array_column($snapshot['members'], 'title'));
        $draft = $service->save($draft, $this->payload([$a->id], ['version' => '1', 'title' => 'Reordered album idea']), $actor);
        $this->assertSame(2, $draft->version);
        $this->assertSame([$a->id], $service->snapshot($draft->id, $actor)['track_ids']);
        $this->assertSame($original, ProductDraftVersion::oldest('id')->first()->getAttributes());
        $this->assertSame([2, 1], array_column($service->snapshot($draft->id, $actor)['history'], 'number'));
        $this->assertDatabaseCount('product_draft_members', 3);
        $created = AuditEvent::where('action', 'catalog.product_draft.created')->sole();
        $this->assertSame($draft->id, $created->subject_id);
        $this->assertSame($actor->id, $created->actor_id);
        $this->assertNull($created->context['before_hash']);
        $this->assertSame(2, $created->context['member_count']);
        $this->assertSame($original['manifest_sha256'], $created->context['after_hash']);
        $updated = AuditEvent::where('action', 'catalog.product_draft.version_saved')->sole();
        $this->assertSame($created->context['after_hash'], $updated->context['before_hash']);
        $this->assertStringNotContainsString('Private descriptive', json_encode($updated->context));
        $this->assertStringNotContainsString('Synthetic A', json_encode($updated->context));
        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(['draft'], Track::pluck('status')->unique()->values()->all());
    }

    public function test_historical_selection_copies_exact_old_metadata_and_new_edit_takes_current_source_snapshot(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->track('Synthetic Original');
        $service = app(ProductDrafts::class);
        $draft = $service->save(null, $this->payload([$track->id], ['kind' => 'album']), $actor);
        $first = ProductDraftVersion::sole();
        app(SaveTrackMetadata::class)->handle($track, ['metadata_version' => 0, 'title' => 'Synthetic Current'], $actor);
        $draft = $service->save($draft, $this->payload([$track->id], ['kind' => 'album', 'version' => 1]), $actor);
        $this->assertSame('Synthetic Current', $service->snapshot($draft->id, $actor)['members'][0]['title']);
        $draft = $service->select($draft, $first->id, 2, $actor);
        $restored = ProductDraftVersion::where('number', 3)->sole();
        $this->assertSame($first->manifest_sha256, $restored->manifest_sha256);
        $this->assertSame($first->id, $restored->source_version_id);
        $this->assertSame(CanonicalJson::encode($first->manifest), CanonicalJson::encode($restored->manifest));
        $this->assertSame('Synthetic Original', $service->snapshot($draft->id, $actor)['members'][0]['title']);
        $this->assertSame('Synthetic Current', $track->fresh()->title);
        $before = $this->evidence();
        $this->assertSame(3, $service->select($draft, $first->id, 3, $actor)->version);
        $this->assertSame($before, $this->evidence());
        $draft = $service->save($draft, $this->payload([$track->id], ['kind' => 'album', 'version' => 3]), $actor);
        $this->assertSame(4, $draft->version);
        $this->assertSame('Synthetic Current', $service->snapshot($draft->id, $actor)['members'][0]['title']);
        $this->assertSame(1, AuditEvent::where('action', 'catalog.product_draft.version_selected')->count());
    }

    public function test_no_op_and_read_projections_preserve_evidence_and_stale_edit_or_selection_never_overwrite(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->track('Synthetic One');
        $service = app(ProductDrafts::class);
        $draft = $service->save(null, $this->payload([$track->id]), $actor);
        $retained = ProductDraftVersion::sole();
        $before = $this->evidence();
        $service->save($draft, $this->payload([$track->id], ['version' => 1]), $actor);
        $service->snapshot($draft->id, $actor);
        $this->assertSame([$track->id => 'Synthetic One (#'.$track->id.')'], $service->tracks($actor));
        $this->assertSame($before, $this->evidence());
        $service->save($draft, $this->payload([$track->id], ['version' => 1, 'title' => 'Concurrent change']), $actor);
        $before = $this->evidence();
        $this->rejects(fn () => $service->save($draft, $this->payload([$track->id], ['version' => 1]), $actor));
        $this->rejects(fn () => $service->select($draft, $retained->id, 1, $actor));
        $this->assertSame($before, $this->evidence());
    }

    public static function invalidPayloads(): array
    {
        return ['kit is separate' => [['kind' => 'kit']], 'empty members' => [['track_ids' => []]],
            'too many' => [['track_ids' => range(1, 101)]], 'duplicate normalized identity' => [['track_ids' => [1, '1']]],
            'noncanonical identity' => [['track_ids' => ['01']]], 'fractional identity' => [['track_ids' => [1.0]]],
            'associative members' => [['track_ids' => ['first' => 1]]], 'missing source' => [['track_ids' => [999999]]],
            'blank title' => [['title' => ' ']], 'title size' => [['title' => str_repeat('x', 181)]],
            'description control' => [['description' => "note\0bad"]], 'description size' => [['description' => str_repeat('x', 4001)]],
            'price injection' => [['price' => 10]], 'media injection' => [['asset_ids' => [1]]],
            'revision on create' => [['version' => 1]]];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_or_unapproved_input_is_rejected_atomically(array $changes): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->track('Synthetic Input');
        $before = $this->evidence();
        $this->rejects(fn () => app(ProductDrafts::class)->save(null, $this->payload([$track->id], $changes), $actor));
        $this->assertSame($before, $this->evidence());
    }

    public function test_foreign_version_and_changed_kind_cannot_be_selected_or_saved(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->track('Synthetic Identity');
        $service = app(ProductDrafts::class);
        $first = $service->save(null, $this->payload([$track->id]), $actor);
        $second = $service->save(null, $this->payload([$track->id], ['kind' => 'album']), $actor);
        $before = $this->evidence();
        $this->rejects(fn () => $service->select($first, $second->versions()->sole()->id, 1, $actor));
        $this->rejects(fn () => $service->save($first, $this->payload([$track->id], ['kind' => 'album', 'version' => 1]), $actor));
        $this->assertSame($before, $this->evidence());
    }

    public function test_fresh_authority_and_required_mfa_protect_reads_and_mutations(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->track('Synthetic Authority');
        $service = app(ProductDrafts::class);
        $draft = $service->save(null, $this->payload([$track->id]), $actor);
        $retained = ProductDraftVersion::sole();
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $actor->saveAppAuthenticationSecret($panel->getMultiFactorAuthenticationProviders()['app']->generateSecret());
        $service->snapshot($draft->id, $actor);
        DB::table('users')->where('id', $actor->id)->update(['app_authentication_secret' => null]);
        $before = $this->evidence();
        foreach ([fn () => $service->save(null, $this->payload([$track->id]), $actor),
            fn () => $service->save($draft, $this->payload([$track->id], ['version' => 1]), $actor),
            fn () => $service->select($draft, $retained->id, 1, $actor),
            fn () => $service->snapshot($draft->id, $actor), fn () => $service->tracks($actor)] as $operation) {
            try {
                $operation();
                $this->fail('Withdrawn MFA was accepted.');
            } catch (AuthorizationException) {
                $this->assertSame($before, $this->evidence());
            }
        }
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: false);
        DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
        $this->expectException(AuthorizationException::class);
        $service->snapshot($draft->id, $actor);
    }

    public function test_audit_failure_rolls_back_parent_version_and_members_together(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->track('Synthetic Rollback');
        $before = $this->evidence();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
        try {
            app(ProductDrafts::class)->save(null, $this->payload([$track->id]), $actor);
            $this->fail('Audit failure should abort the entire mutation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_orm_and_bulk_sql_cannot_mutate_or_delete_evidence_or_reassign_identity(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->track('Synthetic Evidence');
        $draft = app(ProductDrafts::class)->save(null, $this->payload([$track->id]), $actor);
        $version = ProductDraftVersion::sole();
        $member = ProductDraftMember::sole();
        foreach ([fn () => $version->update(['manifest_sha256' => str_repeat('0', 64)]), fn () => $version->delete(),
            fn () => $member->update(['title' => 'Changed']), fn () => $member->delete(),
            fn () => $draft->update(['kind' => 'album']), fn () => $draft->delete()] as $operation) {
            $this->rejects($operation);
        }
        $before = $this->evidence();
        foreach ([fn () => DB::table('product_draft_versions')->update(['manifest_sha256' => str_repeat('0', 64)]),
            fn () => DB::table('product_draft_versions')->delete(), fn () => DB::table('product_draft_members')->update(['title' => 'Changed']),
            fn () => DB::table('product_draft_members')->delete(), fn () => DB::table('product_drafts')->update(['kind' => 'album']),
            fn () => DB::table('product_drafts')->delete(), fn () => DB::table('tracks')->where('id', $track->id)->delete()] as $operation) {
            try {
                $operation();
                $this->fail('Retained evidence was mutated through SQL.');
            } catch (QueryException) {
                $this->assertSame($before, $this->evidence());
            }
        }
    }

    public function test_inconsistent_parent_or_appended_members_fail_closed_without_repairing_evidence(): void
    {
        $actor = LicenseFixtures::admin();
        $a = $this->track('Synthetic Retained');
        $b = $this->track('Synthetic Foreign');
        $service = app(ProductDrafts::class);
        $draft = $service->save(null, $this->payload([$a->id]), $actor);
        DB::table('product_draft_members')->insert(['product_draft_version_id' => ProductDraftVersion::sole()->id,
            'track_id' => $b->id, 'position' => 2, 'title' => $b->title, 'metadata_version' => 0, 'publication_version' => 0]);
        $before = $this->evidence();
        $this->rejects(fn () => $service->snapshot($draft->id, $actor));
        $this->rejects(fn () => $service->save($draft, $this->payload([$a->id], ['version' => 1]), $actor));
        $this->assertSame($before, $this->evidence());
    }
}
