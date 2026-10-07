<?php

namespace Tests\Feature;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyVersion;
use App\Domain\Commerce\Policy\ReviewProductionTrackPolicy;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\ProductionTrackPolicyFixtures;
use Tests\TestCase;

class ProductionTrackPolicyEngineTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL session storage defaults and InnoDB foreign keys require native MySQL.');
        }
    }

    public function test_owned_policy_tables_require_innodb_when_session_defaults_to_myisam(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $migration = require database_path('migrations/2026_10_06_233000_production_track_policy_drafts.php');
        $capabilities = require database_path('migrations/2026_10_06_236000_production_track_policy_capabilities.php');
        $preparation = require database_path('migrations/2026_10_06_238000_production_track_preparation_packets.php');
        // Explicit disposable-fixture cleanup, with FK enforcement unchanged.
        $this->assertDatabaseCount('production_buyer_assent_observations', 0);
        Schema::drop('production_buyer_assent_observations');
        foreach (['production_track_preparation_packet_lines', 'production_track_preparation_packets'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $preparation->down();
        foreach (['production_track_capability_candidates', 'production_track_capability_approvals', 'production_track_capability_closures'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        // Remove the empty additive child before testing this retained parent's engine.
        $capabilities->down();
        $migration->down();
        $original = DB::selectOne('SELECT @@SESSION.default_storage_engine AS engine')->engine;
        DB::statement("SET SESSION default_storage_engine = 'MyISAM'");
        try {
            $this->assertSame('MyISAM', DB::selectOne('SELECT @@SESSION.default_storage_engine AS engine')->engine);
            $migration->up();
            $capabilities->up();
            $preparation->up();
            $tables = ['production_track_policy_drafts', 'production_track_policy_versions', 'production_track_policy_source_reviews'];
            $engines = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->whereIn('TABLE_NAME', $tables)->orderBy('TABLE_NAME')->pluck('ENGINE', 'TABLE_NAME')->all();
            $this->assertCount(3, $engines);
            $this->assertSame(array_fill(0, 3, 'InnoDB'), array_values($engines));
            $foreignKeys = DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->whereIn('TABLE_NAME', $tables)->whereNotNull('REFERENCED_TABLE_NAME')->count();
            $this->assertSame(5, $foreignKeys);
            $author = LicenseFixtures::admin();
            $reviewer = LicenseFixtures::admin();
            $at = now()->utc()->format('Y-m-d H:i:s');
            DB::beginTransaction();
            DB::table($tables[0])->insert(['public_id' => (string) Str::uuid(), 'revision' => 0, 'created_by' => $author->id,
                'created_at' => $at, 'updated_at' => $at]);
            DB::rollBack();
            $this->assertDatabaseCount($tables[0], 0);
            try {
                DB::table($tables[0])->insert(['public_id' => (string) Str::uuid(), 'revision' => 0, 'created_by' => 2147483647,
                    'created_at' => $at, 'updated_at' => $at]);
                $this->fail('Missing author foreign key was accepted.');
            } catch (QueryException) {
                $this->assertDatabaseCount($tables[0], 0);
            }
            $draft = ProductionTrackPolicyFixtures::create($author);
            $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->sole();
            $service = app(ReviewProductionTrackPolicy::class);
            $capture = $service->review($version, $reviewer);
            $before = $this->rows();
            AuditEvent::created(fn () => throw new RuntimeException('Synthetic engine rollback boundary.'));
            try {
                $service->applyReviewed($capture, ['reference' => 'NONBINDING ENGINE REGRESSION',
                    'source_sha256' => hash('sha256', 'synthetic engine reference'), 'authored_source_acknowledged' => true], $reviewer);
                $this->fail('Synthetic late callback failure committed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic engine rollback boundary.', $exception->getMessage());
                $this->assertSame($before, $this->rows());
            } finally {
                AuditEvent::flushEventListeners();
                AuditEvent::clearBootedModels();
            }
        } finally {
            DB::statement('SET SESSION default_storage_engine = ?', [$original]);
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    private function rows(): array
    {
        $result = [];
        foreach (['production_track_policy_drafts', 'production_track_policy_versions', 'production_track_policy_source_reviews', 'audit_events'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $result;
    }
}
