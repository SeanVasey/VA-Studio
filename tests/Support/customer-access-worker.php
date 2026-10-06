<?php

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReviewOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\CustomerSessions;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\RedeemTestDelivery;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CheckoutFixtures;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\DeliveryFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Customer worker requires disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
Carbon::setTestNow($input['at']);
F::configure();
DeliveryFixtures::configure();
$gateway = CheckoutFixtures::gateway();
app()->instance(StripeCheckoutGateway::class, $gateway);
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$actor = User::findOrFail($input['user_id']);
$principal = new CustomerPrincipal(...$input['principal']);
$directory = getenv('VASEY_CUSTOMER_RACE_DIRECTORY');
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Customer worker barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
file_put_contents($directory.'/ready', json_encode(['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
$wait($directory.'/start');
$locks = [];
DB::listen(function ($query) use (&$locks): void {
    if (str_contains($query->sql, 'for update') && preg_match('/from `([a-z_]+)`/', $query->sql, $match)) {
        $locks[] = $match[1];
    }
});
try {
    $owner = $principal->ownerKey;
    $result = match ($input['operation']) {
        'sign-in' => app(CustomerSessions::class)->authenticate($actor->email, F::PASSWORD) === null ? 'denied' : 'saved',
        'create' => app(CreateQuote::class)->handle($owner, $input['key'], $input['items'], $actor, $principal),
        'price' => app(PriceQuote::class)->create($input['quote_id'], $owner, $actor, $principal),
        'review' => app(ReviewOrder::class)->handle($input['quote_id'], $owner, $actor, $principal),
        'prepare' => app(PrepareOrder::class)->handle($owner, $input['key'], $input['request'], $actor, $principal),
        'checkout' => app(HostedCheckout::class)->start($input['order_id'], $owner, $actor, $principal),
        'issue' => app(IssueTestDelivery::class)->handle($input['order_id'], $owner, $input['grant_id'], 'contract', $input['key'], $actor, $principal),
        'redeem' => app(RedeemTestDelivery::class)->handle($input['order_id'], $owner, $input['authorization_id'], $input['token'], $actor, $principal),
        default => throw new LogicException('Unknown customer operation.'),
    };
    $result = $result === 'denied' ? 'denied' : 'saved';
} catch (CustomerAccessException) {
    $result = 'denied';
} catch (Throwable $error) {
    $result = 'unexpected';
    $errorClass = $error::class;
}
echo json_encode(['result' => $result, 'error_class' => $errorClass ?? null, 'connection_id' => $connection, 'pid' => getmypid(),
    'transaction_level' => DB::transactionLevel(), 'locks' => $locks, 'provider_calls' => count($gateway->calls)], JSON_THROW_ON_ERROR);
