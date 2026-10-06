<?php

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\TestUnpaidRelease;
use App\Domain\Commerce\Models\TestUnpaidReleaseEvent;
use App\Domain\Commerce\Models\TestUnpaidReleaseWork;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\UnpaidRelease\ReadUnpaidRelease;
use App\Support\CanonicalJson;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CheckoutFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\UnpaidReleaseFixtures;

require __DIR__.'/unpaid-release-fixture.php';

try {
    umask(0077);
    $mode = $argv[1] ?? null;
    $project = $argv[2] ?? null;
    $phase = $argv[3] ?? null;
    UnpaidReleaseBrowserFixture::check(PHP_SAPI === 'cli' && in_array($mode, ['prepare', 'verify'], true)
        && is_string($project) && array_key_exists($project, UnpaidReleaseBrowserFixture::PROJECTS)
        && count($argv) === ($mode === 'prepare' ? 3 : 4)
        && ($mode === 'prepare' || in_array($phase, ['prepared', 'released', 'replayed'], true)));
    $directory = UnpaidReleaseBrowserFixture::directory();
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    UnpaidReleaseBrowserFixture::effective($directory);
    $path = $directory.'/unpaid-release-'.$project.'.json';
    $log = $directory.'/unpaid-release-'.$project.'.jsonl';

    if ($mode === 'prepare') {
        UnpaidReleaseBrowserFixture::check(! file_exists($path) && ! is_link($path) && ! file_exists($log) && ! is_link($log));
        $old = unpaidBusinessRows();
        $guards = unpaidGuardHash();
        try {
            // Only this guarded CLI process uses existing synthetic nonbinding fixture transports.
            $app->detectEnvironment(fn () => 'testing');
            UnpaidReleaseFixtures::configure();
            Queue::fake();
            $gateway = PaymentFixtures::gateway();
            $sessionId = 'cs_test_BROWSERUNPAID'.UnpaidReleaseBrowserFixture::PROJECTS[$project];
            $gateway->onCreate = fn (array $params) => CheckoutFixtures::session($params, $sessionId);
            $app->instance(StripeCheckoutGateway::class, $gateway);
            $app->instance(StripePaymentGateway::class, $gateway);
            // No promotion in this browser fixture; native domain/race tests cover the paired promotion release.
            $f = UnpaidReleaseFixtures::started($gateway, false);
            $paymentId = 'pi_BROWSERUNPAID'.UnpaidReleaseBrowserFixture::PROJECTS[$project];
            $gateway->session['payment_intent'] = $paymentId;
            $gateway->payment['id'] = $paymentId;
            $original = app(ReadOrder::class)->verify($f['order']);
        } finally {
            $app->detectEnvironment(fn () => 'local');
        }
        $expected = unpaidBusinessRows();
        foreach ($old as $table => $rows) {
            foreach ($rows as $id => $hash) {
                UnpaidReleaseBrowserFixture::check(($expected[$table][$id] ?? null) === $hash);
            }
        }
        $attempt = $f['order']->attempt()->sole();
        $released = $expected;
        $reservation = (array) DB::table('inventory_reservations')->where('id', $attempt->inventory_reservation_id)->sole();
        UnpaidReleaseBrowserFixture::check($reservation['state'] === 'pending' && $attempt->promotion_use_id === null);
        $reservation['state'] = 'released';
        $released['inventory_reservations'][$reservation['id']] = hash('sha256', json_encode($reservation, JSON_THROW_ON_ERROR));
        UnpaidReleaseBrowserFixture::check(unpaidGuardHash() === $guards);
        $fixture = ['purpose' => 'test-unpaid-browser-v1', 'marker' => getenv('VASEY_BROWSER_EXCEPTION_MARKER'),
            'database' => $directory.'/database.sqlite', 'project' => $project, 'operatorId' => 1,
            'capability' => bin2hex(random_bytes(32)), 'orderId' => $f['order']->id, 'orderPublicId' => $f['order']->public_id,
            'originalHash' => CanonicalJson::hash($original), 'inventoryId' => $attempt->inventory_reservation_id,
            'session' => $gateway->session, 'payment' => $gateway->payment,
            'preparedRows' => $expected, 'releasedRows' => $released, 'guardHash' => $guards];
        UnpaidReleaseBrowserFixture::write($log, '');
        UnpaidReleaseBrowserFixture::write($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        echo json_encode(['orderId' => $f['order']->public_id, 'operatorEmail' => 'browser-operator@example.test',
            'capability' => $project.':'.$fixture['capability'],
            'privateMarkers' => [UnpaidReleaseBrowserFixture::ACCOUNT, $sessionId, $paymentId,
                $f['order']->payload_ciphertext, 'Synthetic Buyer Privacy Marker', 'order-privacy-marker@example.invalid']], JSON_THROW_ON_ERROR)."\n";
    } else {
        $fixture = UnpaidReleaseBrowserFixture::manifest($directory, $project);
        $order = Order::findOrFail($fixture['orderId']);
        UnpaidReleaseBrowserFixture::check($order->public_id === $fixture['orderPublicId']
            && CanonicalJson::hash(app(ReadOrder::class)->verify($order)) === $fixture['originalHash']
            && unpaidGuardHash() === $fixture['guardHash']);
        $rows = unpaidBusinessRows();
        UnpaidReleaseBrowserFixture::check($rows === $fixture[$phase === 'prepared' ? 'preparedRows' : 'releasedRows']);
        $events = TestUnpaidReleaseEvent::where('order_id', $order->id)->orderBy('sequence')->get();
        $calls = array_map(fn ($line) => json_decode($line, true, 8, JSON_THROW_ON_ERROR), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        if ($phase === 'prepared') {
            UnpaidReleaseBrowserFixture::check($events->isEmpty() && ! TestUnpaidRelease::where('order_id', $order->id)->exists() && $calls === []);
            $releaseId = null;
        } else {
            $release = TestUnpaidRelease::where('order_id', $order->id)->sole();
            app(ReadUnpaidRelease::class)->verify($release, $order, app(ReadOrder::class)->verify($order));
            UnpaidReleaseBrowserFixture::check($release->actor_id === 1 && $events->count() === 2
                && $events->pluck('sequence')->all() === [1, 2] && $events->pluck('kind')->all() === ['requested', 'observed']
                && $events->pluck('outcome')->all() === ['pending', 'released'] && $events->pluck('actor_id')->all() === [1, 1]
                && $events->pluck('request_id')->unique()->all() === [$release->request_id]
                && $release->event_id === $events->last()->id && $release->inventory_reservation_id === $fixture['inventoryId']);
            $work = TestUnpaidReleaseWork::where('order_id', $order->id)->sole();
            UnpaidReleaseBrowserFixture::check($work->sequence === 2 && $work->request_id === null && $work->claim_token === null && $work->lease_expires_at === null);
            UnpaidReleaseBrowserFixture::check($calls === array_map(fn ($operation) => ['operation' => $operation, 'transactionLevel' => 0], ['account', 'retrieve', 'payment_intent', 'retrieve']));
            foreach (['requested', 'observed'] as $kind) {
                UnpaidReleaseBrowserFixture::check(DB::table('audit_events')->where('action', 'commerce.unpaid_release.'.$kind)
                    ->where('subject_type', Order::class)->where('subject_id', $order->id)->where('actor_id', 1)->count() === 1);
            }
            $releaseId = $release->public_id;
        }
        echo json_encode(['phase' => $phase, 'releaseId' => $releaseId, 'historyCount' => $events->count(),
            'providerReads' => count($calls), 'originalsUnchanged' => true, 'noRightsOrMoneyEffects' => true,
            'guardsUnchanged' => true], JSON_THROW_ON_ERROR)."\n";
    }
} catch (Throwable) {
    fwrite(STDERR, "Isolated unpaid-release browser fixture failed; no private details are printed.\n");
    exit(1);
}

/** Hash complete retained rows; only the designated pending reservation's state may differ. */
function unpaidBusinessRows(): array
{
    $result = [];
    foreach (['orders', 'order_lines', 'order_attempts', 'quotes', 'quote_lines', 'quote_pricings', 'inventory_reservations',
        'inventory_claims', 'promotion_uses', 'checkout_intents', 'checkout_sessions', 'checkout_observations',
        'verified_payments', 'payment_observations', 'order_finalizations', 'license_grants', 'exclusive_sales',
        'pending_entitlements', 'fulfillment_outbox'] as $table) {
        $result[$table] = [];
        foreach (DB::table($table)->orderBy('id')->get() as $row) {
            $result[$table][$row->id] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        }
    }

    return $result;
}

function unpaidGuardHash(): string
{
    return hash('sha256', json_encode(DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->get(['name', 'tbl_name', 'sql']), JSON_THROW_ON_ERROR));
}
