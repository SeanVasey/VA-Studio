<?php

namespace Tests\Feature\Merch;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Merch\Fulfillment\ActiveOrderHolds;
use App\Domain\Merch\Fulfillment\MerchFulfillmentException;
use App\Domain\Merch\Fulfillment\Printful\HttpPrintfulClient;
use App\Domain\Merch\Fulfillment\Printful\PrintfulClient;
use App\Domain\Merch\Fulfillment\Printful\PrintfulException;
use App\Domain\Merch\Fulfillment\Printful\PrintfulOrder;
use App\Domain\Merch\Fulfillment\RecordMerchFulfillment;
use App\Domain\Merch\Fulfillment\SubmitMerchFulfillment;
use App\Domain\Merch\Models\MerchFulfillment;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FakePrintfulClient;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures as F;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\TestCase;

/** BL-63 phase 1: the Printful hand-off. A fake client only; any real request would fail Http::preventStrayRequests(). */
class MerchPrintfulFulfillmentTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private FakePrintfulClient $printful;

    private StripePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Http::preventStrayRequests();
        Queue::fake();
        F::configure();
        $this->gateway = F::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->printful = new FakePrintfulClient;
        $this->app->instance(PrintfulClient::class, $this->printful);
        config(['merch.printful_enabled' => true, 'merch.confirm_orders' => false]);
    }

    /** @return array<string, mixed> */
    private function recipient(): array
    {
        return ['name' => 'Synthetic Buyer', 'address1' => '1 Synthetic Way', 'city' => 'Testville', 'state_code' => 'IA',
            'country_code' => 'US', 'zip' => '50000'];
    }

    /** @return list<array<string, mixed>> */
    private function items(int $variant = 4011): array
    {
        return [['source' => 'catalog', 'catalog_variant_id' => $variant, 'quantity' => 1,
            'placements' => [['placement' => 'front', 'technique' => 'dtg', 'layers' => [['type' => 'file', 'url' => 'https://example.test/art.png']]]]]];
    }

    private function unpaidOrder(): Order
    {
        return F::started($this->gateway)['order'];
    }

    private function paidOrder(): Order
    {
        $fixture = F::started($this->gateway);
        $this->assertSame('awaiting_finalization', app(VerifyTestPayment::class)->reconcile($fixture['intent']));

        return $fixture['order'];
    }

    private function recorded(?Order $order = null): MerchFulfillment
    {
        return app(RecordMerchFulfillment::class)->handle($order ?? $this->paidOrder(), $this->recipient(), $this->items(), 'STANDARD');
    }

    private function refusal(callable $operation): string
    {
        try {
            $operation();
        } catch (MerchFulfillmentException $error) {
            return $error->errorCode;
        }
        $this->fail('Expected a refusal.');
    }

    public function test_nothing_is_recorded_or_sent_before_payment_is_verified(): void
    {
        $order = $this->unpaidOrder();
        $this->assertSame(0, VerifiedPayment::query()->where('order_id', $order->id)->count());
        $this->assertSame('MERCH_PAYMENT_NOT_VERIFIED', $this->refusal(fn () => $this->recorded($order)));
        $this->assertSame(0, MerchFulfillment::query()->count());
        $this->assertSame([], $this->printful->calls);
    }

    public function test_submit_refuses_without_the_verified_payment_it_is_bound_to(): void
    {
        $fulfillment = $this->recorded();
        // The binding is re-checked at submit time, not trusted from record time (simulated: a payment id that matches no payment of the order).
        Schema::disableForeignKeyConstraints();
        DB::table('merch_fulfillments')->where('id', $fulfillment->id)->update(['verified_payment_id' => 987654]);
        $this->assertSame('MERCH_PAYMENT_NOT_VERIFIED', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment->refresh())));
        $this->assertSame([], $this->printful->calls);
    }

    public function test_the_flag_off_and_any_non_test_environment_refuse_every_entry_point(): void
    {
        $order = $this->paidOrder();
        $fulfillment = $this->recorded($order);
        config(['merch.printful_enabled' => false]);
        $this->assertSame('MERCH_FULFILLMENT_DISABLED', $this->refusal(fn () => $this->recorded($order)));
        $this->assertSame('MERCH_FULFILLMENT_DISABLED', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        // Literal true only: a truthy string is not the flag.
        config(['merch.printful_enabled' => 'true']);
        $this->assertSame('MERCH_FULFILLMENT_DISABLED', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        // Production has no admission whatever the flag says.
        config(['merch.printful_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame('MERCH_FULFILLMENT_DISABLED', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $this->app->detectEnvironment(fn () => 'testing');
        $this->assertSame([], $this->printful->calls);
    }

    public function test_the_default_configuration_is_off(): void
    {
        $config = require base_path('config/merch.php');
        $this->assertFalse($config['printful_enabled']);
        $this->assertFalse($config['confirm_orders']);
    }

    public function test_recording_is_idempotent_per_order_and_conflicting_requests_are_refused(): void
    {
        $order = $this->paidOrder();
        $first = $this->recorded($order);
        $again = $this->recorded($order);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, MerchFulfillment::query()->count());
        $this->assertSame('MERCH_REQUEST_CONFLICT', $this->refusal(
            fn () => app(RecordMerchFulfillment::class)->handle($order, $this->recipient(), $this->items(4012), 'STANDARD')));
        $this->assertSame('vasey-'.$first->public_id, $first->external_id);
        // The address is encrypted at rest.
        $raw = (string) DB::table('merch_fulfillments')->where('id', $first->id)->value('request_ciphertext');
        $this->assertStringNotContainsString('Synthetic Way', $raw);
        $this->assertStringNotContainsString('Testville', $raw);
    }

    public function test_recording_rejects_malformed_requests_and_never_accepts_a_price(): void
    {
        $order = $this->paidOrder();
        $bad = ['missing name' => [['name' => ''] + $this->recipient(), $this->items()],
            'lowercase country' => [['country_code' => 'us'] + $this->recipient(), $this->items()],
            'no items' => [$this->recipient(), []],
            'zero quantity' => [$this->recipient(), [['quantity' => 0] + $this->items()[0]]],
            'no placements' => [$this->recipient(), [['placements' => []] + $this->items()[0]]]];
        foreach ($bad as $label => [$recipient, $items]) {
            try {
                app(RecordMerchFulfillment::class)->handle($order, $recipient, $items, 'STANDARD');
                $this->fail($label);
            } catch (MerchFulfillmentException $error) {
                $this->assertSame(422, $error->status, $label);
            }
        }
        $priced = $this->items();
        $priced[0]['retail_price'] = '19.99';
        $fulfillment = app(RecordMerchFulfillment::class)->handle($order, $this->recipient(), $priced, 'STANDARD');
        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertArrayNotHasKey('retail_price', $this->printful->calls[0][1]['items'][0]);
    }

    public function test_a_paid_order_is_submitted_once_as_a_draft_and_repeats_are_no_ops(): void
    {
        $fulfillment = $this->recorded();
        $submitted = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(MerchFulfillment::SUBMITTED, $submitted->state);
        $this->assertNotNull($submitted->printful_order_id);
        $this->assertSame('draft', $submitted->printful_status);
        $this->assertSame(1, $this->printful->count('create'));
        $this->assertSame($fulfillment->external_id, $this->printful->calls[0][1]['externalId']);
        // Replays and retries of the job, from the stale object and from a fresh one.
        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        app(SubmitMerchFulfillment::class)->handle(MerchFulfillment::query()->find($fulfillment->id));
        $this->assertSame(1, $this->printful->count('create'));
        $this->assertSame(0, $this->printful->count('confirm'));
        $this->assertSame(1, MerchFulfillment::query()->count());
    }

    public function test_a_lost_create_response_is_reconciled_by_external_id_not_created_again(): void
    {
        $fulfillment = $this->recorded();
        // The order reaches Printful but the answer is lost (timeout): outcome unknown.
        $this->printful->createFailures[] = ['created' => true, 'exception' => new PrintfulException('PRINTFUL_UNAVAILABLE', true)];
        $this->assertSame('MERCH_PRINTFUL_UNAVAILABLE', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $this->assertSame(MerchFulfillment::RECORDED, $fulfillment->refresh()->state);
        $this->assertSame(1, $fulfillment->attempts);

        $retried = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(MerchFulfillment::SUBMITTED, $retried->state);
        $this->assertSame(1, $this->printful->count('create'), 'The retry looked the order up instead of creating a second one.');
        $this->assertSame(1, $this->printful->count('find'));
        $this->assertSame($this->printful->orders[$fulfillment->external_id]->id, $retried->printful_order_id);
    }

    public function test_a_recovered_order_already_confirmed_in_printful_is_settled_and_never_confirmed_again(): void
    {
        config(['merch.confirm_orders' => true]);
        $fulfillment = $this->recorded();
        $this->printful->createFailures[] = ['created' => true, 'exception' => new PrintfulException('PRINTFUL_UNAVAILABLE', true)];
        $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment));

        // Sean confirmed the draft in the Printful dashboard before the retry ran.
        $external = $fulfillment->refresh()->external_id;
        $draft = $this->printful->orders[$external];
        $this->printful->orders[$external] = new PrintfulOrder($draft->id, $external, 'inprocess');
        $settled = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame([MerchFulfillment::CONFIRMED, 'inprocess', $draft->id], [$settled->state, $settled->printful_status, $settled->printful_order_id]);
        $this->assertSame([1, 0], [$this->printful->count('create'), $this->printful->count('confirm')]);

        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(0, $this->printful->count('confirm'));
    }

    public function test_a_recovered_order_canceled_in_printful_is_recorded_and_refused_loudly(): void
    {
        config(['merch.confirm_orders' => true]);
        $fulfillment = $this->recorded();
        $this->printful->createFailures[] = ['created' => true, 'exception' => new PrintfulException('PRINTFUL_UNAVAILABLE', true)];
        $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment));

        $external = $fulfillment->refresh()->external_id;
        $draft = $this->printful->orders[$external];
        $this->printful->orders[$external] = new PrintfulOrder($draft->id, $external, 'canceled');
        $this->assertSame('MERCH_PRINTFUL_STATUS_UNEXPECTED', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $kept = $fulfillment->refresh();
        $this->assertSame([MerchFulfillment::SUBMITTED, 'canceled', 'PRINTFUL_STATUS_UNEXPECTED', $draft->id],
            [$kept->state, $kept->printful_status, $kept->failure_code, $kept->printful_order_id]);
        $this->assertSame([1, 0], [$this->printful->count('create'), $this->printful->count('confirm')]);
    }

    public function test_a_create_that_never_arrived_is_created_on_the_retry(): void
    {
        $fulfillment = $this->recorded();
        $this->printful->createFailures[] = ['created' => false, 'exception' => new PrintfulException('PRINTFUL_UNAVAILABLE', true)];
        $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment));
        $retried = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(MerchFulfillment::SUBMITTED, $retried->state);
        $this->assertSame(2, $this->printful->count('create'));
        $this->assertSame(1, count($this->printful->orders));
    }

    public function test_a_definite_rejection_is_recorded_as_failed_and_never_retried_automatically(): void
    {
        $fulfillment = $this->recorded();
        $this->printful->createFailures[] = ['created' => false, 'exception' => new PrintfulException('PRINTFUL_REJECTED', false, 400)];
        $failed = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame([MerchFulfillment::FAILED, 'PRINTFUL_REJECTED'], [$failed->state, $failed->failure_code]);
        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(1, $this->printful->count('create'));
    }

    public function test_a_missing_credential_leaves_the_order_recorded_not_failed(): void
    {
        $fulfillment = $this->recorded();
        $this->printful->createFailures[] = ['created' => false, 'exception' => new PrintfulException('PRINTFUL_NOT_CONFIGURED')];
        $this->assertSame('MERCH_PRINTFUL_UNAVAILABLE', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $this->assertSame([MerchFulfillment::RECORDED, 'PRINTFUL_NOT_CONFIGURED'], [$fulfillment->refresh()->state, $fulfillment->failure_code]);
    }

    public function test_a_second_worker_cannot_submit_while_a_claim_is_live_and_resumes_by_lookup_after_it_lapses(): void
    {
        $fulfillment = $this->recorded();
        // Another worker holds the claim (a create is in flight).
        MerchFulfillment::query()->whereKey($fulfillment->id)->update(['state' => 'submitting', 'attempts' => 1, 'lease_expires_at' => now()->addMinutes(3)]);
        $this->assertSame('MERCH_SUBMISSION_IN_PROGRESS', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $this->assertSame([], $this->printful->calls);
        // The holder died after Printful accepted the order: the lapsed claim is resumed by lookup first.
        $this->printful->orders[$fulfillment->external_id] = new PrintfulOrder('777', $fulfillment->external_id, 'draft');
        MerchFulfillment::query()->whereKey($fulfillment->id)->update(['lease_expires_at' => now()->subMinute()]);
        $resumed = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(['777', MerchFulfillment::SUBMITTED], [$resumed->printful_order_id, $resumed->state]);
        $this->assertSame(0, $this->printful->count('create'));
    }

    public function test_an_order_under_an_active_refund_or_dispute_hold_is_not_submitted(): void
    {
        $fulfillment = $this->recorded();
        // A genuine hold needs a full refund chain; the real query runs unstubbed (and finds nothing) in every other test here.
        $this->app->instance(ActiveOrderHolds::class, new class extends ActiveOrderHolds
        {
            public function exist(int $orderId): bool
            {
                return true;
            }
        });
        $this->assertSame('MERCH_ORDER_HELD', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $this->assertSame([], $this->printful->calls);
        $this->assertSame(MerchFulfillment::RECORDED, $fulfillment->refresh()->state);
    }

    private object $financial;

    /** A finalized, paid order (the ledger only reads those), with the real refund/dispute ledger wired to synthetic provider facts. */
    private function finalizedOrder(): array
    {
        R::configure();
        [$payments, $this->financial] = R::bind($this->app);
        $this->gateway = $payments;
        $fixture = DeliveryFixtures::ready($payments);

        return [$fixture['order'], $fixture['payment']->amount_minor];
    }

    /** Observes the facts through the production ledger, which writes the real refund/dispute rows. */
    private function observeProviderFacts(Order $order, array $refunds, array $disputes): void
    {
        R::provider($this->financial, $refunds, $disputes);
        R::observe($order->public_id, LicenseFixtures::admin());
    }

    public function test_a_really_refunded_order_is_not_submitted_even_though_a_refund_creates_no_hold(): void
    {
        [$order, $paid] = $this->finalizedOrder();
        $fulfillment = $this->recorded($order);
        $this->observeProviderFacts($order, [['re_MERCH', $paid, 'succeeded']], []);
        $this->assertSame(0, DB::table('refund_dispute_holds')->where('order_id', $order->id)->count());
        $this->assertGreaterThan(0, DB::table('refund_dispute_objects')->where('order_id', $order->id)->count());

        $this->assertSame('MERCH_ORDER_HELD', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $this->assertSame([], $this->printful->calls);
        $this->assertSame(MerchFulfillment::RECORDED, $fulfillment->refresh()->state);
    }

    public function test_a_really_disputed_order_is_not_submitted(): void
    {
        [$order, $paid] = $this->finalizedOrder();
        $fulfillment = $this->recorded($order);
        $this->observeProviderFacts($order, [], [['du_MERCH', $paid, 'needs_response']]);
        $this->assertSame(1, DB::table('refund_dispute_objects')->where('order_id', $order->id)->where('kind', 'dispute')->count());

        $this->assertSame('MERCH_ORDER_HELD', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $this->assertSame([], $this->printful->calls);
    }

    public function test_a_real_unreleased_hold_row_blocks_and_a_clean_order_does_not(): void
    {
        [$order, $paid] = $this->finalizedOrder();
        $this->assertFalse(app(ActiveOrderHolds::class)->exist($order->id));
        $this->observeProviderFacts($order, [], [['du_HOLD', $paid, 'needs_response']]);
        // The dispute policy places a reconciling hold with the observation; both signals are real rows.
        $this->assertSame(1, DB::table('refund_dispute_holds')->where('order_id', $order->id)->whereNull('released_at')->count());
        $this->assertTrue(app(ActiveOrderHolds::class)->exist($order->id));
        $this->assertFalse(app(ActiveOrderHolds::class)->exist($order->id + 9999));
    }

    public function test_a_refund_that_arrives_after_the_draft_stops_the_confirmation(): void
    {
        [$order] = $this->finalizedOrder();
        $fulfillment = $this->recorded($order);
        $submitted = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(MerchFulfillment::SUBMITTED, $submitted->state);
        $this->observeProviderFacts($order, [['re_LATE', 1000, 'succeeded']], []);

        config(['merch.confirm_orders' => true]);
        $this->assertSame('MERCH_ORDER_HELD', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $this->assertSame(MerchFulfillment::SUBMITTED, $fulfillment->refresh()->state);
        $this->assertSame(0, $this->printful->count('confirm'));
    }

    public function test_a_hold_does_not_block_reconciling_a_confirmation_that_may_have_charged(): void
    {
        [$order] = $this->finalizedOrder();
        config(['merch.confirm_orders' => true]);
        $this->printful->confirmFailure = new PrintfulException('PRINTFUL_UNAVAILABLE', true);
        $fulfillment = $this->recorded($order);
        $this->assertSame(MerchFulfillment::CONFIRMING, app(SubmitMerchFulfillment::class)->handle($fulfillment)->state);
        $this->printful->confirmFailure = null;
        $this->observeProviderFacts($order, [['re_AFTER', 1000, 'succeeded']], []);

        // Still a draft: the refund hold stops the re-send, and the row stays for an operator.
        $kept = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame([MerchFulfillment::CONFIRMING, null], [$kept->state, $kept->lease_expires_at]);
        $this->assertSame(1, $this->printful->count('confirm'));

        // The first confirmation had in fact landed: the read-only lookup still settles it so staff can see the charge.
        $draft = $this->printful->orders[$kept->external_id];
        $this->printful->orders[$kept->external_id] = new PrintfulOrder($draft->id, $draft->externalId, 'inprocess');
        $settled = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame([MerchFulfillment::CONFIRMED, 'inprocess'], [$settled->state, $settled->printful_status]);
        $this->assertSame(1, $this->printful->count('confirm'));
    }

    public function test_a_hold_that_appears_between_the_draft_and_the_confirmation_stops_the_charge(): void
    {
        config(['merch.confirm_orders' => true]);
        // The first check (entry) sees nothing; the second (immediately before confirming) sees the hold.
        $this->app->instance(ActiveOrderHolds::class, new class extends ActiveOrderHolds
        {
            private int $calls = 0;

            public function exist(int $orderId): bool
            {
                return ++$this->calls > 1;
            }
        });
        $result = app(SubmitMerchFulfillment::class)->handle($this->recorded());
        $this->assertSame(MerchFulfillment::SUBMITTED, $result->state);
        $this->assertSame([1, 0], [$this->printful->count('create'), $this->printful->count('confirm')]);
    }

    public function test_two_callers_cannot_both_send_the_confirmation(): void
    {
        $fulfillment = $this->recorded();
        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        config(['merch.confirm_orders' => true]);
        $inner = null;
        $this->printful->duringConfirm = function () use ($fulfillment, &$inner): void {
            $this->printful->duringConfirm = null;
            $inner = $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle(MerchFulfillment::query()->findOrFail($fulfillment->id)));
        };
        $outer = app(SubmitMerchFulfillment::class)->handle($fulfillment);

        // The second caller is told the confirmation is in progress; it never sends one of its own.
        $this->assertSame('MERCH_CONFIRMATION_IN_PROGRESS', $inner);
        $this->assertSame(MerchFulfillment::CONFIRMED, $outer->state);
        $this->assertSame(1, $this->printful->count('confirm'));
    }

    public function test_confirmation_needs_its_own_switch_and_runs_at_most_once(): void
    {
        $fulfillment = $this->recorded();
        $submitted = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(MerchFulfillment::SUBMITTED, $submitted->state);
        $this->assertSame(0, $this->printful->count('confirm'));

        // A draft left submitted earlier is confirmed by the next run once the switch is on, and only once.
        config(['merch.confirm_orders' => true]);
        $confirmed = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame([MerchFulfillment::CONFIRMED, 'pending'], [$confirmed->state, $confirmed->printful_status]);
        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(1, $this->printful->count('confirm'));
        $this->assertSame(1, $this->printful->count('create'));
    }

    public function test_a_definite_rejection_of_the_confirmation_leaves_the_draft_submitted_and_retryable(): void
    {
        config(['merch.confirm_orders' => true]);
        $this->printful->confirmFailure = new PrintfulException('PRINTFUL_REJECTED', false, 422);
        $kept = app(SubmitMerchFulfillment::class)->handle($this->recorded());
        $this->assertSame([MerchFulfillment::SUBMITTED, 'PRINTFUL_REJECTED', null], [$kept->state, $kept->failure_code, $kept->lease_expires_at]);
        $this->assertSame(1, $this->printful->count('create'));

        // The rejection is known not to have taken effect, so the next run confirms the same draft again.
        $this->printful->confirmFailure = null;
        $again = app(SubmitMerchFulfillment::class)->handle($kept);
        $this->assertSame(MerchFulfillment::CONFIRMED, $again->state);
        $this->assertSame([1, 2], [$this->printful->count('create'), $this->printful->count('confirm')]);
    }

    public function test_a_timed_out_confirmation_stays_confirming_and_a_later_run_does_not_confirm_again_when_printful_confirmed(): void
    {
        config(['merch.confirm_orders' => true]);
        $this->printful->confirmFailure = new PrintfulException('PRINTFUL_UNAVAILABLE', true);
        $fulfillment = $this->recorded();
        $held = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame([MerchFulfillment::CONFIRMING, 'PRINTFUL_UNAVAILABLE', null], [$held->state, $held->failure_code, $held->lease_expires_at]);

        // The request in fact landed: Printful reports the order as in production.
        $this->printful->confirmFailure = null;
        $order = $this->printful->orders[$held->external_id];
        $this->printful->orders[$held->external_id] = new PrintfulOrder($order->id, $order->externalId, 'inprocess');
        $settled = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame([MerchFulfillment::CONFIRMED, 'inprocess', null], [$settled->state, $settled->printful_status, $settled->failure_code]);
        $this->assertSame([1, 1, 1], [$this->printful->count('create'), $this->printful->count('confirm'), $this->printful->count('find')]);

        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(1, $this->printful->count('confirm'));
    }

    public function test_a_later_run_reconfirms_exactly_once_when_printful_still_reports_a_draft(): void
    {
        config(['merch.confirm_orders' => true]);
        $this->printful->confirmFailure = new PrintfulException('PRINTFUL_UNAVAILABLE', true);
        $fulfillment = $this->recorded();
        $this->assertSame(MerchFulfillment::CONFIRMING, app(SubmitMerchFulfillment::class)->handle($fulfillment)->state);

        // Printful never received it: still a draft. A lookup precedes the second confirmation.
        $this->printful->confirmFailure = null;
        $settled = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(MerchFulfillment::CONFIRMED, $settled->state);
        $this->assertSame([1, 2, 1], [$this->printful->count('create'), $this->printful->count('confirm'), $this->printful->count('find')]);

        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame(2, $this->printful->count('confirm'));
    }

    public function test_a_second_worker_cannot_reconcile_while_the_first_holds_the_lookup(): void
    {
        config(['merch.confirm_orders' => true]);
        $this->printful->confirmFailure = new PrintfulException('PRINTFUL_UNAVAILABLE', true);
        $fulfillment = $this->recorded();
        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->printful->confirmFailure = null;

        // While the first worker waits on Printful's answer, a second one runs: it must neither look up nor confirm.
        $inner = null;
        $this->printful->onFind = function () use ($fulfillment, &$inner): void {
            $inner = $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle(MerchFulfillment::query()->findOrFail($fulfillment->id)));
        };
        $settled = app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->assertSame('MERCH_CONFIRMATION_IN_PROGRESS', $inner);
        $this->assertSame(MerchFulfillment::CONFIRMED, $settled->state);
        $this->assertSame([1, 2], [$this->printful->count('find'), $this->printful->count('confirm')]);
    }

    public function test_an_unexpected_failure_while_reconciling_gives_the_lease_back(): void
    {
        config(['merch.confirm_orders' => true]);
        $this->printful->confirmFailure = new PrintfulException('PRINTFUL_UNAVAILABLE', true);
        $fulfillment = $this->recorded();
        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->printful->confirmFailure = null;

        // The hold ledger cannot be read during reconciliation: the error surfaces and the lease is released, so the next
        // run reconciles again instead of seeing a live lease and returning as if settled.
        $this->app->instance(ActiveOrderHolds::class, new class extends ActiveOrderHolds
        {
            public function exist(int $orderId): bool
            {
                throw new \RuntimeException('ledger unavailable');
            }
        });
        try {
            app(SubmitMerchFulfillment::class)->handle($fulfillment);
            $this->fail('The ledger failure was swallowed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('ledger unavailable', $exception->getMessage());
        }
        $this->assertSame([MerchFulfillment::CONFIRMING, null], [$fulfillment->refresh()->state, $fulfillment->lease_expires_at]);

        $this->app->forgetInstance(ActiveOrderHolds::class);
        $this->assertSame(MerchFulfillment::CONFIRMED, app(SubmitMerchFulfillment::class)->handle($fulfillment)->state);
        $this->assertSame(2, $this->printful->count('confirm'));
    }

    public function test_a_confirmation_in_progress_is_reported_not_returned_as_settled(): void
    {
        config(['merch.confirm_orders' => true]);
        $this->printful->confirmFailure = new PrintfulException('PRINTFUL_UNAVAILABLE', true);
        $fulfillment = $this->recorded();
        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        MerchFulfillment::query()->whereKey($fulfillment->id)->update(['lease_expires_at' => now()->addMinutes(5)]);

        $this->assertSame('MERCH_CONFIRMATION_IN_PROGRESS', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $this->assertSame([1, 0], [$this->printful->count('confirm'), $this->printful->count('find')]);
    }

    public function test_a_draft_is_not_reconfirmed_once_the_confirm_switch_is_off(): void
    {
        config(['merch.confirm_orders' => true]);
        $this->printful->confirmFailure = new PrintfulException('PRINTFUL_UNAVAILABLE', true);
        $fulfillment = $this->recorded();
        app(SubmitMerchFulfillment::class)->handle($fulfillment);
        $this->printful->confirmFailure = null;

        // The operator turns confirmation off after the timeout; Printful still reports a draft. Nothing is charged.
        config(['merch.confirm_orders' => false]);
        $this->assertSame('MERCH_CONFIRMATION_UNRESOLVED', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $kept = $fulfillment->refresh();
        $this->assertSame([MerchFulfillment::CONFIRMING, null], [$kept->state, $kept->lease_expires_at]);
        $this->assertSame(1, $this->printful->count('confirm'));

        // Read-only reconciliation still works: once Printful reports it confirmed, the row settles without a charge.
        $order = $this->printful->orders[$kept->external_id];
        $this->printful->orders[$kept->external_id] = new PrintfulOrder($order->id, $order->externalId, 'pending');
        $this->assertSame(MerchFulfillment::CONFIRMED, app(SubmitMerchFulfillment::class)->handle($fulfillment)->state);
        $this->assertSame(1, $this->printful->count('confirm'));
    }

    public function test_an_unresolved_confirmation_is_refused_loudly_never_returned_silently(): void
    {
        config(['merch.confirm_orders' => true]);
        $this->printful->confirmFailure = new PrintfulException('PRINTFUL_UNAVAILABLE', true);
        $fulfillment = $this->recorded();
        app(SubmitMerchFulfillment::class)->handle($fulfillment);

        // Printful cannot be asked, reports the order missing, or reports a state that is neither draft nor confirmed.
        $external = $fulfillment->refresh()->external_id;
        $order = $this->printful->orders[$external];
        foreach (['canceled', 'failed', 'onhold'] as $status) {
            $this->printful->orders[$external] = new PrintfulOrder($order->id, $external, $status);
            $this->assertSame('MERCH_CONFIRMATION_UNRESOLVED', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        }
        unset($this->printful->orders[$external]);
        $this->assertSame('MERCH_CONFIRMATION_UNRESOLVED', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));

        // A re-confirmation of a draft that times out again stays unresolved too.
        $this->printful->orders[$external] = $order;
        $this->assertSame('MERCH_CONFIRMATION_UNRESOLVED', $this->refusal(fn () => app(SubmitMerchFulfillment::class)->handle($fulfillment)));
        $this->assertSame(MerchFulfillment::CONFIRMING, $fulfillment->refresh()->state);
        $this->assertSame(2, $this->printful->count('confirm'));
    }

    public function test_a_success_with_an_unreadable_body_is_an_unknown_outcome_not_a_reset(): void
    {
        config(['merch.confirm_orders' => true, 'merch.printful.api_key' => 'pf-'.str_repeat('t', 12)]);
        Http::fake([
            'api.printful.com/v2/orders/55/confirmation' => Http::response(['data' => 'unexpected'], 200),
            'api.printful.com/v2/orders' => Http::response(['data' => ['id' => 55, 'external_id' => 'vasey-shape', 'status' => 'draft']], 201),
        ]);
        $this->app->instance(PrintfulClient::class, new HttpPrintfulClient('pf-'.str_repeat('t', 12)));
        $held = app(SubmitMerchFulfillment::class)->handle($this->recorded());
        $this->assertSame([MerchFulfillment::CONFIRMING, 'PRINTFUL_RESPONSE_INVALID'], [$held->state, $held->failure_code]);
        Http::assertSentCount(2);
    }

    public function test_the_database_keeps_one_fulfillment_per_order_and_only_one_claim_wins(): void
    {
        $fulfillment = $this->recorded();
        Schema::disableForeignKeyConstraints();
        // The unique keys, not application code, are the last line against a second row for the same order, payment or external id.
        foreach (['order_id', 'verified_payment_id', 'external_id', 'public_id'] as $column) {
            $row = DB::table('merch_fulfillments')->where('id', $fulfillment->id)->first();
            $copy = (array) $row;
            unset($copy['id']);
            $copy['order_id'] = $column === 'order_id' ? $row->order_id : 987001;
            $copy['verified_payment_id'] = $column === 'verified_payment_id' ? $row->verified_payment_id : 987002;
            $copy['external_id'] = $column === 'external_id' ? $row->external_id : 'vasey-other';
            $copy['public_id'] = $column === 'public_id' ? $row->public_id : (string) Str::uuid();
            try {
                DB::table('merch_fulfillments')->insert($copy);
                $this->fail('A duplicate '.$column.' was accepted.');
            } catch (QueryException $error) {
                // On a foreign key failure of the invented ids the unique key under test did not fire first.
                $this->assertInstanceOf(UniqueConstraintViolationException::class, $error, $column);
            }
        }
        // The claim is one conditional UPDATE: of two claimants exactly one gets a row.
        $claim = fn (): int => MerchFulfillment::query()->whereKey($fulfillment->id)->where('state', MerchFulfillment::RECORDED)
            ->update(['state' => MerchFulfillment::SUBMITTING, 'attempts' => DB::raw('attempts + 1')]);
        $this->assertSame([1, 0], [$claim(), $claim()]);
        $this->assertSame(1, $fulfillment->refresh()->attempts);
        Schema::enableForeignKeyConstraints();
    }

    public function test_the_http_client_refuses_without_a_token_before_any_request(): void
    {
        Http::fake();
        $client = new HttpPrintfulClient(null);
        foreach ([fn () => $client->createDraftOrder('vasey-x', [], [], 'STANDARD'), fn () => $client->findOrderByExternalId('vasey-x'),
            fn () => $client->confirmOrder('1'), fn () => $client->shippingRates([], [], 'USD')] as $call) {
            try {
                $call();
                $this->fail('Expected a refusal.');
            } catch (PrintfulException $error) {
                $this->assertSame('PRINTFUL_NOT_CONFIGURED', $error->errorCode());
            }
        }
        Http::assertNothingSent();
        $this->assertSame('', (string) config('merch.printful.api_key'), 'No credential exists in this configuration.');
    }

    public function test_the_http_client_speaks_the_documented_v2_shapes_without_retrying(): void
    {
        $token = 'pf-'.str_repeat('t', 12);
        Http::fake([
            'api.printful.com/v2/orders/@missing' => Http::response(['data' => 'Not found'], 404),
            'api.printful.com/v2/orders/@vasey-abc' => Http::response(['data' => ['id' => 55, 'external_id' => 'vasey-abc', 'status' => 'draft']]),
            'api.printful.com/v2/orders/55/confirmation' => Http::response(['data' => ['id' => 55, 'external_id' => 'vasey-abc', 'status' => 'pending']]),
            'api.printful.com/v2/shipping-rates' => Http::response(['data' => [['shipping' => 'STANDARD', 'shipping_method_name' => 'Standard', 'rate' => '4.50', 'currency' => 'USD']]]),
            'api.printful.com/v2/orders' => Http::response(['data' => ['id' => 55, 'external_id' => 'vasey-abc', 'status' => 'draft']], 201),
        ]);
        $client = new HttpPrintfulClient($token, '123');

        $order = $client->createDraftOrder('vasey-abc', $this->recipient(), $this->items(), 'STANDARD');
        $this->assertSame(['55', 'vasey-abc', 'draft'], [$order->id, $order->externalId, $order->status]);
        Http::assertSent(function ($request) use ($token): bool {
            return $request->method() === 'POST' && $request->url() === 'https://api.printful.com/v2/orders'
                && $request->hasHeader('Authorization', 'Bearer '.$token) && $request->hasHeader('X-PF-Store-Id', '123')
                && $request['external_id'] === 'vasey-abc' && $request['shipping'] === 'STANDARD'
                && $request['order_items'][0]['catalog_variant_id'] === 4011 && isset($request['recipient']['zip']);
        });
        $this->assertSame('pending', $client->confirmOrder('55')->status);
        $this->assertNull($client->findOrderByExternalId('missing'));
        $this->assertSame('55', $client->findOrderByExternalId('vasey-abc')->id);
        $rates = $client->shippingRates($this->recipient(), $this->items(), 'USD');
        $this->assertSame(['STANDARD', '4.50', 'USD'], [$rates[0]->shipping, $rates[0]->rate, $rates[0]->currency]);
        $this->assertStringNotContainsString($token, (string) json_encode($order));
    }

    public function test_server_errors_are_never_retried_and_report_an_unknown_outcome(): void
    {
        Http::fake(['api.printful.com/*' => Http::response('boom', 503)]);
        $client = new HttpPrintfulClient('pf-'.str_repeat('t', 12));
        try {
            $client->createDraftOrder('vasey-abc', $this->recipient(), $this->items(), 'STANDARD');
            $this->fail('Expected an error.');
        } catch (PrintfulException $error) {
            $this->assertTrue($error->outcomeUnknown);
            $this->assertSame('PRINTFUL_UNAVAILABLE', $error->errorCode());
            // The response body (which can echo the recipient) is not carried.
            $this->assertStringNotContainsString('boom', $error->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_a_client_error_is_a_definite_rejection(): void
    {
        Http::fake(['api.printful.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);
        $client = new HttpPrintfulClient('pf-'.str_repeat('t', 12));
        try {
            $client->createDraftOrder('vasey-abc', $this->recipient(), $this->items(), 'STANDARD');
            $this->fail('Expected an error.');
        } catch (PrintfulException $error) {
            $this->assertFalse($error->outcomeUnknown);
            $this->assertSame('PRINTFUL_REJECTED', $error->errorCode());
        }
    }
}
