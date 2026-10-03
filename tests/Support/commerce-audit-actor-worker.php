<?php

use App\Domain\Catalog\ReadTrackPublicationManifest;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReviewOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PromotionUsage;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\ReservePricedQuote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CheckoutFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PromotionFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Commerce actor workers require disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
Carbon::setTestNow($input['at']);
OrderFixtures::configure();
PromotionFixtures::configure([PromotionFixtures::policy()]);
if ($input['operation'] === 'checkout' || isset($input['order_id'])) {
    CheckoutFixtures::configure();
}
$gateway = CheckoutFixtures::gateway();
app()->instance(StripeCheckoutGateway::class, $gateway);
$directory = getenv('VASEY_COMMERCE_AUDIT_DIRECTORY');
$worker = getenv('VASEY_COMMERCE_AUDIT_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Commerce actor barrier timed out.');
        }usleep(10000);
        clearstatcache();
    }
};
$actor = User::findOrFail($input['actor_id']);
Auth::setUser($actor);
$customer = ($input['anonymous'] ?? false) ? null : $actor;
file_put_contents($directory.'/ready-'.$worker, json_encode(['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$worker);
$paused = false;
$locks = [];
DB::listen(function ($query) use ($input, $directory, $worker, $wait, &$paused, &$locks): void {
    if (! preg_match('/\Aselect\b/i', $query->sql) || ! str_contains($query->sql, 'for update')) {
        return;
    }
    foreach (['users', 'quote_owners', 'quotes', 'tracks', 'offers', 'quote_pricings', 'promotion_campaigns', 'rights_scopes', 'orders'] as $table) {
        if (! str_contains($query->sql, 'from `'.$table.'`')) {
            continue;
        }
        $ids = array_values(array_map('intval', array_filter($query->bindings, fn ($value) => is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)))));
        // Replay locks by owner/key hash, so the verified retained row ID is not a SQL binding.
        if ($table === 'orders' && isset($input['order_row_id']) && str_contains($query->sql, '`idempotency_key_hash`')) {
            $ids[] = $input['order_row_id'];
        }
        $locks[] = ['table' => $table, 'ids' => $ids];
        if (! $paused && ($input['pause_table'] ?? null) === $table && in_array($input['pause_id'], $ids, true)) {
            $paused = true;
            file_put_contents($directory.'/locked-'.$worker, 'locked');
            $wait($directory.'/release-'.$worker);
        }
    }
});
try {
    $q = $input['quote_id'] ?? '';
    $owner = $input['owner'] ?? '';
    $attempt = $input['attempt'] ?? '';
    match ($input['operation']) {
        'manifest' => app(ReadTrackPublicationManifest::class)->handle($input['track_id'], $actor),
        'lock-user' => DB::transaction(fn () => User::query()->lockForUpdate()->findOrFail($actor->id)),
        'create' => app(CreateQuote::class)->handle($owner, $input['key'], $input['items'], $customer),
        'price' => app(PriceQuote::class)->create($q, $owner, $customer),
        'promoted-price' => app(PriceQuote::class)->createWithPromotion($q, $owner, 'SYNTHETIC', $customer),
        'inventory-hold' => app(ReserveQuoteInventory::class)->hold($q, $owner, $customer),
        'reserve-hold' => app(ReservePricedQuote::class)->hold($q, $owner, 'SYNTHETIC', $customer),
        'inventory-attempt' => app(ReserveQuoteInventory::class)->beginAttempt($q, $owner, $attempt, $customer),
        'reserve-attempt' => app(ReservePricedQuote::class)->beginAttempt($q, $owner, $attempt, $customer),
        'promotion-attempt' => app(PromotionUsage::class)->beginAttempt($q, $owner, $attempt, $customer),
        'prepare' => app(PrepareOrder::class)->handle($owner, $input['key'], $input['request'], $customer),
        'review' => app(ReviewOrder::class)->handle($q, $owner, $customer),
        'checkout' => app(HostedCheckout::class)->start($input['order_id'], $owner, $customer),
        default => throw new LogicException('Unknown commerce actor operation.')
    };
    $result = ['result' => 'saved'];
} catch (QuoteException $error) {
    $result = ['result' => 'blocked', 'code' => $error->errorCode];
} catch (QueryException $error) {
    $result = ['result' => 'database-failed', 'sqlstate' => $error->errorInfo[0] ?? null, 'driver_code' => $error->errorInfo[1] ?? null];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(), 'paused' => $paused, 'locks' => $locks, 'provider_calls' => $gateway->calls, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
file_put_contents($directory.'/finished-'.$worker, 'finished');
