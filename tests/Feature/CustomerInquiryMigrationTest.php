<?php

namespace Tests\Feature;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** Operational rollback and raw SQL guards run outside RefreshDatabase's transaction on a disposable database. */
class CustomerInquiryMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $operator;

    private SiteRelease $release;

    protected function setUp(): void
    {
        parent::setUp();
        // Reverse the additive child first, as a parent rollback must do on MySQL.
        (require database_path('migrations/2026_10_06_000043_inquiry_messages.php'))->down();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $this->operator = LicenseFixtures::admin();
        $content = SiteContentSchema::forEditing(SiteContentSchema::defaults());
        $content['contact'] = ['title' => 'Synthetic contact', 'description' => 'Migration fixture.',
            'paragraphs' => ['Synthetic records only.'], 'email' => 'operator@example.test'];
        $this->release = app(SiteContent::class)->create($content, 'Synthetic inquiry migration fixture', $this->operator);
    }

    private function attributes(array $overrides = []): array
    {
        $payload = ['name' => 'Synthetic Migration Buyer', 'email' => 'migration-buyer@example.test',
            'subject' => 'Synthetic migration inquiry', 'message' => 'Synthetic private message.', 'website' => ''];

        return array_replace([
            'public_id' => (string) Str::uuid(), 'owner_hash' => hash('sha256', 'synthetic-session-owner'),
            'request_key' => (string) Str::uuid(), 'payload_hash' => CanonicalJson::hash($payload), 'payload' => $payload,
            'privacy_notice' => 'SYNTHETIC PRIVATE NOTICE', 'privacy_notice_hash' => hash('sha256', 'SYNTHETIC PRIVATE NOTICE'),
            'retention_policy_reference' => 'SYNTHETIC-RETENTION-POLICY', 'operator_user_id' => $this->operator->id,
            'site_release_id' => $this->release->id, 'site_content_hash' => $this->release->content_hash,
            'state' => 'new', 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Contradictory inquiry evidence or transition was accepted.');
        } catch (QueryException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
    }

    public function test_every_committed_installation_prefix_can_resume_and_repeated_up_preserves_all_guards(): void
    {
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        $parents = $this->parentEvidence();
        $migration->down();
        $ddl = $this->ddl(fn () => $migration->up());
        $this->assertCount(DB::getDriverName() === 'mysql' ? 10 : 8, $ddl);
        $complete = $this->structure();
        foreach (range(1, count($ddl)) as $stop) {
            $migration->down();
            $this->interruptAfter($stop, fn () => $migration->up());
            $this->assertTrue(Schema::hasTable('customer_inquiries'));
            $this->assertDatabaseCount('customer_inquiries', 0);
            $survivors = $this->triggers();
            $migration->up();
            foreach ($survivors as $name => $definition) {
                $this->assertSame($definition, $this->triggers()[$name], "Installed guard {$name} was replaced after DDL {$stop}.");
            }
            $this->assertSame($complete, $this->structure());
            $this->assertSame([], $this->ddl(fn () => $migration->up()));
            $this->assertSame($parents, $this->parentEvidence());
            $this->rejected(fn () => CustomerInquiry::create($this->attributes(['state' => 'read', 'version' => 1])));
        }
        $inquiry = CustomerInquiry::create($this->attributes());
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->delete());
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->update(['payload_hash' => str_repeat('b', 64)]));
    }

    public function test_fully_protected_retained_table_can_resume_without_any_ddl_or_evidence_change(): void
    {
        CustomerInquiry::create($this->attributes());
        $before = [$this->structure(), $this->parentEvidence(), $this->rows('customer_inquiries')];
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        $this->assertSame([], $this->ddl(fn () => $migration->up()));
        $this->assertSame($before, [$this->structure(), $this->parentEvidence(), $this->rows('customer_inquiries')]);
    }

    #[DataProvider('missingRetainedProtection')]
    public function test_nonempty_partial_table_is_never_retroactively_adopted(string $part): void
    {
        CustomerInquiry::create($this->attributes());
        if (str_starts_with($part, 'trigger:')) {
            DB::unprepared('DROP TRIGGER customer_inquiries_'.substr($part, strlen('trigger:')));
        } elseif ($part === 'index') {
            Schema::table('customer_inquiries', fn ($table) => $table->dropUnique('customer_inquiries_public_id_unique'));
        }
        $this->migrationRefused(fn () => (require database_path('migrations/2026_10_01_000031_customer_inquiries.php'))->up());
    }

    public static function missingRetainedProtection(): array
    {
        return ['insert' => ['trigger:insert'], 'update' => ['trigger:update'], 'delete' => ['trigger:delete'], 'unique index' => ['index']];
    }

    public function test_nonempty_partial_table_with_a_missing_mysql_foreign_key_is_never_retroactively_adopted(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('SQLite foreign keys are inline; interrupted foreign-key DDL is a MySQL path.');
        }
        CustomerInquiry::create($this->attributes());
        Schema::table('customer_inquiries', fn ($table) => $table->dropForeign('customer_inquiries_operator_user_id_foreign'));
        $this->migrationRefused(fn () => (require database_path('migrations/2026_10_01_000031_customer_inquiries.php'))->up());
    }

    public function test_every_empty_rollback_prefix_and_absent_table_can_resume_without_changing_parent_evidence(): void
    {
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        $parents = $this->parentEvidence();
        foreach (range(1, 4) as $stop) {
            $this->interruptAfter($stop, fn () => $migration->down());
            $migration->down();
            $this->assertFalse(Schema::hasTable('customer_inquiries'));
            $this->assertSame([], $this->ddl(fn () => $migration->down()));
            $this->assertSame($parents, $this->parentEvidence());
            $migration->up();
        }
        $this->rejected(fn () => CustomerInquiry::create($this->attributes(['state' => 'read'])));
    }

    #[DataProvider('foreignShape')]
    public function test_foreign_schema_or_guard_residue_is_refused_before_any_ddl(string $part): void
    {
        if ($part === 'column') {
            Schema::table('customer_inquiries', fn ($table) => $table->string('foreign_column')->nullable());
        } elseif ($part === 'index') {
            Schema::table('customer_inquiries', function ($table): void {
                $table->dropUnique('customer_inquiries_public_id_unique');
                $table->index('public_id', 'customer_inquiries_public_id_unique');
            });
        } else {
            $name = $part === 'extra trigger' ? 'customer_inquiries_foreign' : 'customer_inquiries_update';
            if ($part !== 'extra trigger') {
                DB::unprepared('DROP TRIGGER '.$name);
            }
            DB::unprepared(DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER {$name} BEFORE UPDATE ON customer_inquiries BEGIN SELECT 1; END"
                : "CREATE TRIGGER {$name} BEFORE UPDATE ON customer_inquiries FOR EACH ROW BEGIN SET @synthetic_inquiry_collision = 1; END");
        }
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        $this->migrationRefused(fn () => $migration->up());
        $this->migrationRefused(fn () => $migration->down());
    }

    public static function foreignShape(): array
    {
        return ['extra column' => ['column'], 'wrong unique index' => ['index'], 'wrong guard body' => ['guard'], 'additional table trigger' => ['extra trigger']];
    }

    public function test_changed_mysql_foreign_key_definition_is_refused_before_any_ddl(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Changed foreign-key DDL is a MySQL shape check; SQLite checks the complete original table SQL.');
        }
        Schema::table('customer_inquiries', function ($table): void {
            $table->dropForeign('customer_inquiries_operator_user_id_foreign');
            $table->foreign('operator_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        $this->migrationRefused(fn () => $migration->up());
        $this->migrationRefused(fn () => $migration->down());
    }

    public function test_shortened_mysql_unique_index_prefix_is_refused_before_any_ddl(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Prefix indexes are a MySQL shape check.');
        }
        DB::unprepared('ALTER TABLE customer_inquiries DROP INDEX customer_inquiries_public_id_unique, ADD UNIQUE INDEX customer_inquiries_public_id_unique (public_id(12))');
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        $this->migrationRefused(fn () => $migration->up());
        $this->migrationRefused(fn () => $migration->down());
    }

    public function test_missing_owned_guard_does_not_allow_foreign_named_guard_to_be_overwritten(): void
    {
        DB::unprepared('DROP TRIGGER customer_inquiries_insert');
        DB::unprepared('DROP TRIGGER customer_inquiries_delete');
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER customer_inquiries_delete BEFORE DELETE ON users BEGIN SELECT 1; END'
            : 'CREATE TRIGGER customer_inquiries_delete BEFORE DELETE ON users FOR EACH ROW BEGIN SET @synthetic_inquiry_collision = 1; END');
        $this->migrationRefused(fn () => (require database_path('migrations/2026_10_01_000031_customer_inquiries.php'))->up());
        $this->assertArrayNotHasKey('customer_inquiries_insert', $this->triggers());
    }

    public function test_unrelated_table_with_an_owned_schema_wide_name_is_refused_before_creating_inquiries(): void
    {
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        $migration->down();
        Schema::create('synthetic_inquiry_foreign_name', function ($table): void {
            $table->id();
            if (DB::getDriverName() === 'sqlite') {
                $table->index('id', 'customer_inquiries_public_id_unique');
            } else {
                $table->unsignedBigInteger('operator_user_id');
                $table->foreign('operator_user_id', 'customer_inquiries_operator_user_id_foreign')->references('id')->on('users')->restrictOnDelete();
            }
        });
        $before = [Schema::getColumns('synthetic_inquiry_foreign_name'), Schema::getIndexes('synthetic_inquiry_foreign_name'),
            Schema::getForeignKeys('synthetic_inquiry_foreign_name'), $this->parentEvidence()];
        $ddl = $this->ddl(function () use ($migration): void {
            try {
                $migration->up();
                $this->fail('The foreign schema-wide name was adopted.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('foreign', $error->getMessage());
            }
        });
        $this->assertSame([], $ddl);
        $this->assertFalse(Schema::hasTable('customer_inquiries'));
        $this->assertSame($before, [Schema::getColumns('synthetic_inquiry_foreign_name'), Schema::getIndexes('synthetic_inquiry_foreign_name'),
            Schema::getForeignKeys('synthetic_inquiry_foreign_name'), $this->parentEvidence()]);
    }

    public function test_foreign_minimal_table_is_not_repaired_or_dropped_as_an_owned_partial_table(): void
    {
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        $migration->down();
        Schema::create('customer_inquiries', fn ($table) => $table->id());
        $this->migrationRefused(fn () => $migration->up());
        $this->migrationRefused(fn () => $migration->down());
        $this->assertTrue(Schema::hasTable('customer_inquiries'));
    }

    public function test_differently_cased_foreign_table_is_neither_adopted_nor_dropped(): void
    {
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        if (DB::getDriverName() === 'mysql' && (int) DB::selectOne('SELECT @@lower_case_table_names AS value')->value === 0) {
            // A complete empty owned table must not hide a second case-folded match.
            Schema::create('CUSTOMER_INQUIRIES', function ($table): void {
                $table->id();
                $table->text('foreign_private_evidence');
            });
            DB::table('CUSTOMER_INQUIRIES')->insert(['foreign_private_evidence' => 'Synthetic sibling evidence must survive.']);
            $before = [$this->schemaRows(), $this->rows('customer_inquiries'), $this->rows('CUSTOMER_INQUIRIES'), $this->parentEvidence()];
            try {
                foreach ([fn () => $migration->up(), fn () => $migration->down()] as $operation) {
                    $ddl = $this->ddl(function () use ($operation): void {
                        try {
                            $operation();
                            $this->fail('A case-folded foreign sibling was hidden by the canonical table.');
                        } catch (LogicException $error) {
                            $this->assertStringContainsString('table identity', $error->getMessage());
                        }
                    });
                    $this->assertSame([], $ddl);
                    $this->assertSame($before, [$this->schemaRows(), $this->rows('customer_inquiries'), $this->rows('CUSTOMER_INQUIRIES'), $this->parentEvidence()]);
                }
            } finally {
                // Remove only the synthetic sibling fixture before the original lone-name case.
                Schema::dropIfExists('CUSTOMER_INQUIRIES');
            }
        }
        $migration->down();
        Schema::create('CUSTOMER_INQUIRIES', function ($table): void {
            $table->id();
            $table->text('foreign_private_evidence');
        });
        DB::table('CUSTOMER_INQUIRIES')->insert(['foreign_private_evidence' => 'Synthetic foreign evidence must survive.']);
        $before = [$this->schemaRows(), $this->rows('CUSTOMER_INQUIRIES'), $this->parentEvidence()];
        foreach ([fn () => $migration->up(), fn () => $migration->down()] as $operation) {
            $ddl = $this->ddl(function () use ($operation): void {
                try {
                    $operation();
                    $this->fail('A differently cased foreign inquiry table was accepted.');
                } catch (LogicException $error) {
                    $this->assertNotSame('', $error->getMessage());
                }
            });
            $this->assertSame([], $ddl);
            $this->assertSame($before, [$this->schemaRows(), $this->rows('CUSTOMER_INQUIRIES'), $this->parentEvidence()]);
        }
    }

    #[DataProvider('uppercaseForeignNames')]
    public function test_differently_cased_schema_wide_names_are_refused_before_any_create(string $part): void
    {
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        if ($part === 'trigger' && DB::getDriverName() === 'mysql') {
            Schema::create('synthetic_inquiry_guard_sibling', function ($table): void {
                $table->id();
                $table->text('foreign_private_evidence');
            });
            DB::table('synthetic_inquiry_guard_sibling')->insert(['foreign_private_evidence' => 'Synthetic sibling guard evidence must survive.']);
            $before = [$this->schemaRows(), $this->rows('synthetic_inquiry_guard_sibling'), $this->rows('customer_inquiries'), $this->parentEvidence()];
            $canonical = $this->triggers();
            try {
                // MySQL's data dictionary rejects case-only duplicates in its schema-wide
                // trigger namespace, even when the second trigger targets another table.
                try {
                    DB::unprepared('CREATE TRIGGER CUSTOMER_INQUIRIES_DELETE BEFORE DELETE ON synthetic_inquiry_guard_sibling FOR EACH ROW BEGIN SET @synthetic_inquiry_collision = 1; END');
                    $this->fail('MySQL accepted a case-only duplicate trigger name.');
                } catch (QueryException $error) {
                    $this->assertSame('HY000', $error->errorInfo[0]);
                    $this->assertSame(1359, $error->errorInfo[1]);
                }
                $this->assertSame($before, [$this->schemaRows(), $this->rows('synthetic_inquiry_guard_sibling'), $this->rows('customer_inquiries'), $this->parentEvidence()]);
                $this->assertSame($canonical, $this->triggers());
            } finally {
                Schema::dropIfExists('synthetic_inquiry_guard_sibling');
            }
        }
        $migration->down();
        Schema::create('synthetic_inquiry_case_collision', function ($table) use ($part): void {
            $table->id();
            $table->text('foreign_private_evidence');
            if ($part === 'index') {
                if (DB::getDriverName() === 'sqlite') {
                    $table->index('id', 'CUSTOMER_INQUIRIES_PUBLIC_ID_UNIQUE');
                } else {
                    $table->unsignedBigInteger('operator_user_id');
                    $table->foreign('operator_user_id', 'CUSTOMER_INQUIRIES_OPERATOR_USER_ID_FOREIGN')->references('id')->on('users')->restrictOnDelete();
                }
            }
        });
        if ($part === 'trigger') {
            DB::unprepared(DB::getDriverName() === 'sqlite'
                ? 'CREATE TRIGGER CUSTOMER_INQUIRIES_DELETE BEFORE DELETE ON synthetic_inquiry_case_collision BEGIN SELECT 1; END'
                : 'CREATE TRIGGER CUSTOMER_INQUIRIES_DELETE BEFORE DELETE ON synthetic_inquiry_case_collision FOR EACH ROW BEGIN SET @synthetic_inquiry_collision = 1; END');
        }
        $attributes = ['foreign_private_evidence' => 'Synthetic foreign collision evidence.'];
        if ($part === 'index' && DB::getDriverName() === 'mysql') {
            $attributes['operator_user_id'] = $this->operator->id;
        }
        DB::table('synthetic_inquiry_case_collision')->insert($attributes);
        $before = [$this->schemaRows(), $this->rows('synthetic_inquiry_case_collision'), $this->parentEvidence()];
        $ddl = $this->ddl(function () use ($migration): void {
            try {
                $migration->up();
                $this->fail('A differently cased foreign schema-wide name was accepted.');
            } catch (LogicException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        });
        $this->assertSame([], $ddl);
        $this->assertFalse(Schema::hasTable('customer_inquiries'));
        $this->assertSame($before, [$this->schemaRows(), $this->rows('synthetic_inquiry_case_collision'), $this->parentEvidence()]);
    }

    public static function uppercaseForeignNames(): array
    {
        return ['SQLite global index or MySQL global foreign key' => ['index'], 'global trigger' => ['trigger']];
    }

    public function test_temporary_objects_cannot_hide_retained_inquiries_or_shadow_owned_schema_names(): void
    {
        CustomerInquiry::create($this->attributes());
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        $before = [$this->schemaRows(), $this->rows('customer_inquiries'), $this->parentEvidence()];
        // MySQL has temporary table shadows; SQLite also permits temporary indexes and
        // temporary triggers whose names can intercept an unqualified schema operation.
        $parts = DB::getDriverName() === 'sqlite' ? ['empty table', 'populated table', 'index', 'trigger'] : ['empty table', 'populated table'];
        foreach ($parts as $part) {
            $temporaryTable = str_ends_with($part, 'table') ? 'customer_inquiries' : 'synthetic_temporary_inquiry_holder';
            $wrapped = DB::connection()->getQueryGrammar()->wrapTable($temporaryTable);
            DB::unprepared('CREATE TEMPORARY TABLE '.$wrapped.' (id BIGINT, private_evidence TEXT)');
            if ($part !== 'empty table') {
                DB::table($temporaryTable)->insert(['id' => 42, 'private_evidence' => 'Synthetic retained temporary evidence.']);
            }
            if ($part === 'index') {
                DB::unprepared('CREATE INDEX CUSTOMER_INQUIRIES_PUBLIC_ID_UNIQUE ON synthetic_temporary_inquiry_holder(id)');
            } elseif ($part === 'trigger') {
                DB::unprepared('CREATE TEMP TRIGGER CUSTOMER_INQUIRIES_DELETE BEFORE DELETE ON main.customer_inquiries BEGIN SELECT 1; END');
            }
            $temporaryRows = $this->rows($temporaryTable);
            $temporarySchema = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_temp_master')->orderBy('type')->orderBy('name')->get()->map(fn ($row): array => (array) $row)->all()
                : (array) DB::selectOne('SHOW CREATE TABLE '.$wrapped);
            $guards = $this->triggers();
            try {
                foreach ([fn () => $migration->up(), fn () => $migration->down()] as $operation) {
                    $ddl = $this->ddl(function () use ($operation): void {
                        try {
                            $operation();
                            $this->fail('A temporary inquiry shadow was accepted.');
                        } catch (LogicException $error) {
                            $this->assertStringContainsString('temporary', strtolower($error->getMessage()));
                        }
                    });
                    $this->assertSame([], $ddl);
                    $this->assertSame($guards, $this->triggers());
                    $this->assertSame($temporaryRows, $this->rows($temporaryTable));
                    $this->assertSame($temporarySchema, DB::getDriverName() === 'sqlite'
                        ? DB::table('sqlite_temp_master')->orderBy('type')->orderBy('name')->get()->map(fn ($row): array => (array) $row)->all()
                        : (array) DB::selectOne('SHOW CREATE TABLE '.$wrapped));
                }
            } finally {
                if ($part === 'trigger') {
                    DB::unprepared('DROP TRIGGER temp.CUSTOMER_INQUIRIES_DELETE');
                }
                DB::unprepared(DB::getDriverName() === 'sqlite'
                    ? 'DROP TABLE '.DB::connection()->getQueryGrammar()->wrapTable('temp.'.$temporaryTable)
                    : 'DROP TEMPORARY TABLE '.$wrapped);
            }
            $this->assertSame($before, [$this->schemaRows(), $this->rows('customer_inquiries'), $this->parentEvidence()]);
            $this->rejected(fn () => DB::table('customer_inquiries')->delete());
        }
    }

    private function schemaRows(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->orderBy('type')->orderBy('name')->get()->map(fn ($row): array => (array) $row)->all();
        }

        $names = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->all();

        return [array_map(fn (string $name): array => (array) DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($name)), $names),
            DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->orderBy('TRIGGER_NAME')
                ->get()->map(fn ($row): array => (array) $row)->all()];
    }

    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    private function parentEvidence(): array
    {
        return [$this->release->fresh()->getAttributes(), $this->operator->fresh()->getAttributes(), $this->rows('audit_events')];
    }

    private function structure(): array
    {
        return [Schema::getColumns('customer_inquiries'), Schema::getIndexes('customer_inquiries'), Schema::getForeignKeys('customer_inquiries'), $this->triggers()];
    }

    private function triggers(): array
    {
        $rows = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'customer_inquiries_%')->orderBy('name')->get()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'like', 'customer_inquiries_%')->orderBy('TRIGGER_NAME')->get();
        $result = [];
        foreach ($rows as $row) {
            $result[DB::getDriverName() === 'sqlite' ? $row->name : $row->TRIGGER_NAME] = DB::getDriverName() === 'sqlite'
                ? [$row->tbl_name, $row->sql] : [$row->EVENT_OBJECT_TABLE, $row->ACTION_TIMING, $row->EVENT_MANIPULATION, $row->ACTION_STATEMENT];
        }

        return $result;
    }

    private function migrationRefused(callable $operation): void
    {
        $before = [$this->structure(), $this->parentEvidence(), $this->rows('customer_inquiries')];
        $ddl = $this->ddl(function () use ($operation): void {
            try {
                $operation();
                $this->fail('A foreign or unprotected migration state was accepted.');
            } catch (LogicException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        });
        $this->assertSame([], $ddl);
        $this->assertSame($before, [$this->structure(), $this->parentEvidence(), $this->rows('customer_inquiries')]);
    }

    private function ddl(callable $operation, ?int $stop = null): array
    {
        $active = true;
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$active, &$statements, $stop): void {
            if (! $active || preg_match('/\A(?:create|alter|drop)\b/i', $query->sql) !== 1) {
                return;
            }
            $statements[] = $query->sql;
            if ($stop !== null && count($statements) === $stop) {
                $active = false;
                throw new RuntimeException('Synthetic interruption after completed migration DDL.');
            }
        });
        try {
            $operation();
        } finally {
            $active = false;
        }

        return $statements;
    }

    private function interruptAfter(int $stop, callable $operation): void
    {
        try {
            $this->ddl($operation, $stop);
            $this->fail('The migration did not reach the requested DDL interruption.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic interruption after completed migration DDL.', $error->getMessage());
        }
    }

    public function test_empty_rollback_and_reapply_preserve_site_and_operator_evidence_and_restore_guards(): void
    {
        $release = $this->release->refresh()->getAttributes();
        $operator = $this->operator->refresh()->getAttributes();
        $audits = DB::table('audit_events')->count();
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('customer_inquiries'));
        $migration->up();
        $this->assertDatabaseCount('customer_inquiries', 0);
        $this->assertSame($release, $this->release->fresh()->getAttributes());
        $this->assertSame($operator, $this->operator->fresh()->getAttributes());
        $this->assertSame($audits, DB::table('audit_events')->count());
        $this->rejected(fn () => CustomerInquiry::create($this->attributes(['state' => 'read', 'version' => 1])));
        $inquiry = CustomerInquiry::create($this->attributes());
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->delete());
        $this->assertDatabaseCount('customer_inquiries', 1);
    }

    public function test_populated_rollback_refuses_to_erase_inquiries_and_leaves_all_guards_in_place(): void
    {
        $inquiry = CustomerInquiry::create($this->attributes());
        $before = $inquiry->refresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        try {
            $migration->down();
            $this->fail('Retained private inquiries were dropped.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('retention', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('customer_inquiries'));
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->delete());
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->update(['payload_hash' => str_repeat('b', 64)]));
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
    }

    public function test_receipt_and_owner_request_uniqueness_do_not_merge_foreign_sessions_or_erase_parent_evidence(): void
    {
        $attributes = $this->attributes();
        $inquiry = CustomerInquiry::create($attributes);
        $this->rejected(fn () => CustomerInquiry::create($this->attributes(['public_id' => $inquiry->public_id])));
        $this->rejected(fn () => CustomerInquiry::create(array_replace($attributes, ['public_id' => (string) Str::uuid()])));
        $this->rejected(fn () => CustomerInquiry::create(array_replace($attributes, ['public_id' => (string) Str::uuid(), 'owner_hash' => hash('sha256', 'foreign-owner')])));
        CustomerInquiry::create($this->attributes(['owner_hash' => hash('sha256', 'foreign-owner')]));
        CustomerInquiry::create(array_replace($attributes, ['public_id' => (string) Str::uuid(), 'request_key' => (string) Str::uuid()]));
        foreach ([['operator_user_id' => 999999], ['site_release_id' => 999999]] as $orphan) {
            $this->rejected(fn () => CustomerInquiry::create($this->attributes($orphan)));
        }
        $this->rejected(fn () => DB::table('users')->where('id', $this->operator->id)->delete());
        $this->assertDatabaseCount('customer_inquiries', 3);
        foreach ([['public_id'], ['request_key'], ['owner_hash', 'request_key']] as $columns) {
            $this->assertCount(1, array_filter(Schema::getIndexes('customer_inquiries'),
                fn ($index) => $index['unique'] && $index['columns'] === $columns));
        }
        $keys = Schema::getForeignKeys('customer_inquiries');
        $this->assertCount(2, $keys);
        foreach ($keys as $key) {
            $this->assertContains(strtolower($key['on_delete']), ['restrict', 'no action']);
        }
    }

    public function test_sql_cannot_mutate_any_original_input_owner_policy_or_publication_evidence_even_with_a_valid_state_change(): void
    {
        $inquiry = CustomerInquiry::create($this->attributes());
        $before = $inquiry->refresh()->getAttributes();
        $changes = [
            'public_id' => (string) Str::uuid(), 'owner_hash' => str_repeat('b', 64), 'request_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('b', 64), 'payload' => 'different-private-ciphertext',
            'privacy_notice' => 'different-private-notice', 'privacy_notice_hash' => str_repeat('b', 64),
            'retention_policy_reference' => 'DIFFERENT-RETENTION', 'operator_user_id' => LicenseFixtures::admin()->id,
            'site_release_id' => 999999, 'site_content_hash' => str_repeat('b', 64),
            'created_at' => now()->subSecond(),
        ];
        foreach ($changes as $field => $value) {
            $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)
                ->update([$field => $value, 'state' => 'read', 'version' => 1, 'updated_at' => now()->addSecond()]));
            $this->assertSame($before, $inquiry->fresh()->getAttributes(), $field.' changed retained evidence.');
        }
        // On MySQL's usual case-insensitive default collation, policy references must still compare as exact bytes.
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->update([
            'retention_policy_reference' => strtolower($inquiry->retention_policy_reference), 'state' => 'read', 'version' => 1,
        ]));
        try {
            $inquiry->forceFill(['payload' => ['email' => 'changed@example.test']])->save();
            $this->fail('The ORM changed immutable inquiry input.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
    }

    public function test_only_new_records_and_monotonic_versioned_read_or_archive_transitions_are_accepted(): void
    {
        foreach ([['state' => 'read'], ['state' => 'archived'], ['state' => 'NEW'], ['state' => 'new '],
            ['version' => 1], ['version' => -1], ['version' => 1.5]] as $invalid) {
            $this->rejected(fn () => CustomerInquiry::create($this->attributes($invalid)));
        }
        $inquiry = CustomerInquiry::create($this->attributes());
        $before = $inquiry->refresh()->getAttributes();
        foreach ([['state' => 'read'], ['version' => 1], ['state' => 'read', 'version' => 2],
            ['state' => 'new', 'version' => 1], ['state' => 'READ', 'version' => 1],
            ['state' => 'read ', 'version' => 1], ['state' => 'archived ', 'version' => 1], ['state' => 'read', 'version' => 2147483647],
            ['state' => 'read', 'version' => 1, 'updated_at' => now()->subSecond()]] as $invalid) {
            $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->update($invalid));
        }
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        $inquiry->update(['state' => 'read', 'version' => 1, 'updated_at' => now()->addSecond()]);
        $this->assertSame(['read', 1], [$inquiry->fresh()->state, $inquiry->fresh()->version]);
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)
            ->update(['state' => 'archived ', 'version' => 2, 'updated_at' => now()->addSeconds(2)]));
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)
            ->update(['state' => 'archived', 'version' => 2, 'updated_at' => now()]));
        $inquiry->refresh()->update(['state' => 'archived', 'version' => 2, 'updated_at' => now()->addSeconds(2)]);
        $archived = $inquiry->fresh()->getAttributes();
        foreach (['new', 'read', 'archived'] as $state) {
            $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->update(['state' => $state, 'version' => 3]));
        }
        $this->assertSame($archived, $inquiry->fresh()->getAttributes());
        $direct = CustomerInquiry::create($this->attributes());
        $direct->update(['state' => 'archived', 'version' => 1]);
        $this->assertSame('archived', $direct->fresh()->state);
    }

    public function test_original_input_is_encrypted_hidden_from_serialization_and_not_deletable_through_the_orm(): void
    {
        $inquiry = CustomerInquiry::create($this->attributes());
        $before = $inquiry->refresh()->getAttributes();
        $this->assertStringNotContainsString('migration-buyer@example.test', $inquiry->getRawOriginal('payload'));
        $this->assertStringNotContainsString('SYNTHETIC PRIVATE NOTICE', $inquiry->getRawOriginal('privacy_notice'));
        $this->assertSame('migration-buyer@example.test', $inquiry->fresh()->payload['email']);
        $this->assertSame('SYNTHETIC PRIVATE NOTICE', $inquiry->fresh()->privacy_notice);
        foreach (['payload', 'privacy_notice', 'owner_hash', 'request_key', 'payload_hash', 'retention_policy_reference'] as $field) {
            $this->assertArrayNotHasKey($field, $inquiry->attributesToArray());
        }
        try {
            $inquiry->delete();
            $this->fail('The ORM deleted retained private inquiry evidence.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
    }
}
