<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationRevision;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class SiteContentTest extends TestCase
{
    use RefreshDatabase;

    private function draft(User $actor, string $title = 'UNPUBLISHED SYNTHETIC COPY'): SiteRelease
    {
        $content = SiteContentSchema::defaults();
        $content['hero']['title'] = $title;

        return app(SiteContent::class)->create($content, 'Private editorial label', $actor);
    }

    public function test_default_public_content_and_private_drafts_are_separate_and_plain_text_is_retained(): void
    {
        $service = app(SiteContent::class);
        $defaults = SiteContentSchema::defaults();
        $this->assertSame($defaults, $service->current());
        $actor = LicenseFixtures::admin();
        $content = $defaults;
        $content['hero']['description'] = "Sean's music & sound — \"Original\".\nSecond line.";
        $release = $service->create($content, 'Private review name', $actor);
        $this->assertSame($content, $service->preview($release->id, $actor));
        $this->assertSame($defaults, $service->current());
        $this->assertSame(CanonicalJson::hash($content), $release->content_hash);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('site_publication_revisions', 0);
        $audit = AuditEvent::where('action', 'site.release.created')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertStringNotContainsString('Private review', json_encode($audit->context));
        $this->assertStringNotContainsString("Sean's music", json_encode($audit->context));
    }

    public function test_publish_and_rollback_only_replace_site_content_and_record_actor_version_hash(): void
    {
        $service = app(SiteContent::class);
        $actor = LicenseFixtures::admin();
        $first = $this->draft($actor, 'FIRST');
        $second = $this->draft($actor, 'SECOND');
        $this->assertSame(1, $service->publish($first->id, 0, $actor)->revision);
        $this->assertSame('FIRST', $service->current()['hero']['title']);
        $this->assertSame(2, $service->publish($second->id, 1, $actor)->revision);
        $this->assertSame('SECOND', $service->current()['hero']['title']);
        $rollback = $service->rollback($first->id, 2, $actor);
        $this->assertSame(3, $rollback->revision);
        $this->assertSame($first->content, $service->current());
        $history = SitePublicationRevision::where('revision', 3)->sole();
        $this->assertSame($second->id, $history->previous_release_id);
        $this->assertSame($first->id, $history->release_id);
        $this->assertSame('rollback', $history->operation);
        $this->assertSame($first->content_hash, $history->content_hash);
        $audit = AuditEvent::where('action', 'site.release.rollback')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame(3, $audit->context['publication_revision']);
        foreach (['tracks', 'orders', 'license_grants', 'pending_entitlements', 'stripe_webhook_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_stale_revision_blocks_publish_rollback_and_aba_even_when_active_release_matches_again(): void
    {
        $actor = LicenseFixtures::admin();
        $service = app(SiteContent::class);
        $a = $this->draft($actor, 'A'); $b = $this->draft($actor, 'B'); $c = $this->draft($actor, 'C');
        $service->publish($a->id, 0, $actor);
        $service->publish($b->id, 1, $actor);
        $service->rollback($a->id, 2, $actor);
        foreach ([fn () => $service->publish($c->id, 1, $actor), fn () => $service->rollback($b->id, 1, $actor)] as $stale) {
            try { $stale(); $this->fail('Stale editor replaced the current site.'); } catch (ValidationException $exception) {
                $this->assertArrayHasKey('publication', $exception->errors());
            }
        }
        $this->assertSame(3, SitePublication::findOrFail(1)->revision);
        $this->assertSame('A', $service->current()['hero']['title']);
        $this->assertDatabaseCount('site_publication_revisions', 4);
    }

    public function test_rollback_rejects_never_published_draft_and_republishing_active_release_is_a_safe_error(): void
    {
        $actor = LicenseFixtures::admin(); $service = app(SiteContent::class);
        $a = $this->draft($actor); $b = $this->draft($actor, 'B');
        foreach ([fn () => $service->rollback($a->id, 0, $actor), fn () => $service->publish($a->id, -1, $actor)] as $invalid) {
            try { $invalid(); $this->fail('Invalid initial transition succeeded.'); } catch (ValidationException) { }
        }
        $service->publish($a->id, 0, $actor);
        foreach ([fn () => $service->rollback($b->id, 1, $actor), fn () => $service->publish($a->id, 1, $actor), fn () => $service->rollback($a->id, 1, $actor)] as $invalid) {
            try { $invalid(); $this->fail('Invalid site transition succeeded.'); } catch (ValidationException) { }
        }
        $this->assertDatabaseCount('site_publication_revisions', 2);
    }

    public function test_every_command_uses_fresh_authority_after_admin_or_verification_withdrawal(): void
    {
        $service = app(SiteContent::class); $actor = LicenseFixtures::admin(); $release = $this->draft($actor);
        $service->publish($release->id, 0, $actor);
        $second = $this->draft($actor, 'SECOND'); $service->publish($second->id, 1, $actor);
        $unverified = LicenseFixtures::admin(); DB::table('users')->where('id', $unverified->id)->update(['email_verified_at' => null]);
        DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
        foreach ([$actor, $unverified, User::factory()->create(), new User] as $denied) {
            foreach ([
                fn () => $service->create(SiteContentSchema::defaults(), 'Denied', $denied),
                fn () => $service->preview($release->id, $denied),
                fn () => $service->publish($release->id, 2, $denied),
                fn () => $service->rollback($release->id, 2, $denied),
            ] as $action) {
                try { $action(); $this->fail('Unauthorized site command succeeded.'); } catch (AuthorizationException) { }
            }
        }
        $this->assertSame(2, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('site_releases', 3);
    }

    public function test_unknown_fields_markup_oversized_lists_and_unsafe_links_are_rejected_without_saving(): void
    {
        $actor = LicenseFixtures::admin(); $service = app(SiteContent::class);
        $changes = [
            ['schema_version', '1'], ['secret', 'private'], ['hero.script', 'alert(1)'],
            ['hero.title', '<script>alert(1)</script>'], ['hero.title', "Hidden\0text"],
            ['studio.paragraphs', array_fill(0, 5, 'Too many')], ['hero.description', str_repeat('a', 601)],
            ['studio.paragraphs', ['one' => 'Object, not list']], ['footer.description', '   '],
            ['navigation', []], ['navigation.0.href', 'javascript:alert(1)'], ['navigation.0.href', '//outside.test'],
            ['navigation.0.href', '/admin'], ['navigation.0.href', '/#catalog?secret=1'], ['navigation.0.href', '/%23catalog'],
            ['navigation.0.href', '/#licenses'], ['navigation.0.html', 'injected'], ['seo.description', '<img onerror=alert(1)>'],
        ];
        foreach ($changes as [$key, $value]) {
            $content = SiteContentSchema::defaults(); data_set($content, $key, $value);
            try { $service->create($content, 'Rejected', $actor); $this->fail('Invalid content accepted: '.$key); } catch (ValidationException) { }
        }
        $this->assertDatabaseCount('site_releases', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_database_and_model_guards_retain_release_and_publication_history(): void
    {
        $actor = LicenseFixtures::admin(); $release = $this->draft($actor);
        app(SiteContent::class)->publish($release->id, 0, $actor);
        foreach ([$release, SitePublicationRevision::where('revision', 1)->sole()] as $model) {
            try { $model->delete(); $this->fail('Model erased immutable site evidence.'); } catch (LogicException) { }
            foreach (['delete', 'update'] as $operation) {
                try {
                    DB::transaction(fn () => $operation === 'delete'
                        ? DB::table($model->getTable())->where('id', $model->id)->delete()
                        : DB::table($model->getTable())->where('id', $model->id)->update(['content_hash' => str_repeat('f', 64)]));
                    $this->fail('Bulk write changed immutable site evidence.');
                } catch (QueryException) { }
            }
        }
        foreach ([
            fn () => DB::table('site_publications')->delete(),
            fn () => DB::table('site_publications')->update(['revision' => 0, 'active_release_id' => null]),
            fn () => DB::table('site_publications')->insert(['id' => 2, 'revision' => 0]),
        ] as $action) {
            try { DB::transaction($action); $this->fail('Raw write bypassed pointer guard.'); } catch (QueryException) { }
        }
        $this->assertSame(1, SitePublication::findOrFail(1)->revision);
    }

    public function test_hash_corruption_is_rejected_on_preview_public_read_and_publication(): void
    {
        $actor = LicenseFixtures::admin(); $service = app(SiteContent::class);
        // Simulate an invalid imported/restored snapshot without disabling mutation guards or transactional isolation.
        $bad = SiteContentSchema::defaults(); $bad['hero']['title'] = 'CORRUPTED';
        $hash = CanonicalJson::hash(SiteContentSchema::defaults());
        $id = DB::table('site_releases')->insertGetId([
            'label' => 'Synthetic corrupt restoration', 'schema_version' => 1, 'content' => json_encode($bad),
            'content_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION,
            'created_by' => $actor->id, 'created_at' => now(),
        ]);
        foreach ([fn () => $service->preview($id, $actor), fn () => $service->publish($id, 0, $actor)] as $action) {
            try { $action(); $this->fail('Corrupted release passed verification.'); } catch (ValidationException $exception) {
                $this->assertArrayHasKey('publication', $exception->errors());
            }
        }
        DB::table('site_publication_revisions')->insert(['revision' => 1, 'release_id' => $id, 'previous_release_id' => null,
            'operation' => 'publish', 'content_hash' => $hash, 'actor_id' => $actor->id, 'created_at' => now()]);
        DB::table('site_publications')->where('id', 1)->update(['revision' => 1, 'active_release_id' => $id, 'updated_at' => now()]);
        $this->expectException(ValidationException::class);
        $service->current();
    }

    public function test_first_publication_preserves_original_defaults_as_an_atomic_rollback_target(): void
    {
        $actor = LicenseFixtures::admin(); $service = app(SiteContent::class); $draft = $this->draft($actor);
        $service->publish($draft->id, 0, $actor);
        $baseline = SitePublicationRevision::where('revision', 0)->sole();
        $this->assertSame('baseline', $baseline->operation);
        $this->assertSame('Original site content', SiteRelease::findOrFail($baseline->release_id)->label);
        $this->assertSame($actor->id, $baseline->actor_id);
        $this->assertSame(SiteContentSchema::defaults(), $service->preview($baseline->release_id, $actor));
        $service->rollback($baseline->release_id, 1, $actor);
        $this->assertSame(SiteContentSchema::defaults(), $service->current());
        $this->assertSame(2, SitePublication::findOrFail(1)->revision);
        $this->assertSame(1, AuditEvent::where('action', 'site.release.baseline_retained')->count());
    }

    public function test_audit_failure_rolls_back_draft_and_publication_atomically(): void
    {
        $actor = LicenseFixtures::admin(); $release = $this->draft($actor); $service = app(SiteContent::class);
        AuditEvent::creating(function (AuditEvent $event): void {
            // Let baseline retention succeed, then fail after history/pointer writes in the publish action.
            if ($event->action !== 'site.release.baseline_retained') { throw new RuntimeException('Synthetic site audit failure'); }
        });
        try {
            foreach ([fn () => $this->draft($actor, 'ROLL BACK'), fn () => $service->publish($release->id, 0, $actor)] as $action) {
                try { $action(); $this->fail('Audit failure was ignored.'); } catch (RuntimeException $exception) {
                    $this->assertSame('Synthetic site audit failure', $exception->getMessage());
                }
            }
        } finally { AuditEvent::flushEventListeners(); }
        $this->assertDatabaseCount('site_releases', 1);
        $this->assertDatabaseCount('site_publication_revisions', 0);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertSame(SiteContentSchema::defaults(), $service->current());
    }

    public function test_populated_migration_rollback_is_refused_without_removing_guards(): void
    {
        $actor = LicenseFixtures::admin(); $release = $this->draft($actor);
        $migration = require database_path('migrations/2026_09_28_000024_site_content_releases.php');
        try { $migration->down(); $this->fail('Populated site history was dropped.'); } catch (LogicException) { }
        try { DB::transaction(fn () => DB::table('site_releases')->where('id', $release->id)->delete()); $this->fail('Migration refusal removed guards.'); } catch (QueryException) { }
        $this->assertDatabaseCount('site_releases', 1);
    }
}
