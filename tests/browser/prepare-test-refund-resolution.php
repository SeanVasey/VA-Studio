<?php

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestPaymentExceptionWork;
use App\Domain\Commerce\Models\TestRefundResolution;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Support\CanonicalJson;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationFixtures;
use Tests\Support\PaymentFinancialFixtures;
use Tests\Support\PaymentFixtures;

require __DIR__.'/refund-resolution-fixture.php';

try {
    umask(0077);
    $mode = $argv[1] ?? null;
    $project = $argv[2] ?? null;
    $phase = $argv[3] ?? null;
    RefundResolutionBrowserFixture::check(PHP_SAPI === 'cli' && in_array($mode, ['prepare', 'verify'], true)
        && is_string($project) && array_key_exists($project, UnpaidReleaseBrowserFixture::PROJECTS)
        && count($argv) === ($mode === 'prepare' ? 3 : 4)
        && ($mode === 'prepare' || in_array($phase, ['prepared', 'released', 'replayed', 'partial'], true)));
    $directory = RefundResolutionBrowserFixture::directory();
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    RefundResolutionBrowserFixture::effective($directory);
    $path = $directory.'/refund-resolution-'.$project.'.json';
    $log = $directory.'/refund-resolution-'.$project.'.jsonl';

    if ($mode === 'prepare') {
        RefundResolutionBrowserFixture::check(! file_exists($path) && ! is_link($path) && ! file_exists($log) && ! is_link($log));
        $before = refundBusinessRows();
        $guards = refundGuardHash();
        $records = [];
        try {
            $app->detectEnvironment(fn () => 'testing');
            FinalizationFixtures::configure();
            Queue::fake();
            foreach (['refunded', 'partial'] as $case) {
                // Keep the late-confirmed synthetic purchase in the past. HTTP verification
                // uses the real clock and must not release resources before finalization.
                $wallClock = CarbonImmutable::now()->startOfSecond();
                Carbon::setTestNow($wallClock->subHours(2));
                CarbonImmutable::setTestNow($wallClock->subHours(2));
                $suffix = UnpaidReleaseBrowserFixture::PROJECTS[$project].strtoupper($case);
                $gateway = PaymentFixtures::gateway();
                $gateway->onCreate = fn (array $params) => CheckoutFixtures::session($params, 'cs_test_BROWSERREFUND'.$suffix);
                $app->instance(StripeCheckoutGateway::class, $gateway);
                $app->instance(StripePaymentGateway::class, $gateway);
                $f = PaymentFixtures::started($gateway);
                $gateway->session['payment_intent'] = 'pi_BROWSERREFUND'.$suffix;
                $gateway->payment['id'] = $gateway->session['payment_intent'];
                $gateway->payment['latest_charge'] = 'ch_BROWSERREFUND'.$suffix;
                $cutoff = $f['order']->attempt()->sole()->expires_at->addSecond();
                Carbon::setTestNow($cutoff);
                CarbonImmutable::setTestNow($cutoff);
                $f = FinalizationFixtures::confirm($f);
                RefundResolutionBrowserFixture::check(app(FinalizeTestPayment::class)->handle($f['payment']->id) === 'paid_exception');
                $finalization = OrderFinalization::where('order_id', $f['order']->id)->sole();
                RefundResolutionBrowserFixture::check($finalization->finalized_at->lessThan($wallClock));
                $financial = PaymentFinancialFixtures::source($gateway->payment);
                $refunded = $case === 'refunded' ? $gateway->payment['amount'] : $gateway->payment['amount'] - 1;
                foreach (['charge_before', 'charge_after'] as $key) {
                    $financial[$key]['amount_refunded'] = $refunded;
                    $financial[$key]['refunded'] = $case === 'refunded';
                }
                $refund = PaymentFinancialFixtures::item('refund', 'succeeded', $refunded);
                $refund['id'] = 're_BROWSERREFUND'.$suffix;
                $refund['payment_intent'] = $gateway->payment['id'];
                $refund['charge'] = $gateway->payment['latest_charge'];
                $financial['refunds']['data'] = [$refund];
                $attempt = $f['order']->attempt()->sole();
                RefundResolutionBrowserFixture::check($attempt->promotion_use_id === null);
                $records[$case] = ['id' => $finalization->id, 'publicId' => $finalization->public_id,
                    'orderId' => $f['order']->id, 'orderPublicId' => $f['order']->public_id,
                    'originalHash' => CanonicalJson::hash(app(ReadOrder::class)->verify($f['order'])),
                    'inventoryId' => $attempt->inventory_reservation_id,
                    'session' => $gateway->session, 'payment' => $gateway->payment, 'financial' => $financial];
                Carbon::setTestNow();
                CarbonImmutable::setTestNow();
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            $app->detectEnvironment(fn () => 'local');
        }
        $prepared = refundBusinessRows();
        foreach ($before as $table => $rows) {
            foreach ($rows as $id => $hash) {
                RefundResolutionBrowserFixture::check(($prepared[$table][$id] ?? null) === $hash);
            }
        }
        $released = $prepared;
        $reservation = (array) DB::table('inventory_reservations')->where('id', $records['refunded']['inventoryId'])->sole();
        RefundResolutionBrowserFixture::check($reservation['state'] === 'pending' && refundGuardHash() === $guards);
        $reservation['state'] = 'released';
        $released['inventory_reservations'][$reservation['id']] = hash('sha256', json_encode($reservation, JSON_THROW_ON_ERROR));
        $fixture = ['purpose' => 'test-refund-browser-v1', 'marker' => getenv('VASEY_BROWSER_EXCEPTION_MARKER'),
            'database' => $directory.'/database.sqlite', 'project' => $project, 'operatorId' => 1,
            'capability' => bin2hex(random_bytes(32)), 'records' => $records,
            'preparedRows' => $prepared, 'releasedRows' => $released, 'guardHash' => $guards];
        UnpaidReleaseBrowserFixture::write($log, '');
        UnpaidReleaseBrowserFixture::write($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        echo json_encode(['refundedId' => $records['refunded']['publicId'], 'partialId' => $records['partial']['publicId'],
            'operatorEmail' => 'browser-operator@example.test', 'capability' => $project.':'.$fixture['capability'],
            'privateMarkers' => [UnpaidReleaseBrowserFixture::ACCOUNT, 'Synthetic Buyer Privacy Marker', 'order-privacy-marker@example.invalid', 'private-financial@example.test',
                ...array_merge(...array_map(fn ($record) => [$record['session']['id'], $record['payment']['id'], $record['payment']['latest_charge']], array_values($records)))]], JSON_THROW_ON_ERROR)."\n";
    } else {
        $fixture = RefundResolutionBrowserFixture::manifest($directory, $project);
        RefundResolutionBrowserFixture::check(refundGuardHash() === $fixture['guardHash']
            && refundBusinessRows() === $fixture[$phase === 'prepared' ? 'preparedRows' : 'releasedRows']);
        foreach ($fixture['records'] as $record) {
            $order = Order::findOrFail($record['orderId']);
            RefundResolutionBrowserFixture::check($order->public_id === $record['orderPublicId']
                && CanonicalJson::hash(app(ReadOrder::class)->verify($order)) === $record['originalHash']);
        }
        $full = $fixture['records']['refunded'];
        $partial = $fixture['records']['partial'];
        $events = TestPaymentExceptionEvent::where('order_finalization_id', $full['id'])->orderBy('sequence')->get();
        $partialEvents = TestPaymentExceptionEvent::where('order_finalization_id', $partial['id'])->orderBy('sequence')->get();
        $calls = array_map(fn ($line) => json_decode($line, true, 8, JSON_THROW_ON_ERROR), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        RefundResolutionBrowserFixture::check(! TestRefundResolution::where('order_finalization_id', $partial['id'])->exists());
        if ($phase === 'prepared') {
            RefundResolutionBrowserFixture::check($events->isEmpty() && $partialEvents->isEmpty()
                && ! TestRefundResolution::where('order_finalization_id', $full['id'])->exists() && $calls === []);
            $resolutionId = null;
        } else {
            $resolution = TestRefundResolution::where('order_finalization_id', $full['id'])->sole();
            RefundResolutionBrowserFixture::check($resolution->actor_id === 1 && $resolution->order_id === $full['orderId']
                && $resolution->inventory_reservation_id === $full['inventoryId'] && $resolution->promotion_use_id === null
                && $events->count() === 2 && $events->pluck('sequence')->all() === [1, 2]
                && $events->pluck('kind')->all() === ['reconciliation_requested', 'reconciliation_observed']
                && $events->pluck('outcome')->all() === ['pending', 'confirmed']
                && $events->pluck('actor_id')->all() === [1, 1] && $resolution->observed_event_id === $events->last()->id);
            $work = TestPaymentExceptionWork::where('order_finalization_id', $full['id'])->sole();
            RefundResolutionBrowserFixture::check($work->sequence === 2 && $work->request_id === null
                && $work->claim_token === null && $work->lease_expires_at === null);
            $operations = ['account', 'retrieve', 'payment_intent', 'financial_state'];
            if ($phase === 'partial') {
                RefundResolutionBrowserFixture::check($partialEvents->count() === 2 && $partialEvents->pluck('outcome')->all() === ['pending', 'confirmed']);
                $operations = [...$operations, ...$operations];
            } else {
                RefundResolutionBrowserFixture::check($partialEvents->isEmpty());
            }
            RefundResolutionBrowserFixture::check($calls === array_map(fn ($operation) => ['operation' => $operation, 'transactionLevel' => 0], $operations));
            $resolutionId = $resolution->public_id;
        }
        echo json_encode(['phase' => $phase, 'resolutionId' => $resolutionId, 'historyCount' => $events->count(),
            'partialHistoryCount' => $partialEvents->count(), 'providerReads' => count($calls),
            'originalsUnchanged' => true, 'noRightsOrMoneyEffects' => true, 'guardsUnchanged' => true], JSON_THROW_ON_ERROR)."\n";
    }
} catch (Throwable) {
    fwrite(STDERR, "Isolated refund-resolution browser fixture failed; no private details are printed.\n");
    exit(1);
}

function refundBusinessRows(): array
{
    $result = [];
    foreach (['orders', 'order_lines', 'order_attempts', 'quotes', 'quote_lines', 'quote_pricings', 'inventory_reservations',
        'inventory_claims', 'promotion_uses', 'checkout_intents', 'checkout_sessions', 'checkout_observations',
        'verified_payments', 'payment_observations', 'order_finalizations', 'license_grants', 'exclusive_sales',
        'pending_entitlements', 'fulfillment_outbox', 'contract_render_requests', 'grant_contracts'] as $table) {
        $result[$table] = [];
        foreach (DB::table($table)->orderBy('id')->get() as $row) {
            $result[$table][$row->id] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        }
    }

    return $result;
}

function refundGuardHash(): string
{
    return hash('sha256', json_encode(DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->get(['name', 'tbl_name', 'sql']), JSON_THROW_ON_ERROR));
}
