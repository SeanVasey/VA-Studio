<?php

namespace Tests\Feature;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class RightsEvidenceGuardMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const GUARDS = ['rights_evidence_immutable_update', 'rights_evidence_immutable_delete',
        'rights_evidence_verified_insert', 'rights_evidence_verified_identity_insert',
        'tracks_rights_evidence_delete', 'tracks_rights_evidence_update', 'tracks_rights_evidence_insert'];

    protected function setUp(): void
    {
        parent::setUp();
        // 035 admits only its own guards and its predecessors' triggers on the protected tables.
        // The later discovery epoch (240) legitimately adds AFTER triggers to both tables, and 240
        // refuses operational teardown. Reduce the disposable fixture to 035's own baseline by
        // dropping exactly those triggers, derived from their owning schema class.
        foreach (DiscoveryEpoch::guards(DB::getDriverName()) as $name => $guard) {
            if (in_array($guard['table'], ['tracks', 'rights_declarations'], true)) {
                DB::unprepared('DROP TRIGGER '.$name);
            }
        }
        // Baseline proof: the reduced fixture is an exact, admitted 035 installation, so every
        // refusal case below is caused by its own drift rather than by an unrelated later trigger.
        $this->assertSame([], $this->ddlDuring(fn () => $this->migration()->up()));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_02_000035_rights_evidence_guards.php');
    }

    private function dropGuards(): void
    {
        foreach (self::GUARDS as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
    }

    private function retained(): RightsDeclaration
    {
        $actor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Retained synthetic rights', 'slug' => 'retained-synthetic-rights']);
        $pending = $track->rightsDeclarations()->create(['provenance_reference' => 'SYNTHETIC-ORIGINAL-REFERENCE',
            'sample_disclosure' => 'Original synthetic disclosure', 'status' => 'pending']);

        return app(VerifyRightsDeclaration::class)->handle($pending, $actor);
    }

    private function rows(): array
    {
        $retained = [];
        foreach ($this->tableNames() as $table) {
            $rows = DB::table($table)->get()->map(fn ($row): array => (array) $row)->all();
            usort($rows, fn (array $left, array $right): int => strcmp(serialize($left), serialize($right)));
            $retained[$table] = $rows;
        }

        return $retained;
    }

    private function tableNames(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'table')->orderBy('name')->pluck('name')->all()
            : DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_TYPE', 'BASE TABLE')->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->all();
    }

    private function schema(): array
    {
        $schema = [];
        foreach ($this->tableNames() as $table) {
            $statement = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql')
                : DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table))->{'Create Table'};
            $schema[$table] = [$statement, Schema::getColumns($table), Schema::getIndexes($table), Schema::getForeignKeys($table)];
        }

        return $schema;
    }

    private function catalog(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->orderBy('type')->orderBy('name')->get()->map(fn ($row): array => (array) $row)->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->orderBy('TRIGGER_NAME')->get()->map(fn ($row): array => (array) $row)->all();
    }

    private function state(): array
    {
        return [$this->rows(), $this->schema(), $this->catalog()];
    }

    private function definitions(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->whereIn('name', self::GUARDS)->orderBy('name')->get()->map(fn ($row): array => (array) $row)->keyBy('name')->toArray();
        }

        return DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->whereIn('TRIGGER_NAME', self::GUARDS)->orderBy('TRIGGER_NAME')
            ->get(['TRIGGER_NAME', 'EVENT_OBJECT_TABLE', 'ACTION_TIMING', 'EVENT_MANIPULATION', 'ACTION_STATEMENT'])->map(fn ($row): array => (array) $row)->keyBy('TRIGGER_NAME')->toArray();
    }

    private function ddlDuring(callable $operation): array
    {
        $statements = [];
        $inspect = true;
        DB::listen(function ($query) use (&$statements, &$inspect): void {
            if ($inspect && preg_match('/\A\s*(?:create|alter|drop|truncate|rename)\b/i', $query->sql)) {
                $statements[] = $query->sql;
            }
        });
        try {
            $operation();
        } finally {
            $inspect = false;
        }

        return $statements;
    }

    private function rejectedRetry(): array
    {
        return $this->ddlDuring(function (): void {
            try {
                $this->migration()->up();
                $this->fail('Rights migration accepted unowned, drifted or unsafe retained schema.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('Unexpected rights evidence', $error->getMessage());
            }
        });
    }

    public function test_additive_install_retry_and_operational_rollback_preserve_exact_historical_rows_audits_and_protection(): void
    {
        $verified = $this->retained();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        DeliveryFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        DeliveryFixtures::ready($gateway);
        $pending = $verified->track->rightsDeclarations()->create(['provenance_reference' => 'SYNTHETIC-PENDING-REFERENCE',
            'sample_disclosure' => 'Unreviewed synthetic disclosure', 'status' => 'pending']);
        $this->assertGreaterThan(0, DB::table('license_grants')->count());
        $this->assertGreaterThan(0, DB::table('grant_contracts')->count());
        $this->assertGreaterThan(0, DB::table('orders')->count());
        $this->dropGuards();
        $before = [$this->rows(), $this->schema()];
        $ddl = $this->ddlDuring(fn () => $this->migration()->up());
        $this->assertCount(7, $ddl);
        $this->assertSame($before, [$this->rows(), $this->schema()]);
        $definitions = $this->definitions();
        $this->assertCount(7, $definitions);
        $this->assertSame([], $this->ddlDuring(fn () => $this->migration()->up()));
        $this->assertSame([], $this->ddlDuring(fn () => $this->migration()->down()));
        $this->assertSame($definitions, $this->definitions());
        try {
            DB::table('rights_declarations')->where('id', $verified->id)->update(['sample_disclosure' => 'SYNTHETIC-UNREVIEWED']);
            $this->fail('Operational rollback removed the evidence guard.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Verified rights evidence', $error->getMessage());
        }
        $this->assertSame($before, [$this->rows(), $this->schema()]);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public static function installationPrefixes(): array
    {
        return array_combine(array_map(fn ($count) => 'recognized prefix '.$count, range(0, 7)), array_map(fn ($count) => [$count], range(0, 7)));
    }

    #[DataProvider('installationPrefixes')]
    public function test_only_the_missing_suffix_of_a_recognized_installation_prefix_is_restored(int $count): void
    {
        $this->retained();
        $definitions = $this->definitions();
        foreach (array_slice(self::GUARDS, $count) as $name) {
            DB::unprepared('DROP TRIGGER '.$name);
        }
        $prefix = $this->definitions();
        $before = [$this->rows(), $this->schema()];
        $ddl = $this->ddlDuring(fn () => $this->migration()->up());
        $this->assertCount(7 - $count, $ddl);
        foreach (array_slice(self::GUARDS, $count) as $index => $name) {
            $this->assertStringContainsString($name, $ddl[$index]);
        }
        $this->assertSame($definitions, $this->definitions());
        $this->assertSame($prefix, array_intersect_key($this->definitions(), $prefix));
        $this->assertSame($before, [$this->rows(), $this->schema()]);
    }

    public function test_an_impossible_nonprefix_installation_is_refused_before_any_ddl_or_data_repair(): void
    {
        $this->retained();
        DB::unprepared('DROP TRIGGER '.self::GUARDS[0]);
        $before = $this->state();
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, $this->state());
    }

    public static function guardNames(): array
    {
        return array_combine(self::GUARDS, array_map(fn ($name) => [$name], self::GUARDS));
    }

    #[DataProvider('guardNames')]
    public function test_foreign_definitions_at_each_owned_guard_name_are_refused_before_installing_any_other_guard(string $name): void
    {
        $this->retained();
        $this->dropGuards();
        $table = str_starts_with($name, 'rights_') ? 'tracks' : 'rights_declarations';
        $sql = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE UPDATE ON {$table} BEGIN SELECT 1; END"
            : "CREATE TRIGGER {$name} BEFORE UPDATE ON {$table} FOR EACH ROW SET @synthetic_foreign_rights_guard = 1";
        DB::unprepared($sql);
        $before = $this->state();
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, $this->state());
    }

    public static function lateGuardDrift(): array
    {
        return array_combine(['body', 'table', 'timing', 'operation', 'case'],
            array_map(fn ($part) => [$part], ['body', 'table', 'timing', 'operation', 'case']));
    }

    #[DataProvider('lateGuardDrift')]
    public function test_late_owned_guard_drift_is_refused_before_restoring_an_earlier_missing_guard(string $part): void
    {
        $this->retained();
        $name = 'tracks_rights_evidence_insert';
        $definition = $this->definitions()[$name];
        $statement = DB::getDriverName() === 'sqlite' ? $definition['sql']
            : "CREATE TRIGGER {$name} {$definition['ACTION_TIMING']} {$definition['EVENT_MANIPULATION']} ON {$definition['EVENT_OBJECT_TABLE']} FOR EACH ROW {$definition['ACTION_STATEMENT']}";
        $changed = match ($part) {
            'body' => str_replace('Verified rights evidence is immutable.', 'Synthetic unowned evidence guard.', $statement),
            'table' => str_replace('ON tracks ', 'ON rights_declarations ', $statement),
            'timing' => str_replace('BEFORE INSERT', 'AFTER INSERT', $statement),
            'operation' => str_replace('BEFORE INSERT', 'BEFORE UPDATE', $statement),
            'case' => str_replace($name, strtoupper($name), $statement),
        };
        // Use a simple foreign body for the wrong-table probe: NEW.slug is not a rights column
        // in MySQL, so its trigger compiler would reject that invalid setup before our preflight.
        if ($part === 'table') {
            $changed = DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER {$name} BEFORE INSERT ON rights_declarations BEGIN SELECT 1; END"
                : "CREATE TRIGGER {$name} BEFORE INSERT ON rights_declarations FOR EACH ROW SET @synthetic_foreign_rights_guard = 1";
        }
        $this->assertNotSame($statement, $changed);
        DB::unprepared('DROP TRIGGER '.$name);
        DB::unprepared($changed);
        DB::unprepared('DROP TRIGGER '.self::GUARDS[0]);
        $before = $this->state();
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, $this->state());
    }

    public function test_temporary_table_and_engine_specific_trigger_shadows_cannot_redirect_schema_or_guard_recovery(): void
    {
        $this->retained();
        $before = $this->state();
        foreach (['tracks', 'rights_declarations'] as $table) {
            DB::statement("CREATE TEMPORARY TABLE {$table} (synthetic_unowned_column INTEGER)");
            try {
                DB::table($table)->insert(['synthetic_unowned_column' => 73]);
                $this->assertSame([], $this->rejectedRetry());
                $this->assertSame(73, (int) DB::table($table)->sole()->synthetic_unowned_column);
            } finally {
                DB::statement(DB::getDriverName() === 'mysql' ? "DROP TEMPORARY TABLE {$table}" : "DROP TABLE temp.{$table}");
            }
            $this->assertSame($before, $this->state());
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('CREATE TEMP TRIGGER synthetic_rights_shadow BEFORE UPDATE ON main.rights_declarations BEGIN SELECT 1; END');
            try {
                $temporary = DB::table('sqlite_temp_master')->orderBy('name')->get()->toJson();
                $this->assertSame([], $this->rejectedRetry());
                $this->assertSame($temporary, DB::table('sqlite_temp_master')->orderBy('name')->get()->toJson());
            } finally {
                DB::unprepared('DROP TRIGGER temp.synthetic_rights_shadow');
            }
            $this->assertSame($before, $this->state());
        }
    }

    public static function extraUniqueKeys(): array
    {
        return ['rights verification key' => ['rights_declarations', 'verified_at'], 'track title key' => ['tracks', 'title']];
    }

    #[DataProvider('extraUniqueKeys')]
    public function test_unowned_unique_conflict_keys_are_refused_before_restoring_missing_guards(string $table, string $column): void
    {
        $this->retained();
        $this->dropGuards();
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($column, 'synthetic_rights_conflict_key'));
        $before = $this->state();
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, $this->state());
    }

    public function test_a_collation_or_prefix_change_to_the_actual_parent_unique_key_is_refused_without_ddl(): void
    {
        $this->retained();
        $this->dropGuards();
        Schema::table('tracks', fn (Blueprint $table) => $table->dropUnique('tracks_slug_unique'));
        DB::statement(DB::getDriverName() === 'sqlite'
            ? 'CREATE UNIQUE INDEX tracks_slug_unique ON tracks (slug COLLATE NOCASE)'
            : 'CREATE UNIQUE INDEX tracks_slug_unique ON tracks (slug(32))');
        $before = $this->state();
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, $this->state());
    }

    public function test_a_cascading_foreign_key_is_not_silently_accepted_as_the_supported_restrict_schema(): void
    {
        $this->retained();
        $this->dropGuards();
        Schema::table('rights_declarations', function (Blueprint $table): void {
            $table->dropForeign(['track_id']);
            $table->foreign('track_id')->references('id')->on('tracks')->cascadeOnDelete();
        });
        $before = $this->state();
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, $this->state());
    }

    public function test_a_changed_required_rights_column_is_refused_without_correcting_data_or_installing_guards(): void
    {
        $this->retained();
        $this->dropGuards();
        Schema::table('rights_declarations', fn (Blueprint $table) => $table->text('provenance_reference')->nullable()->change());
        $before = $this->state();
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, $this->state());
    }

    public function test_an_additional_trigger_on_a_protected_table_is_refused_before_restoring_missing_guards(): void
    {
        $this->retained();
        $this->dropGuards();
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER synthetic_unowned_rights_writer BEFORE UPDATE ON rights_declarations BEGIN SELECT 1; END'
            : 'CREATE TRIGGER synthetic_unowned_rights_writer BEFORE UPDATE ON rights_declarations FOR EACH ROW SET @synthetic_rights_writer = 1');
        $before = $this->state();
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, $this->state());
    }

    public function test_engine_specific_column_or_index_collation_drift_is_refused_before_any_guard_ddl(): void
    {
        $this->retained();
        $this->dropGuards();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX tracks_status_index');
            DB::statement('CREATE INDEX tracks_status_index ON tracks (status COLLATE NOCASE)');
        } else {
            $collation = DB::connection()->getConfig('collation') === 'utf8mb4_bin' ? 'utf8mb4_unicode_ci' : 'utf8mb4_bin';
            DB::statement("ALTER TABLE rights_declarations MODIFY status VARCHAR(255) COLLATE {$collation} NOT NULL DEFAULT 'pending'");
        }
        $before = $this->state();
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, $this->state());
    }

    public static function invalidRetainedReferences(): array
    {
        return ['orphan track' => ['track_id'], 'orphan verifier' => ['verified_by']];
    }

    #[DataProvider('invalidRetainedReferences')]
    public function test_retained_orphan_references_are_refused_before_any_guard_ddl_and_remain_exact(string $column): void
    {
        $verified = $this->retained();
        $this->dropGuards();
        Schema::disableForeignKeyConstraints();
        try {
            DB::table('rights_declarations')->where('id', $verified->id)->update([$column => 9223372036854775807]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        $before = $this->state();
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, $this->state());
        $this->assertSame(9223372036854775807, (int) DB::table('rights_declarations')->where('id', $verified->id)->value($column));
    }

    public function test_retained_nonpositive_verified_identity_is_refused_without_fabricating_or_repairing_history(): void
    {
        $verified = $this->retained();
        $this->dropGuards();
        $originalMode = DB::getDriverName() === 'mysql' ? DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode : null;
        if ($originalMode !== null) {
            DB::statement('SET SESSION sql_mode = ?', [implode(',', array_unique([...array_filter(explode(',', $originalMode)), 'NO_AUTO_VALUE_ON_ZERO']))]);
        }
        try {
            // SQLite permits -1; MySQL's unsigned key needs an actual zero rather than its auto-ID sentinel.
            $id = DB::getDriverName() === 'sqlite' ? -1 : 0;
            DB::table('rights_declarations')->where('id', $verified->id)->update(['id' => $id]);
            $before = $this->state();
            $this->assertSame([], $this->rejectedRetry());
            $this->assertSame($before, $this->state());
            $this->assertSame('verified', DB::table('rights_declarations')->where('id', $id)->value('status'));
            $this->assertSame(1, DB::table('audit_events')->where('action', 'rights.declaration.verified')->count());
        } finally {
            if ($originalMode !== null) {
                DB::statement('SET SESSION sql_mode = ?', [$originalMode]);
            }
        }
    }
}
