<?php

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures;
use Tests\Support\RehearsalBillingGateway;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_MEMBERSHIP_BILLING_RACE_ONLY') !== '1') {
    throw new LogicException('Isolated native membership billing race fixture only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
BillingStripeFixtures::configure();
$directory = getenv('VA_MEMBERSHIP_BILLING_RACE_DIRECTORY');
$worker = getenv('VA_MEMBERSHIP_BILLING_RACE_WORKER');
$pdo = DB::connection()->getPdo();
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
file_put_contents($directory.'/ready-'.$worker.'.tmp', (string) $connectionId);
rename($directory.'/ready-'.$worker.'.tmp', $directory.'/ready-'.$worker);
$deadline = microtime(true) + 30;
while (! is_file($directory.'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Billing worker barrier timed out.');
    }
    usleep(5000);
    clearstatcache();
}
try {
    $observation = (new BillingReconciliation(new RehearsalBillingGateway(BillingStripeFixtures::graph())))->retrieve($input['binding_id'], BillingStripeFixtures::INVOICE);
    $result = 'saved';
} catch (BillingException $error) {
    $result = 'denied';
    $reason = $error->reason;
} catch (Throwable $error) {
    $result = 'unexpected';
    $errorClass = $error::class;
}
echo json_encode(['result' => $result, 'observation_id' => $observation['id'] ?? null, 'sequence' => $observation['sequence'] ?? null,
    'invoice_id' => $observation['invoice_id'] ?? null, 'reason' => $reason ?? null, 'error_class' => $errorClass ?? null,
    'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
