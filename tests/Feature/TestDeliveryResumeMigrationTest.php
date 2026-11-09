<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Delivery\DeliveryByteRange;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryCoverage;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Domain\Delivery\ReadTestDeliveryAuthorization;
use App\Domain\Delivery\TransferTestDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** FP-077 schema: v1 and v2 authorizations admitted by one guard, and the append-only v2 coverage log. */
class TestDeliveryResumeMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;

    private const FILE = '2026_11_09_270300_test_delivery_resume_v2.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configureV2();
        Queue::fake();
        $this->gateway = $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::FILE);
    }

    /** A v2 authorization whose transfer started and served bytes 0..9 (one coverage row, not complete). */
    private function started(): array
    {
        $f = F::ready($this->gateway);
        $issued = app(IssueTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $f['grants'][0]->public_id, 'contract',
            (string) Str::uuid(), sessionSecret: F::SESSION_SECRET);
        $file = app(ReadTestDeliveryAuthorization::class)->forSession($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId,
            F::SESSION_SECRET)['target']['file'];
        app(TransferTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, F::SESSION_SECRET,
            DeliveryByteRange::evaluate('bytes=0-9', null, $file['size_bytes'], $file['sha256']))->writeTo(fn () => null);

        return ['authorization' => TestDeliveryAuthorization::sole(), 'redemption' => TestDeliveryRedemption::sole(), 'size' => $file['size_bytes']];
    }

    private function triggers(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'test_delivery_authorizations')->orderBy('name')->pluck('sql', 'name')->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('EVENT_OBJECT_TABLE', 'test_delivery_authorizations')->orderBy('TRIGGER_NAME')->pluck('ACTION_STATEMENT', 'TRIGGER_NAME')->all();
    }

    private function rejected(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('The schema admitted an invalid or immutable change.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Invalid or immutable test delivery evidence', $error->getMessage());
        }
    }

    private function copyAuthorization(TestDeliveryAuthorization $source, string $version): array
    {
        $row = (array) DB::table('test_delivery_authorizations')->where('id', $source->id)->first();
        unset($row['id']);

        return ['public_id' => (string) Str::uuid(), 'token_hash' => hash('sha256', Str::random()), 'idempotency_key_hash' => hash('sha256', Str::random()),
            'policy_version' => $version] + $row;
    }

    private function coverage(array $s, array $changes = []): array
    {
        return $changes + ['test_delivery_authorization_id' => $s['authorization']->id, 'test_delivery_redemption_id' => $s['redemption']->id,
            'sequence' => 2, 'first_byte' => 10, 'last_byte' => 19, 'intervals' => '[[0,19]]', 'covered_bytes' => 20,
            'completed_authorization_id' => null, 'recorded_at' => now()->utc()->format('Y-m-d H:i:s')];
    }

    public function test_the_authorization_guard_admits_exactly_v1_and_v2_and_keeps_every_other_rule(): void
    {
        $s = $this->started();
        $this->assertSame(['test_delivery_authorizations_immutable_delete', 'test_delivery_authorizations_immutable_update',
            'test_delivery_authorizations_valid_insert_v2'], array_values(array_filter(array_keys($this->triggers()), fn ($name) => ! str_starts_with($name, 'hash_bytes_') && ! str_starts_with($name, 'uuid_bytes_'))));
        foreach (['test-owner-delivery-v3', 'test-owner-delivery-v2 ', 'TEST-OWNER-DELIVERY-V2', 'live'] as $version) {
            $this->rejected(fn () => DB::table('test_delivery_authorizations')->insert($this->copyAuthorization($s['authorization'], $version)));
        }
        // Everything else in the 000023 guard still applies to v2, e.g. the 60-second start window.
        $longer = $this->copyAuthorization($s['authorization'], 'test-owner-delivery-v2');
        $longer['expires_at'] = $s['authorization']->issued_at->addSeconds(61)->format('Y-m-d H:i:s');
        $this->rejected(fn () => DB::table('test_delivery_authorizations')->insert($longer));
        foreach (['test-owner-delivery-v1', 'test-owner-delivery-v2'] as $version) {
            DB::table('test_delivery_authorizations')->insert($this->copyAuthorization($s['authorization'], $version));
        }
        $this->assertDatabaseCount('test_delivery_authorizations', 3);
    }

    public function test_coverage_is_append_only_sequential_monotonic_and_completes_exactly_once(): void
    {
        $s = $this->started();
        $this->assertSame([1, 0, 9, 10, '[[0,9]]'], [TestDeliveryCoverage::sole()->sequence, TestDeliveryCoverage::sole()->first_byte,
            TestDeliveryCoverage::sole()->last_byte, TestDeliveryCoverage::sole()->covered_bytes, TestDeliveryCoverage::sole()->intervals]);
        $size = $s['size'];
        $v1 = $this->copyAuthorization($s['authorization'], 'test-owner-delivery-v1');
        DB::table('test_delivery_authorizations')->insert($v1);
        $v1Id = DB::table('test_delivery_authorizations')->where('public_id', $v1['public_id'])->value('id');
        foreach ([
            'duplicate sequence' => ['sequence' => 1], 'gap' => ['sequence' => 3], 'sequence zero' => ['sequence' => 0],
            'shrinking coverage' => ['covered_bytes' => 9, 'first_byte' => 0, 'last_byte' => 0, 'intervals' => '[[0,8]]'],
            'past the file' => ['last_byte' => $size, 'first_byte' => $size - 1],
            'reversed bytes' => ['first_byte' => 19, 'last_byte' => 10],
            'more coverage than the file' => ['covered_bytes' => $size + 1],
            'served more than covered' => ['covered_bytes' => 5, 'first_byte' => 0, 'last_byte' => 9],
            'completion before complete' => ['completed_authorization_id' => $s['authorization']->id],
            'complete without completion' => ['first_byte' => 10, 'last_byte' => $size - 1, 'covered_bytes' => $size, 'intervals' => '[[0,'.($size - 1).']]'],
            'foreign completion' => ['first_byte' => 10, 'last_byte' => $size - 1, 'covered_bytes' => $size, 'intervals' => '[[0,'.($size - 1).']]', 'completed_authorization_id' => $v1Id],
            'v1 authorization' => ['test_delivery_authorization_id' => $v1Id],
            'not json intervals' => ['intervals' => 'x'],
            'before the start' => ['recorded_at' => $s['redemption']->redeemed_at->subSecond()->format('Y-m-d H:i:s')],
        ] as $label => $changes) {
            $this->rejected(fn () => DB::table('test_delivery_coverage')->insert($this->coverage($s, $changes)));
        }
        $complete = ['first_byte' => 10, 'last_byte' => $size - 1, 'covered_bytes' => $size, 'intervals' => '[[0,'.($size - 1).']]'];
        DB::table('test_delivery_coverage')->insert($this->coverage($s, $complete + ['completed_authorization_id' => $s['authorization']->id]));
        // After completion, re-served bytes append without a second completion, and a second completion is impossible.
        $this->rejected(fn () => DB::table('test_delivery_coverage')->insert($this->coverage($s, $complete + ['sequence' => 3, 'completed_authorization_id' => $s['authorization']->id])));
        DB::table('test_delivery_coverage')->insert($this->coverage($s, $complete + ['sequence' => 3]));
        $this->assertSame(1, TestDeliveryCoverage::whereNotNull('completed_authorization_id')->count());
        $this->rejected(fn () => DB::table('test_delivery_coverage')->where('sequence', 1)->update(['covered_bytes' => $size]));
        $this->rejected(fn () => DB::table('test_delivery_coverage')->where('sequence', 3)->delete());
        $this->assertDatabaseCount('test_delivery_coverage', 3);
    }

    public function test_rollback_refuses_retained_v2_evidence(): void
    {
        $this->started();
        $before = $this->triggers();
        try {
            $this->migration()->down();
            $this->fail('Rollback discarded v2 evidence.');
        } catch (LogicException) {
        }
        $this->assertSame($before, $this->triggers());
        $this->assertTrue(Schema::hasTable('test_delivery_coverage'));
    }

    public function test_empty_roundtrip_restores_the_exact_v1_guard_and_refuses_a_drifted_one(): void
    {
        $migration = $this->migration();
        $after = $this->triggers();
        $migration->down();
        $this->assertFalse(Schema::hasTable('test_delivery_coverage'));
        $restored = $this->triggers();
        $this->assertArrayHasKey('test_delivery_authorizations_valid_insert', $restored);
        $this->assertArrayNotHasKey('test_delivery_authorizations_valid_insert_v2', $restored);
        $this->assertStringContainsString("'test-owner-delivery-v1'", $restored['test_delivery_authorizations_valid_insert']);
        $this->assertStringNotContainsString("'test-owner-delivery-v2'", $restored['test_delivery_authorizations_valid_insert']);

        // A drifted installed guard is never adopted or replaced.
        $original = $restored['test_delivery_authorizations_valid_insert'];
        $drifted = str_replace('BETWEEN 1 AND 4294967293', 'BETWEEN 0 AND 4294967293', $original);
        $this->assertNotSame($original, $drifted);
        DB::unprepared('DROP TRIGGER test_delivery_authorizations_valid_insert');
        DB::unprepared(DB::getDriverName() === 'sqlite' ? $drifted
            : 'CREATE TRIGGER test_delivery_authorizations_valid_insert BEFORE INSERT ON test_delivery_authorizations FOR EACH ROW '.$drifted);
        try {
            $migration->up();
            $this->fail('A drifted guard was replaced.');
        } catch (LogicException) {
        }
        $this->assertFalse(Schema::hasTable('test_delivery_coverage'));
        $this->assertArrayNotHasKey('test_delivery_authorizations_valid_insert_v2', $this->triggers());
        DB::unprepared('DROP TRIGGER test_delivery_authorizations_valid_insert');
        DB::unprepared(DB::getDriverName() === 'sqlite' ? $original
            : 'CREATE TRIGGER test_delivery_authorizations_valid_insert BEFORE INSERT ON test_delivery_authorizations FOR EACH ROW '.$original);

        $migration->up();
        $this->assertSame($after, $this->triggers());
        $this->assertTrue(Schema::hasTable('test_delivery_coverage'));
    }
}
