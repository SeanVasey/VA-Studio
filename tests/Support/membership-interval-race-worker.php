<?php

use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_MEMBERSHIP_INTERVAL_RACE_ONLY') !== '1') {
    throw new LogicException('Isolated native interval fixture only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
if (! in_array($input['table'], [BillingSchema::TABLES[2], BillingSchema::TABLES[5]], true)) {
    throw new LogicException('Unknown interval fixture table.');
}
$directory = getenv('VA_MEMBERSHIP_INTERVAL_RACE_DIRECTORY');
$worker = getenv('VA_MEMBERSHIP_INTERVAL_RACE_WORKER');
$pdo = DB::connection()->getPdo();
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::beginTransaction();
// Both workers pin an older snapshot before either inserts; the guard must use current evidence.
foreach ([BillingSchema::TABLES[2], BillingSchema::TABLES[5]] as $table) {
    $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
}
file_put_contents($directory.'/ready-'.$worker, (string) $connectionId);
$deadline = microtime(true) + 30;
while (! is_file($directory.'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Interval worker barrier timed out.');
    }
    usleep(5000);
    clearstatcache();
}
try {
    $row = $input['row'];
    $pdo->prepare('INSERT INTO '.$input['table'].' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')')->execute(array_values($row));
    DB::commit();
    $result = 'saved';
} catch (PDOException $error) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $result = 'denied';
    $state = $error->errorInfo[0] ?? $error->getCode();
    $driverCode = $error->errorInfo[1] ?? null;
}
echo json_encode(['result' => $result, 'sqlstate' => $state ?? null, 'driver_code' => $driverCode ?? null,
    'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
