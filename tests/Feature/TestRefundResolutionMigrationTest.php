<?php

namespace Tests\Feature;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestRefundResolution;
use App\Domain\Commerce\Models\TestRefundResolutionRequest;
use App\Domain\Commerce\Operations\TestPaymentExceptionOperations;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures as F;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFinancialFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestRefundResolutionMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function fixture(bool $exception = true): array
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $payments = PaymentFixtures::gateway();
        $financial = PaymentFinancialFixtures::gateway($payments);
        $this->app->instance(StripeCheckoutGateway::class, $payments);
        $this->app->instance(StripePaymentGateway::class, $payments);
        $this->app->instance(StripeFinancialInspectionGateway::class, $financial);
        $f = PaymentFixtures::started($payments, true, true);
        if ($exception) {
            $this->travelTo($f['order']->attempt()->sole()->expires_at->addSecond());
        }
        $f = F::confirm($f);
        $this->assertSame($exception ? 'paid_exception' : 'paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));

        return $f + ['record' => OrderFinalization::where('order_id', $f['order']->id)->sole(), 'admin' => LicenseFixtures::admin()];
    }

    private function request(array $f): array
    {
        return ['public_id' => (string) Str::uuid(), 'order_finalization_id' => $f['record']->id, 'actor_id' => $f['admin']->id,
            'request_id' => (string) Str::uuid(), 'expected_sequence' => 0, 'policy_version' => 'test-refunded-exception-release-v1', 'created_at' => now()->format('Y-m-d H:i:s')];
    }

    private function proof(array $f): array
    {
        $request = TestRefundResolutionRequest::create($this->request($f));
        app(TestPaymentExceptionOperations::class)->reconcile($f['record']->public_id, $f['admin'], $request->request_id, 0);
        $attempt = $f['order']->attempt()->sole();

        // These are schema-shape fixtures. Financial eligibility and encrypted proof verification belong to the service tests.
        return ['public_id' => (string) Str::uuid(), 'request_record_id' => $request->id, 'order_id' => $f['order']->id,
            'order_finalization_id' => $f['record']->id, 'order_attempt_id' => $attempt->id,
            'observed_event_id' => TestPaymentExceptionEvent::where('kind', 'reconciliation_observed')->sole()->id,
            'inventory_reservation_id' => $attempt->inventory_reservation_id, 'promotion_use_id' => $attempt->promotion_use_id,
            'actor_id' => $f['admin']->id, 'evidence_ciphertext' => 'synthetic-schema-only-evidence', 'evidence_hash' => str_repeat('a', 64),
            'canonicalization_version' => 'vasey-json-v1', 'policy_version' => 'test-refunded-exception-release-v1', 'released_at' => now()->format('Y-m-d H:i:s')];
    }

    public function test_request_guards_reject_invalid_identity_policy_sequence_and_timestamp_before_accepting_valid_request(): void
    {
        $f = $this->fixture();
        $valid = $this->request($f);
        foreach ([['public_id' => strtoupper($valid['public_id'])], ['public_id' => str_repeat('a', 36)], ['public_id' => str_repeat('é', 36)],
            ['request_id' => 'bad'], ['request_id' => strtoupper($valid['request_id'])], ['expected_sequence' => -1], ['expected_sequence' => 1],
            ['expected_sequence' => 4294967294], ['expected_sequence' => 0.5], ['actor_id' => 0], ['order_finalization_id' => 0],
            ['policy_version' => 'TEST-REFUNDED-EXCEPTION-RELEASE-V1'], ['policy_version' => 'test-refunded-exception-release-v1 '], ['created_at' => 'not-a-date'], ['created_at' => null]] as $change) {
            $this->rejected(fn () => DB::table('test_refund_resolution_requests')->insert(array_replace($valid, $change)));
        }
        $row = TestRefundResolutionRequest::create($valid);
        $this->assertSame(0, $row->expected_sequence);
        $this->assertArrayNotHasKey('request_id', $row->toArray());
        $this->assertDatabaseCount('test_refund_resolution_requests', 1);
    }

    public function test_paid_finalization_cannot_receive_a_refund_resource_resolution_request(): void
    {
        $f = $this->fixture(false);
        $this->rejected(fn () => DB::table('test_refund_resolution_requests')->insert($this->request($f)));
        $this->assertDatabaseCount('test_refund_resolution_requests', 0);
    }

    public function test_proof_guards_bind_exact_request_event_actor_resources_and_evidence_shape(): void
    {
        $f = $this->fixture();
        $valid = $this->proof($f);
        foreach ([['public_id' => str_repeat('a', 36)], ['evidence_hash' => str_repeat('A', 64)], ['evidence_hash' => str_repeat('g', 64)],
            ['evidence_hash' => str_repeat('a', 63)], ['evidence_ciphertext' => ''], ['canonicalization_version' => 'VASEY-JSON-V1'],
            ['policy_version' => 'test-unpaid-release-v1'], ['policy_version' => 'test-refunded-exception-release-v1 '], ['canonicalization_version' => 'vasey-json-v1 '], ['request_record_id' => 0], ['order_id' => 0], ['order_attempt_id' => 0],
            ['order_finalization_id' => 0], ['actor_id' => LicenseFixtures::admin()->id], ['promotion_use_id' => null], ['inventory_reservation_id' => 0],
            ['observed_event_id' => TestPaymentExceptionEvent::where('kind', 'reconciliation_requested')->sole()->id],
            ['released_at' => now()->subSecond()->format('Y-m-d H:i:s')]] as $change) {
            $this->rejected(fn () => DB::table('test_refund_resolutions')->insert(array_replace($valid, $change)));
        }
        $record = TestRefundResolution::create($valid);
        $this->assertSame($f['order']->id, $record->order_id);
        $this->assertArrayNotHasKey('evidence_ciphertext', $record->toArray());
        $this->assertArrayNotHasKey('evidence_hash', $record->toArray());
        $this->assertDatabaseCount('test_refund_resolutions', 1);
    }

    public function test_raw_and_model_updates_deletes_upserts_and_replacements_preserve_both_records(): void
    {
        $f = $this->fixture();
        TestRefundResolution::create($this->proof($f));
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers=OFF');
        }
        foreach (['test_refund_resolution_requests', 'test_refund_resolutions'] as $table) {
            $row = (array) DB::table($table)->sole();
            $before = DB::table($table)->get()->toJson();
            foreach ([fn () => DB::table($table)->update(['public_id' => (string) Str::uuid()]), fn () => DB::table($table)->delete(),
                fn () => DB::table($table)->upsert([$row], ['public_id'], ['policy_version']),
                fn () => DB::statement('REPLACE INTO '.$table.' ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row))] as $operation) {
                $this->rejected($operation);
                $this->assertSame($before, DB::table($table)->get()->toJson());
            }
            // Changing both surrogate and public identities still collides with a retained semantic key.
            unset($row['id']);
            $row['public_id'] = (string) Str::uuid();
            $this->rejected(fn () => DB::statement('REPLACE INTO '.$table.' ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row)));
            $this->assertSame($before, DB::table($table)->get()->toJson());
        }
        foreach ([TestRefundResolutionRequest::sole(), TestRefundResolution::sole()] as $record) {
            $this->refused(fn () => $record->update(['public_id' => (string) Str::uuid()]));
            $this->refused(fn () => $record->delete());
        }
    }

    public function test_pending_resources_release_only_after_bound_proof_and_remain_immutable(): void
    {
        $f = $this->fixture();
        $proof = $this->proof($f);
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            $this->rejected(fn () => DB::table($table)->update(['state' => 'released']));
        }
        TestRefundResolution::create($proof);
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            $this->rejected(fn () => DB::table($table)->update(['state' => 'released', 'attempt_id' => (string) Str::uuid()]));
            $this->assertSame(1, DB::table($table)->update(['state' => 'released']));
            $before = DB::table($table)->get()->toJson();
            foreach ([['state' => 'pending'], ['state' => 'consumed', 'consumed_at' => now()], ['state' => 'RELEASED']] as $change) {
                $this->rejected(fn () => DB::table($table)->update($change));
                $this->assertSame($before, DB::table($table)->get()->toJson());
            }
        }
        $this->assertSame('paid_exception', $f['record']->refresh()->outcome);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_parent_retention_and_populated_rollback_leave_all_evidence_and_guards_intact(): void
    {
        $f = $this->fixture();
        TestRefundResolution::create($this->proof($f));
        $before = F::retained();
        foreach (['order_finalizations', 'order_attempts', 'test_payment_exception_events', 'inventory_reservations', 'promotion_uses'] as $table) {
            $this->rejected(fn () => DB::table($table)->delete());
        }
        $this->rejected(fn () => DB::table('users')->where('id', $f['admin']->id)->delete());
        Schema::disableForeignKeyConstraints();
        try {
            $this->rejected(fn () => DB::table('test_refund_resolution_requests')->delete());
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        $this->refused(fn () => $this->migration()->down());
        $this->assertSame($before, F::retained());
        $this->assertDatabaseCount('test_refund_resolutions', 1);
        $this->rejected(fn () => DB::table('test_refund_resolutions')->delete());
    }

    public function test_request_alone_is_retained_and_prevents_rollback(): void
    {
        $f = $this->fixture();
        TestRefundResolutionRequest::create($this->request($f));
        $this->refused(fn () => $this->migration()->down());
        $this->assertTrue(Schema::hasTable('test_refund_resolutions'));
        $this->assertDatabaseCount('test_refund_resolution_requests', 1);
    }

    public function test_empty_roundtrip_preserves_existing_paid_exception_and_pending_resources(): void
    {
        $this->fixture();
        $before = F::retained();
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasTable('test_refund_resolutions'));
        $this->assertFalse(Schema::hasTable('test_refund_resolution_requests'));
        $migration->up();
        $this->assertSame($before, F::retained());
        $this->assertDatabaseCount('test_refund_resolutions', 0);
        $this->assertDatabaseCount('test_refund_resolution_requests', 0);
    }

    public function test_foreign_column_index_trigger_and_external_reference_refuse_rollback_before_ddl(): void
    {
        $migration = $this->migration();
        Schema::table('test_refund_resolution_requests', fn (Blueprint $table) => $table->string('foreign_evidence')->nullable());
        $this->refused(fn () => $migration->down());
        Schema::table('test_refund_resolution_requests', fn (Blueprint $table) => $table->dropColumn('foreign_evidence'));
        Schema::table('test_refund_resolutions', fn (Blueprint $table) => $table->index('released_at', 'foreign_refund_index'));
        $this->refused(fn () => $migration->down());
        Schema::table('test_refund_resolutions', fn (Blueprint $table) => $table->dropIndex('foreign_refund_index'));
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER foreign_refund_trigger BEFORE INSERT ON test_refund_resolutions BEGIN SELECT 1; END'
            : 'CREATE TRIGGER foreign_refund_trigger BEFORE INSERT ON test_refund_resolutions FOR EACH ROW SET NEW.id = NEW.id');
        $this->refused(fn () => $migration->down());
        DB::unprepared('DROP TRIGGER foreign_refund_trigger');
        Schema::create('foreign_refund_child', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resolution_id')->constrained('test_refund_resolutions')->restrictOnDelete();
        });
        $this->refused(fn () => $migration->down());
        Schema::drop('foreign_refund_child');
        $this->assertTrue(Schema::hasTable('test_refund_resolution_requests'));
        $migration->down();
        $migration->up();
    }

    public function test_temporary_shadow_and_foreign_named_guard_prevent_any_migration_ddl(): void
    {
        $migration = $this->migration();
        DB::statement('CREATE TEMPORARY TABLE test_refund_resolutions (id INTEGER)');
        try {
            $this->refused(fn () => $migration->down());
        } finally {
            DB::statement(DB::getDriverName() === 'sqlite' ? 'DROP TABLE temp.test_refund_resolutions' : 'DROP TEMPORARY TABLE test_refund_resolutions');
        }
        $migration->down();
        Schema::create('foreign_refund_owner', fn (Blueprint $table) => $table->id());
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER refund_resolution_insert BEFORE INSERT ON foreign_refund_owner BEGIN SELECT 1; END'
            : 'CREATE TRIGGER refund_resolution_insert BEFORE INSERT ON foreign_refund_owner FOR EACH ROW SET NEW.id = NEW.id');
        $this->refused(fn () => $migration->up());
        $this->assertFalse(Schema::hasTable('test_refund_resolution_requests'));
        $this->assertTrue(Schema::hasTable('foreign_refund_owner'));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_000047_test_refund_resolutions.php');
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid or rewritten retained evidence was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Retained evidence mutation or foreign schema rollback was accepted.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
    }
}
