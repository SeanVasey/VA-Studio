<?php

namespace Tests\Feature;

use App\Domain\Services\ServiceDrafts;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PrivateProductDraftFixtures;
use Tests\TestCase;

/** Real Migrator interruption: exact empty owned DDL must be safely resumable. */
final class ServiceProjectRecoveryCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MIGRATION = '2026_10_07_244000_service_projects';

    public function test_exact_owned_partial_install_can_retry_without_erasing_prior_definitions_or_bookkeeping(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        $this->assertDatabaseCount('service_projects', 0);
        Schema::drop('service_project_events');
        Schema::drop('service_projects');
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        $actor = LicenseFixtures::admin();
        $drafts = app(ServiceDrafts::class);
        $drafts->applyReviewed($drafts->review(null, PrivateProductDraftFixtures::payload('service'), $actor), $actor);
        $prior = $this->prior();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_contains(strtoupper($query->sql), 'CREATE TRIGGER') && str_contains($query->sql, 'service_projects_immutable')) {
                $armed = false;
                throw new RuntimeException('Independent interruption after committed first service guard.');
            }
        });
        try {
            $this->migrateService();
            $this->fail('The independent fault must interrupt the real migrator.');
        } catch (RuntimeException $error) {
            $this->assertSame('Independent interruption after committed first service guard.', $error->getMessage());
        }
        $this->assertSame($prior, $this->prior());
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        $survivors = $this->objects();

        $retryFailure = null;
        try {
            $this->migrateService();
        } catch (LogicException $error) {
            $retryFailure = $error->getMessage();
        }
        $after = $this->objects();
        $priorPreserved = $prior === $this->prior();
        $receipt = ['source' => getenv('SERVICE_REVIEW_SOURCE_SHA') ?: 'a03dec44dd2004963998fa648125e191f7dad269',
            'driver' => DB::getDriverName(), 'version' => DB::getDriverName() === 'mysql' ? DB::selectOne('SELECT VERSION() AS v')->v : DB::selectOne('SELECT sqlite_version() AS v')->v,
            'surviving_objects_before_retry' => $survivors, 'objects_after_retry' => $after,
            'prior_definition_and_ledger_preserved' => $priorPreserved,
            'retry_failure' => $retryFailure, 'migration_records' => DB::table('migrations')->where('migration', self::MIGRATION)->count()];
        $phase = getenv('SERVICE_REVIEW_PHASE');
        file_put_contents(__DIR__.'/recovery-'.DB::getDriverName().($phase ? '-'.$phase : '').'-probe.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->assertTrue($priorPreserved);
        if (DB::getDriverName() === 'mysql') {
            foreach ($survivors['tables'] as $table => $definition) {
                $this->assertSame($definition, $after['tables'][$table]);
            }
            foreach ($survivors['guards'] as $definition) {
                $retained = array_values(array_filter($after['guards'], fn (array $row): bool => $row['TRIGGER_NAME'] === $definition['TRIGGER_NAME']));
                $this->assertSame([$definition], $retained);
            }
        } else {
            foreach ($survivors as $definition) {
                $retained = array_values(array_filter($after, fn (array $row): bool => $row['type'] === $definition['type'] && $row['name'] === $definition['name']));
                $this->assertSame([$definition], $retained);
            }
        }
        if ($retryFailure !== null) {
            $this->assertSame($survivors, $after);
        }
        $this->assertNull($retryFailure, 'An exact owned installation prefix remains permanently unlogged and cannot resume.');
        $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);
    }

    private function migrateService(): void
    {
        app('migrator')->run([database_path('migrations/'.self::MIGRATION.'.php')], ['pretend' => false, 'step' => false]);
    }

    private function prior(): array
    {
        return array_map(function (string $table): array {
            $query = DB::table($table)->orderBy('id');
            if ($table === 'migrations') {
                $query->where('migration', '!=', self::MIGRATION);
            }

            return $query->get()->map(fn ($row): array => (array) $row)->all();
        }, ['service_drafts', 'service_draft_versions', 'migrations']);
    }

    private function objects(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return array_map(fn ($row): array => (array) $row, DB::select("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name LIKE 'service_project%' ORDER BY type, name"));
        }

        $tables = [];
        foreach (['service_project_events', 'service_projects'] as $table) {
            if (Schema::hasTable($table)) {
                $tables[$table] = (array) DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table));
            }
        }

        return ['tables' => $tables,
            'guards' => array_map(fn ($row): array => (array) $row, DB::select("SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'service_project%' ORDER BY TRIGGER_NAME"))];
    }
}
