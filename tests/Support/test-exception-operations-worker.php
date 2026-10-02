<?php

use App\Domain\Commerce\Operations\TestPaymentExceptionOperations;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FinalizationFixtures;
use Tests\Support\PaymentFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Exception operation workers require disposable MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
Carbon::setTestNow($input['at']);
FinalizationFixtures::configure();
Queue::fake();
$gateway = PaymentFixtures::gateway();
$gateway->session = $input['session'];
$gateway->payment = $input['payment'];
app()->instance(StripePaymentGateway::class, $gateway);
$directory = getenv('VASEY_EXCEPTION_OPS_DIRECTORY');
$worker = getenv('VASEY_EXCEPTION_OPS_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Exception operation barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$actor = User::findOrFail($input['actor_id']);
file_put_contents($directory.'/ready-'.$worker, json_encode(['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$worker);
$paused = false;
if (($input['pause'] ?? null) === 'order') {
    DB::listen(function ($query) use ($directory, $worker, $wait, &$paused): void {
        if (! $paused && str_contains($query->sql, 'from `orders`') && str_contains($query->sql, 'for update')) {
            $paused = true;
            file_put_contents($directory.'/locked-'.$worker, 'locked');
            $wait($directory.'/release-'.$worker);
        }
    });
} elseif (($input['pause'] ?? null) === 'provider') {
    $gateway->onRetrieve = function () use ($directory, $worker, $wait, $gateway): array {
        file_put_contents($directory.'/locked-'.$worker, 'provider');
        $wait($directory.'/release-'.$worker);

        return $gateway->session;
    };
}
try {
    $service = app(TestPaymentExceptionOperations::class);
    $result = $input['operation'] === 'disposition'
        ? $service->disposition($input['public_id'], $actor, $input['key'], 0, 'acknowledged')
        : $service->reconcile($input['public_id'], $actor, $input['key'], 0);
    $result = ['result' => $result['status']];
} catch (RuntimeException) {
    $result = ['result' => 'blocked'];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(),
    'provider_calls' => $gateway->calls, 'transaction_level' => DB::transactionLevel(), 'jobs' => array_keys(Queue::pushedJobs())], JSON_THROW_ON_ERROR);
file_put_contents($directory.'/finished-'.$worker, 'finished');
