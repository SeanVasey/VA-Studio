<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\CheckoutObservation;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PaymentObservation;
use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Models\TestUnpaidRelease;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Commerce\UnpaidRelease\ReleaseTestOrderResources;
use App\Domain\Commerce\UnpaidRelease\UnpaidReleasePolicy;
use App\Domain\Customers\DataRights\RetentionSchedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\UnpaidReleaseFixtures as F;
use Tests\TestCase;

/**
 * FP-076, decision register "Unpaid order snapshots (retention)": what a late webhook or reconcile does to an unpaid Stripe-test
 * order snapshot after its provider session expired. Both outcomes are safe (refused with the receipt retained, or recorded
 * against the retained order as a paid exception, never a grant), but the second one means the chain has NO terminal unpaid
 * state: a staff release keeps accepting a late confirmed payment by contract. The retention schedule therefore leaves every
 * table of this chain with the order. Synthetic provider transport only.
 */
final class StripeTestUnpaidSnapshotRetentionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const RETENTION = ['policy_reference' => 'Synthetic retention schedule SR-1 (test only)',
        'financial_records_years' => 7, 'consent_records_years' => 3, 'correspondence_records_years' => 2, 'security_records_years' => 1,
        'evidence_records_years' => 7, 'abandoned_checkout_days' => 30, 'operational_records_days' => 14];

    private const CHAIN = ['orders', 'order_lines', 'order_attempts', 'checkout_intents', 'checkout_sessions', 'checkout_observations',
        'payment_observations', 'stripe_webhook_receipts', 'stripe_receipt_work', 'verified_payments', 'order_finalizations'];

    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        config(['customer-data-rights.retention' => self::RETENTION]);
        Queue::fake();
        $this->gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
    }

    /** @return array<string, array{0: string}> */
    public static function latePaths(): array
    {
        return ['late webhook' => ['webhook'], 'late reconcile' => ['reconcile']];
    }

    #[DataProvider('latePaths')]
    public function test_after_a_recorded_expiry_without_a_release_a_late_paid_read_is_refused_and_its_evidence_is_retained(string $path): void
    {
        $f = $this->expired();
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $terminal = CheckoutObservation::orderByDesc('id')->first()->getAttributes();
        $this->assertSame('expired', $terminal['status']);
        $observations = PaymentObservation::count();

        $this->travel(45)->days();
        $this->providerReportsPayment();
        if ($path === 'webhook') {
            $receipt = PaymentFixtures::receipt($this->gateway->session, 'evt_LateAfterExpiry');
            $this->assertSame('payment_changed', app(ProcessStripeReceipt::class)->handle($receipt->id));
            // Refused, not lost: the signed event and its quarantined work row stay for investigation.
            $work = StripeReceiptWork::where('stripe_webhook_receipt_id', $receipt->id)->sole();
            $this->assertSame(['quarantined', 'payment_changed'], [$work->state, $work->outcome]);
            $this->assertSame('evt_LateAfterExpiry', StripeWebhookReceipt::whereKey($receipt->id)->sole()->event_id);
            // An explicit replay of the retained receipt is refused the same way.
            $this->assertSame('payment_changed', app(ProcessStripeReceipt::class)->handle($receipt->id, true));
        } else {
            $this->assertSame('payment_changed', app(VerifyTestPayment::class)->reconcile($f['intent']));
        }
        $this->assertSame($terminal, CheckoutObservation::orderByDesc('id')->first()->getAttributes());
        $this->assertSame($observations, PaymentObservation::count());
        $this->assertSame(0, PaymentObservation::where('outcome', 'confirmed')->count());
        $this->assertNoPaymentOrGrant();
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertChainStaysWithTheOrder();
    }

    #[DataProvider('latePaths')]
    public function test_after_a_staff_release_a_late_confirmed_payment_still_attaches_so_a_release_is_not_a_terminal_unpaid_state(string $path): void
    {
        $f = $this->expired();
        $this->assertSame('released', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0)['status']);
        $this->assertSame('paid_exception_preserve_released', UnpaidReleasePolicy::CONTRACT['late_confirmation']);
        $release = TestUnpaidRelease::sole();
        $this->assertNoPaymentOrGrant();
        $history = CheckoutObservation::orderBy('id')->get()->toJson();

        // 45 days after the release (past the 30-day abandoned period) the provider reports the session paid.
        $this->travel(45)->days();
        $this->providerReportsPayment();
        if ($path === 'webhook') {
            $receipt = PaymentFixtures::receipt($this->gateway->session, 'evt_LateAfterRelease');
            $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
            $this->assertSame($receipt->id, PaymentObservation::where('outcome', 'confirmed')->sole()->stripe_webhook_receipt_id);
        } else {
            $this->assertSame('awaiting_finalization', app(VerifyTestPayment::class)->reconcile($f['intent']));
            $this->assertNull(PaymentObservation::where('outcome', 'confirmed')->sole()->stripe_webhook_receipt_id);
        }
        // (i) Recorded against the still-retained order: money is never suppressed, and the terminal checkout history is intact.
        $payment = VerifiedPayment::sole();
        $this->assertSame([$f['order']->id, $f['intent']->id, $release->checkout_session_id], [$payment->order_id, $payment->checkout_intent_id, $payment->checkout_session_id]);
        $this->assertTrue($payment->confirmed_at->greaterThan($release->released_at->addDays(30)));
        $this->assertSame($history, CheckoutObservation::orderBy('id')->get()->toJson());
        // Never a grant: the released attempt finalizes as a paid exception for staff to resolve.
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($payment->id));
        $this->assertSame(['paid_exception', 'released_attempt'], [OrderFinalization::sole()->outcome, OrderFinalization::sole()->reason]);
        foreach (['license_grants', 'exclusive_sales', 'pending_entitlements', 'grant_contracts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('inventory_reservations', ['state' => 'released', 'consumed_at' => null]);
        $this->assertChainStaysWithTheOrder();
    }

    /** A started order whose provider session expired unpaid, with the expiry recorded locally (checkout and payment observation). */
    private function expired(): array
    {
        $f = F::started($this->gateway);
        app(HostedCheckout::class)->reconcile($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('canceled', app(VerifyTestPayment::class)->reconcile($f['intent']));
        $this->assertSame('expired', app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER)['status']);

        return $f;
    }

    /** A contradicting provider read: the same session now complete and paid, with a succeeded PaymentIntent. */
    private function providerReportsPayment(): void
    {
        $this->gateway->session = ['status' => 'complete', 'payment_status' => 'paid', 'url' => null, 'payment_intent' => PaymentFixtures::PAYMENT] + $this->gateway->session;
        $this->gateway->payment = PaymentFixtures::payment($this->gateway->session);
    }

    private function assertNoPaymentOrGrant(): void
    {
        foreach (['verified_payments', 'order_finalizations', 'license_grants', 'exclusive_sales', 'pending_entitlements', 'fulfillment_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    /** However old, no table of this chain is scheduled: each is `indefinite` and absent from the counted report lines. */
    private function assertChainStaysWithTheOrder(): void
    {
        $this->travel(50)->years();
        $byTable = RetentionSchedule::byTable();
        $this->assertSame(0, Artisan::call('vasey:retention-report'));
        $output = Artisan::output();
        foreach (self::CHAIN as $table) {
            $this->assertSame(['indefinite', null], [$byTable[$table]['class'], $byTable[$table]['clock']], $table);
            $this->assertDoesNotMatchRegularExpression('/^  '.$table.' /m', $output, $table);
            $this->assertMatchesRegularExpression('/^class=indefinite \w+=(?:[a-z_]+,)*'.$table.'(?:,|$)/m', $output, $table);
        }
        $this->assertSame('not_applicable', $byTable['test_unpaid_releases']['class']);
    }
}
