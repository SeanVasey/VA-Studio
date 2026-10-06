<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\PaymentObservation;
use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures as F;
use Tests\Support\PaymentRace;
use Tests\TestCase;

class TestPaymentConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Payment worker races require independent MySQL processes.'); }
    }

    private function paymentRaceInput(bool $distinctReceipts = false): array
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $gateway = F::gateway(); $this->app->instance(StripeCheckoutGateway::class, $gateway); $this->app->instance(StripePaymentGateway::class, $gateway);
        F::started($gateway, true, true); $first = F::receipt($gateway->session);
        $second = $distinctReceipts ? F::receipt($gateway->session, 'evt_CONCURRENTSECOND') : $first;
        $common = ['session' => $gateway->session, 'payment' => $gateway->payment, 'now' => now()->toIso8601ZuluString()];

        return [[$common + ['receipt' => $first->id], $common + ['receipt' => $second->id]], F::unchangedBusinessEvidence()];
    }

    public function test_two_workers_for_one_receipt_share_one_claim_and_one_confirmation(): void
    {
        [$inputs, $before] = $this->paymentRaceInput(); $results = PaymentRace::run($this, $inputs, 'same_receipt');
        $outcomes = array_column($results, 'outcome'); sort($outcomes);
        $this->assertSame(['awaiting_finalization', 'busy'], $outcomes);
        $this->assertSame(1, StripeReceiptWork::sole()->attempts); $this->assertSame('processed', StripeReceiptWork::sole()->state);
        $this->assertDatabaseCount('verified_payments', 1); $this->assertDatabaseCount('payment_observations', 1);
        $this->assertSame($before, F::unchangedBusinessEvidence());
        foreach ($results as $result) { foreach ($result['calls'] as $call) { $this->assertSame(0, $call['transaction_level']); } }
    }

    public function test_distinct_receipts_racing_for_one_payment_cannot_duplicate_confirmation(): void
    {
        [$inputs, $before] = $this->paymentRaceInput(true); $results = PaymentRace::run($this, $inputs, 'different_receipts');
        $this->assertSame(['awaiting_finalization', 'awaiting_finalization'], array_column($results, 'outcome'));
        $this->assertDatabaseCount('verified_payments', 1); $this->assertDatabaseCount('payment_observations', 2);
        $this->assertSame(2, StripeReceiptWork::where('state', 'processed')->count());
        $this->assertSame(F::PAYMENT, VerifiedPayment::sole()->provider_payment_intent_id);
        $this->assertSame($before, F::unchangedBusinessEvidence());
    }

    public function test_worker_whose_lease_was_reclaimed_cannot_commit_stale_payment_evidence(): void
    {
        [$inputs, $before] = $this->paymentRaceInput();
        $inputs[1]['now'] = now()->addSeconds(121)->toIso8601ZuluString();
        $results = PaymentRace::run($this, $inputs, 'stale_lease');
        $this->assertSame(['stale', 'awaiting_finalization'], array_column($results, 'outcome'));
        $this->assertSame(2, StripeReceiptWork::sole()->attempts); $this->assertSame('processed', StripeReceiptWork::sole()->state);
        $this->assertDatabaseCount('verified_payments', 1); $this->assertDatabaseCount('payment_observations', 1);
        $this->assertSame($before, F::unchangedBusinessEvidence());
    }

    public function test_old_provider_failure_cannot_overwrite_success_after_lease_takeover(): void
    {
        [$inputs, $before] = $this->paymentRaceInput(); $inputs[0]['fail'] = true;
        $inputs[1]['now'] = now()->addSeconds(121)->toIso8601ZuluString();
        $results = PaymentRace::run($this, $inputs, 'stale_failure');
        $this->assertSame(['stale', 'awaiting_finalization'], array_column($results, 'outcome'));
        $this->assertSame('processed', StripeReceiptWork::sole()->state);
        $this->assertSame('awaiting_finalization', StripeReceiptWork::sole()->outcome);
        $this->assertSame(2, StripeReceiptWork::sole()->attempts);
        $this->assertDatabaseCount('verified_payments', 1); $this->assertDatabaseCount('payment_observations', 1);
        $this->assertSame($before, F::unchangedBusinessEvidence());
    }

    public function test_late_pending_observation_cannot_regress_a_concurrently_confirmed_payment(): void
    {
        [$inputs, $before] = $this->paymentRaceInput(true);
        $inputs[0]['payment']['status'] = 'processing'; $inputs[0]['payment']['amount_received'] = 0;
        $inputs[0]['session']['payment_status'] = 'unpaid';
        $results = PaymentRace::run($this, $inputs, 'stale_observation');
        $this->assertSame(['awaiting_finalization', 'awaiting_finalization'], array_column($results, 'outcome'));
        $this->assertDatabaseCount('verified_payments', 1); $this->assertDatabaseCount('payment_observations', 2);
        $this->assertSame(1, PaymentObservation::where('outcome', 'confirmed')->count());
        $this->assertSame(1, PaymentObservation::where('outcome', 'pending')->count());
        $this->assertSame(2, StripeReceiptWork::where('state', 'processed')->count());
        $this->assertSame($before, F::unchangedBusinessEvidence());
    }
}
