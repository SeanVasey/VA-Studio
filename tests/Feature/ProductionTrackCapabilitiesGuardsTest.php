<?php

namespace Tests\Feature;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyVersion;
use App\Domain\Commerce\Policy\PrepareProductionTrackPolicy;
use App\Domain\Commerce\Policy\ReviewProductionTrackPolicy;
use App\Domain\Commerce\Policy\SaveProductionTrackPolicy;
use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\CloseProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityCandidate;
use App\Domain\Commerce\ProductionPolicy\PreparationContextV1;
use App\Domain\Commerce\ProductionPolicy\PrepareProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReadProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReviewProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SaveProductionTrackCapabilities;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CapabilityRollbackFixture;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\ProductionTrackCapabilitiesFixtures;
use Tests\Support\ProductionTrackPolicyFixtures;
use Tests\TestCase;

class ProductionTrackCapabilitiesGuardsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Http::preventStrayRequests();
    }

    private function useSqliteAdversaryFixture(): void
    {
        // PRAGMA and transactional trigger removal are SQLite mechanisms. MySQL
        // DROP TRIGGER implicitly commits; these adversaries never prove native
        // rollback. Keep their full assertions on an isolated SQLite connection.
        $original = DB::getDefaultConnection();
        config(['database.connections.production_capability_sqlite_adversary' => array_replace(config('database.connections.sqlite'),
            ['database' => ':memory:', 'url' => null])]);
        DB::setDefaultConnection('production_capability_sqlite_adversary');
        Schema::clearResolvedInstance('db.schema');
        $this->beforeApplicationDestroyed(function () use ($original): void {
            DB::purge('production_capability_sqlite_adversary');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        });
        $this->artisan('migrate:fresh', ['--database' => 'production_capability_sqlite_adversary', '--force' => true])->assertExitCode(0);
        $this->assertSame('sqlite', DB::getDriverName());
    }

    private function reference(string $affirmation): array
    {
        return ['reference' => 'synthetic:review-only', 'source_sha256' => hash('sha256', 'synthetic'), $affirmation => true];
    }

    private function prepared(bool $approved = false, bool $closed = false): array
    {
        $author = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $source = ProductionTrackPolicyFixtures::create($author);
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $source->id)->sole();
        app(ReviewProductionTrackPolicy::class)->applyReviewed(app(ReviewProductionTrackPolicy::class)->review($version, $reviewer), $this->reference('authored_source_acknowledged'), $reviewer);
        $machine = ProductionTrackCapabilitiesFixtures::machine();
        $candidate = app(SaveProductionTrackCapabilities::class)->applyReviewed(app(PrepareProductionTrackCapabilities::class)->review(null, $source, $machine, $author), $author);
        if ($approved) {
            app(ReviewProductionTrackCapabilities::class)->applyReviewed(app(ReviewProductionTrackCapabilities::class)->review($candidate, $reviewer), $this->reference('software_choices_reviewed'), $reviewer);
        }
        if ($closed) {
            app(CloseProductionTrackCapabilities::class)->applyReviewed(app(CloseProductionTrackCapabilities::class)->review($candidate, $author), ['reason_code' => 'owner_withdrawal', ...$this->reference('closure_requested')], $author);
        }

        return [$candidate, $source, $author, $reviewer, $machine, $version];
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['users', 'production_track_policy_drafts', 'production_track_policy_versions', 'production_track_policy_source_reviews',
            CapabilityHistory::CANDIDATES, CapabilityHistory::APPROVALS, CapabilityHistory::CLOSURES, 'audit_events'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $rows;
    }

    public static function immutableOperations(): array
    {
        $cases = [];
        foreach ([CapabilityHistory::CANDIDATES => 'ptc_candidate', CapabilityHistory::APPROVALS => 'ptc_approval', CapabilityHistory::CLOSURES => 'ptc_closure'] as $table => $prefix) {
            foreach (['update', 'delete', 'replace primary', 'replace unique'] as $operation) {
                $cases[$table.' '.$operation] = [$table, $prefix, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('immutableOperations')]
    public function test_sql_history_cannot_update_delete_or_replace_even_without_recursive_triggers(string $table, string $prefix, string $operation): void
    {
        $this->useSqliteAdversaryFixture();
        $this->prepared(true, true);
        DB::statement('PRAGMA recursive_triggers = OFF');
        $before = $this->rows();
        $row = $before[$table][0];
        try {
            if ($operation === 'update') {
                DB::table($table)->where('id', $row['id'])->update(['created_at' => '2000-01-01 00:00:00']);
            } elseif ($operation === 'delete') {
                DB::table($table)->where('id', $row['id'])->delete();
            } else {
                if ($operation === 'replace unique') {
                    $row['id'] = 1000000;
                }
                $columns = array_keys($row);
                DB::insert('INSERT OR REPLACE INTO '.$table.' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')', array_values($row));
            }
            $this->fail('Immutable evidence was replaced.');
        } catch (QueryException) {
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_models_and_populated_rollback_refuse_history_changes(): void
    {
        [$candidate] = $this->prepared();
        try {
            $candidate->save();
            $this->fail('ORM save admitted.');
        } catch (LogicException) {
            $this->assertDatabaseCount(CapabilityHistory::CANDIDATES, 1);
        }
        try {
            $candidate->delete();
            $this->fail('ORM delete admitted.');
        } catch (LogicException) {
            $this->assertDatabaseCount(CapabilityHistory::CANDIDATES, 1);
        }
        // Explicit disposable-fixture cleanup of the empty later dependents (derived from the
        // catalog), with FK enforcement unchanged.
        CapabilityRollbackFixture::isolateCapabilityTables();
        $migration = require base_path('database/migrations/2026_10_06_236000_production_track_policy_capabilities.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_empty_migration_can_rollback_and_recreate_all_three_tables(): void
    {
        // Explicit disposable-fixture cleanup of the empty later dependents (derived from the
        // catalog), with FK enforcement unchanged.
        CapabilityRollbackFixture::isolateCapabilityTables();
        $preparation = require database_path('migrations/2026_10_06_238000_production_track_preparation_packets.php');
        $migration = require base_path('database/migrations/2026_10_06_236000_production_track_policy_capabilities.php');
        $migration->down();
        $migration->up();
        $preparation->up();
        $this->assertDatabaseCount(CapabilityHistory::CANDIDATES, 0);
        $this->assertDatabaseCount(CapabilityHistory::APPROVALS, 0);
        $this->assertDatabaseCount(CapabilityHistory::CLOSURES, 0);
    }

    public function test_adapter_source_edit_is_rolled_back_with_every_adapter_side_effect(): void
    {
        $this->useSqliteAdversaryFixture();
        [$candidate, $source, $author, , $machine] = $this->prepared(true);
        $before = $this->rows();
        try {
            app(ReadProductionTrackCapabilities::class)->withLockedForAdapter($candidate, PreparationContextV1::forMachine($machine), $author,
                function () use ($source): void {
                    DB::unprepared('DROP TRIGGER production_policy_draft_update');
                    DB::table('production_track_policy_drafts')->where('id', $source->id)->update(['revision' => 0]);
                });
            $this->fail('Mutated source committed.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_adapter_withdrawn_actor_and_durable_aba_roll_back(): void
    {
        [$candidate, , $author, , $machine] = $this->prepared(true);
        $before = $this->rows();
        foreach ([false, true] as $aba) {
            try {
                app(ReadProductionTrackCapabilities::class)->withLockedForAdapter($candidate, PreparationContextV1::forMachine($machine), $author,
                    function () use ($author, $aba): void {
                        DB::table('users')->where('id', $author->id)->update(['is_admin' => false]);
                        if ($aba) {
                            DB::table('users')->where('id', $author->id)->update(['is_admin' => true]);
                            AuditEvent::create(['actor_id' => $author->id, 'action' => 'synthetic.role.aba', 'subject_type' => User::class,
                                'subject_id' => $author->id, 'context' => ['synthetic' => true], 'created_at' => now()->utc()->format('Y-m-d H:i:s')]);
                        }
                    });
                $this->fail('Adapter authority edit committed.');
            } catch (AuthorizationException) {
                $this->assertSame($before, $this->rows());
            }
        }
    }

    public function test_last_authority_query_callback_cannot_escape_captured_primary_proof(): void
    {
        [$candidate, $source, $author, , $machine] = $this->prepared();
        $machine['version'] = 'synthetic-machine-v2';
        $capture = app(PrepareProductionTrackCapabilities::class)->review($candidate, $source, $machine, $author);
        $before = $this->rows();
        $armed = true;
        $fired = false;
        DB::listen(function ($query) use ($author, &$armed, &$fired): void {
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $classes = array_column($frames, 'class');
            $functions = array_column($frames, 'function');
            if (! $armed || ! preg_match('/\Aselect\b/i', $query->sql) || ! preg_match('/from ["`]users["`]/', $query->sql)
                || ! in_array(ProductionTrackCapabilities::class, $classes, true) || ! in_array('finish', $functions, true)
                || ! in_array('authority', $functions, true) || array_intersect(['find', 'authorize', 'satisfiedBy'], $functions) !== []) {
                return;
            }
            $armed = false;
            $statement = DB::connection()->getPdo()->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
            $statement->execute([0, $author->id]);
            $fired = true;
        });
        try {
            app(SaveProductionTrackCapabilities::class)->applyReviewed($capture, $author);
            $this->fail('Last post-fetch callback committed.');
        } catch (AuthorizationException) {
            $this->assertTrue($fired);
            $this->assertSame($before, $this->rows());
        } finally {
            $armed = false;
        }
    }

    public function test_audit_created_callback_source_edit_cannot_escape_post_callback_graph_proof(): void
    {
        $this->useSqliteAdversaryFixture();
        [$candidate, $source, $author, , $machine] = $this->prepared();
        $machine['version'] = 'synthetic-machine-v2';
        $capture = app(PrepareProductionTrackCapabilities::class)->review($candidate, $source, $machine, $author);
        $before = $this->rows();
        $fired = false;
        AuditEvent::created(function (AuditEvent $event) use ($source, &$fired): void {
            if ($event->subject_type === ProductionTrackCapabilities::class) {
                $fired = true;
                DB::unprepared('DROP TRIGGER production_policy_draft_update');
                DB::table('production_track_policy_drafts')->where('id', $source->id)->update(['revision' => 0]);
            }
        });
        try {
            app(SaveProductionTrackCapabilities::class)->applyReviewed($capture, $author);
            $this->fail('Audit model callback source edit committed.');
        } catch (ValidationException) {
            $this->assertTrue($fired);
            $this->assertSame($before, $this->rows());
        }
    }

    public static function ordinaryDmlCallbacks(): array
    {
        return ['adapter' => [false], 'audit created' => [true]];
    }

    #[DataProvider('ordinaryDmlCallbacks')]
    public function test_ordinary_dml_callback_evidence_edit_rolls_back_on_the_selected_database(bool $auditCallback): void
    {
        [$candidate, $source, $author, , $machine] = $this->prepared(! $auditCallback);
        $before = $this->rows();
        $fired = false;
        $auditId = DB::table('audit_events')->where('subject_type', ProductionTrackCapabilities::class)
            ->where('action', 'commerce.production_capability.candidate_saved')->sole()->id;
        $edit = function () use ($auditId, &$fired): void {
            $changed = DB::table('audit_events')->where('id', $auditId)->update(['action' => 'synthetic.evidence.damage']);
            $this->assertSame(1, $changed);
            $fired = true;
        };
        try {
            if ($auditCallback) {
                $machine['version'] = 'synthetic-machine-v2';
                $capture = app(PrepareProductionTrackCapabilities::class)->review($candidate, $source, $machine, $author);
                AuditEvent::created(function (AuditEvent $event) use ($edit): void {
                    if ($event->subject_type === ProductionTrackCapabilities::class) {
                        $edit();
                    }
                });
                app(SaveProductionTrackCapabilities::class)->applyReviewed($capture, $author);
            } else {
                app(ReadProductionTrackCapabilities::class)->withLockedForAdapter($candidate, PreparationContextV1::forMachine($machine), $author, $edit);
            }
            $this->fail('Ordinary DML evidence edit committed.');
        } catch (ValidationException) {
            $this->assertTrue($fired);
            $this->assertSame($before, $this->rows());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            AuditEvent::flushEventListeners();
            AuditEvent::clearBootedModels();
        }
    }

    public static function damagedHistory(): array
    {
        return [
            'current candidate hash' => [CapabilityHistory::CANDIDATES, 'ptc_candidate_update', 'payload_hash', str_repeat('0', 64)],
            'current candidate author' => [CapabilityHistory::CANDIDATES, 'ptc_candidate_update', 'created_by', 999],
            'current approval hash' => [CapabilityHistory::APPROVALS, 'ptc_approval_update', 'approval_hash', str_repeat('0', 64)],
            'current approval actor' => [CapabilityHistory::APPROVALS, 'ptc_approval_update', 'reviewed_by', 999],
            'retained source version actor' => ['production_track_policy_versions', 'production_policy_version_immutable_update', 'created_by', 999],
        ];
    }

    #[DataProvider('damagedHistory')]
    public function test_authenticated_history_refuses_current_and_retained_raw_evidence_damage(string $table, string $trigger, string $field, mixed $value): void
    {
        $this->useSqliteAdversaryFixture();
        [$candidate, , $author, , $machine] = $this->prepared(true);
        // Simulated broken restore in the disposable fixture only. Operational
        // SQL/model guards remain installed in shipped source.
        DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table($table)->update([$field => $value]);
        $before = $this->rows();
        try {
            app(ReadProductionTrackCapabilities::class)->project($candidate, PreparationContextV1::forMachine($machine), $author);
            $this->fail('Damaged evidence projected.');
        } catch (ValidationException|AuthorizationException) {
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_prior_source_revision_retains_an_exact_authenticated_prefix_after_new_source(): void
    {
        [$candidate, $source, $author, $reviewer, $machine, $version] = $this->prepared(true);
        $authored = ProductionTrackPolicyFixtures::authored();
        $authored['version'] = 'synthetic-source-v2';
        $source = app(SaveProductionTrackPolicy::class)->applyReviewed(app(PrepareProductionTrackPolicy::class)->review($source, $authored, $author), $author);
        $current = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $source->id)->latest('id')->firstOrFail();
        app(ReviewProductionTrackPolicy::class)->applyReviewed(app(ReviewProductionTrackPolicy::class)->review($current, $reviewer), $this->reference('authored_source_acknowledged'), $reviewer);
        $machine['version'] = 'synthetic-machine-v2';
        $next = app(SaveProductionTrackCapabilities::class)->applyReviewed(app(PrepareProductionTrackCapabilities::class)->review($candidate, $source, $machine, $author), $author);
        app(ReviewProductionTrackCapabilities::class)->applyReviewed(app(ReviewProductionTrackCapabilities::class)->review($next, $reviewer), $this->reference('software_choices_reviewed'), $reviewer);
        DB::unprepared('DROP TRIGGER IF EXISTS production_policy_version_immutable_update');
        DB::table('production_track_policy_versions')->where('id', $version->id)->update(['created_at' => '2000-01-01 00:00:00']);
        $this->expectException(ValidationException::class);
        app(ReadProductionTrackCapabilities::class)->project($next, PreparationContextV1::forMachine($machine), $author);
    }

    public function test_projection_graph_rejects_missing_or_edited_audits(): void
    {
        [$candidate, , $author, , $machine] = $this->prepared(true);
        DB::table('audit_events')->where('subject_type', ProductionTrackCapabilities::class)->where('action', 'commerce.production_capability.software_approved')
            ->update(['action' => 'synthetic.unrelated']);
        $this->expectException(ValidationException::class);
        app(ReadProductionTrackCapabilities::class)->project($candidate, PreparationContextV1::forMachine($machine), $author);
    }

    public function test_replayed_source_capture_after_lost_save_response_requires_fresh_authenticated_noop(): void
    {
        [$candidate, $source, $author, , $machine] = $this->prepared();
        // Original returned model is discarded, as if transport lost its response.
        unset($candidate);
        $retained = ProductionTrackCapabilityCandidate::where('production_track_policy_draft_id', $source->id)->sole();
        $before = $this->rows();
        $freshCapture = app(PrepareProductionTrackCapabilities::class)->review($retained, $source, $machine, $author);
        $recovered = app(SaveProductionTrackCapabilities::class)->applyReviewed($freshCapture, $author);
        $this->assertSame($retained->id, $recovered->id);
        $this->assertSame($before, $this->rows());
    }
}
