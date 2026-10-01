<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class UuidByteGuardMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    public function test_uuid_predicates_count_stored_bytes_and_preserve_existing_format_policies(): void
    {
        $migration = $this->migration();
        foreach ([
            'canonical' => [self::UUID, true, true],
            'nil UUID remains allowed' => ['00000000-0000-0000-0000-000000000000', true, true],
            'uppercase length-only' => [strtoupper(self::UUID), true, false],
            'arbitrary length-only' => [str_repeat('g', 36), true, false],
            'wrong separators' => [str_replace('-', '_', self::UUID), true, false],
            'short' => [substr(self::UUID, 0, 35), false, false],
            'long' => [self::UUID.'a', false, false],
            'NUL suffix' => [self::UUID."\0suffix", false, false],
            'NUL terminator' => [self::UUID."\0", false, false],
            'embedded NUL' => [substr(self::UUID, 0, 20)."\0".substr(self::UUID, 21), DB::getDriverName() === 'mysql', false],
            'newline suffix' => [self::UUID."\n", false, false],
            'space suffix' => [self::UUID.' ', false, false],
            'multibyte characters' => [str_repeat('é', 36), false, false],
            'null' => [null, false, false],
        ] as $case => [$value, $lengthOnly, $canonical]) {
            foreach ([false => $lengthOnly, true => $canonical] as $strict => $expected) {
                $predicate = $migration->uuidBytes('candidate', (bool) $strict);
                $actual = DB::selectOne("SELECT COALESCE(({$predicate}), 0) AS accepted FROM (SELECT ? AS candidate) probe", [$value]);
                $this->assertSame($expected, (bool) $actual->accepted, $case.'; canonical='.(int) $strict);
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach ([false, true] as $strict) {
                $predicate = $migration->uuidBytes('candidate', $strict);
                $actual = DB::selectOne("SELECT COALESCE(({$predicate}), 0) AS accepted FROM (SELECT CAST(? AS BLOB) AS candidate) probe", [self::UUID]);
                $this->assertFalse((bool) $actual->accepted);
            }
        }
    }

    public function test_request_and_render_claim_writes_reject_nul_tails_and_blob_values_while_nullable_states_remain_valid(): void
    {
        $fixture = ContractFixtures::paid($this->gateway());
        $attributes = $this->request($fixture);
        foreach (['public_id', 'document_public_id'] as $column) {
            foreach ($this->malformedUuids() as $uuid) {
                $this->rejected(fn () => DB::table('contract_render_requests')->insert([...$attributes, $column => $uuid]));
            }
        }
        $request = DB::table('contract_render_requests')->insertGetId($attributes);
        $work = DB::table('contract_render_work')->insertGetId(['contract_render_request_id' => $request,
            'state' => 'pending', 'attempts' => 0, 'claim_token' => null, 'created_at' => now(), 'updated_at' => now()]);
        $this->migration()->up();
        $this->assertNull(DB::table('contract_render_work')->where('id', $work)->value('claim_token'));
        $claim = ['state' => 'processing', 'attempts' => 1, 'claim_token' => self::UUID,
            'lease_expires_at' => now()->addSeconds(300), 'next_attempt_at' => null, 'reason' => null, 'updated_at' => now()];
        foreach ($this->malformedUuids() as $uuid) {
            $this->rejected(fn () => DB::table('contract_render_work')->where('id', $work)->update([...$claim, 'claim_token' => $uuid]));
        }
        DB::table('contract_render_work')->where('id', $work)->update($claim);
        $this->migration()->up();
        $this->assertSame(self::UUID, DB::table('contract_render_work')->where('id', $work)->value('claim_token'));
        DB::table('contract_render_work')->where('id', $work)->update(['state' => 'retry', 'claim_token' => null,
            'lease_expires_at' => null, 'reason' => 'render_failed', 'next_attempt_at' => now()->addMinute(), 'updated_at' => now()]);
        $this->migration()->up();
        $this->assertNull(DB::table('contract_render_work')->where('id', $work)->value('claim_token'));
    }

    public function test_receipt_claims_keep_nullable_states_length_only_policy_and_mysql_character_coercion(): void
    {
        $work = $this->receiptWork();
        $this->assertNull(DB::table('stripe_receipt_work')->where('id', $work)->value('claim_token'));
        $claim = ['state' => 'processing', 'attempts' => 1, 'claim_token' => str_repeat('g', 36), 'lease_expires_at' => now()->addMinute()];
        foreach ($this->malformedUuids() as $uuid) {
            $this->rejected(fn () => DB::table('stripe_receipt_work')->where('id', $work)->update([...$claim, 'claim_token' => $uuid]));
        }
        DB::table('stripe_receipt_work')->where('id', $work)->update($claim);
        $this->migration()->up();
        $this->assertSame(str_repeat('g', 36), DB::table('stripe_receipt_work')->where('id', $work)->value('claim_token'));
        if (DB::getDriverName() === 'mysql') {
            DB::table('stripe_receipt_work')->where('id', $work)->update(['claim_token' => DB::raw("CAST('".self::UUID."' AS BINARY)")]);
            $this->assertSame(self::UUID, DB::table('stripe_receipt_work')->where('id', $work)->value('claim_token'));
        }
        DB::table('stripe_receipt_work')->where('id', $work)->update(['state' => 'processed', 'claim_token' => null, 'lease_expires_at' => null]);
        $this->migration()->up();
        $this->assertNull(DB::table('stripe_receipt_work')->where('id', $work)->value('claim_token'));
    }

    public function test_populated_upgrade_retains_originals_and_canonical_identifiers_without_weakening_lifecycle_guards(): void
    {
        $migration = $this->migration(); $migration->down();
        $fixture = DeliveryFixtures::ready($this->gateway());
        $before = DeliveryFixtures::retained();
        $control = (array) DB::table('test_delivery_controls')->where('id', $fixture['delivery_control']->id)->sole();
        $migration->up(); $migration->up();
        $this->assertSame($before, DeliveryFixtures::retained());
        $this->assertSame($control, (array) DB::table('test_delivery_controls')->where('id', $control['id'])->sole());
        foreach ($this->malformedUuids() as $uuid) {
            $this->rejected(fn () => DB::table('test_delivery_controls')->where('id', $control['id'])
                ->update(['public_id' => $uuid, 'blocked' => true, 'control_version' => $control['control_version'] + 1]));
        }
        DB::table('test_delivery_controls')->where('id', $control['id'])
            ->update(['blocked' => true, 'control_version' => $control['control_version'] + 1]);
        $this->assertSame($control['public_id'], DB::table('test_delivery_controls')->where('id', $control['id'])->value('public_id'));
        $migration->down();
        $this->rejected(fn () => DB::table('grant_contracts')->delete());
        $this->rejected(fn () => DB::table('test_delivery_controls')->where('id', $control['id'])->delete());
        $migration->up();
        $this->assertSame($before, DeliveryFixtures::retained());
    }

    public function test_preflight_reports_a_retained_request_uuid_with_no_rewrite_or_partial_installation(): void
    {
        $migration = $this->migration(); $migration->down();
        $fixture = ContractFixtures::paid($this->gateway());
        $uuid = self::UUID."\0suffix";
        if (DB::getDriverName() === 'mysql') {
            // MySQL's declared VARCHAR(36) already rejects the SQLite NUL-tail bypass.
            // A missing legacy guard and a short value exercise retained corruption there.
            DB::unprepared('DROP TRIGGER contract_render_requests_valid_insert');
            $uuid = 'retained-invalid-uuid';
        }
        $id = DB::table('contract_render_requests')->insertGetId([...$this->request($fixture), 'public_id' => $uuid]);
        $before = (array) DB::table('contract_render_requests')->where('id', $id)->sole();
        $this->preflightRejected($migration, 'contract_render_requests.public_id');
        $this->assertSame($before, (array) DB::table('contract_render_requests')->where('id', $id)->sole());
        $this->rejected(fn () => DB::table('contract_render_requests')->where('id', $id)->delete());
    }

    public function test_preflight_reports_a_retained_nullable_claim_with_no_rewrite(): void
    {
        $migration = $this->migration(); $migration->down();
        $id = $this->receiptWork(); $uuid = self::UUID."\0suffix";
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER stripe_receipt_work_valid_update');
            $uuid = 'retained-invalid-claim';
        }
        DB::table('stripe_receipt_work')->where('id', $id)->update(['state' => 'processing', 'claim_token' => $uuid,
            'lease_expires_at' => now()->addMinute(), 'attempts' => 1]);
        $before = (array) DB::table('stripe_receipt_work')->where('id', $id)->sole();
        $this->preflightRejected($migration, 'stripe_receipt_work.claim_token');
        $this->assertSame($before, (array) DB::table('stripe_receipt_work')->where('id', $id)->sole());
        $this->rejected(fn () => DB::table('stripe_receipt_work')->where('id', $id)->delete());
    }

    public function test_partial_installation_retries_verify_the_expected_trigger_set_and_down_preserves_original_guards(): void
    {
        $expected = [
            'uuid_bytes_stripe_receipt_work_insert', 'uuid_bytes_stripe_receipt_work_update',
            'uuid_bytes_order_finalizations_insert', 'uuid_bytes_license_grants_insert', 'uuid_bytes_fulfillment_outbox_insert',
            'uuid_bytes_contract_render_requests_insert', 'uuid_bytes_contract_render_work_insert', 'uuid_bytes_contract_render_work_update',
            'uuid_bytes_grant_contracts_insert', 'uuid_bytes_test_fulfillment_activations_insert',
            'uuid_bytes_test_delivery_controls_insert', 'uuid_bytes_test_delivery_controls_update',
            'uuid_bytes_test_delivery_authorizations_insert', 'uuid_bytes_test_delivery_redemptions_insert',
        ];
        sort($expected); $this->assertSame($expected, $this->triggers());
        $migration = $this->migration();
        DB::unprepared('DROP TRIGGER uuid_bytes_contract_render_work_update');
        $migration->up(); $migration->up();
        $this->assertSame($expected, $this->triggers());
        $migration->down(); $this->assertSame([], $this->triggers());
        $work = $this->receiptWork();
        $this->rejected(fn () => DB::table('stripe_receipt_work')->where('id', $work)->delete());
        $migration->up(); $this->assertSame($expected, $this->triggers());
    }

    public function test_retry_refuses_a_same_named_unrelated_trigger_without_replacing_it(): void
    {
        $migration = $this->migration(); $migration->down();
        $name = 'uuid_bytes_stripe_receipt_work_insert';
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE INSERT ON stripe_receipt_work BEGIN SELECT 1; END"
            : "CREATE TRIGGER {$name} BEFORE INSERT ON stripe_receipt_work FOR EACH ROW BEGIN IF 1 = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic collision'; END IF; END");
        try { $migration->up(); $this->fail('An unrelated UUID trigger was trusted.'); }
        catch (LogicException $error) {
            $this->assertSame("Unexpected UUID guard definition for {$name}; the existing guard is unchanged. Investigate before retrying the migration.", $error->getMessage());
        }
        $this->assertSame([$name], $this->triggers());
    }

    private function gateway(): StripePaymentGateway
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); DeliveryFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());

        return $gateway;
    }

    private function request(array $fixture): array
    {
        $profile = ['schema_version' => 1, 'purpose' => 'synthetic_uuid_schema_test']; $grant = $fixture['grant'];

        return ['public_id' => (string) Str::uuid(), 'license_grant_id' => $grant->id,
            'fulfillment_outbox_id' => FulfillmentOutbox::where('license_grant_id', $grant->id)->sole()->id,
            'input_hash' => $grant->render_input_hash, 'profile' => CanonicalJson::encode($profile), 'profile_hash' => CanonicalJson::hash($profile),
            'canonicalization_version' => CanonicalJson::VERSION, 'document_public_id' => (string) Str::uuid(), 'created_at' => now()];
    }

    private function receiptWork(): int
    {
        $receipt = DB::table('stripe_webhook_receipts')->insertGetId(['account_id' => 'acct_SYNTHETICUUID', 'livemode' => false,
            'event_id' => 'evt_'.Str::uuid(), 'event_type' => 'checkout.session.completed', 'object_type' => 'checkout.session',
            'provider_created_at' => time(), 'signature_timestamp' => time(), 'payload_sha256' => str_repeat('a', 64),
            'event_fingerprint' => str_repeat('b', 64), 'fingerprint_version' => 'synthetic-uuid-test',
            'payload_ciphertext' => 'synthetic schema evidence', 'received_at' => now()]);

        return DB::table('stripe_receipt_work')->insertGetId(['stripe_webhook_receipt_id' => $receipt, 'state' => 'pending',
            'claim_token' => null, 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function malformedUuids(): array
    {
        $values = [self::UUID."\0suffix", self::UUID."\0", self::UUID."\n", str_repeat('é', 36)];
        if (DB::getDriverName() === 'sqlite') { $values[] = DB::raw("CAST('".self::UUID."' AS BLOB)"); }

        return $values;
    }

    private function preflightRejected(object $migration, string $field): void
    {
        try { $migration->up(); $this->fail('Malformed retained UUID was accepted.'); }
        catch (LogicException $error) {
            $this->assertSame("Invalid retained UUID bytes in {$field}; evidence is unchanged. Investigate before retrying the migration.", $error->getMessage());
        }
        $this->assertSame([], $this->triggers());
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_01_000030_byte_exact_uuid_guards.php');
    }

    private function triggers(): array
    {
        $names = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'uuid_bytes_%')->pluck('name')->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('TRIGGER_NAME', 'like', 'uuid_bytes_%')->pluck('TRIGGER_NAME')->all();
        sort($names);

        return $names;
    }

    private function rejected(callable $operation): void
    {
        try { $operation(); $this->fail('Invalid or immutable UUID evidence was accepted.'); }
        catch (QueryException) { $this->addToAssertionCount(1); }
    }
}
