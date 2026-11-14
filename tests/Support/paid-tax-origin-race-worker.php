<?php

use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
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
$phase = 'authenticated';
$begunDictionaryRead = false;
$connection = DB::connection();
$connection->getEventDispatcher()->listen(TransactionBeginning::class, static function ($event) use ($connection, $pdo, &$begunDictionaryRead): void {
    if ($event->connection === $connection && ! $begunDictionaryRead) {
        $pdo->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        $begunDictionaryRead = true;
    }
});
$waits = static fn (): array => $pdo->query('SELECT @@session.lock_wait_timeout, @@session.innodb_lock_wait_timeout')->fetch(PDO::FETCH_NUM);
file_put_contents($directory.'/ready.tmp', json_encode(['connection_id' => $connectionId, 'pid' => getmypid(), 'waits' => $waits()], JSON_THROW_ON_ERROR));
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
    $phase = 'command-started';
    $commandStarted = hrtime(true);
    $projection = (new PaidTaxGrants)->finalize($buyer['principal'], $buyer['user'], $input['order_id']);
    $result = 'saved';
} catch (Throwable $error) {
    $result = 'unexpected';
    $errorClass = $error::class;
    $status = property_exists($error, 'status') ? $error->status : null;
    $location = basename($error->getFile()).':'.$error->getLine();
    // Call sites only: never include arguments, SQL bindings, configuration or credentials.
    $trace = array_map(static fn (array $frame): array => [
        'call' => ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''),
        'site' => isset($frame['file']) ? basename($frame['file']).':'.($frame['line'] ?? 0) : null,
    ], array_slice($error->getTrace(), 0, 12));
}
echo json_encode(['result' => $result, 'id' => $projection['id'] ?? null, 'order_id' => $projection['orderId'] ?? null,
    'error_class' => $errorClass ?? null, 'status' => $status ?? null, 'location' => $location ?? null, 'phase' => $phase,
    'trace' => $trace ?? [], 'command_elapsed_seconds' => (hrtime(true) - $commandStarted) / 1_000_000_000,
    'waits' => $waits(),
    'begun_dictionary_read' => $begunDictionaryRead,
    'connection_id' => $connectionId, 'pid' => getmypid(),
    'transaction_level' => DB::transactionLevel(), 'in_transaction' => $pdo->inTransaction()], JSON_THROW_ON_ERROR);
