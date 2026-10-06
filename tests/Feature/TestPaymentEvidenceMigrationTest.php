<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\PaymentObservation;
use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\CheckoutFixtures as F;
use Tests\TestCase;

/** Synthetic storage fixtures prove database guards, not successful provider payment. */
class TestPaymentEvidenceMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
    }

    public function test_empty_payment_migration_roundtrip_preserves_existing_private_evidence_and_pending_resources(): void
    {
        $this->schemaCheckout(F::ACCOUNT, true);
        $this->schemaReceipt();
        $tables = ['orders', 'order_lines', 'order_attempts', 'checkout_intents', 'checkout_sessions',
            'stripe_webhook_receipts', 'inventory_reservations', 'inventory_claims', 'promotion_uses', 'audit_events'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $migration = require database_path('migrations/2026_09_26_000019_test_payment_evidence.php');
        $finalizations = require database_path('migrations/2026_09_26_000020_test_order_finalization.php');
        $contracts = require database_path('migrations/2026_09_26_000021_test_contract_issuance.php');
        $fulfillmentActivations = require database_path('migrations/2026_09_26_000022_test_fulfillment_activation.php');
        $delivery = require database_path('migrations/2026_09_26_000023_test_owner_delivery.php');
        $financialObservations = require database_path('migrations/2026_10_06_000039_test_payment_financial_observations.php');
        $unpaidRelease = require database_path('migrations/2026_10_06_000042_test_unpaid_releases.php');
        $unpaidRelease->down();
        $financialObservations->down();
        $exceptionOperations = require database_path('migrations/2026_10_02_000036_test_payment_exception_operations.php');
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        foreach (['stripe_receipt_work', 'payment_observations', 'verified_payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $exceptionOperations->down(); $delivery->down(); $fulfillmentActivations->down(); $contracts->down(); $finalizations->down(); $migration->down();
        foreach (['stripe_receipt_work', 'payment_observations', 'verified_payments'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $migration->up(); $finalizations->up(); $contracts->up(); $fulfillmentActivations->up(); $delivery->up(); $exceptionOperations->up();
        $financialObservations->up();
        $unpaidRelease->up();
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        }
        $this->assertSame('pending', DB::table('inventory_reservations')->sole()->state);
        $this->assertSame('pending', DB::table('promotion_uses')->sole()->state);
        foreach (['stripe_receipt_work', 'payment_observations', 'verified_payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_work_state_is_mutable_while_receipt_identity_and_evidence_remain_unchanged(): void
    {
        $receipt = $this->schemaReceipt();
        $original = $receipt->refresh()->getAttributes();
        $work = StripeReceiptWork::create(['stripe_webhook_receipt_id' => $receipt->id]);
        $this->assertSame('stripe_receipt_work', $work->getTable());
        $this->assertSame('pending', $work->state);
        $this->assertSame(0, $work->attempts);
        $this->assertTrue($work->receipt->is($receipt));
        $createdAt = $work->created_at;

        $work->update(['state' => 'processing', 'claim_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinute(), 'attempts' => 1]);
        $this->assertSame('processing', $work->fresh()->state);
        $this->assertStringNotContainsString('claim_token', $work->toJson());
        $work->update(['state' => 'retry', 'claim_token' => null, 'lease_expires_at' => null,
            'next_attempt_at' => now()->addMinutes(5), 'outcome' => 'PROVIDER_UNAVAILABLE']);
        $this->assertTrue($work->fresh()->next_attempt_at->equalTo(now()->addMinutes(5)));
        $work->update(['state' => 'processing', 'claim_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinute(), 'attempts' => 2, 'next_attempt_at' => null]);
        $work->update(['state' => 'processed', 'claim_token' => null, 'lease_expires_at' => null, 'outcome' => 'confirmed']);

        $this->assertSame(2, $work->fresh()->attempts);
        $this->assertTrue($work->fresh()->created_at->equalTo($createdAt));
        $this->assertSame($original, $receipt->fresh()->getAttributes());
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('payment_observations', 0);
    }

    public static function invalidWorkExamples(): array
    {
        return [
            'unknown state' => [['state' => 'paid']],
            'uppercase state' => [['state' => 'PENDING']],
            'processing without claim' => [['state' => 'processing']],
            'processing without lease' => [['state' => 'processing', 'claim_token' => '00000000-0000-4000-8000-000000000000']],
            'processing without token' => [['state' => 'processing', 'lease_expires_at' => '2026-09-27 00:00:00']],
            'short token' => [['state' => 'processing', 'claim_token' => 'short', 'lease_expires_at' => '2026-09-27 00:00:00']],
            'nonprocessing token' => [['state' => 'retry', 'claim_token' => '00000000-0000-4000-8000-000000000000']],
            'nonprocessing lease' => [['state' => 'processed', 'lease_expires_at' => '2026-09-27 00:00:00']],
            'negative attempts' => [['attempts' => -1]],
            'unbounded outcome' => [['outcome' => str_repeat('x', 65)]],
        ];
    }

    #[DataProvider('invalidWorkExamples')]
    public function test_invalid_work_claims_are_rejected_by_models_and_direct_sql(array $invalid): void
    {
        $receipt = $this->schemaReceipt();
        $attributes = ['stripe_webhook_receipt_id' => $receipt->id, 'state' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now()];
        try {
            StripeReceiptWork::create([...$attributes, ...$invalid]);
            $this->fail('Invalid work state was accepted by the model.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Invalid', $error->getMessage());
        }
        $this->schemaRejected(fn () => DB::table('stripe_receipt_work')->insert([...$attributes, ...$invalid]));
        $work = StripeReceiptWork::create($attributes);
        $before = $work->refresh()->getAttributes();
        $this->schemaRejected(fn () => DB::table('stripe_receipt_work')->where('id', $work->id)->update($invalid));
        $this->assertSame($before, $work->fresh()->getAttributes());
    }

    public static function evidenceMutationExamples(): array
    {
        return [['orm_update'], ['orm_delete'], ['sql_update'], ['sql_delete']];
    }

    #[DataProvider('evidenceMutationExamples')]
    public function test_payment_evidence_and_receipt_work_identity_reject_model_and_bulk_mutation(string $operation): void
    {
        [$intent, $session] = $this->schemaCheckout();
        $receipt = $this->schemaReceipt();
        $models = [
            StripeReceiptWork::create(['stripe_webhook_receipt_id' => $receipt->id]),
            PaymentObservation::create($this->schemaObservationAttributes($intent, $receipt)),
            VerifiedPayment::create($this->schemaPaymentAttributes($intent, $session)),
        ];
        foreach ($models as $model) {
            $before = $model->refresh()->getAttributes();
            $change = match (true) {
                $model instanceof StripeReceiptWork => ['created_at' => now()->addDay()],
                $model instanceof PaymentObservation => ['outcome' => 'expired'],
                default => ['amount_minor' => 1],
            };
            try {
                match ($operation) {
                    'orm_update' => $model->forceFill($change)->save(),
                    'orm_delete' => $model->delete(),
                    'sql_update' => DB::table($model->getTable())->where('id', $model->id)->update($change),
                    'sql_delete' => DB::table($model->getTable())->where('id', $model->id)->delete(),
                };
                $this->fail('Retained '.$model->getTable().' evidence was changed.');
            } catch (LogicException|QueryException $error) {
                $this->assertInstanceOf(str_starts_with($operation, 'orm_') ? LogicException::class : QueryException::class, $error);
                $this->assertSame($before, $model->fresh()->getAttributes());
            }
        }
        $otherReceipt = $this->schemaReceipt();
        $this->schemaRejected(fn () => DB::table('stripe_receipt_work')->where('id', $models[0]->id)
            ->update(['stripe_webhook_receipt_id' => $otherReceipt->id]));
        $this->assertSame($receipt->id, $models[0]->fresh()->stripe_webhook_receipt_id);
    }

    public function test_append_only_observations_retain_private_evidence_and_model_relationships(): void
    {
        [$intent, $session] = $this->schemaCheckout(F::ACCOUNT, true);
        $receipt = $this->schemaReceipt();
        $first = PaymentObservation::create($this->schemaObservationAttributes($intent));
        $original = $first->refresh()->getAttributes();
        $confirmed = PaymentObservation::create([...$this->schemaObservationAttributes($intent, $receipt),
            'outcome' => 'confirmed', 'observed_at' => now()->addMinute()]);
        $payment = VerifiedPayment::create($this->schemaPaymentAttributes($intent, $session));
        $this->assertSame($original, $first->fresh()->getAttributes());
        $this->assertNull($first->receipt);
        $this->assertTrue($confirmed->receipt->is($receipt));
        $this->assertTrue($confirmed->intent->is($intent));
        $this->assertTrue($payment->order->is($intent->order));
        $this->assertTrue($payment->attempt->is($intent->attempt));
        $this->assertTrue($payment->intent->is($intent));
        $this->assertTrue($payment->session->is($session));
        $this->assertTrue($confirmed->observed_at->greaterThan($first->observed_at));
        $this->assertDatabaseCount('payment_observations', 2);
        $this->assertSame('pending', DB::table('inventory_reservations')->sole()->state);
        $this->assertSame('pending', DB::table('promotion_uses')->sole()->state);
        foreach ([$first, $confirmed, $payment] as $model) {
            $this->assertSame(hash('sha256', $model->evidence_ciphertext), $model->evidence_hash);
            $this->assertStringContainsString('payment-private-marker@example.invalid', Crypt::decryptString($model->evidence_ciphertext));
            $this->assertStringNotContainsString('payment-private-marker@example.invalid', $model->evidence_ciphertext);
            foreach (['account_id', 'evidence_ciphertext', 'evidence_hash', 'provider_payment_intent_id'] as $field) {
                $this->assertArrayNotHasKey($field, $model->attributesToArray());
            }
        }
    }

    public function test_unique_confirmation_bindings_and_case_sensitive_provider_scopes_are_retained(): void
    {
        [$first, $firstSession] = $this->schemaCheckout();
        [$second, $secondSession] = $this->schemaCheckout();
        [$third, $thirdSession] = $this->schemaCheckout(strtolower(F::ACCOUNT));
        $receipt = $this->schemaReceipt();
        StripeReceiptWork::create(['stripe_webhook_receipt_id' => $receipt->id]);
        $this->schemaRejected(fn () => StripeReceiptWork::create(['stripe_webhook_receipt_id' => $receipt->id]), true);
        $payment = VerifiedPayment::create($this->schemaPaymentAttributes($first, $firstSession));
        $this->schemaRejected(fn () => VerifiedPayment::create($this->schemaPaymentAttributes($first, $firstSession)), true);
        $this->schemaRejected(fn () => VerifiedPayment::create($this->schemaPaymentAttributes($second, $secondSession)), true);
        VerifiedPayment::create([...$this->schemaPaymentAttributes($second, $secondSession),
            'provider_payment_intent_id' => strtolower($payment->provider_payment_intent_id)]);
        VerifiedPayment::create($this->schemaPaymentAttributes($third, $thirdSession));
        $this->assertDatabaseCount('verified_payments', 3);

        $indexes = Schema::getIndexes('verified_payments');
        foreach ([['order_id'], ['order_attempt_id'], ['checkout_intent_id'], ['checkout_session_id'],
            ['account_id', 'mode', 'provider_payment_intent_id']] as $columns) {
            $this->assertCount(1, array_filter($indexes, fn (array $index) => $index['unique'] && $index['columns'] === $columns));
        }
    }

    public function test_sql_rejects_incoherent_bindings_unsupported_states_and_non_test_payment_evidence(): void
    {
        [$first, $firstSession] = $this->schemaCheckout();
        [$second, $secondSession] = $this->schemaCheckout();
        $receipt = $this->schemaReceipt();
        $otherAccountReceipt = $this->schemaReceipt('acct_OTHER');
        $payment = $this->schemaPaymentAttributes($first, $firstSession);
        foreach ([['order_id' => $second->order_id], ['order_attempt_id' => $second->order_attempt_id],
            ['checkout_intent_id' => $second->id], ['checkout_session_id' => $secondSession->id],
            ['account_id' => strtolower(F::ACCOUNT)], ['mode' => 'live'], ['mode' => 'TEST'],
            ['currency' => 'EUR'], ['currency' => 'usd'], ['amount_minor' => 0], ['amount_minor' => -1]] as $invalid) {
            $this->schemaRejected(fn () => DB::table('verified_payments')->insert([...$payment, ...$invalid]));
        }
        $observation = $this->schemaObservationAttributes($first, $receipt);
        foreach ([['outcome' => 'paid'], ['outcome' => 'CONFIRMED'], ['mode' => 'live'],
            ['account_id' => strtolower(F::ACCOUNT)], ['stripe_webhook_receipt_id' => $otherAccountReceipt->id]] as $invalid) {
            $this->schemaRejected(fn () => DB::table('payment_observations')->insert([...$observation, ...$invalid]));
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->schemaRejected(fn () => DB::table('verified_payments')->insert([...$payment, 'amount_minor' => 1.5]));
            $this->schemaRejected(fn () => DB::table('stripe_receipt_work')->insert(['stripe_webhook_receipt_id' => $receipt->id,
                'attempts' => 1.5, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]));
        }
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('payment_observations', 0);
    }

    public function test_sql_rejects_orphan_records_and_foreign_keys_restrict_evidence_deletion(): void
    {
        [$intent, $session] = $this->schemaCheckout();
        $receipt = $this->schemaReceipt();
        $observation = $this->schemaObservationAttributes($intent, $receipt);
        $payment = $this->schemaPaymentAttributes($intent, $session);
        $orphan = 999999;
        $this->schemaRejected(fn () => StripeReceiptWork::create(['stripe_webhook_receipt_id' => $orphan]));
        foreach (['checkout_intent_id', 'stripe_webhook_receipt_id'] as $field) {
            $this->schemaRejected(fn () => DB::table('payment_observations')->insert([...$observation, $field => $orphan]));
        }
        foreach (['order_id', 'order_attempt_id', 'checkout_intent_id', 'checkout_session_id'] as $field) {
            $this->schemaRejected(fn () => DB::table('verified_payments')->insert([...$payment, $field => $orphan]));
        }
        foreach (['stripe_receipt_work' => 1, 'payment_observations' => 2, 'verified_payments' => 4] as $table => $expected) {
            $keys = Schema::getForeignKeys($table);
            $this->assertCount($expected, $keys);
            foreach ($keys as $key) {
                $this->assertContains(strtolower($key['on_delete']), ['restrict', 'no action']);
            }
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function schemaCheckout(string $account = F::ACCOUNT, bool $promoted = false): array
    {
        $fixture = F::prepared(false, $promoted);
        $order = $fixture['order'];
        $cipher = Crypt::encryptString(CanonicalJson::encode(['fixture' => 'schema-only', 'order' => $order->public_id]));
        $intent = CheckoutIntent::create(['public_id' => (string) Str::uuid(), 'order_id' => $order->id,
            'order_attempt_id' => $order->attempt()->sole()->id, 'account_id' => $account, 'mode' => 'test',
            'idempotency_key' => 'Request-'.$order->public_id, 'request_ciphertext' => $cipher,
            'request_hash' => hash('sha256', $cipher), 'canonicalization_version' => CanonicalJson::VERSION,
            'created_at' => now(), 'initiate_before' => now()->addMinute(), 'retry_before' => now()->addMinutes(15),
            'provider_expires_at' => now()->addHour()]);
        $session = CheckoutSession::create(['checkout_intent_id' => $intent->id, 'account_id' => $account, 'mode' => 'test',
            'provider_session_id' => 'cs_test_'.Str::uuid(), ...$this->schemaPrivateEvidence(), 'created_at' => now()]);

        return [$intent, $session];
    }

    private function schemaReceipt(string $account = F::ACCOUNT): StripeWebhookReceipt
    {
        $body = CanonicalJson::encode(['fixture' => 'schema-only']);

        return StripeWebhookReceipt::create(['account_id' => $account, 'livemode' => false,
            'event_id' => 'evt_'.Str::uuid(), 'event_type' => 'checkout.session.completed', 'object_id' => 'cs_test_Synthetic',
            'object_type' => 'checkout.session', 'api_version' => '2026-08-26.dahlia', 'provider_created_at' => time(),
            'signature_timestamp' => time(), 'payload_sha256' => hash('sha256', $body), 'event_fingerprint' => hash('sha256', $body),
            'fingerprint_version' => 'fixture-only', 'payload_ciphertext' => Crypt::encryptString($body), 'received_at' => now()]);
    }

    private function schemaObservationAttributes(CheckoutIntent $intent, ?StripeWebhookReceipt $receipt = null): array
    {
        return ['checkout_intent_id' => $intent->id, 'stripe_webhook_receipt_id' => $receipt?->id,
            'account_id' => $intent->account_id, 'mode' => 'test', 'provider_payment_intent_id' => null,
            'outcome' => 'pending', ...$this->schemaPrivateEvidence(), 'observed_at' => now()];
    }

    private function schemaPaymentAttributes(CheckoutIntent $intent, CheckoutSession $session): array
    {
        return ['order_id' => $intent->order_id, 'order_attempt_id' => $intent->order_attempt_id,
            'checkout_intent_id' => $intent->id, 'checkout_session_id' => $session->id,
            'account_id' => $intent->account_id, 'mode' => 'test', 'provider_payment_intent_id' => 'pi_SYNTHETIC',
            'amount_minor' => 3499, 'currency' => 'USD', ...$this->schemaPrivateEvidence(), 'confirmed_at' => now()];
    }

    private function schemaPrivateEvidence(): array
    {
        $cipher = Crypt::encryptString(CanonicalJson::encode(['fixture' => 'payment-private-marker@example.invalid']));

        return ['evidence_ciphertext' => $cipher, 'evidence_hash' => hash('sha256', $cipher),
            'canonicalization_version' => CanonicalJson::VERSION];
    }

    private function schemaRejected(callable $operation, bool $unique = false): void
    {
        try {
            $operation();
            $this->fail('Contradictory payment storage was accepted.');
        } catch (QueryException $error) {
            if ($unique) {
                $message = strtolower($error->getMessage());
                $this->assertTrue(str_contains($message, 'unique') || str_contains($message, 'duplicate'), $message);
            } else {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }
}
