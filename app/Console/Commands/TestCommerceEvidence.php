<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutObservation;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderAttempt;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PaymentObservation;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Models\TestUnpaidRelease;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Support\Environment\TestEnvironment;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Minimized, read-only evidence snapshot of one Stripe TEST order for the M-07 drill packet
 * (docs/ops/stripe-test-drills.md). It reads plaintext ledger columns only: no decryption, no
 * provider request, no buyer details, owner keys, checkout URLs, ciphertext or credentials, and
 * every database statement it issues must be a SELECT. It refuses production, an ambiguous
 * environment, anything outside local, testing and staging, and any Stripe live-mode configuration.
 */
final class TestCommerceEvidence extends Command
{
    public const SCHEMA = 'vasey-test-commerce-evidence-v1';

    /** Most recent observation rows printed; the totals still count every row. */
    public const OBSERVATION_LIMIT = 20;

    public const RECEIPT_LIMIT = 50;

    protected $signature = 'vasey:test-commerce-evidence
        {reference : Order UUID, checkout intent UUID, Stripe test session (cs_test_...) or event (evt_...) ID}
        {--json : Emit one JSON object instead of key=value lines}';

    protected $description = 'Print a minimized read-only Stripe TEST evidence snapshot for one order (local, testing or staging only; no writes, no Stripe requests).';

    /** Set only while this command reads, so the statement guard never affects other work in the process. */
    private bool $reading = false;

    public function handle(): int
    {
        try {
            $account = $this->admit();
        } catch (RuntimeException $refusal) {
            $this->error('Refused: '.$refusal->getMessage());

            return self::FAILURE;
        }
        $reference = $this->argument('reference');
        if (! is_string($reference) || ! $this->reference($reference)) {
            $this->error('Supply an order or checkout intent UUID, a cs_test_ session ID or an evt_ event ID.');

            return self::FAILURE;
        }
        $connection = DB::connection();
        $connection->beforeExecuting(function (string $query): void {
            if ($this->reading && preg_match('/\A\s*select\s/i', $query) !== 1) {
                throw new LogicException('The evidence snapshot issues SELECT statements only.');
            }
        });
        try {
            $this->reading = true;
            $snapshot = $this->snapshot($account, $reference);
        } catch (Throwable $error) {
            // No message, query, path or provider detail leaves the console; the log keeps only the class.
            Log::warning('Test commerce evidence snapshot unavailable.', ['exception_class' => $error::class]);
            $this->error('Evidence snapshot unavailable. Nothing was written.');

            return self::FAILURE;
        } finally {
            $this->reading = false;
        }
        if ($snapshot === null) {
            $this->error('No Stripe test order matches that reference for the configured account.');

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $snapshot['payment']['observation_outcomes'] = (object) $snapshot['payment']['observation_outcomes'];
            $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->text($snapshot);
        }

        return self::SUCCESS;
    }

    /** Mirrors the staging synthetic catalog admission: both environment sources must agree, and nothing live. */
    private function admit(): string
    {
        $configured = config('app.env');
        if ($configured === 'production' || app()->environment('production') || app()->isProduction()) {
            throw new RuntimeException('the evidence snapshot never runs in production.');
        }
        if (! is_string($configured) || $configured !== app()->environment()) {
            throw new RuntimeException('the configured environment (APP_ENV) and the running environment differ.');
        }
        if (! TestEnvironment::admitsTestCommerce()) {
            throw new RuntimeException('the evidence snapshot runs only when APP_ENV is local, testing or staging.');
        }
        $secret = config('payments.stripe.secret_key');
        $productionSecret = config('production_checkout.secret_key');
        if (config('payments.stripe.mode') !== 'test'
            || ! ($secret === null || $secret === '' || (is_string($secret) && preg_match('/\Ask_test_[A-Za-z0-9_]{8,200}\z/D', $secret) === 1))
            || config('production_checkout.funds_mode') === 'live'
            || (is_string($productionSecret) && preg_match('/\A(?:sk|rk)_live_/', $productionSecret) === 1)) {
            throw new RuntimeException('Stripe test mode only: STRIPE_MODE=test, an sk_test_ key or none, and no live production checkout configuration.');
        }
        $account = config('payments.stripe.account_id');
        if (! is_string($account) || preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) !== 1) {
            throw new RuntimeException('configure STRIPE_ACCOUNT_ID (acct_...).');
        }

        return $account;
    }

    private function reference(string $reference): bool
    {
        return OrderRequest::uuid($reference)
            || preg_match('/\Acs_test_[A-Za-z0-9]{1,120}\z/D', $reference) === 1
            || preg_match('/\Aevt_[A-Za-z0-9_]{1,120}\z/D', $reference) === 1;
    }

    private function intent(string $account, string $reference): ?CheckoutIntent
    {
        $scoped = fn () => CheckoutIntent::query()->where('account_id', $account)->where('mode', 'test');
        if (OrderRequest::uuid($reference)) {
            $orderId = Order::query()->where('public_id', $reference)->value('id');

            return $orderId !== null ? $scoped()->where('order_id', $orderId)->first()
                : $scoped()->where('public_id', $reference)->first();
        }
        $session = str_starts_with($reference, 'evt_')
            ? StripeWebhookReceipt::query()->where('account_id', $account)->where('livemode', false)
                ->where('event_id', $reference)->value('object_id')
            : $reference;
        if (! is_string($session)) {
            return null;
        }
        $intentId = CheckoutSession::query()->where('account_id', $account)->where('mode', 'test')
            ->where('provider_session_id', $session)->value('checkout_intent_id');

        return $intentId === null ? null : $scoped()->whereKey($intentId)->first();
    }

    private function snapshot(string $account, string $reference): ?array
    {
        $intent = $this->intent($account, $reference);
        if ($intent === null) {
            return null;
        }
        $order = Order::query()->whereKey($intent->order_id)->firstOrFail(['id', 'public_id', 'created_at']);
        $attempt = OrderAttempt::query()->where('order_id', $order->id)->first(['id', 'inventory_reservation_id', 'expires_at']);
        $reservation = $attempt === null ? null
            : InventoryReservation::query()->whereKey($attempt->inventory_reservation_id)->first(['id', 'state', 'consumed_at']);
        $session = CheckoutSession::query()->where('checkout_intent_id', $intent->id)->first(['id', 'provider_session_id']);
        $latest = $session === null ? null
            : CheckoutObservation::query()->where('checkout_session_id', $session->id)->orderByDesc('id')->first(['status']);
        $receipts = $session === null ? collect()
            : StripeWebhookReceipt::query()->where('account_id', $account)->where('livemode', false)
                ->where('object_id', $session->provider_session_id)->orderBy('id')->limit(self::RECEIPT_LIMIT)
                ->get(['id', 'event_id', 'event_type', 'received_at']);
        $work = StripeReceiptWork::query()->whereIn('stripe_webhook_receipt_id', $receipts->pluck('id'))
            ->get(['stripe_webhook_receipt_id', 'state', 'outcome', 'attempts'])->keyBy('stripe_webhook_receipt_id');
        $observations = PaymentObservation::query()->where('checkout_intent_id', $intent->id);
        $outcomes = (clone $observations)->selectRaw('outcome, COUNT(*) AS total')->groupBy('outcome')->orderBy('outcome')
            ->pluck('total', 'outcome')->map(fn ($total) => (int) $total)->all();
        $recent = (clone $observations)->orderByDesc('id')->limit(self::OBSERVATION_LIMIT)
            ->get(['id', 'stripe_webhook_receipt_id', 'outcome', 'observed_at'])->reverse()->values();
        $events = StripeWebhookReceipt::query()->whereIn('id', $recent->pluck('stripe_webhook_receipt_id')->filter())
            ->pluck('event_id', 'id');
        $payment = VerifiedPayment::query()->where('checkout_intent_id', $intent->id)
            ->first(['id', 'provider_payment_intent_id', 'amount_minor', 'currency', 'confirmed_at', 'evidence_hash']);
        $confirming = $payment === null ? null : PaymentObservation::query()->where('checkout_intent_id', $intent->id)
            ->where('evidence_hash', $payment->evidence_hash)->orderBy('id')->first(['stripe_webhook_receipt_id']);
        $finalization = OrderFinalization::query()->where('order_id', $order->id)->first(['id', 'outcome', 'reason', 'finalized_at']);
        $grantIds = $finalization === null ? collect()
            : LicenseGrant::query()->where('order_finalization_id', $finalization->id)->pluck('id');
        $activation = TestFulfillmentActivation::query()->where('order_id', $order->id)->first(['id', 'activated_at']);
        $control = TestDeliveryControl::query()->where('order_id', $order->id)->first(['blocked', 'control_version']);
        $release = TestUnpaidRelease::query()->where('order_id', $order->id)->first(['released_at']);

        return [
            'schema' => self::SCHEMA,
            'captured_at' => $this->time(now()),
            'environment' => app()->environment(),
            'stripe' => ['mode' => 'test', 'account_id' => $account],
            'order' => ['id' => $order->public_id, 'created_at' => $this->time($order->created_at),
                'payment_window_ends_at' => $this->time($attempt?->expires_at)],
            'reservation' => $reservation === null ? null
                : ['state' => $reservation->state, 'consumed' => $reservation->consumed_at !== null],
            'checkout' => ['intent_id' => $intent->public_id, 'session_id' => $session?->provider_session_id,
                'latest_status' => $latest?->status, 'provider_expires_at' => $this->time($intent->provider_expires_at)],
            'payment' => [
                'verified' => $payment !== null,
                'confirmed_at' => $this->time($payment?->confirmed_at),
                'amount_minor' => $payment?->amount_minor,
                'currency' => $payment?->currency,
                'payment_intent_id' => $payment?->provider_payment_intent_id,
                'source' => $payment === null ? null : ($confirming === null ? 'unknown'
                    : ($confirming->stripe_webhook_receipt_id === null ? 'reconciliation' : 'webhook_receipt')),
                'confirming_event_id' => $confirming?->stripe_webhook_receipt_id === null ? null
                    : StripeWebhookReceipt::query()->whereKey($confirming->stripe_webhook_receipt_id)->value('event_id'),
                'observation_total' => array_sum($outcomes),
                'observation_outcomes' => $outcomes,
                'observations' => $recent->map(fn (PaymentObservation $row) => ['observed_at' => $this->time($row->observed_at),
                    'outcome' => $row->outcome, 'source' => $row->stripe_webhook_receipt_id === null ? 'reconciliation' : 'receipt',
                    'event_id' => $row->stripe_webhook_receipt_id === null ? null : $events->get($row->stripe_webhook_receipt_id)])->all(),
            ],
            // receipt_id is the console locator for `vasey:process-stripe-receipts <id> --replay`.
            'receipts' => $receipts->map(fn (StripeWebhookReceipt $receipt) => ['receipt_id' => $receipt->id, 'event_id' => $receipt->event_id,
                'event_type' => $receipt->event_type, 'received_at' => $this->time($receipt->received_at),
                'work_state' => $work->get($receipt->id)?->state, 'work_outcome' => $work->get($receipt->id)?->outcome,
                'attempts' => $work->get($receipt->id)?->attempts])->all(),
            'finalization' => $finalization === null ? null : ['outcome' => $finalization->outcome,
                'reason' => $finalization->reason, 'finalized_at' => $this->time($finalization->finalized_at)],
            'grants' => ['count' => $grantIds->count(),
                'pending_entitlements' => PendingEntitlement::query()->whereIn('license_grant_id', $grantIds)->count(),
                'contracts_recorded' => GrantContract::query()->whereIn('license_grant_id', $grantIds)->count()],
            'fulfillment' => ['activated' => $activation !== null, 'activated_at' => $this->time($activation?->activated_at),
                'delivery' => $control === null ? null : ($control->blocked ? 'blocked' : 'enabled'),
                'delivery_version' => $control?->control_version],
            'unpaid_release' => $release === null ? null : ['released_at' => $this->time($release->released_at)],
            // Account-wide test totals: compare before and after a drill step, for example a webhook resend.
            'totals' => [
                'receipts' => StripeWebhookReceipt::query()->where('account_id', $account)->where('livemode', false)->count(),
                'verified_payments' => VerifiedPayment::query()->where('account_id', $account)->where('mode', 'test')->count(),
                'license_grants' => LicenseGrant::query()->whereIn('order_finalization_id', OrderFinalization::query()
                    ->select('order_finalizations.id')->join('verified_payments', 'verified_payments.id', '=', 'order_finalizations.verified_payment_id')
                    ->where('verified_payments.account_id', $account)->where('verified_payments.mode', 'test'))->count(),
            ],
        ];
    }

    private function time(?CarbonInterface $value): ?string
    {
        return $value?->toImmutable()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function text(array $s): void
    {
        $value = fn (mixed $item): string => match (true) {
            $item === null => '-', $item === true => 'yes', $item === false => 'no', default => (string) $item,
        };
        $pairs = fn (array $items): string => implode(' ', array_map(fn ($key) => $key.'='.$value($items[$key]), array_keys($items)));
        $this->line('VASEY.AUDIO Stripe TEST evidence snapshot ('.$s['schema'].'). Read-only: no writes, no Stripe requests.');
        $this->line('snapshot captured_at='.$s['captured_at'].' environment='.$s['environment'].' stripe_mode=test account='.$s['stripe']['account_id']);
        $this->line('order '.$pairs($s['order']));
        $this->line('reservation '.($s['reservation'] === null ? 'none' : $pairs($s['reservation'])));
        $this->line('checkout '.$pairs($s['checkout']));
        $payment = $s['payment'];
        $outcomes = $payment['observation_outcomes'] === [] ? '-' : implode(',', array_map(fn ($outcome) => $outcome.':'.$payment['observation_outcomes'][$outcome], array_keys($payment['observation_outcomes'])));
        $this->line('payment '.$pairs(array_diff_key($payment, array_flip(['observation_outcomes', 'observations']))).' observation_outcomes='.$outcomes);
        foreach ($payment['observations'] as $observation) {
            $this->line('payment_observation '.$pairs($observation));
        }
        foreach ($s['receipts'] as $receipt) {
            $this->line('receipt '.$pairs($receipt));
        }
        if ($s['receipts'] === []) {
            $this->line('receipt none');
        }
        $this->line('finalization '.($s['finalization'] === null ? 'none' : $pairs($s['finalization'])));
        $this->line('grants '.$pairs($s['grants']));
        $this->line('fulfillment '.$pairs($s['fulfillment']));
        $this->line('unpaid_release '.($s['unpaid_release'] === null ? 'none' : $pairs($s['unpaid_release'])));
        $this->line('totals '.$pairs($s['totals']));
    }
}
