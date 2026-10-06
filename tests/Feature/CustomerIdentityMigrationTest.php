<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerIdentityPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerIdentityMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_every_retained_unique_identity_refuses_replace_without_recursive_delete_triggers(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers=OFF');
        }
        $retained = $this->insert();
        foreach (['id', 'public_id', 'request_hash'] as $column) {
            $replacement = array_replace($this->row(), ['id' => $retained['id'] + 1000, $column => $retained[$column]]);
            $this->refusedSql(fn () => DB::statement('REPLACE INTO customer_identity_challenges ('.implode(',', array_keys($replacement)).') VALUES ('.implode(',', array_fill(0, count($replacement), '?')).')', array_values($replacement)));
            $this->assertSame($retained, (array) DB::table('customer_identity_challenges')->sole());
        }
    }

    public function test_pending_proof_and_identity_are_immutable_even_in_an_otherwise_valid_completion(): void
    {
        $account = CustomerFixtures::account();
        $other = CustomerFixtures::account();
        $row = $this->insert(['purpose' => 'recover', 'user_id' => $account['user']->id, 'account_id' => $account['account']->id,
            'access_version' => 1, 'credential_stamp' => app(CustomerAccess::class)->stamp($account['user'])]);
        $completion = $this->completion($account);
        $changes = ['id' => $row['id'] + 1000, 'public_id' => (string) Str::uuid(), 'address_key' => $this->row()['address_key'],
            'request_hash' => str_repeat('b', 64), 'purpose' => 'enroll', 'policy_version' => 'rewritten-policy', 'email' => 'rewritten-ciphertext',
            'proof_hash' => str_repeat('b', 64), 'user_id' => $other['user']->id, 'account_id' => $other['account']->id,
            'access_version' => 2, 'credential_stamp' => str_repeat('b', 64), 'created_at' => date('Y-m-d H:i:s', strtotime($row['created_at']) - 1),
            'expires_at' => now()->addHour()->format('Y-m-d H:i:s')];
        foreach ($changes as $column => $value) {
            $this->refusedSql(fn () => DB::table('customer_identity_challenges')->where('id', $row['id'])->update(array_replace($completion, [$column => $value])));
            $this->assertSame($row, (array) DB::table('customer_identity_challenges')->sole());
        }
        DB::table('customer_identity_challenges')->where('id', $row['id'])->update($completion);
        $this->assertDatabaseHas('customer_identity_challenges', ['id' => $row['id'], 'state' => 'completed']);
    }

    public function test_insert_refuses_forged_states_partial_recovery_and_malformed_proof_identity(): void
    {
        $account = CustomerFixtures::account();
        $bad = [['state' => 'completed'], ['state' => 'Pending'], ['state' => 'pending '], ['purpose' => 'Enroll'], ['purpose' => 'enroll '],
            ['public_id' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'], ['public_id' => str_repeat('-', 36)],
            ['request_hash' => str_repeat('A', 64)], ['proof_hash' => str_repeat('a', 63)], ['proof_hash' => str_repeat('a', 63)."\0"],
            ['expires_at' => now()->subSecond()->format('Y-m-d H:i:s')], ['user_id' => $account['user']->id],
            ['purpose' => 'recover'], ['purpose' => 'recover', 'user_id' => $account['user']->id, 'account_id' => $account['account']->id],
            ['state' => 'unavailable', 'user_id' => $account['user']->id], ['result_user_id' => $account['user']->id],
            ['completed_at' => now()->format('Y-m-d H:i:s')], ['completion_hash' => str_repeat('a', 64)]];
        foreach ($bad as $values) {
            $this->refusedSql(fn () => DB::table('customer_identity_challenges')->insert($this->row($values)));
        }
        $this->assertDatabaseCount('customer_identity_challenges', 0);
        $this->insert(['state' => 'unavailable', 'purpose' => 'recover']);
        $this->insert();
        $this->assertDatabaseCount('customer_identity_challenges', 2);
    }

    public function test_completion_requires_exact_terminal_state_complete_current_result_and_unexpired_time(): void
    {
        $account = CustomerFixtures::account();
        $other = CustomerFixtures::account();
        $row = $this->insert(['purpose' => 'recover', 'user_id' => $account['user']->id, 'account_id' => $account['account']->id,
            'access_version' => 1, 'credential_stamp' => app(CustomerAccess::class)->stamp($account['user'])]);
        $completion = $this->completion($account);
        $bad = [['state' => 'Completed'], ['state' => 'completed '], ['state' => 'pending'], ['state' => 'unavailable'],
            ['completed_at' => null], ['completed_at' => date('Y-m-d H:i:s', strtotime($row['created_at']) - 1)], ['completed_at' => $row['expires_at']],
            ['result_user_id' => null], ['result_account_id' => null], ['result_access_version' => null], ['result_access_version' => 0],
            ['result_stamp' => null], ['result_stamp' => str_repeat('A', 64)], ['completion_hash' => null], ['completion_hash' => 'bad'],
            ['result_user_id' => $other['user']->id], ['result_account_id' => $other['account']->id],
            ['result_user_id' => $other['user']->id, 'result_account_id' => $other['account']->id]];
        foreach ($bad as $values) {
            $this->refusedSql(fn () => DB::table('customer_identity_challenges')->where('id', $row['id'])->update(array_replace($completion, $values)));
            $this->assertSame($row, (array) DB::table('customer_identity_challenges')->sole());
        }
        DB::table('customer_identity_challenges')->where('id', $row['id'])->update($completion);
        $retained = (array) DB::table('customer_identity_challenges')->sole();
        foreach ([$completion, ['state' => 'pending'], ['result_stamp' => str_repeat('b', 64)], ['completion_hash' => str_repeat('b', 64)]] as $values) {
            $this->refusedSql(fn () => DB::table('customer_identity_challenges')->where('id', $row['id'])->update($values));
        }
        $this->refusedSql(fn () => DB::table('customer_identity_challenges')->delete());
        $this->assertSame($retained, (array) DB::table('customer_identity_challenges')->sole());
    }

    public function test_unavailable_evidence_cannot_be_promoted_or_deleted(): void
    {
        $row = $this->insert(['state' => 'unavailable']);
        $account = CustomerFixtures::account();
        $this->refusedSql(fn () => DB::table('customer_identity_challenges')->update(['state' => 'pending']));
        $this->refusedSql(fn () => DB::table('customer_identity_challenges')->update($this->completion($account)));
        $this->refusedSql(fn () => DB::table('customer_identity_challenges')->delete());
        $this->assertSame($row, (array) DB::table('customer_identity_challenges')->sole());
    }

    public function test_populated_rollback_preserves_every_row_and_guard(): void
    {
        $row = $this->insert();
        $this->refusedMigration(fn () => $this->migration()->down());
        $this->assertSame($row, (array) DB::table('customer_identity_challenges')->sole());
        $this->refusedSql(fn () => DB::table('customer_identity_challenges')->delete());
    }

    public function test_address_only_evidence_also_refuses_rollback(): void
    {
        $this->row();
        $this->refusedMigration(fn () => $this->migration()->down());
        $this->assertDatabaseCount('customer_identity_addresses', 1);
    }

    public function test_empty_owned_rollback_is_repeatable_and_reinstall_restores_guards(): void
    {
        $migration = $this->migration();
        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('customer_identity_challenges'));
        $this->assertFalse(Schema::hasTable('customer_identity_addresses'));
        $migration->up();
        $row = $this->insert();
        $this->refusedSql(fn () => DB::table('customer_identity_challenges')->delete());
        $this->assertSame($row, (array) DB::table('customer_identity_challenges')->sole());
    }

    public function test_foreign_named_guards_are_preserved_before_creation_and_absent_rollback(): void
    {
        $this->migration()->down();
        Schema::create('identity_guard_fixture', fn (Blueprint $table) => $table->integer('id'));
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER customer_identity_challenges_insert BEFORE INSERT ON identity_guard_fixture BEGIN SELECT 1; END'
            : 'CREATE TRIGGER customer_identity_challenges_insert BEFORE INSERT ON identity_guard_fixture FOR EACH ROW SET NEW.id=NEW.id');
        foreach (['up', 'down'] as $direction) {
            $this->refusedMigration(fn () => $this->migration()->$direction());
            $this->assertFalse(Schema::hasTable('customer_identity_addresses'));
            $this->assertFalse(Schema::hasTable('customer_identity_challenges'));
        }
        $this->assertTrue(Schema::hasTable('identity_guard_fixture'));
    }

    public function test_incomplete_schema_or_altered_columns_indexes_and_guards_refuse_before_any_drop(): void
    {
        Schema::table('customer_identity_challenges', fn (Blueprint $table) => $table->string('foreign_data')->nullable());
        $this->refusedMigration(fn () => $this->migration()->down());
        Schema::table('customer_identity_challenges', fn (Blueprint $table) => $table->dropColumn('foreign_data'));
        Schema::table('customer_identity_challenges', fn (Blueprint $table) => $table->index('proof_hash', 'foreign_identity_index'));
        $this->refusedMigration(fn () => $this->migration()->down());
        Schema::table('customer_identity_challenges', fn (Blueprint $table) => $table->dropIndex('foreign_identity_index'));
        Schema::table('customer_identity_challenges', fn (Blueprint $table) => $table->dropUnique('customer_identity_challenges_request_hash_unique'));
        $this->refusedMigration(fn () => $this->migration()->down());
        Schema::table('customer_identity_challenges', fn (Blueprint $table) => $table->unique('request_hash'));
        DB::unprepared('DROP TRIGGER customer_identity_challenges_delete');
        $this->refusedMigration(fn () => $this->migration()->down());
        Schema::drop('customer_identity_challenges');
        foreach (['up', 'down'] as $direction) {
            $this->refusedMigration(fn () => $this->migration()->$direction());
        }
        $this->assertTrue(Schema::hasTable('customer_identity_addresses'));
    }

    public function test_foreign_inbound_reference_refuses_before_removing_owned_guards(): void
    {
        Schema::create('identity_foreign_child', function (Blueprint $table): void {
            $table->foreignId('challenge_id')->constrained('customer_identity_challenges')->restrictOnDelete();
        });
        $this->refusedMigration(fn () => $this->migration()->down());
        $this->assertTrue(Schema::hasTable('identity_foreign_child'));
        $this->insert();
        $this->refusedSql(fn () => DB::table('customer_identity_challenges')->delete());
    }

    public function test_temporary_table_shadows_cannot_hide_retained_rows_or_remove_guards(): void
    {
        $row = $this->insert();
        foreach (['customer_identity_addresses', 'customer_identity_challenges'] as $table) {
            DB::statement('CREATE TEMPORARY TABLE '.$table.' (id INTEGER)');
            try {
                foreach (['up', 'down'] as $direction) {
                    $this->refusedMigration(fn () => $this->migration()->$direction());
                }
            } finally {
                DB::statement(DB::getDriverName() === 'sqlite' ? 'DROP TABLE temp.'.$table : 'DROP TEMPORARY TABLE '.$table);
            }
        }
        $this->assertSame($row, (array) DB::table('customer_identity_challenges')->sole());
        $this->refusedSql(fn () => DB::table('customer_identity_challenges')->delete());
    }

    public function test_temporary_guard_name_never_removes_a_permanent_guard(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // MySQL has no temporary triggers; its table-resolution hazard is independently exercised above.
            $this->assertSame('mysql', DB::getDriverName());

            return;
        }
        DB::statement('CREATE TEMPORARY TABLE identity_guard_fixture (id INTEGER)');
        DB::unprepared('CREATE TEMP TRIGGER customer_identity_challenges_insert BEFORE INSERT ON identity_guard_fixture BEGIN SELECT 1; END');
        try {
            foreach (['up', 'down'] as $direction) {
                $this->refusedMigration(fn () => $this->migration()->$direction());
            }
            $this->assertSame(1, DB::table('sqlite_temp_master')->where('name', 'customer_identity_challenges_insert')->count());
        } finally {
            DB::statement('DROP TABLE temp.identity_guard_fixture');
        }
        $this->insert();
        $this->refusedSql(fn () => DB::table('customer_identity_challenges')->delete());
    }

    public function test_schema_wide_foreign_constraint_or_index_name_prevents_partial_installation(): void
    {
        $this->migration()->down();
        Schema::create('identity_name_fixture', function (Blueprint $table): void {
            $table->foreignId('user_id');
            if (DB::getDriverName() === 'sqlite') {
                $table->index('user_id', 'customer_identity_challenges_request_hash_unique');
            } else {
                $table->foreign('user_id', 'customer_identity_challenges_user_id_foreign')->references('id')->on('users');
            }
        });
        $this->refusedMigration(fn () => $this->migration()->up());
        $this->assertFalse(Schema::hasTable('customer_identity_addresses'));
        $this->assertFalse(Schema::hasTable('customer_identity_challenges'));
        $this->assertTrue(Schema::hasTable('identity_name_fixture'));
    }

    private function row(array $values = []): array
    {
        $address = bin2hex(random_bytes(32));
        DB::table('customer_identity_addresses')->insert(['address_key' => $address]);

        return array_replace(['public_id' => (string) Str::uuid(), 'address_key' => $address, 'request_hash' => bin2hex(random_bytes(32)),
            'purpose' => 'enroll', 'policy_version' => CustomerIdentityPolicy::VERSION, 'email' => 'synthetic-ciphertext', 'proof_hash' => bin2hex(random_bytes(32)),
            'user_id' => null, 'account_id' => null, 'access_version' => null, 'credential_stamp' => null, 'state' => 'pending',
            'created_at' => now()->startOfSecond()->format('Y-m-d H:i:s'), 'expires_at' => now()->startOfSecond()->addMinutes(10)->format('Y-m-d H:i:s'),
            'completed_at' => null, 'result_user_id' => null, 'result_account_id' => null, 'result_access_version' => null, 'result_stamp' => null, 'completion_hash' => null], $values);
    }

    private function insert(array $values = []): array
    {
        $id = DB::table('customer_identity_challenges')->insertGetId($this->row($values));

        return (array) DB::table('customer_identity_challenges')->where('id', $id)->sole();
    }

    private function completion(array $account): array
    {
        return ['state' => 'completed', 'completed_at' => now()->startOfSecond()->format('Y-m-d H:i:s'), 'result_user_id' => $account['user']->id,
            'result_account_id' => $account['account']->id, 'result_access_version' => $account['account']->access_version,
            'result_stamp' => app(CustomerAccess::class)->stamp($account['user']), 'completion_hash' => bin2hex(random_bytes(32))];
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_000045_customer_identity_challenges.php');
    }

    private function refusedSql(callable $operation): void
    {
        try {
            $operation();
            $this->fail('A retained customer challenge mutation was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private function refusedMigration(callable $operation): void
    {
        $before = $this->guards();
        try {
            $operation();
            $this->fail('An unsafe customer challenge migration was accepted.');
        } catch (\LogicException) {
            $this->assertSame($before, $this->guards());
        }
    }

    private function guards(): array
    {
        return (DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'customer_identity_%')->orderBy('name')->get()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'like', 'customer_identity_%')->orderBy('TRIGGER_NAME')->get())
            ->map(fn ($guard) => (array) $guard)->all();
    }
}
