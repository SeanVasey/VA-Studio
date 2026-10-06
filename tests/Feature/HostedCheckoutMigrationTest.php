<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutObservation;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures as F;
use Tests\TestCase;

/** Schema fixtures are synthetic evidence only; no provider adapter or network call is used. */
class HostedCheckoutMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
    }

    public function test_empty_checkout_tables_roundtrip_without_rewriting_existing_prepared_order_evidence(): void
    {
        $order = $this->prepared(true);
        $tables = ['orders', 'order_lines', 'order_attempts', 'quotes', 'quote_lines', 'quote_pricings',
            'inventory_reservations', 'inventory_claims', 'promotion_campaigns', 'promotion_uses', 'audit_events'];
        $before = [];
        foreach ($tables as $table) { $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(); }
        $status = app(ReadOrder::class)->handle($order->public_id, InventoryFixtures::OWNER);
        $migration = require database_path('migrations/2026_09_26_000018_hosted_test_checkout.php');
        $payments = require database_path('migrations/2026_09_26_000019_test_payment_evidence.php');
        $finalizations = require database_path('migrations/2026_09_26_000020_test_order_finalization.php');
        $contracts = require database_path('migrations/2026_09_26_000021_test_contract_issuance.php');
        $fulfillmentActivations = require database_path('migrations/2026_09_26_000022_test_fulfillment_activation.php');
        $delivery = require database_path('migrations/2026_09_26_000023_test_owner_delivery.php');
        $financialObservations = require database_path('migrations/2026_10_06_000039_test_payment_financial_observations.php');
        $purchaseClaims = require database_path('migrations/2026_10_06_000048_customer_purchase_claims.php');
        $unpaidRelease = require database_path('migrations/2026_10_06_000042_test_unpaid_releases.php');
        $purchaseClaims->down(); $unpaidRelease->down();
        $financialObservations->down();
        $exceptionOperations = require database_path('migrations/2026_10_02_000036_test_payment_exception_operations.php');
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        foreach (['stripe_receipt_work', 'payment_observations', 'verified_payments'] as $table) { $this->assertDatabaseCount($table, 0); }
        foreach (['checkout_intents', 'checkout_sessions', 'checkout_observations'] as $table) { $this->assertDatabaseCount($table, 0); }
        $exceptionOperations->down(); $delivery->down(); $fulfillmentActivations->down(); $contracts->down(); $finalizations->down(); $payments->down(); $migration->down();
        foreach (['checkout_intents', 'checkout_sessions', 'checkout_observations'] as $table) { $this->assertFalse(Schema::hasTable($table)); }
        $migration->up(); $payments->up(); $finalizations->up(); $contracts->up(); $fulfillmentActivations->up(); $delivery->up(); $exceptionOperations->up();
        $financialObservations->up();
        $unpaidRelease->up(); $purchaseClaims->up();
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        }
        $this->assertSame($status, app(ReadOrder::class)->handle($order->public_id, InventoryFixtures::OWNER));
        foreach (['checkout_intents', 'checkout_sessions', 'checkout_observations'] as $table) { $this->assertDatabaseCount($table, 0); }

        [$intent, $session, $observation] = $this->evidence($order);
        $this->assertTrue($intent->order->is($order));
        $this->assertTrue($intent->attempt->is($order->attempt()->sole()));
        $this->assertTrue($intent->session->is($session));
        $this->assertTrue($session->intent->is($intent));
        $this->assertTrue($session->observations()->sole()->is($observation));
        $this->assertTrue($observation->session->is($session));
        $this->assertSame('pending', DB::table('inventory_reservations')->sole()->state);
        $this->assertSame('pending', DB::table('promotion_uses')->sole()->state);
        $status['paymentStatus'] = 'not_verified';
        $this->assertSame($status, app(ReadOrder::class)->handle($order->public_id, InventoryFixtures::OWNER));
    }

    public static function immutableOperations(): array
    {
        return [['orm_update'], ['orm_delete'], ['sql_update'], ['sql_delete']];
    }

    #[DataProvider('immutableOperations')]
    public function test_checkout_evidence_rejects_model_and_bulk_mutation(string $operation): void
    {
        $models = $this->evidence($this->prepared());
        foreach ($models as $model) {
            $before = $model->refresh()->getAttributes();
            $change = $model instanceof CheckoutObservation ? ['status' => 'expired'] : ['created_at' => now()->addDay()];
            try {
                match ($operation) {
                    'orm_update' => $model->forceFill($change)->save(),
                    'orm_delete' => $model->delete(),
                    'sql_update' => DB::table($model->getTable())->where('id', $model->id)->update($change),
                    'sql_delete' => DB::table($model->getTable())->where('id', $model->id)->delete(),
                };
                $this->fail('Immutable '.$model->getTable().' evidence was changed.');
            } catch (LogicException|QueryException $error) {
                $this->assertInstanceOf(str_starts_with($operation, 'orm_') ? LogicException::class : QueryException::class, $error);
                $this->assertStringContainsString(str_contains($operation, 'delete') && str_starts_with($operation, 'orm_') ? 'retained' : 'immutable', strtolower($error->getMessage()));
                $this->assertSame($before, $model->fresh()->getAttributes());
            }
        }
    }

    public function test_unique_bindings_and_case_sensitive_account_scopes_prevent_ambiguous_session_identity(): void
    {
        $first = $this->prepared(); $second = $this->prepared(); $third = $this->prepared();
        $intent = CheckoutIntent::create($this->intentAttributes($first));
        $candidate = $this->intentAttributes($second);
        $this->uniqueRejected(fn () => CheckoutIntent::create([...$candidate, 'order_id' => $first->id]));
        $this->uniqueRejected(fn () => CheckoutIntent::create([...$candidate, 'order_attempt_id' => $first->attempt()->sole()->id]));
        $this->uniqueRejected(fn () => CheckoutIntent::create([...$candidate, 'public_id' => $intent->public_id]));
        $this->uniqueRejected(fn () => CheckoutIntent::create([...$candidate, 'idempotency_key' => $intent->idempotency_key]));
        $caseKey = CheckoutIntent::create([...$candidate, 'idempotency_key' => strtolower($intent->idempotency_key)]);
        $caseAccount = CheckoutIntent::create([...$this->intentAttributes($third),
            'account_id' => strtolower($intent->account_id), 'idempotency_key' => $intent->idempotency_key]);
        $this->assertDatabaseCount('checkout_intents', 3);

        $session = CheckoutSession::create($this->sessionAttributes($intent));
        $this->uniqueRejected(fn () => CheckoutSession::create([...$this->sessionAttributes($intent), 'provider_session_id' => 'cs_test_Different']));
        $this->uniqueRejected(fn () => CheckoutSession::create($this->sessionAttributes($caseKey)));
        CheckoutSession::create([...$this->sessionAttributes($caseKey), 'provider_session_id' => strtolower($session->provider_session_id)]);
        CheckoutSession::create($this->sessionAttributes($caseAccount));
        $this->assertDatabaseCount('checkout_sessions', 3);
        foreach (['checkout_intents' => ['account_id', 'mode', 'idempotency_key'],
            'checkout_sessions' => ['account_id', 'mode', 'provider_session_id']] as $table => $columns) {
            $scoped = array_filter(Schema::getIndexes($table), fn (array $index) => $index['unique'] && $index['columns'] === $columns);
            $this->assertCount(1, $scoped);
        }
    }

    public function test_foreign_keys_reject_orphan_records_and_retain_all_referenced_evidence(): void
    {
        $first = $this->prepared(); $second = $this->prepared();
        [$intent, $session] = $this->evidence($first);
        $orphan = 999999;
        $candidate = $this->intentAttributes($second);
        $operations = [
            fn () => CheckoutIntent::create([...$candidate, 'order_id' => $orphan]),
            fn () => CheckoutIntent::create([...$candidate, 'order_attempt_id' => $orphan]),
            fn () => CheckoutSession::create([...$this->sessionAttributes($intent), 'checkout_intent_id' => $orphan, 'provider_session_id' => 'cs_test_Orphan']),
            fn () => CheckoutObservation::create([...$this->observationAttributes($session), 'checkout_session_id' => $orphan]),
        ];
        foreach ($operations as $operation) {
            try { $operation(); $this->fail('Orphan checkout evidence was accepted.'); }
            catch (QueryException $error) { $this->assertStringContainsString('foreign key', strtolower($error->getMessage())); }
        }
        foreach (['checkout_intents' => 2, 'checkout_sessions' => 1, 'checkout_observations' => 1] as $table => $count) {
            $keys = Schema::getForeignKeys($table); $this->assertCount($count, $keys);
            foreach ($keys as $key) { $this->assertContains(strtolower($key['on_delete']), ['restrict', 'no action']); }
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('orders', 2); $this->assertDatabaseCount('order_attempts', 2);
    }

    public function test_observations_append_and_private_ciphertext_is_hidden_by_every_model(): void
    {
        [$intent, $session, $observation] = $this->evidence($this->prepared());
        $before = $observation->refresh()->getAttributes();
        $later = CheckoutObservation::create([...$this->observationAttributes($session),
            'observed_at' => now()->addMinute(), 'status' => 'expired']);
        $this->assertDatabaseCount('checkout_observations', 2);
        $this->assertSame($before, $observation->fresh()->getAttributes());
        $this->assertTrue($later->observed_at->greaterThan($observation->observed_at));
        foreach ([$intent, $session, $observation, $later] as $model) {
            $cipherField = $model instanceof CheckoutIntent ? 'request_ciphertext' : 'evidence_ciphertext';
            $hashField = $model instanceof CheckoutIntent ? 'request_hash' : 'evidence_hash';
            $this->assertSame(hash('sha256', $model->{$cipherField}), $model->{$hashField});
            $this->assertStringContainsString('checkout-private-marker@example.invalid', Crypt::decryptString($model->{$cipherField}));
            $this->assertStringNotContainsString('checkout-private-marker@example.invalid', $model->{$cipherField});
            $this->assertStringNotContainsString($cipherField, $model->toJson());
            $this->assertStringNotContainsString($hashField, $model->toJson());
        }
        $this->assertStringNotContainsString('idempotency_key', $intent->toJson());
        $this->assertStringNotContainsString('provider_session_id', $session->toJson());
    }

    private function prepared(bool $promoted = false): Order
    {
        $f = F::priced(false, $promoted);

        return app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), F::request($f['quote']));
    }

    private function evidence(Order $order): array
    {
        $intent = CheckoutIntent::create($this->intentAttributes($order));
        $session = CheckoutSession::create($this->sessionAttributes($intent));
        $observation = CheckoutObservation::create($this->observationAttributes($session));

        return [$intent, $session, $observation];
    }

    private function intentAttributes(Order $order): array
    {
        $cipher = Crypt::encryptString(CanonicalJson::encode(['fixture' => 'checkout-private-marker@example.invalid', 'order' => $order->public_id]));

        return ['public_id' => (string) Str::uuid(), 'order_id' => $order->id, 'order_attempt_id' => $order->attempt()->sole()->id,
            'account_id' => 'acct_Synthetic', 'mode' => 'test', 'idempotency_key' => 'Request-'.$order->public_id,
            'request_ciphertext' => $cipher, 'request_hash' => hash('sha256', $cipher), 'canonicalization_version' => CanonicalJson::VERSION,
            'created_at' => now(), 'initiate_before' => now()->addMinute(), 'retry_before' => now()->addMinutes(20),
            'provider_expires_at' => now()->addMinutes(30)];
    }

    private function sessionAttributes(CheckoutIntent $intent): array
    {
        $cipher = Crypt::encryptString(CanonicalJson::encode(['fixture' => 'checkout-private-marker@example.invalid', 'intent' => $intent->public_id]));

        return ['checkout_intent_id' => $intent->id, 'account_id' => $intent->account_id, 'mode' => $intent->mode,
            'provider_session_id' => 'cs_test_Synthetic', 'evidence_ciphertext' => $cipher,
            'evidence_hash' => hash('sha256', $cipher), 'canonicalization_version' => CanonicalJson::VERSION, 'created_at' => now()];
    }

    private function observationAttributes(CheckoutSession $session): array
    {
        $cipher = Crypt::encryptString(CanonicalJson::encode(['fixture' => 'checkout-private-marker@example.invalid', 'session' => $session->provider_session_id]));

        return ['checkout_session_id' => $session->id, 'observed_at' => now(), 'status' => 'open',
            'evidence_ciphertext' => $cipher, 'evidence_hash' => hash('sha256', $cipher), 'canonicalization_version' => CanonicalJson::VERSION];
    }

    private function uniqueRejected(callable $operation): void
    {
        try { $operation(); $this->fail('Duplicate checkout identity was accepted.'); }
        catch (QueryException $error) {
            $message = strtolower($error->getMessage());
            $this->assertTrue(str_contains($message, 'unique') || str_contains($message, 'duplicate'), $message);
        }
    }
}
