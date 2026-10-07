<?php

namespace Tests\Feature;

use App\Domain\Services\ServiceDrafts;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PrivateProductDraftFixtures;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

class ServiceProjectSchemaTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MIGRATION = '2026_10_07_244000_service_projects';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
    }

    private function rows(): array
    {
        return array_map(fn ($table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), ['service_projects', 'service_project_events', 'migrations']);
    }

    public function test_sql_cannot_update_delete_ignore_or_replace_original_brief_and_accepted_quote_evidence(): void
    {
        $f = F::setup();
        F::accept($f);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers = OFF');
        }
        $before = $this->rows();
        foreach (['service_projects', 'service_project_events'] as $table) {
            $row = (array) DB::table($table)->orderBy('id')->first();
            $mutations = [fn () => DB::table($table)->where('id', $row['id'])->update(['created_at' => '2099-01-01 00:00:00']),
                fn () => DB::table($table)->where('id', $row['id'])->delete(),
                fn () => DB::table($table)->insertOrIgnore($row),
                function () use ($table, $row): void {
                    $grammar = DB::connection()->getQueryGrammar();
                    DB::insert('REPLACE INTO '.$grammar->wrapTable($table).' ('.implode(',', array_map($grammar->wrap(...), array_keys($row))).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row));
                }];
            foreach ($mutations as $mutation) {
                try {
                    $mutation();
                    $this->fail('SQL must preserve immutable service evidence.');
                } catch (QueryException) {
                    $this->assertSame($before, $this->rows());
                }
            }
        }
    }

    public function test_actual_rollback_failure_retains_original_rows_and_migration_bookkeeping(): void
    {
        $f = F::setup();
        F::author($f);
        $before = $this->rows();
        try {
            app('migrator')->rollback([database_path('migrations/'.self::MIGRATION.'.php')], ['step' => 1]);
            $this->fail('Retained service evidence must refuse rollback.');
        } catch (LogicException) {
            $this->assertSame($before, $this->rows());
            $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);
        }
    }

    public function test_failed_ddl_retains_prior_service_rows_and_never_logs_or_adopts_partial_native_schema(): void
    {
        $this->assertDatabaseCount('service_projects', 0);
        Schema::drop('service_project_events');
        Schema::drop('service_projects');
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $actor = LicenseFixtures::admin();
        $drafts = app(ServiceDrafts::class);
        $drafts->applyReviewed($drafts->review(null, PrivateProductDraftFixtures::payload('service'), $actor), $actor);
        $prior = DB::table('service_draft_versions')->get()->toJson();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_contains(strtoupper($query->sql), 'CREATE TRIGGER') && str_contains($query->sql, 'service_projects_immutable')) {
                $armed = false;
                throw new RuntimeException('Synthetic interruption after the first service SQL guard.');
            }
        });
        try {
            $this->artisan('migrate', ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--force' => true])->run();
            $this->fail('The actual migrator must report the interrupted DDL.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic interruption after the first service SQL guard.', $error->getMessage());
        }
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        $this->assertSame($prior, DB::table('service_draft_versions')->get()->toJson());
        if (DB::getDriverName() === 'mysql') {
            $this->assertTrue(Schema::hasTable('service_projects'));
            $this->assertTrue(Schema::hasTable('service_project_events'));
            $objects = DB::select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME LIKE ?', [DB::getDatabaseName(), 'service_project%']);
            $this->assertCount(1, $objects);
            try {
                $this->artisan('migrate', ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--force' => true])->run();
                $this->fail('Native partial schema must remain unadopted for inspection.');
            } catch (LogicException) {
                $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
                $this->assertSame($objects, DB::select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME LIKE ?', [DB::getDatabaseName(), 'service_project%']));
            }
        } else {
            $this->assertTrue(Schema::hasTable('service_projects'));
            try {
                $this->artisan('migrate', ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--force' => true])->run();
                $this->fail('Partial SQLite schema must remain unadopted for inspection.');
            } catch (LogicException) {
                $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
                $this->assertSame($prior, DB::table('service_draft_versions')->get()->toJson());
            }
        }
    }
}
