<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class TrackMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function draft(User $actor): Track
    {
        return app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic draft', 'slug' => 'synthetic-draft'], $actor);
    }

    public function test_filament_create_and_edit_record_the_actor_revision_and_minimized_change_evidence(): void
    {
        $author = LicenseFixtures::admin();
        $editor = LicenseFixtures::admin();
        $this->actingAs($author);
        Livewire::test(ManageTracks::class)->callAction('create', data: [
            'title' => 'Synthetic draft', 'slug' => 'synthetic-draft', 'artist' => 'Test only', 'bpm' => '95',
            'tags' => ['Piano'], 'description' => 'Private synthetic working note',
            'status' => 'published', 'duration_seconds' => 999, 'published_slug' => 'forged',
        ])->assertHasNoActionErrors();
        $track = Track::sole();
        $this->assertSame('draft', $track->status);
        $this->assertNull($track->published_slug);
        $this->assertNull($track->duration_seconds);
        $this->assertSame(1, $track->metadata_version);
        $created = AuditEvent::where('action', 'catalog.track.created')->sole();
        $this->assertSame($author->id, $created->actor_id);
        $this->assertSame($track->id, $created->subject_id);
        $this->assertNull($created->context['before_hash']);
        $this->assertStringNotContainsString('Private synthetic', json_encode($created->context));

        $this->actingAs($editor);
        Livewire::test(ManageTracks::class)->callTableAction('edit', $track, data: ['title' => 'Revised synthetic title', 'bpm' => '100'])
            ->assertHasNoTableActionErrors();
        $updated = AuditEvent::where('action', 'catalog.track.metadata_updated')->sole();
        $this->assertSame($editor->id, $updated->actor_id);
        $this->assertSame(['title', 'bpm'], $updated->context['changed_fields']);
        $this->assertSame($created->context['after_hash'], $updated->context['before_hash']);
        $this->assertNotSame($updated->context['before_hash'], $updated->context['after_hash']);
        $this->assertSame(2, $updated->context['metadata_version']);
        $this->assertSame(100, $track->refresh()->bpm);
        $this->assertSame(['Piano'], $track->tags);
        $this->assertSame(2, $track->metadata_version);
        $this->get('/tracks/'.$track->slug)->assertNotFound();
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
    }

    public function test_no_op_saves_keep_the_revision_timestamp_and_audit_count(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor)->refresh();
        $before = $track->toArray();
        $this->travel(5)->minutes();
        $same = app(SaveTrackMetadata::class)->handle($track, ['title' => 'Synthetic draft', 'metadata_version' => 1], $actor);
        $this->assertSame($before, $same->toArray());
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_command_rejects_non_staff_unverified_staff_and_mass_assignment_without_changes(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $unverified = LicenseFixtures::admin();
        $unverified->email_verified_at = null;
        $unverified->save();
        foreach ([User::factory()->create(), $unverified] as $denied) {
            foreach ([null, $track] as $target) {
                try {
                    app(SaveTrackMetadata::class)->handle($target, ['title' => 'Forbidden'], $denied);
                    $this->fail('Unauthorized metadata command succeeded.');
                } catch (AuthorizationException) {
                }
            }
            $this->actingAs($denied)->get('/admin/tracks')->assertForbidden();
        }
        foreach (['id' => 999, 'status' => 'published', 'published_at' => now(), 'published_slug' => 'forged', 'waveform' => [1], 'duration_seconds' => 999, 'actor_id' => $actor->id] as $field => $value) {
            foreach ([null, $track] as $target) {
                try {
                    app(SaveTrackMetadata::class)->handle($target, ['title' => 'Injected', 'slug' => 'injected', $field => $value, 'metadata_version' => 1], $actor);
                    $this->fail('Unexpected track field was accepted: '.$field);
                } catch (ValidationException) {
                }
            }
        }
        $this->assertSame('Synthetic draft', $track->fresh()->title);
        $this->assertDatabaseCount('tracks', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_mounted_admin_actions_reauthorize_when_the_request_actor_changes(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $this->actingAs($actor);
        $create = Livewire::test(ManageTracks::class)->mountAction('create')->setActionData(['title' => 'Forbidden', 'slug' => 'forbidden', 'artist' => 'Test']);
        $edit = Livewire::test(ManageTracks::class)->mountTableAction('edit', $track)->setTableActionData(['title' => 'Forbidden']);
        $this->actingAs(User::factory()->create());
        $create->callMountedAction()->assertForbidden();
        $edit->callMountedTableAction()->assertForbidden();
        $this->assertDatabaseCount('tracks', 1);
        $this->assertSame('Synthetic draft', $track->fresh()->title);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_stale_edit_form_cannot_overwrite_a_newer_save_and_reopening_recovers(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $this->actingAs($actor);
        $stale = Livewire::test(ManageTracks::class)->mountTableAction('edit', $track)->setTableActionData(['title' => 'Losing edit']);
        app(SaveTrackMetadata::class)->handle($track, ['title' => 'Winning edit', 'metadata_version' => 1], $actor);
        $stale->callMountedTableAction()->assertHasTableActionErrors(['title']);
        $this->assertSame('Winning edit', $track->fresh()->title);
        $this->assertDatabaseCount('audit_events', 2);
        Livewire::test(ManageTracks::class)->callTableAction('edit', $track->fresh(), data: ['mood' => 'Reflective'])->assertHasNoTableActionErrors();
        $this->assertSame('Winning edit', $track->refresh()->title);
        $this->assertSame('Reflective', $track->mood);
        $this->assertSame(3, $track->metadata_version);
    }

    public function test_validation_is_enforced_for_direct_commands_and_returns_actionable_form_errors(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $this->actingAs($actor);
        foreach (['bpm' => 401, 'tags' => array_fill(0, 21, 'Tag'), 'slug' => 'Unsafe/Slug', 'musical_key' => str_repeat('x', 25), 'description' => str_repeat('x', 10001), 'metadata_version' => null] as $field => $value) {
            try {
                app(SaveTrackMetadata::class)->handle($track, [$field => $value, 'metadata_version' => $field === 'metadata_version' ? $value : 1], $actor);
                $this->fail('Invalid metadata accepted: '.$field);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        Livewire::test(ManageTracks::class)->callTableAction('edit', $track, data: ['bpm' => 401])->assertHasTableActionErrors(['bpm']);
        Livewire::test(ManageTracks::class)->callAction('create', data: ['title' => 'Duplicate', 'slug' => $track->slug, 'artist' => 'Test'])->assertHasActionErrors(['slug']);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseCount('tracks', 1);
    }

    public function test_audit_failure_rolls_back_creation_and_edits(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor)->refresh();
        $before = $track->toArray();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
        foreach ([null, $track] as $target) {
            try {
                app(SaveTrackMetadata::class)->handle($target, $target ? ['title' => 'Must roll back', 'metadata_version' => 1] : ['title' => 'Must roll back', 'slug' => 'rollback'], $actor);
                $this->fail('Audit failure did not abort the save.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic audit failure', $exception->getMessage());
            }
        }
        $this->assertSame($before, $track->fresh()->toArray());
        $this->assertDatabaseCount('tracks', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_published_metadata_can_change_but_incomplete_saves_and_url_changes_cannot(): void
    {
        $fixture = QuoteFixtures::selection();
        $track = $fixture['track'];
        $actor = $fixture['actor'];
        $this->actingAs($actor);
        $slug = $track->slug;
        $this->assertSame($slug, $track->published_slug);
        Livewire::test(ManageTracks::class)->callTableAction('edit', $track, data: ['title' => 'Updated public title', 'slug' => 'forged-disabled-field'])
            ->assertHasNoTableActionErrors();
        $this->assertSame($slug, $track->refresh()->slug);
        $this->get('/tracks/'.$slug)->assertOk()->assertSee('Updated public title');
        $before = $track->toArray();
        $count = AuditEvent::count();
        Livewire::test(ManageTracks::class)->callTableAction('edit', $track, data: ['genre' => null])->assertHasTableActionErrors(['title']);
        $this->assertSame($before, $track->fresh()->toArray());
        $this->assertSame($count, AuditEvent::count());
        foreach (['published', 'draft'] as $state) {
            if ($state === 'draft') {
                $track = app(PublishTrack::class)->unpublish($track, $actor);
                $this->get('/tracks/'.$slug)->assertNotFound();
            }
            try {
                app(SaveTrackMetadata::class)->handle($track, ['slug' => 'replacement-url', 'metadata_version' => $track->metadata_version], $actor);
                $this->fail('Previously published URL changed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('slug', $exception->errors());
            }
            $this->assertSame($slug, $track->fresh()->published_slug);
        }
        $track = app(SaveTrackMetadata::class)->handle($track, ['mood' => 'Restored', 'metadata_version' => $track->metadata_version], $actor);
        app(PublishTrack::class)->handle($track, $actor);
        $this->get('/tracks/'.$slug)->assertOk();
        $this->get('/tracks/replacement-url')->assertNotFound();
        $this->assertSame(2, AuditEvent::where('action', 'catalog.track.published')->count());
        $this->assertSame(1, AuditEvent::where('action', 'catalog.track.unpublished')->count());
    }

    public function test_database_and_model_preserve_reserved_urls_against_bulk_rewrites_and_deletion(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->draft($actor);
        $track = app(SaveTrackMetadata::class)->handle($track, ['slug' => 'editable-draft', 'metadata_version' => 1], $actor);
        $this->assertSame('editable-draft', $track->slug);
        // Synthetic retained URL; no saleable product or fabricated readiness is required to test the constraint.
        DB::table('tracks')->where('id', $track->id)->update(['published_slug' => $track->slug]);
        $track->refresh();
        foreach ([['slug' => 'changed'], ['slug' => 'EDITABLE-DRAFT'], ['published_slug' => null], ['slug' => 'changed', 'published_slug' => 'changed']] as $change) {
            try {
                $track->fresh()->update($change);
                $this->fail('Model allowed a reserved URL rewrite.');
            } catch (ValidationException) {
            }
            try {
                DB::table('tracks')->where('id', $track->id)->update($change);
                $this->fail('Bulk SQL allowed a reserved URL rewrite.');
            } catch (QueryException) {
            }
        }
        try {
            $track->delete();
            $this->fail('Model deleted a retained URL.');
        } catch (ValidationException) {
        }
        try {
            DB::table('tracks')->where('id', $track->id)->delete();
            $this->fail('Bulk SQL deleted a retained URL.');
        } catch (QueryException) {
        }
        try {
            DB::table('tracks')->insert(['title' => 'Unreserved', 'slug' => 'unreserved', 'status' => 'published']);
            $this->fail('SQL published without reserving the URL.');
        } catch (QueryException) {
        }
        $this->assertSame('editable-draft', $track->fresh()->slug);
        $this->assertDatabaseCount('tracks', 1);
        $this->assertDatabaseCount('audit_events', 2);
    }
}
