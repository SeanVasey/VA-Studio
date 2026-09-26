<?php

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\QuoteException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\CheckoutFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') { throw new LogicException('Checkout race requires test MySQL.'); }
    $directory = getenv('VASEY_CHECKOUT_RACE_DIRECTORY'); $worker = getenv('VASEY_CHECKOUT_RACE_WORKER');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true)) { throw new LogicException('Missing checkout race configuration.'); }
    $input = json_decode(stream_get_contents(STDIN, 32768), true, 32, JSON_THROW_ON_ERROR);
    CheckoutFixtures::configure();
    \Illuminate\Support\Carbon::setTestNow($input['now']); \Carbon\CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id; $passed = false;
    $barrier = function () use ($directory, $worker, $connectionId, &$passed): void {
        if (DB::transactionLevel() !== 0 || DB::table('checkout_intents')->count() !== 1) { throw new LogicException('Provider call preceded durable intent commit.'); }
        $passed = true;
        if (file_put_contents($directory.'/ready-'.$worker, (string) $connectionId) === false) { throw new RuntimeException('Cannot signal checkout barrier.'); }
        $deadline = microtime(true) + 25;
        do { clearstatcache(true, $directory.'/release'); if (is_file($directory.'/release')) { return; } usleep(10000); }
        while (microtime(true) < $deadline);
        throw new RuntimeException('Checkout provider barrier timed out.');
    };
    $gateway = CheckoutFixtures::gateway();
    $gateway->onCreate = function (array $params, string $key) use ($input, $barrier): array {
        $barrier();

        return CheckoutFixtures::session($params, $input['session_id']);
    };
    $gateway->onRetrieve = function (string $id) use ($input, $barrier): array {
        $barrier(); $session = $input['session'];
        $session['status'] = $input['status']; $session['payment_status'] = $input['status'] === 'complete' ? 'paid' : 'unpaid';
        if ($input['status'] !== 'open') { $session['url'] = null; }
        if ($input['status'] === 'complete') { $session['payment_intent'] = 'pi_SYNTHETIC'; }

        return $session;
    };
    $app->instance(StripeCheckoutGateway::class, $gateway);
    try {
        $value = app(HostedCheckout::class)->{$input['action']}($input['order'], $input['owner']);
        $result = ['result' => 'ok', 'checkout' => $value];
    } catch (QuoteException $error) { $result = ['result' => 'rejected', 'code' => $error->errorCode]; }
    if (! $passed) { throw new LogicException('Operation missed its provider barrier.'); }
    echo json_encode($result + ['pid' => getmypid(), 'calls' => $gateway->calls], JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR); exit(1);
}
