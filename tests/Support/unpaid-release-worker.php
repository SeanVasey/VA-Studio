<?php

use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\UnpaidRelease\ReleaseTestOrderResources;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\PromotionFixtures;
use Tests\Support\UnpaidReleaseFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Test MySQL required.');
    }
    $input = json_decode(stream_get_contents(STDIN, 131072), true, 64, JSON_THROW_ON_ERROR);
    $directory = getenv('VASEY_UNPAID_RACE_DIRECTORY');
    $worker = getenv('VASEY_UNPAID_RACE_WORKER');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true)) {
        throw new LogicException('Missing barrier.');
    }
    UnpaidReleaseFixtures::configure();
    Queue::fake();
    if (! is_string($input['media_root'] ?? null) || ! is_dir($input['media_root'])) {
        throw new LogicException('Missing private fixture storage.');
    }
    config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local');
    if (isset($input['promotion'])) {
        PromotionFixtures::configure([$input['promotion']]);
    }
    Carbon::setTestNow($input['now']);
    CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $gateway = PaymentFixtures::gateway();
    $gateway->session = $input['session'];
    $gateway->payment = $input['payment'];
    $app->instance(StripeCheckoutGateway::class, $gateway);
    $app->instance(StripePaymentGateway::class, $gateway);
    $inspection = null;
    if ($input['operation'] === 'payment') {
        $gateway->session['status'] = 'complete';
        $gateway->session['payment_status'] = 'paid';
        $gateway->session['payment_intent'] = PaymentFixtures::PAYMENT;
        $gateway->payment = PaymentFixtures::payment($gateway->session);
        $inspection = app(VerifyTestPayment::class)->inspect($gateway->session['id'], CheckoutIntent::findOrFail($input['intent_id']));
    }
    $wait = function (string $path): void {
        $deadline = microtime(true) + 35;
        do {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return;
            } usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Unpaid worker barrier timed out.');
    };
    $matches = fn (string $sql): bool => preg_match('/\Aselect\b/i', $sql) && str_contains($sql, 'from `'.$input['mutex'].'`') && str_contains($sql, 'for update');
    $before = 0;
    $after = 0;
    DB::connection()->beforeExecuting(function (string $sql) use ($matches, $directory, $worker, $input, &$before): void {
        if ($matches($sql) && ++$before === $input['ordinal']) {
            touch($directory.'/attempting-'.$worker);
        }
    });
    DB::listen(function ($query) use ($matches, $directory, $worker, $input, $wait, &$after): void {
        if ($matches($query->sql) && ++$after === $input['ordinal']) {
            if (DB::transactionLevel() < 1) {
                throw new LogicException('No mutex transaction.');
            }
            touch($directory.'/locked-'.$worker);
            if ($worker === '0') {
                $wait($directory.'/release');
            }
        }
    });
    file_put_contents($directory.'/ready-'.$worker, (string) $connection);
    $wait($directory.'/start-'.$worker);
    $result = match ($input['operation']) {
        'release' => app(ReleaseTestOrderResources::class)->release($input['public_id'], User::findOrFail($input['actor_id']), $input['request_id'], 0)['status'],
        'payment' => app(VerifyTestPayment::class)->commit($inspection, null),
        'hold' => app(ReserveQuoteInventory::class)->hold($input['quote_id'], InventoryFixtures::OWNER)->state,
        'price' => (function () use ($input) {
            app(PriceQuote::class)->createWithPromotion($input['quote_id'], InventoryFixtures::OWNER, 'SYNTHETIC');

            return 'priced';
        })(),
    };
    if ($after < $input['ordinal']) {
        throw new LogicException('Missing intended fence.');
    }
    echo json_encode(['result' => $result, 'connection' => $connection, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(), 'calls' => $gateway->calls], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['error' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    exit(1);
}
