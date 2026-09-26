<?php

namespace Tests\Feature;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\ContractFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures as F;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** Synthetic manifests prove schema guards only; domain tests prove encrypted graph and physical-file checks. */
class TestFulfillmentActivationMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $activationSchemaGateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
        $this->activationSchemaGateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->activationSchemaGateway);
        $this->app->instance(StripePaymentGateway::class, $this->activationSchemaGateway);
    }

    public function test_empty_activation_roundtrip_preserves_originals_grants_and_pending_rows(): void
    {
        $fixture = $this->activationSchemaPaid();
        $this->activationSchemaOriginal($fixture['grants'][0]);
        $before = ContractFixtures::retained();
        $migration = require database_path('migrations/2026_09_26_000022_test_fulfillment_activation.php');
        $delivery = require database_path('migrations/2026_09_26_000023_test_owner_delivery.php');
        $delivery->down(); $migration->down(); $this->assertFalse(Schema::hasTable('test_fulfillment_activations'));
        $this->assertSame($before, ContractFixtures::retained());
        $migration->up(); $delivery->up(); $this->assertDatabaseCount('test_fulfillment_activations', 0);
        $this->assertSame($before, ContractFixtures::retained());
        $this->assertSame('pending', DB::table('pending_entitlements')->sole()->state);
        $this->assertSame('pending', DB::table('fulfillment_outbox')->sole()->state);
    }

    public function test_populated_rollback_and_model_or_sql_mutations_cannot_erase_recorded_decision(): void
    {
        $fixture = $this->activationSchemaPaid(); $this->activationSchemaOriginal($fixture['grants'][0]);
        $activation = TestFulfillmentActivation::create($this->activationSchemaAttributes($fixture));
        $before = $activation->refresh()->getAttributes(); $retained = ContractFixtures::retained();
        $migration = require database_path('migrations/2026_09_26_000022_test_fulfillment_activation.php');
        try { $migration->down(); $this->fail('Populated activation rollback erased evidence.'); }
        catch (LogicException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
        $this->assertTrue(Schema::hasTable('test_fulfillment_activations'));
        foreach (['orm_update', 'orm_delete', 'sql_update', 'sql_delete'] as $operation) {
            $activation->refresh();
            try {
                match ($operation) {
                    'orm_update' => $activation->forceFill(['activated_at' => now()->addSecond()])->save(),
                    'orm_delete' => $activation->delete(),
                    'sql_update' => DB::table('test_fulfillment_activations')->where('id', $activation->id)->update(['activated_at' => now()->addSecond()]),
                    'sql_delete' => DB::table('test_fulfillment_activations')->where('id', $activation->id)->delete(),
                };
                $this->fail('Retained activation evidence changed.');
            } catch (LogicException|QueryException $error) {
                $this->assertInstanceOf(str_starts_with($operation, 'orm_') ? LogicException::class : QueryException::class, $error);
            }
            $this->assertSame($before, $activation->fresh()->getAttributes());
        }
        $this->assertSame($retained, ContractFixtures::retained());
    }

    public function test_every_line_requires_a_completed_original_before_one_whole_order_decision(): void
    {
        $fixture = $this->activationSchemaPaid(true); $attributes = $this->activationSchemaAttributes($fixture);
        $this->activationSchemaRejected(fn () => TestFulfillmentActivation::create($attributes));
        $first = $this->activationSchemaOriginal($fixture['grants'][0], false);
        $this->activationSchemaRejected(fn () => TestFulfillmentActivation::create($attributes));
        $this->activationSchemaComplete($first['work']);
        $this->activationSchemaRejected(fn () => TestFulfillmentActivation::create($attributes));
        $second = $this->activationSchemaOriginal($fixture['grants'][1], false);
        $this->activationSchemaRejected(fn () => TestFulfillmentActivation::create($attributes));
        $this->activationSchemaComplete($second['work']);
        $retained = ContractFixtures::retained();
        TestFulfillmentActivation::create($attributes);
        $this->assertDatabaseCount('test_fulfillment_activations', 1);
        $this->assertSame($retained, ContractFixtures::retained());
        $this->activationSchemaRejected(fn () => TestFulfillmentActivation::create([...$attributes, 'public_id' => (string) Str::uuid()]));
    }

    public function test_shape_and_observation_bounds_reject_invalid_or_stale_evidence_and_accept_exact_300_seconds(): void
    {
        $fixture = $this->activationSchemaPaid(); $this->activationSchemaOriginal($fixture['grants'][0]);
        $attributes = $this->activationSchemaAttributes($fixture);
        foreach ([['public_id' => 'not-a-uuid'], ['public_id' => str_repeat('a', 36)],
            ['public_id' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'], ['evidence_hash' => 'short'],
            ['evidence_hash' => str_repeat('A', 64)], ['evidence_hash' => str_repeat('g', 64)],
            ['evidence_ciphertext' => ''], ['evidence_ciphertext' => str_repeat('a', 4194305)],
            ['policy_version' => 'test-fulfillment-activation-v2'], ['policy_version' => 'test-fulfillment-activation-v1 '],
            ['canonicalization_version' => 'unknown'], ['canonicalization_version' => 'vasey-json-v1 '],
            ['order_id' => 999999], ['order_finalization_id' => 999999],
            ['verified_from' => now()->subSecond()], ['verified_from' => now()->addSecond()],
            ['verified_through' => now()->subSecond()], ['verified_through' => now()->addSecond()],
            ['activated_at' => now()->subSecond()], ['activated_at' => now()->addSeconds(301)]] as $invalid) {
            $this->activationSchemaRejected(fn () => TestFulfillmentActivation::create([...$attributes, ...$invalid]));
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['2026-09-26T12:00:00Z', '2026-09-26 12:00:00.1', '2026-02-30 12:00:00', 'not-a-date'] as $invalid) {
                $this->activationSchemaRejected(fn () => DB::table('test_fulfillment_activations')->insert([...$attributes, 'verified_from' => $invalid]));
            }
        }
        $activation = TestFulfillmentActivation::create([...$attributes, 'verified_through' => now()->addSeconds(299),
            'activated_at' => now()->addSeconds(300)]);
        $this->assertTrue($activation->fresh()->activated_at->equalTo(now()->addSeconds(300)));
    }

    public function test_observation_cannot_predate_the_last_original_even_after_paid_finalization(): void
    {
        $fixture = $this->activationSchemaPaid(); $attributes = $this->activationSchemaAttributes($fixture);
        $this->travelTo(now()->addSecond()); $this->activationSchemaOriginal($fixture['grants'][0]);
        $this->activationSchemaRejected(fn () => TestFulfillmentActivation::create([...$attributes,
            'verified_through' => now(), 'activated_at' => now()]));
        TestFulfillmentActivation::create($this->activationSchemaAttributes($fixture));
        $this->assertDatabaseCount('test_fulfillment_activations', 1);
    }

    public function test_paid_exception_cannot_create_activation(): void
    {
        $fixture = PaymentFixtures::started($this->activationSchemaGateway);
        $this->travelTo($fixture['order']->attempt->expires_at);
        $fixture = F::confirm($fixture);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($fixture['payment']->id));
        $fixture['finalization'] = OrderFinalization::where('order_id', $fixture['order']->id)->sole();
        $this->activationSchemaRejected(fn () => TestFulfillmentActivation::create($this->activationSchemaAttributes($fixture)));
        $this->assertDatabaseCount('test_fulfillment_activations', 0);
    }

    public function test_model_relations_casts_and_hidden_evidence_do_not_activate_pending_rows(): void
    {
        $fixture = $this->activationSchemaPaid(); $this->activationSchemaOriginal($fixture['grants'][0]);
        $activation = TestFulfillmentActivation::create($this->activationSchemaAttributes($fixture));
        $this->assertTrue($activation->order->is($fixture['order']));
        $this->assertTrue($activation->finalization->is($fixture['finalization']));
        foreach (['verified_from', 'verified_through', 'activated_at'] as $field) {
            $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $activation->{$field});
            $this->assertTrue($activation->{$field}->equalTo(now()));
        }
        foreach (['evidence_ciphertext', 'evidence_hash'] as $field) { $this->assertArrayNotHasKey($field, $activation->attributesToArray()); }
        $this->assertSame('pending', DB::table('pending_entitlements')->sole()->state);
        $this->assertSame('pending', DB::table('fulfillment_outbox')->sole()->state);
    }

    public function test_parent_keys_restrict_deletion_and_one_decision_has_unique_order_finalization_and_public_identity(): void
    {
        $keys = Schema::getForeignKeys('test_fulfillment_activations'); $this->assertCount(2, $keys);
        foreach ($keys as $key) { $this->assertContains(strtolower($key['on_delete']), ['restrict', 'no action']); }
        foreach ([['public_id'], ['order_id'], ['order_finalization_id']] as $columns) {
            $this->assertCount(1, array_filter(Schema::getIndexes('test_fulfillment_activations'), fn ($index) => $index['unique'] && $index['columns'] === $columns));
        }
    }

    private function activationSchemaPaid(bool $mixed = false): array
    {
        $fixture = $mixed ? F::confirmedMixedCart($this->activationSchemaGateway) : F::confirmed($this->activationSchemaGateway);
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($fixture['payment']->id));
        $fixture['finalization'] = OrderFinalization::where('order_id', $fixture['order']->id)->sole();
        $fixture['grants'] = LicenseGrant::where('order_finalization_id', $fixture['finalization']->id)->orderBy('id')->get();

        return $fixture;
    }

    private function activationSchemaOriginal(LicenseGrant $grant, bool $completed = true): array
    {
        $profile = ['schema_version' => 1, 'purpose' => 'synthetic_schema_only_profile'];
        $request = ContractRenderRequest::create(['public_id' => (string) Str::uuid(), 'license_grant_id' => $grant->id,
            'fulfillment_outbox_id' => FulfillmentOutbox::where('license_grant_id', $grant->id)->sole()->id,
            'input_hash' => $grant->render_input_hash, 'profile' => $profile, 'profile_hash' => CanonicalJson::hash($profile),
            'canonicalization_version' => CanonicalJson::VERSION, 'document_public_id' => (string) Str::uuid(), 'created_at' => now()]);
        $work = ContractRenderWork::create(['contract_render_request_id' => $request->id]);
        $work->update(['state' => 'processing', 'attempts' => 1, 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds(300)]);
        $contract = GrantContract::create(['public_id' => $request->document_public_id, 'license_grant_id' => $grant->id,
            'contract_render_request_id' => $request->id, 'input_hash' => $request->input_hash, 'profile_hash' => $request->profile_hash,
            'disk' => 'local', 'storage_path' => 'contracts/test/'.$request->public_id.'/'.$work->claim_token.'/original.pdf',
            'claim_token' => $work->claim_token, 'pdf_hash' => str_repeat('a', 64), 'size_bytes' => 1024, 'page_count' => 1, 'issued_at' => now()]);
        if ($completed) { $this->activationSchemaComplete($work); }

        return compact('request', 'work', 'contract');
    }

    private function activationSchemaComplete(ContractRenderWork $work): void
    {
        $work->update(['state' => 'completed', 'claim_token' => null, 'lease_expires_at' => null]);
    }

    private function activationSchemaAttributes(array $fixture): array
    {
        $ciphertext = Crypt::encryptString(CanonicalJson::encode(['purpose' => 'synthetic_schema_only_evidence']));

        return ['public_id' => (string) Str::uuid(), 'order_id' => $fixture['order']->id,
            'order_finalization_id' => $fixture['finalization']->id, 'policy_version' => 'test-fulfillment-activation-v1',
            'evidence_ciphertext' => $ciphertext, 'evidence_hash' => hash('sha256', $ciphertext),
            'canonicalization_version' => CanonicalJson::VERSION, 'verified_from' => now(), 'verified_through' => now(), 'activated_at' => now()];
    }

    private function activationSchemaRejected(callable $operation): void
    {
        try { $operation(); $this->fail('Contradictory activation schema write was accepted.'); }
        catch (QueryException $error) { $this->assertNotSame('', $error->getMessage()); }
    }
}
