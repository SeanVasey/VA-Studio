<?php

namespace Tests\Feature;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\Models\InquiryNotificationIntent;
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

/** The same raw SQL cases run on disposable SQLite and MySQL; no provider is invoked. */
class InquiryNotificationMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $operator;

    private int $releaseId;

    private string $releaseHash;

    private CustomerInquiry $recoveryInquiry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $this->operator = LicenseFixtures::admin();
        $release = app(SiteContent::class)->create(SiteContentSchema::forEditing(SiteContentSchema::defaults()),
            'Synthetic notification migration fixture', $this->operator);
        $this->releaseId = $release->id;
        $this->releaseHash = $release->content_hash;
    }

    private function inquiry(): CustomerInquiry
    {
        $payload = ['name' => 'Synthetic Notification Buyer', 'email' => 'notification-buyer@example.test',
            'subject' => 'Synthetic notification subject', 'message' => 'Synthetic private message.', 'website' => ''];

        return CustomerInquiry::create([
            'public_id' => (string) Str::uuid(), 'owner_hash' => hash('sha256', 'synthetic-notification-owner'),
            'request_key' => (string) Str::uuid(), 'payload_hash' => CanonicalJson::hash($payload), 'payload' => $payload,
            'privacy_notice' => 'SYNTHETIC NOTICE', 'privacy_notice_hash' => hash('sha256', 'SYNTHETIC NOTICE'),
            'retention_policy_reference' => 'SYNTHETIC-RETENTION', 'operator_user_id' => $this->operator->id,
            'site_release_id' => $this->releaseId, 'site_content_hash' => $this->releaseHash,
            'state' => 'new', 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attributes(CustomerInquiry $inquiry, array $overrides = []): array
    {
        return array_replace([
            'customer_inquiry_id' => $inquiry->id, 'operator_user_id' => $inquiry->operator_user_id,
            'kind' => 'operator_inbox_v1', 'state' => 'pending', 'attempts' => 0,
            'claim_token' => null, 'lease_expires_at' => null, 'next_attempt_at' => null, 'outcome' => null,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ], $overrides);
    }

    private function intent(): InquiryNotificationIntent
    {
        return InquiryNotificationIntent::create($this->attributes($this->inquiry()));
    }

    /** Compare every raw column and its type; SQL column order is not retained evidence. */
    private function rawAttributes(CustomerInquiry|InquiryNotificationIntent $model): array
    {
        $attributes = $model->getRawOriginal();
        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid notification evidence or transition was accepted by SQL.');
        } catch (QueryException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
    }

    private function update(InquiryNotificationIntent $intent, array $attributes): void
    {
        $this->assertSame(1, DB::table('inquiry_notification_intents')->where('id', $intent->id)->update($attributes));
        $intent->refresh();
    }

    private function claim(InquiryNotificationIntent $intent, int $seconds = 0): void
    {
        $this->update($intent, ['state' => 'processing', 'attempts' => $intent->attempts + 1,
            'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds($seconds + 60),
            'next_attempt_at' => null, 'outcome' => null, 'updated_at' => now()->addSeconds($seconds)]);
    }

    private function finish(string $state, string $outcome, int $seconds = 1): array
    {
        return ['state' => $state, 'claim_token' => null, 'lease_expires_at' => null,
            'next_attempt_at' => null, 'outcome' => $outcome, 'updated_at' => now()->addSeconds($seconds)];
    }

    public function test_every_committed_installation_prefix_can_resume_and_repeated_up_preserves_all_guards(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $parents = $this->recoveryParentEvidence();
        $migration->down();
        $ddl = $this->ddl(fn () => $migration->up());
        $this->assertCount(DB::getDriverName() === 'mysql' ? 9 : 7, $ddl);
        $complete = $this->structure();
        foreach (range(1, count($ddl)) as $stop) {
            $migration->down();
            $this->interruptAfter($stop, fn () => $migration->up());
            $this->assertTrue(Schema::hasTable('inquiry_notification_intents'));
            $this->assertDatabaseCount('inquiry_notification_intents', 0);
            $survivors = $this->triggers();
            $migration->up();
            foreach ($survivors as $name => $definition) {
                $this->assertSame($definition, $this->triggers()[$name], "Installed guard {$name} was replaced after DDL {$stop}.");
            }
            $this->assertSame($complete, $this->structure());
            $this->assertSame([], $this->ddl(fn () => $migration->up()));
            $this->assertSame($parents, $this->recoveryParentEvidence());
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->insert($this->recoveryAttributes(['state' => 'processing', 'attempts' => 1])));
        }
        $inquiry = InquiryNotificationIntent::create($this->recoveryAttributes());
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $inquiry->id)->delete());
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $inquiry->id)->update(['kind' => 'operator_inbox_v2']));
    }

    public function test_fully_protected_retained_table_can_resume_without_any_ddl_or_evidence_change(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        InquiryNotificationIntent::create($this->recoveryAttributes());
        $before = [$this->structure(), $this->recoveryParentEvidence(), $this->rows('inquiry_notification_intents')];
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $this->assertSame([], $this->ddl(fn () => $migration->up()));
        $this->assertSame($before, [$this->structure(), $this->recoveryParentEvidence(), $this->rows('inquiry_notification_intents')]);
    }

    #[DataProvider('missingRetainedProtection')]
    public function test_nonempty_partial_table_is_never_retroactively_adopted(string $part): void
    {
        $this->recoveryInquiry = $this->inquiry();
        InquiryNotificationIntent::create($this->recoveryAttributes());
        if (str_starts_with($part, 'trigger:')) {
            DB::unprepared('DROP TRIGGER inquiry_notification_intents_'.substr($part, strlen('trigger:')));
        } elseif ($part === 'index') {
            $this->preserveCustomerForeignKeySupport();
            Schema::table('inquiry_notification_intents', fn ($table) => $table->dropUnique('inquiry_notification_intents_customer_inquiry_id_unique'));
        }
        $this->migrationRefused(fn () => (require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php'))->up());
    }

    public static function missingRetainedProtection(): array
    {
        return ['insert' => ['trigger:insert'], 'update' => ['trigger:update'], 'delete' => ['trigger:delete'], 'unique index' => ['index']];
    }

    public function test_nonempty_notification_table_with_a_missing_mysql_foreign_key_is_never_retroactively_adopted(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('SQLite foreign keys are inline; interrupted foreign-key DDL is a MySQL path.');
        }
        InquiryNotificationIntent::create($this->recoveryAttributes());
        Schema::table('inquiry_notification_intents', fn ($table) => $table->dropForeign('inquiry_notification_intents_operator_user_id_foreign'));
        $this->migrationRefused(fn () => (require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php'))->up());
    }

    public function test_every_empty_rollback_prefix_and_absent_table_can_resume_without_changing_parent_evidence(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $parents = $this->recoveryParentEvidence();
        foreach (range(1, 4) as $stop) {
            $this->interruptAfter($stop, fn () => $migration->down());
            $migration->down();
            $this->assertFalse(Schema::hasTable('inquiry_notification_intents'));
            $this->assertSame([], $this->ddl(fn () => $migration->down()));
            $this->assertSame($parents, $this->recoveryParentEvidence());
            $migration->up();
        }
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->insert($this->recoveryAttributes(['state' => 'processing'])));
    }

    #[DataProvider('foreignShape')]
    public function test_foreign_schema_or_guard_residue_is_refused_before_any_ddl(string $part): void
    {
        $this->recoveryInquiry = $this->inquiry();
        if ($part === 'column') {
            Schema::table('inquiry_notification_intents', fn ($table) => $table->string('foreign_column')->nullable());
        } elseif ($part === 'index') {
            $this->preserveCustomerForeignKeySupport();
            Schema::table('inquiry_notification_intents', function ($table): void {
                $table->dropUnique('inquiry_notification_intents_customer_inquiry_id_unique');
                $table->index('customer_inquiry_id', 'inquiry_notification_intents_customer_inquiry_id_unique');
            });
        } else {
            $name = $part === 'extra trigger' ? 'inquiry_notification_intents_foreign' : 'inquiry_notification_intents_update';
            if ($part !== 'extra trigger') {
                DB::unprepared('DROP TRIGGER '.$name);
            }
            DB::unprepared(DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER {$name} BEFORE UPDATE ON inquiry_notification_intents BEGIN SELECT 1; END"
                : "CREATE TRIGGER {$name} BEFORE UPDATE ON inquiry_notification_intents FOR EACH ROW BEGIN SET @synthetic_inquiry_collision = 1; END");
        }
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $this->migrationRefused(fn () => $migration->up());
        $this->migrationRefused(fn () => $migration->down());
    }

    public static function foreignShape(): array
    {
        return ['extra column' => ['column'], 'wrong unique index' => ['index'], 'wrong guard body' => ['guard'], 'additional table trigger' => ['extra trigger']];
    }

    public function test_changed_mysql_notification_foreign_key_is_refused_before_any_ddl(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Changed foreign-key DDL is a MySQL shape check; SQLite checks the complete original table SQL.');
        }
        Schema::table('inquiry_notification_intents', function ($table): void {
            $table->dropForeign('inquiry_notification_intents_operator_user_id_foreign');
            $table->foreign('operator_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $this->migrationRefused(fn () => $migration->up());
        $this->migrationRefused(fn () => $migration->down());
    }

    public function test_shortened_mysql_notification_due_index_prefix_is_refused_before_any_ddl(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Prefix indexes are a MySQL shape check.');
        }
        DB::unprepared('ALTER TABLE inquiry_notification_intents DROP INDEX inquiry_notifications_due, ADD INDEX inquiry_notifications_due (state(8), next_attempt_at, id)');
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $this->migrationRefused(fn () => $migration->up());
        $this->migrationRefused(fn () => $migration->down());
    }

    public function test_missing_owned_guard_does_not_allow_foreign_named_guard_to_be_overwritten(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        DB::unprepared('DROP TRIGGER inquiry_notification_intents_insert');
        DB::unprepared('DROP TRIGGER inquiry_notification_intents_delete');
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER inquiry_notification_intents_delete BEFORE DELETE ON users BEGIN SELECT 1; END'
            : 'CREATE TRIGGER inquiry_notification_intents_delete BEFORE DELETE ON users FOR EACH ROW BEGIN SET @synthetic_inquiry_collision = 1; END');
        $this->migrationRefused(fn () => (require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php'))->up());
        $this->assertArrayNotHasKey('inquiry_notification_intents_insert', $this->triggers());
    }

    public function test_changed_mysql_notification_fk_support_index_in_a_partial_installation_is_refused_before_any_ddl(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The implicit foreign-key support index and separate FK DDL are MySQL paths.');
        }
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $migration->down();
        $this->interruptAfter(2, fn () => $migration->up());
        // Retain the exact customer FK but substitute a differently shaped same-name support.
        // This is not a missing owned part and must never be silently adopted or normalized.
        DB::unprepared('ALTER TABLE inquiry_notification_intents DROP FOREIGN KEY inquiry_notification_intents_customer_inquiry_id_foreign');
        DB::unprepared('ALTER TABLE inquiry_notification_intents DROP INDEX inquiry_notification_intents_customer_inquiry_id_foreign, ADD INDEX inquiry_notification_intents_customer_inquiry_id_foreign (customer_inquiry_id, operator_user_id)');
        DB::unprepared('ALTER TABLE inquiry_notification_intents ADD CONSTRAINT inquiry_notification_intents_customer_inquiry_id_foreign FOREIGN KEY (customer_inquiry_id) REFERENCES customer_inquiries(id) ON DELETE RESTRICT');
        $this->migrationRefused(fn () => $migration->up());
        $this->migrationRefused(fn () => $migration->down());
    }

    public function test_unrelated_table_with_an_owned_schema_wide_name_is_refused_before_creating_notifications(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $migration->down();
        Schema::create('synthetic_inquiry_foreign_name', function ($table): void {
            $table->id();
            if (DB::getDriverName() === 'sqlite') {
                $table->index('id', 'inquiry_notification_intents_customer_inquiry_id_unique');
            } else {
                $table->unsignedBigInteger('operator_user_id');
                $table->foreign('operator_user_id', 'inquiry_notification_intents_operator_user_id_foreign')->references('id')->on('users')->restrictOnDelete();
            }
        });
        $before = [Schema::getColumns('synthetic_inquiry_foreign_name'), Schema::getIndexes('synthetic_inquiry_foreign_name'),
            Schema::getForeignKeys('synthetic_inquiry_foreign_name'), $this->recoveryParentEvidence()];
        $ddl = $this->ddl(function () use ($migration): void {
            try {
                $migration->up();
                $this->fail('The foreign schema-wide name was adopted.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('foreign', $error->getMessage());
            }
        });
        $this->assertSame([], $ddl);
        $this->assertFalse(Schema::hasTable('inquiry_notification_intents'));
        $this->assertSame($before, [Schema::getColumns('synthetic_inquiry_foreign_name'), Schema::getIndexes('synthetic_inquiry_foreign_name'),
            Schema::getForeignKeys('synthetic_inquiry_foreign_name'), $this->recoveryParentEvidence()]);
    }

    public function test_foreign_minimal_table_is_not_repaired_or_dropped_as_an_owned_partial_table(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $migration->down();
        Schema::create('inquiry_notification_intents', fn ($table) => $table->id());
        $this->migrationRefused(fn () => $migration->up());
        $this->migrationRefused(fn () => $migration->down());
        $this->assertTrue(Schema::hasTable('inquiry_notification_intents'));
    }

    public function test_differently_cased_foreign_table_is_neither_adopted_nor_dropped(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        if (DB::getDriverName() === 'mysql' && (int) DB::selectOne('SELECT @@lower_case_table_names AS value')->value === 0) {
            // A complete empty owned table must not hide a second case-folded match.
            Schema::create('INQUIRY_NOTIFICATION_INTENTS', function ($table): void {
                $table->id();
                $table->text('foreign_private_evidence');
            });
            DB::table('INQUIRY_NOTIFICATION_INTENTS')->insert(['foreign_private_evidence' => 'Synthetic notification sibling evidence must survive.']);
            $before = [$this->schemaRows(), $this->rows('inquiry_notification_intents'), $this->rows('INQUIRY_NOTIFICATION_INTENTS'), $this->recoveryParentEvidence()];
            try {
                foreach ([fn () => $migration->up(), fn () => $migration->down()] as $operation) {
                    $ddl = $this->ddl(function () use ($operation): void {
                        try {
                            $operation();
                            $this->fail('A case-folded foreign sibling was hidden by the canonical notification table.');
                        } catch (LogicException $error) {
                            $this->assertStringContainsString('table identity', $error->getMessage());
                        }
                    });
                    $this->assertSame([], $ddl);
                    $this->assertSame($before, [$this->schemaRows(), $this->rows('inquiry_notification_intents'), $this->rows('INQUIRY_NOTIFICATION_INTENTS'), $this->recoveryParentEvidence()]);
                }
            } finally {
                Schema::dropIfExists('INQUIRY_NOTIFICATION_INTENTS');
            }
        }
        $migration->down();
        Schema::create('INQUIRY_NOTIFICATION_INTENTS', function ($table): void {
            $table->id();
            $table->text('foreign_private_evidence');
        });
        DB::table('INQUIRY_NOTIFICATION_INTENTS')->insert(['foreign_private_evidence' => 'Synthetic foreign evidence must survive.']);
        $before = [$this->schemaRows(), $this->rows('INQUIRY_NOTIFICATION_INTENTS'), $this->recoveryParentEvidence()];
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
            $this->assertSame($before, [$this->schemaRows(), $this->rows('INQUIRY_NOTIFICATION_INTENTS'), $this->recoveryParentEvidence()]);
        }
    }

    #[DataProvider('uppercaseForeignNames')]
    public function test_differently_cased_schema_wide_names_are_refused_before_any_create(string $part): void
    {
        $this->recoveryInquiry = $this->inquiry();
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        if ($part === 'trigger' && DB::getDriverName() === 'mysql') {
            Schema::create('synthetic_notification_guard_sibling', function ($table): void {
                $table->id();
                $table->text('foreign_private_evidence');
            });
            DB::table('synthetic_notification_guard_sibling')->insert(['foreign_private_evidence' => 'Synthetic notification sibling guard evidence must survive.']);
            $before = [$this->schemaRows(), $this->rows('synthetic_notification_guard_sibling'), $this->rows('inquiry_notification_intents'), $this->recoveryParentEvidence()];
            $canonical = $this->triggers();
            try {
                // MySQL's data dictionary rejects case-only duplicates in its schema-wide
                // trigger namespace, even when the second trigger targets another table.
                try {
                    DB::unprepared('CREATE TRIGGER INQUIRY_NOTIFICATION_INTENTS_DELETE BEFORE DELETE ON synthetic_notification_guard_sibling FOR EACH ROW BEGIN SET @synthetic_inquiry_collision = 1; END');
                    $this->fail('MySQL accepted a case-only duplicate trigger name.');
                } catch (QueryException $error) {
                    $this->assertSame('HY000', $error->errorInfo[0]);
                    $this->assertSame(1359, $error->errorInfo[1]);
                }
                $this->assertSame($before, [$this->schemaRows(), $this->rows('synthetic_notification_guard_sibling'), $this->rows('inquiry_notification_intents'), $this->recoveryParentEvidence()]);
                $this->assertSame($canonical, $this->triggers());
            } finally {
                Schema::dropIfExists('synthetic_notification_guard_sibling');
            }
        }
        $migration->down();
        Schema::create('synthetic_inquiry_case_collision', function ($table) use ($part): void {
            $table->id();
            $table->text('foreign_private_evidence');
            if ($part === 'index') {
                if (DB::getDriverName() === 'sqlite') {
                    $table->index('id', 'INQUIRY_NOTIFICATION_INTENTS_CUSTOMER_INQUIRY_ID_UNIQUE');
                } else {
                    $table->unsignedBigInteger('operator_user_id');
                    $table->foreign('operator_user_id', 'INQUIRY_NOTIFICATION_INTENTS_OPERATOR_USER_ID_FOREIGN')->references('id')->on('users')->restrictOnDelete();
                }
            }
        });
        if ($part === 'trigger') {
            DB::unprepared(DB::getDriverName() === 'sqlite'
                ? 'CREATE TRIGGER INQUIRY_NOTIFICATION_INTENTS_DELETE BEFORE DELETE ON synthetic_inquiry_case_collision BEGIN SELECT 1; END'
                : 'CREATE TRIGGER INQUIRY_NOTIFICATION_INTENTS_DELETE BEFORE DELETE ON synthetic_inquiry_case_collision FOR EACH ROW BEGIN SET @synthetic_inquiry_collision = 1; END');
        }
        $attributes = ['foreign_private_evidence' => 'Synthetic foreign collision evidence.'];
        if ($part === 'index' && DB::getDriverName() === 'mysql') {
            $attributes['operator_user_id'] = $this->operator->id;
        }
        DB::table('synthetic_inquiry_case_collision')->insert($attributes);
        $before = [$this->schemaRows(), $this->rows('synthetic_inquiry_case_collision'), $this->recoveryParentEvidence()];
        $ddl = $this->ddl(function () use ($migration): void {
            try {
                $migration->up();
                $this->fail('A differently cased foreign schema-wide name was accepted.');
            } catch (LogicException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        });
        $this->assertSame([], $ddl);
        $this->assertFalse(Schema::hasTable('inquiry_notification_intents'));
        $this->assertSame($before, [$this->schemaRows(), $this->rows('synthetic_inquiry_case_collision'), $this->recoveryParentEvidence()]);
    }

    public static function uppercaseForeignNames(): array
    {
        return ['SQLite global index or MySQL global foreign key' => ['index'], 'global trigger' => ['trigger']];
    }

    public function test_temporary_objects_cannot_hide_retained_inquiries_or_shadow_owned_schema_names(): void
    {
        $this->recoveryInquiry = $this->inquiry();
        InquiryNotificationIntent::create($this->recoveryAttributes());
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $before = [$this->schemaRows(), $this->rows('inquiry_notification_intents'), $this->recoveryParentEvidence()];
        // MySQL has temporary table shadows; SQLite also permits temporary indexes and
        // temporary triggers whose names can intercept an unqualified schema operation.
        $parts = DB::getDriverName() === 'sqlite' ? ['empty table', 'populated table', 'empty parent', 'populated parent', 'index', 'trigger'] : ['empty table', 'populated table', 'empty parent', 'populated parent'];
        foreach ($parts as $part) {
            $temporaryTable = str_ends_with($part, 'table') ? 'inquiry_notification_intents'
                : (str_ends_with($part, 'parent') ? 'customer_inquiries' : 'synthetic_temporary_inquiry_holder');
            $wrapped = DB::connection()->getQueryGrammar()->wrapTable($temporaryTable);
            DB::unprepared('CREATE TEMPORARY TABLE '.$wrapped.' (id BIGINT, private_evidence TEXT)');
            if (! str_starts_with($part, 'empty ')) {
                DB::table($temporaryTable)->insert(['id' => 42, 'private_evidence' => 'Synthetic retained temporary evidence.']);
            }
            if ($part === 'index') {
                DB::unprepared('CREATE INDEX INQUIRY_NOTIFICATION_INTENTS_CUSTOMER_INQUIRY_ID_UNIQUE ON synthetic_temporary_inquiry_holder(id)');
            } elseif ($part === 'trigger') {
                DB::unprepared('CREATE TEMP TRIGGER INQUIRY_NOTIFICATION_INTENTS_DELETE BEFORE DELETE ON main.inquiry_notification_intents BEGIN SELECT 1; END');
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
                    DB::unprepared('DROP TRIGGER temp.INQUIRY_NOTIFICATION_INTENTS_DELETE');
                }
                DB::unprepared(DB::getDriverName() === 'sqlite'
                    ? 'DROP TABLE '.DB::connection()->getQueryGrammar()->wrapTable('temp.'.$temporaryTable)
                    : 'DROP TEMPORARY TABLE '.$wrapped);
            }
            $this->assertSame($before, [$this->schemaRows(), $this->rows('inquiry_notification_intents'), $this->recoveryParentEvidence()]);
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->delete());
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

    private function preserveCustomerForeignKeySupport(): void
    {
        if (DB::getDriverName() === 'mysql' && ! in_array('inquiry_notification_intents_customer_inquiry_id_foreign',
            array_column(Schema::getIndexes('inquiry_notification_intents'), 'name'), true)) {
            DB::unprepared('ALTER TABLE inquiry_notification_intents ADD INDEX inquiry_notification_intents_customer_inquiry_id_foreign (customer_inquiry_id)');
        }
    }

    private function recoveryAttributes(array $overrides = []): array
    {
        return $this->attributes($this->recoveryInquiry, $overrides);
    }

    private function recoveryParentEvidence(): array
    {
        return [$this->rows('customer_inquiries'), $this->rows('users'), $this->rows('site_releases'), $this->rows('audit_events')];
    }

    private function structure(): array
    {
        return [Schema::getColumns('inquiry_notification_intents'), Schema::getIndexes('inquiry_notification_intents'), Schema::getForeignKeys('inquiry_notification_intents'), $this->triggers()];
    }

    private function triggers(): array
    {
        $rows = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'inquiry_notification_intents_%')->orderBy('name')->get()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'like', 'inquiry_notification_intents_%')->orderBy('TRIGGER_NAME')->get();
        $result = [];
        foreach ($rows as $row) {
            $result[DB::getDriverName() === 'sqlite' ? $row->name : $row->TRIGGER_NAME] = DB::getDriverName() === 'sqlite'
                ? [$row->tbl_name, $row->sql] : [$row->EVENT_OBJECT_TABLE, $row->ACTION_TIMING, $row->EVENT_MANIPULATION, $row->ACTION_STATEMENT];
        }

        return $result;
    }

    private function migrationRefused(callable $operation): void
    {
        $before = [$this->structure(), $this->recoveryParentEvidence(), $this->rows('inquiry_notification_intents'), $this->schemaRows()];
        $ddl = $this->ddl(function () use ($operation): void {
            try {
                $operation();
                $this->fail('A foreign or unprotected migration state was accepted.');
            } catch (LogicException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        });
        $this->assertSame([], $ddl);
        $this->assertSame($before, [$this->structure(), $this->recoveryParentEvidence(), $this->rows('inquiry_notification_intents'), $this->schemaRows()]);
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

    public function test_empty_rollback_reapply_retains_parent_records_and_restores_guards(): void
    {
        $inquiry = $this->inquiry();
        $before = $this->rawAttributes($inquiry);
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('inquiry_notification_intents'));
        $migration->up();
        $this->assertSame($before, $this->rawAttributes($inquiry->fresh()));
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->insert($this->attributes($inquiry, ['kind' => 'operator_inbox_v1 '])));
        InquiryNotificationIntent::create($this->attributes($inquiry));
        $this->assertDatabaseCount('inquiry_notification_intents', 1);
    }

    public function test_populated_rollback_refuses_deletion_and_leaves_identity_and_state_guards_active(): void
    {
        $intent = $this->intent();
        $before = $this->rawAttributes($intent);
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        try {
            $migration->down();
            $this->fail('Retained notification evidence was erased.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('retention', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('inquiry_notification_intents'));
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->delete());
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(['state' => 'submitted']));
        $this->assertSame($before, $this->rawAttributes($intent->fresh()));
    }

    public function test_one_id_only_intent_per_inquiry_has_the_original_operator_and_restrictive_foreign_keys(): void
    {
        $intent = $this->intent();
        $inquiry = CustomerInquiry::findOrFail($intent->customer_inquiry_id);
        $attributes = $this->attributes($inquiry);
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->insert($attributes));
        $other = $this->inquiry();
        foreach ([['operator_user_id' => LicenseFixtures::admin()->id], ['operator_user_id' => 999999],
            ['customer_inquiry_id' => 999999]] as $invalid) {
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->insert($this->attributes($other, $invalid)));
        }
        $this->rejected(fn () => DB::table('users')->where('id', $this->operator->id)->delete());
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->delete());
        $columns = Schema::getColumnListing('inquiry_notification_intents');
        sort($columns);
        $expected = ['id', 'customer_inquiry_id', 'operator_user_id', 'kind', 'state', 'attempts', 'claim_token',
            'lease_expires_at', 'next_attempt_at', 'outcome', 'created_at', 'updated_at'];
        sort($expected);
        $this->assertSame($expected, $columns);
        $this->assertStringNotContainsString('notification-buyer@example.test', json_encode($this->rawAttributes($intent), JSON_THROW_ON_ERROR));
        $this->assertCount(1, array_filter(Schema::getIndexes('inquiry_notification_intents'),
            fn ($index) => $index['unique'] && $index['columns'] === ['customer_inquiry_id']));
        $keys = Schema::getForeignKeys('inquiry_notification_intents');
        $this->assertCount(2, $keys);
        foreach ($keys as $key) {
            $this->assertContains(strtolower($key['on_delete']), ['restrict', 'no action']);
        }
    }

    public function test_insert_guards_reject_nonempty_nonpending_padded_or_unknown_values(): void
    {
        $inquiry = $this->inquiry();
        foreach ([['kind' => 'operator_inbox_v2'], ['kind' => 'OPERATOR_INBOX_V1'], ['kind' => "operator_inbox_v1\0"],
            ['state' => 'PENDING'], ['state' => 'pending '], ['state' => 'retry'], ['state' => 'processing'],
            ['attempts' => 1], ['attempts' => -1], ['attempts' => 1.5], ['claim_token' => (string) Str::uuid()],
            ['lease_expires_at' => now()->addMinute()], ['next_attempt_at' => now()->addMinute()],
            ['outcome' => 'authority_withdrawn'], ['updated_at' => now()->addSecond()],
            ['created_at' => now()->subSecond()], ['created_at' => 'invalid-date', 'updated_at' => 'invalid-date'],
            ['created_at' => '2026-02-31 00:00:00', 'updated_at' => '2026-02-31 00:00:00']] as $invalid) {
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->insert($this->attributes($inquiry, $invalid)));
            $this->assertDatabaseCount('inquiry_notification_intents', 0);
        }
    }

    public function test_identity_cannot_be_rewritten_even_with_an_otherwise_valid_claim(): void
    {
        $intent = $this->intent();
        $before = $this->rawAttributes($intent);
        $other = $this->inquiry();
        $claim = ['state' => 'processing', 'attempts' => 1, 'claim_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinute(), 'updated_at' => now()];
        foreach (['id' => $intent->id + 10000, 'customer_inquiry_id' => $other->id,
            'operator_user_id' => LicenseFixtures::admin()->id, 'kind' => 'OPERATOR_INBOX_V1',
            'created_at' => now()->subSecond()] as $column => $value) {
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($claim, [$column => $value])));
            $this->assertSame($before, $this->rawAttributes($intent->fresh()), $column.' changed immutable evidence.');
        }
    }

    public function test_claim_requires_exact_uuid_one_attempt_and_an_unexpired_lease(): void
    {
        $intent = $this->intent();
        $before = $this->rawAttributes($intent);
        $uuid = '12345678-9abc-4def-8123-123456789abc';
        $claim = ['state' => 'processing', 'attempts' => 1, 'claim_token' => $uuid, 'lease_expires_at' => now()->addMinute()];
        foreach ([['state' => 'processing '], ['state' => 'PROCESSING'], ['attempts' => 0], ['attempts' => 2], ['attempts' => 1.5],
            ['claim_token' => null], ['claim_token' => str_repeat('a', 36)], ['claim_token' => strtoupper($uuid)],
            ['claim_token' => $uuid.' '], ['claim_token' => $uuid."\0"], ['claim_token' => $uuid."\n"],
            ['claim_token' => $uuid.str_repeat(' ', 28)], ['claim_token' => $uuid.str_repeat(' ', 29)],
            ['claim_token' => "\0".substr($uuid, 1)], ['claim_token' => 'é'.substr($uuid, 1)],
            ['lease_expires_at' => null], ['lease_expires_at' => 'invalid-date'], ['lease_expires_at' => '2027-02-31 00:00:00'],
            ['lease_expires_at' => now()], ['next_attempt_at' => now()->addMinute()],
            ['outcome' => 'handed_off'], ['updated_at' => now()->subSecond()]] as $invalid) {
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($claim, $invalid)));
            $this->assertSame($before, $this->rawAttributes($intent->fresh()));
        }
        $this->claim($intent);
        $processing = $this->rawAttributes($intent);
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(['claim_token' => (string) Str::uuid(), 'attempts' => 2]));
        $this->assertSame($processing, $this->rawAttributes($intent->fresh()));
        $this->assertArrayNotHasKey('claim_token', $intent->attributesToArray());
    }

    public function test_only_definite_non_submission_may_retry_and_due_retry_stops_after_three_claims(): void
    {
        $intent = $this->intent();
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $start = ($attempt - 1) * 20;
            $this->claim($intent, $start);
            $before = $this->rawAttributes($intent);
            $retry = array_replace($this->finish('retry', 'definitely_not_submitted', $start + 1),
                ['next_attempt_at' => now()->addSeconds($start + 20)]);
            if ($attempt === 3) {
                $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update($retry));
                $this->assertSame($before, $this->rawAttributes($intent->fresh()));
                $this->update($intent, $this->finish('blocked', 'retry_exhausted', $start + 1));
                break;
            }
            foreach ([['outcome' => 'handoff_uncertain'], ['outcome' => 'definitely_not_submitted '],
                ['next_attempt_at' => null], ['next_attempt_at' => now()->addSeconds($start + 1)],
                ['claim_token' => (string) Str::uuid()], ['attempts' => $attempt + 1]] as $invalid) {
                $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($retry, $invalid)));
                $this->assertSame($before, $this->rawAttributes($intent->fresh()));
            }
            $this->update($intent, $retry);
            $retryBefore = $this->rawAttributes($intent);
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update([
                'state' => 'processing', 'attempts' => $attempt + 1, 'claim_token' => (string) Str::uuid(),
                'lease_expires_at' => now()->addSeconds($start + 80), 'next_attempt_at' => null, 'outcome' => null,
                'updated_at' => now()->addSeconds($start + 19),
            ]));
            $this->assertSame($retryBefore, $this->rawAttributes($intent->fresh()));
        }
        $this->assertSame(['blocked', 3, 'retry_exhausted'], [$intent->state, $intent->attempts, $intent->outcome]);
    }

    public function test_active_claim_completes_once_and_ambiguous_outcomes_never_become_retryable(): void
    {
        foreach ([['submitted', 'handed_off'], ['unknown', 'handoff_uncertain'], ['blocked', 'authority_withdrawn'],
            ['blocked', 'configuration_withdrawn']] as [$state, $outcome]) {
            $intent = $this->intent();
            $this->claim($intent);
            $before = $this->rawAttributes($intent);
            $finish = $this->finish($state, $outcome);
            foreach ([['outcome' => 'private provider exception text'], ['outcome' => $outcome.' '],
                ['claim_token' => $intent->claim_token], ['lease_expires_at' => $intent->lease_expires_at],
                ['next_attempt_at' => now()->addMinute()], ['attempts' => 2], ['updated_at' => now()->addMinute()]] as $invalid) {
                $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($finish, $invalid)));
                $this->assertSame($before, $this->rawAttributes($intent->fresh()));
            }
            $this->update($intent, $finish);
            $terminal = $this->rawAttributes($intent);
            foreach ([$finish, ['state' => 'processing', 'attempts' => 2, 'claim_token' => (string) Str::uuid(),
                'lease_expires_at' => now()->addMinutes(2), 'outcome' => null, 'updated_at' => now()->addSecond()],
                array_replace($this->finish('retry', 'definitely_not_submitted'), ['next_attempt_at' => now()->addMinute()])] as $invalid) {
                $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update($invalid));
                $this->assertSame($terminal, $this->rawAttributes($intent->fresh()));
            }
        }
    }

    public function test_expired_claim_becomes_unknown_without_reclaim_or_definite_outcome(): void
    {
        $intent = $this->intent();
        $this->claim($intent);
        $before = $this->rawAttributes($intent);
        $expired = $this->finish('unknown', 'lease_expired', 60);
        foreach ([['updated_at' => now()->addSeconds(59)], ['outcome' => 'handoff_uncertain'],
            ['state' => 'submitted', 'outcome' => 'handed_off'], ['state' => 'blocked', 'outcome' => 'authority_withdrawn'],
            ['state' => 'processing', 'attempts' => 2, 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinutes(2), 'outcome' => null],
            ['state' => 'retry', 'outcome' => 'definitely_not_submitted', 'next_attempt_at' => now()->addMinutes(2)]] as $invalid) {
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($expired, $invalid)));
            $this->assertSame($before, $this->rawAttributes($intent->fresh()));
        }
        $this->update($intent, $expired);
        $this->assertSame(['unknown', 1, 'lease_expired'], [$intent->state, $intent->attempts, $intent->outcome]);
    }

    public function test_authority_withdrawal_blocks_pending_or_scheduled_retry_without_consuming_an_attempt(): void
    {
        foreach ([false, true] as $retry) {
            $intent = $this->intent();
            if ($retry) {
                $this->claim($intent);
                $this->update($intent, array_replace($this->finish('retry', 'definitely_not_submitted'), ['next_attempt_at' => now()->addMinute()]));
            }
            $before = $this->rawAttributes($intent);
            $blocked = $this->finish('blocked', 'authority_withdrawn', 2);
            foreach ([['outcome' => 'retry_exhausted'], ['outcome' => 'configuration_withdrawn'], ['attempts' => $intent->attempts + 1],
                ['next_attempt_at' => now()->addMinute()]] as $invalid) {
                $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($blocked, $invalid)));
                $this->assertSame($before, $this->rawAttributes($intent->fresh()));
            }
            $this->update($intent, $blocked);
            $this->assertSame($retry ? 1 : 0, $intent->attempts);
        }
    }

    public function test_orm_rejects_identity_deletion_and_invalid_state_changes_but_accepts_valid_claim_and_handoff(): void
    {
        $intent = $this->intent();
        $before = $this->rawAttributes($intent);
        foreach ([fn () => $intent->delete(), fn () => $intent->forceFill(['kind' => 'operator_inbox_v2'])->save(),
            fn () => $intent->fresh()->forceFill(['state' => 'pending '])->save(),
            fn () => $intent->fresh()->forceFill(['state' => 'submitted', 'outcome' => 'handed_off'])->save()] as $operation) {
            try {
                $operation();
                $this->fail('The ORM accepted contradictory notification evidence.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame($before, $this->rawAttributes($intent->fresh()));
        }
        $intent->refresh()->update(['state' => 'processing', 'attempts' => 1, 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinute()]);
        $intent->update($this->finish('submitted', 'handed_off'));
        $this->assertSame(['submitted', 1, 'handed_off'], [$intent->state, $intent->attempts, $intent->outcome]);
        $inquiry = $this->inquiry();
        foreach ([['operator_user_id' => LicenseFixtures::admin()->id], ['kind' => 'operator_inbox_v1 '], ['state' => 'processing'], ['attempts' => 1]] as $invalid) {
            try {
                InquiryNotificationIntent::create($this->attributes($inquiry, $invalid));
                $this->fail('The ORM accepted invalid notification creation.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_notification_insert_rolls_back_the_new_inquiry_in_the_same_transaction(): void
    {
        $inquiries = DB::table('customer_inquiries')->count();
        $audits = DB::table('audit_events')->count();
        $this->rejected(fn () => DB::transaction(function (): void {
            $inquiry = $this->inquiry();
            DB::table('inquiry_notification_intents')->insert($this->attributes($inquiry, ['operator_user_id' => 999999]));
        }));
        $this->assertDatabaseCount('customer_inquiries', $inquiries);
        $this->assertDatabaseCount('inquiry_notification_intents', 0);
        $this->assertSame($audits, DB::table('audit_events')->count());
    }
}
