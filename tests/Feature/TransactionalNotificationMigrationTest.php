<?php

namespace Tests\Feature;

use App\Domain\Notifications\NotificationException;
use App\Domain\Notifications\TestTransactionalNotifications;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\TransactionalNotificationFixtures as F;
use Tests\TestCase;

class TransactionalNotificationMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const NOTICES = 'transactional_notices';

    private const ATTEMPTS = 'transactional_notice_attempts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_231000_test_transactional_notifications.php');
    }

    public function test_empty_owned_rollback_reinstall_and_absent_rollback_preserve_the_complete_guard_set(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasTable(self::NOTICES));
        $this->assertFalse(Schema::hasTable(self::ATTEMPTS));
        $this->assertSame([], $this->guards());
        $migration->down();
        $migration->up();
        $this->assertCount(6, $this->guards());
        $f = F::ready();
        $this->assertSame('accepted', app(TestTransactionalNotifications::class)->dispatch($f['notice']['notificationId'])['state']);
        $this->assertDatabaseCount(self::NOTICES, 1);
        $this->assertDatabaseCount(self::ATTEMPTS, 1);
    }

    public function test_existing_owned_and_populated_schema_is_not_adopted_or_erased(): void
    {
        $guards = $this->guards();
        $this->refused(fn () => $this->migration()->up());
        $this->assertSame($guards, $this->guards());
        $f = F::ready();
        app(TestTransactionalNotifications::class)->claim($f['notice']['notificationId']);
        $rows = $this->rows();
        $this->refused(fn () => $this->migration()->down());
        $this->assertSame($rows, $this->rows());
        $this->assertSame($guards, $this->guards());
        $this->queryRefused(fn () => DB::table(self::ATTEMPTS)->delete());
        $this->queryRefused(fn () => DB::table(self::NOTICES)->delete());
    }

    public function test_owned_tables_keep_restrictive_foreign_keys_with_a_different_mysql_default_engine(): void
    {
        $migration = $this->migration();
        $migration->down();
        $original = DB::getDriverName() === 'mysql' ? DB::selectOne('SELECT @@SESSION.default_storage_engine AS engine')->engine : null;
        try {
            if ($original !== null) {
                DB::statement("SET SESSION default_storage_engine = 'MyISAM'");
                $this->assertSame('MyISAM', DB::selectOne('SELECT @@SESSION.default_storage_engine AS engine')->engine);
            }
            $migration->up();
            $this->assertCount(5, Schema::getForeignKeys(self::NOTICES));
            $this->assertCount(1, Schema::getForeignKeys(self::ATTEMPTS));
            foreach ([self::NOTICES, self::ATTEMPTS] as $table) {
                $this->assertCount(3, array_filter($this->guards(), fn ($guard) => ($guard['tbl_name'] ?? $guard['EVENT_OBJECT_TABLE']) === $table));
                if ($original !== null) {
                    $this->assertSame('InnoDB', DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->sole()->ENGINE);
                }
            }
            $migration->down();
            $this->assertFalse(Schema::hasTable(self::NOTICES));
            $this->assertFalse(Schema::hasTable(self::ATTEMPTS));
        } finally {
            if ($original !== null) {
                DB::statement('SET SESSION default_storage_engine = ?', [$original]);
            }
        }
    }

    public function test_every_notice_field_and_deletion_is_immutable_even_for_a_noop_raw_update(): void
    {
        F::ready();
        $row = (array) DB::table(self::NOTICES)->sole();
        foreach ($row as $field => $value) {
            $this->queryRefused(fn () => DB::table(self::NOTICES)->where('id', $row['id'])->update([$field => $value]));
            $this->assertSame($row, (array) DB::table(self::NOTICES)->sole(), $field);
        }
        $this->queryRefused(fn () => DB::table(self::NOTICES)->delete());
        $this->assertSame($row, (array) DB::table(self::NOTICES)->sole());
    }

    public function test_unique_notice_collisions_never_replace_original_rows_without_recursive_delete_triggers(): void
    {
        F::ready();
        $other = F::ready(enqueue: false, suffix: 'TWO');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers=OFF');
        }
        $row = (array) DB::table(self::NOTICES)->sole();
        foreach (['id', 'public_id', 'event_key'] as $collision) {
            $source = $collision === 'event_key' ? $row : array_replace($row, [
                'account_id' => $other['account']->id, 'user_id' => $other['user']->id, 'order_id' => $other['order']->id,
                'activation_id' => $other['fulfillment_activation']->id,
                'event_key' => 'test_order_ready:'.$other['fulfillment_activation']->public_id.':'.$other['account']->public_id]);
            $replacement = array_replace($source, ['id' => 100000, 'public_id' => (string) Str::uuid(), $collision => $row[$collision]]);
            $this->queryRefused(fn () => $this->replace(self::NOTICES, $replacement));
            $this->assertSame([$row], $this->rows()[self::NOTICES]);
        }
    }

    public function test_raw_insert_requires_exact_notice_identity_policy_hashes_authority_and_activation(): void
    {
        $f = F::ready(enqueue: false);
        // A genuine domain enqueue supplies the positive canonical source shape.
        $id = app(TestTransactionalNotifications::class)->enqueueOrderReady($f['order']->public_id, $f['principal'])['notificationId'];
        $row = (array) DB::table(self::NOTICES)->where('public_id', $id)->sole();
        // Insert guards are tested in a separate otherwise-valid activated source, not by colliding with the existing event.
        $other = F::ready(enqueue: false, suffix: 'TWO');
        $valid = array_replace($row, ['id' => 100000, 'public_id' => (string) Str::uuid(),
            'account_id' => $other['account']->id, 'user_id' => $other['user']->id, 'order_id' => $other['order']->id,
            'activation_id' => $other['fulfillment_activation']->id,
            'event_key' => 'test_order_ready:'.$other['fulfillment_activation']->public_id.':'.$other['account']->public_id]);
        $before = $this->rows();
        foreach ([['public_id' => strtoupper($valid['public_id'])], ['public_id' => $valid['public_id'].' '], ['public_id' => $valid['public_id']."\n"],
            ['notification_type' => 'test_order_ready '], ['notification_type' => 'TEST_ORDER_READY'],
            ['policy_version' => 'test-transactional-notification-v1 '], ['canonicalization_version' => 'vasey-json-v1 '],
            ['capture_hash' => str_repeat('A', 64)], ['recipient_hmac' => str_repeat('a', 63)],
            ['request_hmac' => str_repeat('a', 64).' '], ['payload_hash' => str_repeat('g', 64)],
            ['capture_ciphertext' => ''], ['capture_ciphertext' => str_repeat('x', 16385)],
            ['event_key' => $valid['event_key'].' '], ['access_version' => 2], ['user_id' => $f['user']->id],
            ['activation_id' => $f['fulfillment_activation']->id], ['created_at' => now()->subDay()->format('Y-m-d H:i:s')]] as $changes) {
            $context = implode(',', array_keys($changes)).'/'.implode(',', array_map(fn ($value) => is_string($value) ? (string) strlen($value) : get_debug_type($value), $changes));
            $this->queryRefused(fn () => DB::table(self::NOTICES)->insert(array_replace($valid, $changes)), $context);
            $this->assertSame($before, $this->rows());
        }
        DB::table(self::NOTICES)->insert($valid);
        $this->assertDatabaseCount(self::NOTICES, 2);
        // Raw shape-valid bytes alone do not establish the domain's encrypted/hash-bound acceptance.
        $this->expectException(NotificationException::class);
        app(TestTransactionalNotifications::class)->status($valid['public_id']);
    }

    public function test_raw_attempt_insertion_enforces_exact_lease_shape_ttl_sequence_and_creation_floor(): void
    {
        F::ready();
        $notice = DB::table(self::NOTICES)->sole();
        $valid = $this->attempt($notice->id);
        foreach ([['public_id' => strtoupper($valid['public_id'])], ['public_id' => $valid['public_id'].' '],
            ['token_hash' => str_repeat('A', 64)], ['token_hash' => str_repeat('a', 64).' '],
            ['number' => 0], ['number' => 2], ['number' => 4], ['state' => 'leased '], ['state' => 'LEASED'],
            ['reason' => ''], ['receipt_hash' => str_repeat('a', 64)], ['finished_at' => $valid['started_at']],
            ['lease_expires_at' => now()->addSeconds(31)->format('Y-m-d H:i:s')],
            ['started_at' => now()->subSecond()->format('Y-m-d H:i:s'), 'lease_expires_at' => now()->addSeconds(29)->format('Y-m-d H:i:s')]] as $changes) {
            $this->queryRefused(fn () => DB::table(self::ATTEMPTS)->insert(array_replace($valid, $changes)));
            $this->assertDatabaseCount(self::ATTEMPTS, 0);
        }
        DB::table(self::ATTEMPTS)->insert($valid);
        $this->assertDatabaseCount(self::ATTEMPTS, 1);
        $this->queryRefused(fn () => DB::table(self::ATTEMPTS)->insert($this->attempt($notice->id, 2)));
        $this->queryRefused(fn () => DB::table(self::ATTEMPTS)->delete());
    }

    public function test_attempt_claim_identity_and_illegal_states_or_padding_cannot_change_a_lease(): void
    {
        $f = F::ready();
        app(TestTransactionalNotifications::class)->claim($f['notice']['notificationId']);
        $row = (array) DB::table(self::ATTEMPTS)->sole();
        $finish = ['state' => 'accepted', 'reason' => null, 'receipt_hash' => str_repeat('a', 64), 'finished_at' => now()->format('Y-m-d H:i:s')];
        foreach (['id' => 100000, 'public_id' => (string) Str::uuid(), 'notice_id' => 100000,
            'number' => 2, 'token_hash' => str_repeat('b', 64), 'started_at' => now()->addSecond()->format('Y-m-d H:i:s'),
            'lease_expires_at' => now()->addSeconds(31)->format('Y-m-d H:i:s')] as $field => $value) {
            $this->queryRefused(fn () => DB::table(self::ATTEMPTS)->update(array_replace($finish, [$field => $value])));
            $this->assertSame($row, (array) DB::table(self::ATTEMPTS)->sole());
        }
        foreach ([['state' => 'accepted '], ['state' => 'ACCEPTED'], ['receipt_hash' => str_repeat('A', 64)], ['receipt_hash' => str_repeat('a', 64).' '],
            ['reason' => 'capture_reconciled'], ['finished_at' => now()->subSecond()->format('Y-m-d H:i:s')],
            ['finished_at' => $row['lease_expires_at']],
            ['state' => 'failed', 'reason' => 'private_storage_refused ', 'receipt_hash' => null],
            ['state' => 'failed', 'reason' => 'PRIVATE_STORAGE_REFUSED', 'receipt_hash' => null],
            ['state' => 'uncertain', 'reason' => 'lease_expired', 'receipt_hash' => null],
            ['state' => 'pending']] as $changes) {
            $this->queryRefused(fn () => DB::table(self::ATTEMPTS)->update(array_replace($finish, $changes)));
            $this->assertSame($row, (array) DB::table(self::ATTEMPTS)->sole());
        }
        DB::table(self::ATTEMPTS)->update($finish);
        $accepted = (array) DB::table(self::ATTEMPTS)->sole();
        foreach ([$finish, ['state' => 'uncertain', 'receipt_hash' => null, 'reason' => 'capture_unknown'], ['receipt_hash' => str_repeat('b', 64)]] as $changes) {
            $this->queryRefused(fn () => DB::table(self::ATTEMPTS)->update($changes));
            $this->assertSame($accepted, (array) DB::table(self::ATTEMPTS)->sole());
        }
    }

    public function test_known_failure_requires_a_new_bounded_claim_and_uncertainty_never_authorizes_another_attempt(): void
    {
        F::ready();
        $notice = DB::table(self::NOTICES)->sole();
        for ($number = 1; $number <= 3; $number++) {
            DB::table(self::ATTEMPTS)->insert($this->attempt($notice->id, $number));
            DB::table(self::ATTEMPTS)->where('number', $number)->update(['state' => 'failed', 'reason' => 'private_storage_refused',
                'finished_at' => now()->format('Y-m-d H:i:s')]);
        }
        $before = $this->rows();
        $this->queryRefused(fn () => DB::table(self::ATTEMPTS)->insert($this->attempt($notice->id, 4)));
        $this->queryRefused(fn () => DB::table(self::ATTEMPTS)->where('number', 3)->update(['state' => 'leased', 'reason' => null, 'finished_at' => null]));
        $this->assertSame($before, $this->rows());
        $other = F::ready(suffix: 'TWO');
        app(TestTransactionalNotifications::class)->claim($other['notice']['notificationId']);
        $second = DB::table(self::NOTICES)->where('public_id', $other['notice']['notificationId'])->sole();
        DB::table(self::ATTEMPTS)->where('notice_id', $second->id)->update(['state' => 'uncertain', 'reason' => 'capture_unknown', 'finished_at' => now()->format('Y-m-d H:i:s')]);
        $this->queryRefused(fn () => DB::table(self::ATTEMPTS)->insert($this->attempt($second->id, 2)));
        $this->queryRefused(fn () => DB::table(self::ATTEMPTS)->where('notice_id', $second->id)->update(['state' => 'accepted', 'reason' => null,
            'receipt_hash' => str_repeat('a', 64), 'finished_at' => now()->format('Y-m-d H:i:s')]));
        DB::table(self::ATTEMPTS)->where('notice_id', $second->id)->update(['state' => 'accepted', 'reason' => 'capture_reconciled',
            'receipt_hash' => str_repeat('a', 64), 'finished_at' => now()->format('Y-m-d H:i:s')]);
        $this->assertSame('accepted', DB::table(self::ATTEMPTS)->where('notice_id', $second->id)->value('state'));
    }

    public function test_unique_attempt_collisions_cannot_retire_or_replace_claim_evidence(): void
    {
        $f = F::ready();
        app(TestTransactionalNotifications::class)->claim($f['notice']['notificationId']);
        DB::table(self::ATTEMPTS)->update(['state' => 'failed', 'reason' => 'private_storage_refused', 'finished_at' => now()->format('Y-m-d H:i:s')]);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers=OFF');
        }
        $row = (array) DB::table(self::ATTEMPTS)->sole();
        foreach (['id', 'public_id', 'token_hash', 'number'] as $collision) {
            $new = array_replace($this->attempt($row['notice_id'], 2), ['id' => 100000, 'public_id' => (string) Str::uuid(), 'token_hash' => bin2hex(random_bytes(32)),
                'number' => 2, $collision => $row[$collision]]);
            $this->queryRefused(fn () => $this->replace(self::ATTEMPTS, $new));
            $this->assertSame([$row], $this->rows()[self::ATTEMPTS]);
        }
    }

    public function test_temporary_table_and_guard_shadows_are_rejected_before_any_permanent_ddl(): void
    {
        $guards = $this->guards();
        foreach ([self::NOTICES, self::ATTEMPTS] as $table) {
            DB::statement('CREATE TEMPORARY TABLE '.$table.' (id INTEGER)');
            try {
                foreach (['up', 'down'] as $direction) {
                    $this->refused(fn () => $this->migration()->$direction());
                    $this->assertSame($guards, $this->guards());
                }
            } finally {
                DB::statement(DB::getDriverName() === 'sqlite' ? 'DROP TABLE temp.'.$table : 'DROP TEMPORARY TABLE '.$table);
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE TEMPORARY TABLE notification_guard_fixture (id INTEGER)');
            DB::unprepared('CREATE TEMP TRIGGER transactional_notices_insert BEFORE INSERT ON notification_guard_fixture BEGIN SELECT 1; END');
            try {
                $this->refused(fn () => $this->migration()->down());
                $this->assertSame($guards, $this->guards());
                $this->assertSame(1, DB::table('sqlite_temp_master')->where('name', 'transactional_notices_insert')->count());
            } finally {
                DB::statement('DROP TABLE temp.notification_guard_fixture');
            }
        }
        $this->assertCount(6, $this->guards());
    }

    public function test_partial_unknown_or_case_aliased_schema_is_never_adopted_or_dropped(): void
    {
        $this->migration()->down();
        foreach ([self::NOTICES, strtoupper(self::NOTICES)] as $name) {
            DB::statement('CREATE TABLE '.$name.' (id INTEGER)');
            try {
                foreach (['up', 'down'] as $direction) {
                    $this->refused(fn () => $this->migration()->$direction());
                    $this->assertTrue(Schema::hasTable($name));
                    $this->assertFalse(Schema::hasTable(self::ATTEMPTS));
                    $this->assertSame([], $this->guards());
                }
            } finally {
                Schema::drop($name);
            }
        }
    }

    public function test_foreign_guard_or_index_name_collision_is_preserved_before_creation(): void
    {
        $this->migration()->down();
        DB::statement('CREATE TABLE notification_guard_fixture (id INTEGER)');
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER TRANSACTIONAL_NOTICES_INSERT BEFORE INSERT ON notification_guard_fixture BEGIN SELECT 1; END'
            : 'CREATE TRIGGER TRANSACTIONAL_NOTICES_INSERT BEFORE INSERT ON notification_guard_fixture FOR EACH ROW SET NEW.id = NEW.id');
        foreach (['up', 'down'] as $direction) {
            $this->refused(fn () => $this->migration()->$direction());
            $this->assertFalse(Schema::hasTable(self::NOTICES));
            $this->assertFalse(Schema::hasTable(self::ATTEMPTS));
        }
        DB::unprepared('DROP TRIGGER TRANSACTIONAL_NOTICES_INSERT');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE INDEX TRANSACTIONAL_NOTICES_PUBLIC_ID_UNIQUE ON notification_guard_fixture(id)');
            $this->refused(fn () => $this->migration()->up());
            $this->assertSame(1, DB::table('sqlite_master')->where('name', 'TRANSACTIONAL_NOTICES_PUBLIC_ID_UNIQUE')->count());
        } else {
            // Foreign constraint names share the schema namespace even on a different table.
            DB::statement('CREATE TABLE notification_foreign_fixture (account_id BIGINT UNSIGNED, CONSTRAINT TRANSACTIONAL_NOTICES_ACCOUNT_ID_FOREIGN FOREIGN KEY (account_id) REFERENCES customer_accounts(id))');
            $this->refused(fn () => $this->migration()->up());
            $this->assertTrue(Schema::hasTable('notification_foreign_fixture'));
        }
        $this->assertFalse(Schema::hasTable(self::NOTICES));
        $this->assertFalse(Schema::hasTable(self::ATTEMPTS));
    }

    public static function modifications(): array
    {
        return ['extra column' => ['column'], 'extra index' => ['index'], 'missing index' => ['missing_index'],
            'missing guard' => ['missing_guard'], 'changed guard' => ['changed_guard'], 'extra guard' => ['extra_guard']];
    }

    #[DataProvider('modifications')]
    public function test_modified_empty_schema_refuses_before_dropping_either_table_or_any_remaining_guard(string $change): void
    {
        match ($change) {
            'column' => Schema::table(self::NOTICES, fn (Blueprint $table) => $table->string('foreign_note')->nullable()),
            'index' => Schema::table(self::ATTEMPTS, fn (Blueprint $table) => $table->index('state', 'foreign_notification_state')),
            'missing_index' => Schema::table(self::ATTEMPTS, fn (Blueprint $table) => $table->dropUnique('transactional_notice_attempts_token_hash_unique')),
            'missing_guard', 'changed_guard' => DB::unprepared('DROP TRIGGER transactional_notice_attempts_update'),
            'extra_guard' => null,
        };
        if (in_array($change, ['changed_guard', 'extra_guard'], true)) {
            $name = $change === 'changed_guard' ? 'transactional_notice_attempts_update' : 'foreign_notification_guard';
            DB::unprepared(DB::getDriverName() === 'sqlite'
                ? 'CREATE TRIGGER '.$name.' BEFORE UPDATE ON transactional_notice_attempts BEGIN SELECT 1; END'
                : 'CREATE TRIGGER '.$name.' BEFORE UPDATE ON transactional_notice_attempts FOR EACH ROW SET NEW.id = NEW.id');
        }
        $guards = $this->guards();
        $columns = Schema::getColumns(self::NOTICES);
        $indexes = Schema::getIndexes(self::ATTEMPTS);
        $this->refused(fn () => $this->migration()->down());
        $this->assertTrue(Schema::hasTable(self::NOTICES));
        $this->assertTrue(Schema::hasTable(self::ATTEMPTS));
        $this->assertSame($guards, $this->guards());
        $this->assertSame($columns, Schema::getColumns(self::NOTICES));
        $this->assertSame($indexes, Schema::getIndexes(self::ATTEMPTS));
    }

    public function test_external_empty_child_foreign_key_prevents_all_rollback_ddl(): void
    {
        Schema::create('notification_foreign_child', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notice_id')->constrained(self::NOTICES)->restrictOnDelete();
        });
        $guards = $this->guards();
        $this->refused(fn () => $this->migration()->down());
        $this->assertSame($guards, $this->guards());
        $this->assertTrue(Schema::hasTable(self::NOTICES));
        $this->assertTrue(Schema::hasTable(self::ATTEMPTS));
        $this->assertTrue(Schema::hasTable('notification_foreign_child'));
        Schema::drop('notification_foreign_child');
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable(self::NOTICES));
        $this->assertFalse(Schema::hasTable(self::ATTEMPTS));
    }

    private function attempt(int $noticeId, int $number = 1): array
    {
        return ['public_id' => (string) Str::uuid(), 'notice_id' => $noticeId, 'number' => $number,
            'token_hash' => bin2hex(random_bytes(32)), 'started_at' => now()->format('Y-m-d H:i:s'),
            'lease_expires_at' => now()->addSeconds(30)->format('Y-m-d H:i:s'), 'state' => 'leased',
            'reason' => null, 'receipt_hash' => null, 'finished_at' => null];
    }

    private function replace(string $table, array $row): void
    {
        DB::statement('REPLACE INTO '.$table.' ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row));
    }

    private function queryRefused(callable $operation, string $context = ''): void
    {
        try {
            $operation();
        } catch (QueryException) {
            $this->assertTrue(true);

            return;
        }
        if ($context === 'public_id/37' && DB::getDriverName() === 'mysql') {
            $warnings = DB::select('SHOW WARNINGS');
            $stored = DB::table(self::NOTICES)->where('id', 100000)->value('public_id');
            echo json_encode(['raw_alias_diagnostic' => ['submitted_public_id_bytes' => 37,
                'stored_public_id_bytes' => strlen($stored), 'warning_codes' => array_map(fn ($warning) => $warning->Code, $warnings)]], JSON_THROW_ON_ERROR).PHP_EOL;
        }
        $this->fail('Raw mutation changed retained notification evidence: '.$context);
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
        } catch (LogicException) {
            $this->assertTrue(true);

            return;
        }
        $this->fail('Unexpected notification schema was adopted or erased.');
    }

    private function rows(): array
    {
        return array_combine([self::NOTICES, self::ATTEMPTS], array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), [self::NOTICES, self::ATTEMPTS]));
    }

    private function guards(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', [self::NOTICES, self::ATTEMPTS])->orderBy('name')->get()->map(fn ($row) => (array) $row)->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereIn('EVENT_OBJECT_TABLE', [self::NOTICES, self::ATTEMPTS])
                ->orderBy('TRIGGER_NAME')->get(['TRIGGER_NAME', 'EVENT_OBJECT_TABLE', 'ACTION_TIMING', 'EVENT_MANIPULATION', 'ACTION_STATEMENT'])->map(fn ($row) => (array) $row)->all();
    }
}
