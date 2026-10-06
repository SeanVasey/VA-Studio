<?php

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\CustomerPurchaseClaims;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomerFixtures;
use Tests\Support\DeliveryFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Disposable testing MySQL required.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
CustomerFixtures::configure();
DeliveryFixtures::configure();
config(['customer.test_purchase_claims_enabled' => true]);
Carbon::setTestNow($input['at']);
$actor = User::findOrFail($input['user']);
$principal = new CustomerPrincipal(...$input['principal']);
$id = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout=15');
$directory = getenv('VASEY_PURCHASE_CLAIM_RACE');
$index = getenv('VASEY_PURCHASE_CLAIM_WORKER');
file_put_contents($directory.'/ready-'.$index, json_encode(['connection' => $id, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
$deadline = microtime(true) + 20;
while (! is_file($directory.'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout.');
    } usleep(10000);
    clearstatcache();
}
$locks = [];
DB::listen(function ($query) use (&$locks): void {
    if (str_contains($query->sql, 'for update') && preg_match('/from `([a-z_]+)`/', $query->sql, $match)) {
        $locks[] = $match[1];
    }
});
try {
    $claim = app(CustomerPurchaseClaims::class)->complete($input['marker'], $input['order'], $principal, $actor);
    $result = 'saved';
    $claimId = $claim->public_id;
} catch (CustomerAccessException) {
    $result = 'denied';
} catch (Throwable $error) {
    $result = 'unexpected';
    $errorClass = $error::class;
}
echo json_encode(['result' => $result, 'claim' => $claimId ?? null, 'error' => $errorClass ?? null,
    'connection' => $id, 'pid' => getmypid(), 'locks' => $locks, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
