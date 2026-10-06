<?php

use App\Domain\Customers\CustomerAccessException;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CustomerIdentityFixtures as F;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Customer identity worker requires disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
Carbon::setTestNow($input['at']);
F::configure();
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$directory = getenv('VASEY_CUSTOMER_IDENTITY_RACE_DIRECTORY');
file_put_contents($directory.'/ready', json_encode(['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
$deadline = microtime(true) + 20;
while (! is_file($directory.'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Customer identity worker barrier timed out.');
    }
    usleep(10000);
    clearstatcache();
}
$locks = [];
DB::listen(function ($query) use (&$locks): void {
    if (str_contains($query->sql, 'for update') && preg_match('/from `([a-z_]+)`/', $query->sql, $match)) {
        $locks[] = $match[1];
    }
});
try {
    F::complete($input['body']);
    $result = 'saved';
} catch (CustomerAccessException) {
    $result = 'denied';
} catch (Throwable $error) {
    $result = 'unexpected';
    $errorClass = $error::class;
}
echo json_encode(['result' => $result, 'error_class' => $errorClass ?? null, 'connection_id' => $connection,
    'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(), 'locks' => $locks], JSON_THROW_ON_ERROR);
