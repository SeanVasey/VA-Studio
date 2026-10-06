<?php

namespace Tests\Feature;

use App\Domain\ProductAuthoring\PrivateDraftSchema;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PrivateProductDraftFixtures as Fixtures;
use Tests\TestCase;

class PrivateProductDraftTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        // Commands own their transaction; the existing isolated fixture lifecycle supplies
        // a fresh schema without an inherited transaction or a destructive retained-data down().
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
    }

    public static function families(): array
    {
        return Fixtures::families();
    }

    private function evidence(string $kind): array
    {
        return array_map(fn ($table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            [$kind.'_drafts', $kind.'_draft_versions', 'audit_events', 'users']);
    }

    private function rejects(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected private draft evidence to be refused.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    #[DataProvider('families')]
    public function test_actual_create_review_is_read_only_and_save_retains_encrypted_history_without_external_effects(string $kind): void
    {
        [$commandClass, $draftClass, $versionClass] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        Http::fake();
        Mail::fake();
        Queue::fake();
        $command = app($commandClass);
        $before = $this->evidence($kind);
        $review = $command->review(null, Fixtures::payload($kind), $actor);
        $this->assertSame($before, $this->evidence($kind));
        $draft = $command->applyReviewed($review, $actor);
        $this->assertInstanceOf($draftClass, $draft);
        $this->assertSame(1, $draft->version);
        $snapshot = $command->snapshot($draft->id, $actor);
        $this->assertSame($review['manifest'], $snapshot['manifest']);
        $this->assertSame([1], array_column($snapshot['history'], 'number'));
        $cipher = DB::table($kind.'_draft_versions')->sole()->manifest;
        $this->assertStringNotContainsString('Private authored', $cipher);
        $this->assertStringNotContainsString('actual owner policy', $cipher);
        $this->assertArrayNotHasKey('manifest', $versionClass::sole()->toArray());
        $audit = AuditEvent::where('subject_type', $draftClass)->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame($review['manifest_hash'], $audit->context['manifest_sha256']);
        $this->assertStringNotContainsString('Synthetic', json_encode($audit->context));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('tracks', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('families')]
    public function test_edit_appends_exact_versions_noop_is_zero_write_and_stale_or_aba_capture_never_overwrites(string $kind): void
    {
        [$class, $draftClass] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $command = app($class);
        $initial = Fixtures::payload($kind);
        $draft = $command->applyReviewed($command->review(null, $initial, $actor), $actor);
        $oldVersion = DB::table($kind.'_draft_versions')->sole();
        $noop = $command->review($draft, $initial, $actor);
        $before = $this->evidence($kind);
        $this->assertSame(1, $command->applyReviewed($noop, $actor)->version);
        $this->assertSame($before, $this->evidence($kind));
        $change = Fixtures::payload($kind, ['description' => 'Second retained content']);
        $stale = $command->review($draft, $change, $actor);
        $draft = $command->applyReviewed($stale, $actor);
        $this->assertSame(2, $draft->version);
        $draft = $command->applyReviewed($command->review($draft, $initial, $actor), $actor);
        $this->assertSame(3, $draft->version);
        $before = $this->evidence($kind);
        $this->rejects(fn () => $command->applyReviewed($stale, $actor));
        $this->assertSame($before, $this->evidence($kind));
        $this->assertSame((array) $oldVersion, (array) DB::table($kind.'_draft_versions')->where('id', $oldVersion->id)->sole());
        $this->assertSame([3, 2, 1], array_column($command->snapshot($draft->id, $actor)['history'], 'number'));
        $this->assertSame(1, $draftClass::count());
    }

    #[DataProvider('families')]
    public function test_same_create_review_replay_is_idempotent_but_replay_after_edit_requires_fresh_review(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $command = app($class);
        $review = $command->review(null, Fixtures::payload($kind), $actor);
        $draft = $command->applyReviewed($review, $actor);
        $before = $this->evidence($kind);
        $this->assertSame($draft->id, $command->applyReviewed($review, $actor)->id);
        $this->assertSame($before, $this->evidence($kind));
        $command->applyReviewed($command->review($draft, Fixtures::payload($kind, ['title' => 'Changed private title']), $actor), $actor);
        $before = $this->evidence($kind);
        $this->rejects(fn () => $command->applyReviewed($review, $actor));
        $this->assertSame($before, $this->evidence($kind));
    }

    #[DataProvider('families')]
    public function test_review_is_actor_family_content_and_opened_state_bound(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $other = LicenseFixtures::admin();
        $command = app($class);
        $review = $command->review(null, Fixtures::payload($kind), $actor);
        $before = $this->evidence($kind);
        $this->rejects(fn () => $command->applyReviewed($review, $other));
        $forged = $review;
        $forged['manifest']['title'] = 'Recomputed public hash is not a server signature';
        $forged['manifest_hash'] = CanonicalJson::hash($forged['manifest']);
        $this->rejects(fn () => $command->applyReviewed($forged, $actor));
        [$otherClass] = Fixtures::classes($kind === 'service' ? 'merch' : 'service');
        $this->rejects(fn () => app($otherClass)->applyReviewed($review, $actor));
        $this->assertSame($before, $this->evidence($kind));
        $draft = $command->applyReviewed($review, $actor);
        $opened = $command->snapshot($draft->id, $actor);
        $current = $command->applyReviewed($command->review($draft, Fixtures::payload($kind, ['description' => 'Changed behind open editor']), $actor), $actor);
        $before = $this->evidence($kind);
        $this->rejects(fn () => $command->review($current, Fixtures::payload($kind), $actor, $opened['state_hash']));
        $this->assertSame($before, $this->evidence($kind));
    }

    #[DataProvider('families')]
    public function test_current_authority_withdrawal_refuses_a_retained_actor_and_capture(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $command = app($class);
        $review = $command->review(null, Fixtures::payload($kind), $actor);
        DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
        $before = $this->evidence($kind);
        try {
            $command->applyReviewed($review, $actor);
            $this->fail('Withdrawn authority must fail.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->evidence($kind));
        }
    }

    #[DataProvider('families')]
    public function test_audit_failure_or_late_observer_authority_change_rolls_back_every_product_write(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $command = app($class);
        $review = $command->review(null, Fixtures::payload($kind), $actor);
        $before = $this->evidence($kind);
        AuditEvent::creating(function (AuditEvent $event) use ($actor): void {
            if (str_starts_with($event->action, 'products.private_')) {
                DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
            }
        });
        try {
            $command->applyReviewed($review, $actor);
            $this->fail('Late authority withdrawal must roll back.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->evidence($kind));
        }
    }

    #[DataProvider('families')]
    public function test_late_audit_context_rewrite_is_not_accepted_as_saved_evidence(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $command = app($class);
        $review = $command->review(null, Fixtures::payload($kind), $actor);
        $before = $this->evidence($kind);
        AuditEvent::creating(function (AuditEvent $event): void {
            if (str_starts_with($event->action, 'products.private_')) {
                $event->context = [...$event->context, 'unreviewed_extra' => true];
            }
        });
        $this->rejects(fn () => $command->applyReviewed($review, $actor));
        $this->assertSame($before, $this->evidence($kind));
    }

    #[DataProvider('families')]
    public function test_parent_and_version_evidence_cannot_be_replaced_when_recursive_delete_triggers_are_disabled(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $command = app($class);
        $draft = $command->applyReviewed($command->review(null, Fixtures::payload($kind), $actor), $actor);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers = OFF');
        }
        $parent = (array) DB::table($kind.'_drafts')->sole();
        $version = (array) DB::table($kind.'_draft_versions')->sole();
        $before = $this->evidence($kind);
        foreach ([[$kind.'_drafts', $parent], [$kind.'_draft_versions', $version],
            [$kind.'_drafts', array_replace($parent, ['id' => $parent['id'] + 50])],
            [$kind.'_draft_versions', array_replace($version, ['id' => $version['id'] + 50])]] as [$table, $row]) {
            $columns = implode(',', array_map(fn ($field): string => DB::connection()->getQueryGrammar()->wrap($field), array_keys($row)));
            $verb = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
            try {
                DB::statement($verb.' INTO '.$table.' ('.$columns.') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row));
                $this->fail('Replacement must be refused before its implicit deletion.');
            } catch (QueryException) {
                $this->assertSame($before, $this->evidence($kind));
            }
        }
        $this->assertSame(1, $command->snapshot($draft->id, $actor)['version']);
    }

    #[DataProvider('families')]
    public function test_sql_mutation_and_schema_adoption_are_refused_without_destroying_owned_evidence(string $kind): void
    {
        [$class, $draftClass, $versionClass] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $command = app($class);
        $draft = $command->applyReviewed($command->review(null, Fixtures::payload($kind), $actor), $actor);
        $before = $this->evidence($kind);
        foreach ([fn () => DB::table($kind.'_draft_versions')->update(['manifest_sha256' => str_repeat('a', 64)]),
            fn () => DB::table($kind.'_draft_versions')->delete(), fn () => DB::table($kind.'_drafts')->delete(),
            fn () => DB::table($kind.'_drafts')->update(['creation_review_hash' => str_repeat('b', 64)])] as $write) {
            try {
                $write();
                $this->fail('Retained SQL evidence must not change.');
            } catch (QueryException) {
                $this->assertSame($before, $this->evidence($kind));
            }
        }
        try {
            PrivateDraftSchema::install($kind);
            $this->fail('An existing schema must not be adopted or replaced.');
        } catch (LogicException) {
            $this->assertSame($before, $this->evidence($kind));
        }
        $this->rejects(fn () => $versionClass::sole()->update(['manifest_sha256' => str_repeat('a', 64)]));
        $this->rejects(fn () => $draftClass::findOrFail($draft->id)->delete());
    }

    #[DataProvider('families')]
    public function test_commands_refuse_inherited_transactions_without_effects(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $before = $this->evidence($kind);
        DB::beginTransaction();
        try {
            app($class)->review(null, Fixtures::payload($kind), $actor);
            $this->fail('Inherited snapshots are not eligible review transactions.');
        } catch (LogicException) {
            $this->assertSame($before, $this->evidence($kind));
        } finally {
            DB::rollBack();
        }
    }

    #[DataProvider('families')]
    public function test_no_query_or_model_observer_runs_after_the_audit_callback_before_the_whole_final_proof(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $command = app($class);
        $review = $command->review(null, Fixtures::payload($kind), $actor);
        $armed = false;
        $lateQueries = 0;
        AuditEvent::created(function (AuditEvent $event) use (&$armed): void {
            if (str_starts_with($event->action, 'products.private_')) {
                $armed = true;
            }
        });
        DB::listen(function () use (&$armed, &$lateQueries): void {
            if ($armed) {
                $lateQueries++;
            }
        });
        $saved = $command->applyReviewed($review, $actor);
        $this->assertSame(0, $lateQueries);
        $armed = false;
        $this->assertSame(1, $saved->version);
        $this->assertSame($review['manifest'], $command->snapshot($saved->id, $actor)['manifest']);
    }

    #[DataProvider('families')]
    public function test_owning_migration_rollback_retains_data_and_mysql_tables_explicitly_use_innodb(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $command = app($class);
        $command->applyReviewed($command->review(null, Fixtures::payload($kind), $actor), $actor);
        $before = $this->evidence($kind);
        $name = $kind === 'service' ? '2026_10_06_234000_service_draft_versions.php' : '2026_10_06_235000_merch_draft_versions.php';
        (require database_path('migrations/'.$name))->down();
        $this->assertSame($before, $this->evidence($kind));
        if (DB::getDriverName() === 'mysql') {
            $engines = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->whereIn('TABLE_NAME', [$kind.'_drafts', $kind.'_draft_versions'])->pluck('ENGINE')->all();
            $this->assertSame(['InnoDB', 'InnoDB'], $engines);
        }
    }
}
