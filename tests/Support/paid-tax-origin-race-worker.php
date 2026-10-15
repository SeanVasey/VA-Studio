<?php

use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_PAID_TAX_RACE_ONLY') !== '1'
    || DB::getDatabaseName() !== getenv('DB_DATABASE')) {
    throw new LogicException('Explicit isolated native Tax origin race only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
config(['production-customer-identity' => $input['identity'], 'production-tax-checkout' => $input['tax'],
    'paid-tax-grants' => $input['consumer'], 'filesystems.disks.local.root' => $input['private_root'], 'filesystems.disks.local.serve' => false]);
$buyer = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123');
if ($buyer === null) {
    throw new LogicException('Actual enrolled synthetic buyer authentication failed.');
}
$pdo = DB::getPdo();
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$directory = getenv('VA_PAID_TAX_RACE_DIRECTORY');
file_put_contents($directory.'/ready.tmp', json_encode(['connection_id' => $connectionId, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
rename($directory.'/ready.tmp', $directory.'/ready');
$end = hrtime(true) + 15_000_000_000;
while (! is_file($directory.'/start')) {
    if (hrtime(true) > $end) {
        throw new RuntimeException('Tax origin worker barrier timed out.');
    }
    usleep(10000);
    clearstatcache();
}
try {
    // Authentication precedes the barrier; the ordinary command budget starts exactly once inside finalize.
    $projection = (new PaidTaxGrants)->finalize($buyer['principal'], $buyer['user'], $input['order_id']);
    $result = 'saved';
} catch (Throwable $error) {
    $result = 'unexpected';
    $errorClass = $error::class;
}
echo json_encode(['result' => $result, 'id' => $projection['id'] ?? null, 'order_id' => $projection['orderId'] ?? null,
    'error_class' => $errorClass ?? null, 'connection_id' => $connectionId, 'pid' => getmypid(),
    'transaction_level' => DB::transactionLevel(), 'in_transaction' => $pdo->inTransaction()], JSON_THROW_ON_ERROR);
