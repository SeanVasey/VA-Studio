<?php

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_CHECKOUT_RACE_ONLY') !== '1') {
    throw new LogicException('Isolated native checkout race fixture only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
    'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.funds_mode' => 'test',
    'production_checkout.account_id' => 'acct_SYNTHETIC', 'production_checkout.return_origin' => 'https://review.invalid',
    'production_checkout.review_lifetime_seconds' => 600, 'production_checkout.exemption_policy_owner_ids' => $input['owner_ids'],
    'filesystems.disks.local.root' => $input['private_root'], 'filesystems.disks.local.serve' => false,
    'production_checkout.provider_io_enabled' => false]);
$directory = getenv('VA_CHECKOUT_RACE_DIRECTORY');
$buyer = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123');
if ($buyer === null) {
    throw new IdentityException;
}
$pdo = DB::connection()->getPdo();
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
file_put_contents($directory.'/ready.tmp', json_encode(['connection_id' => $connectionId, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
rename($directory.'/ready.tmp', $directory.'/ready');
$deadline = microtime(true) + 20;
while (! is_file($directory.'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Checkout worker barrier timed out.');
    }
    usleep(10000);
    clearstatcache();
}
try {
    // Authentication is complete before the barrier. The command then takes its exact
    // original-user lock; LOWER(email) authentication scans are not the race under test.
    $order = (new ProductionCheckout(new ProductionCustomerAccess))->accept($buyer['principal'], $buyer['user'],
        $input['review_id'], $input['review_hash'], true, $input['key']);
    $result = 'saved';
} catch (CheckoutException|IdentityException) {
    $result = 'denied';
} catch (Throwable $error) {
    $result = 'unexpected';
    $errorClass = $error::class;
}
echo json_encode(['result' => $result, 'order_id' => $order['orderId'] ?? null, 'error_class' => $errorClass ?? null,
    'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
