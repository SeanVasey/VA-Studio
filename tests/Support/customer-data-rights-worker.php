<?php

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\DataRightsException;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomerFixtures as F;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Customer data rights worker requires disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
Carbon::setTestNow($input['at']);
F::configure();
config(['customer-data-rights.enabled' => true, 'customer-data-rights.retention' => $input['retention']]);
// BL-44: `production` turns the production identity path on; a customer's principal (`mint` is the user id) is minted from server
// authority (the production origin and credential on file), as the session does, BEFORE the worker announces itself, so the
// command's own locks are the first thing it waits on. Staff approval passes `production` without `mint`.
$production = ($input['production'] ?? false) === true;
$minted = null;
if ($production) {
    config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => 'synthetic_rehearsal',
        'production-customer-identity.public_origin' => 'http://localhost']);
    $minted = isset($input['mint']) ? ProductionCustomerPrincipal::forUser(User::findOrFail($input['mint'])) : null;
}
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$directory = getenv('VASEY_DATA_RIGHTS_RACE_DIRECTORY');
$deadline = microtime(true) + 20;
$barrier = array_key_exists('barrier', $input) ? $input['barrier'] : 'start';
file_put_contents($directory.'/'.($input['name'] ?? 'ready'), json_encode(['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
while ($barrier !== null && ! is_file($directory.'/'.$barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Customer data rights worker barrier timed out.');
    }
    usleep(10000);
    clearstatcache();
}
// Optional pause right after the Nth query matching every pause_sql needle (first attempt only), until <dir>/<release> exists.
if (isset($input['pause_sql'])) {
    $seen = 0;
    $released = false;
    DB::listen(function ($query) use (&$seen, &$released, $input, $directory): void {
        foreach ($input['pause_sql'] as $needle) {
            if (! str_contains($query->sql, $needle)) {
                return;
            }
        }
        if (! $released && ++$seen === $input['pause_at']) {
            file_put_contents($directory.'/'.$input['name'].'.paused', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
            $deadline = microtime(true) + 60;
            while (! is_file($directory.'/'.$input['release'])) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Customer data rights worker pause timed out.');
                }
                usleep(5000);
                clearstatcache();
            }
            $released = true;
        }
    });
}
$rights = app(CustomerDataRights::class);
$reason = null;
try {
    $principal = $minted ?? (isset($input['principal']) ? new CustomerPrincipal(...$input['principal']) : null);
    $actor = User::findOrFail($input['actor_id']);
    $value = match ($input['operation']) {
        'export' => $production ? $rights->requestProductionExport($principal, $actor) : $rights->requestExport($principal, $actor),
        'download' => $production ? $rights->downloadProduction($principal, $actor, $input['request_id'], $input['token'])
            : $rights->download($principal, $actor, $input['request_id'], $input['token']),
        'deletion' => $production ? $rights->requestProductionDeletion($principal, $actor) : $rights->requestDeletion($principal, $actor),
        'approve' => $rights->approveDeletion($input['request_id'], $actor, $input['version']),
        default => throw new LogicException('Unknown customer data rights operation.'),
    };
    $result = 'saved';
} catch (CustomerAccessException|IdentityException) {
    $result = 'denied';
} catch (DataRightsException $error) {
    $result = 'refused';
    $reason = $error->reason;
} catch (Throwable $error) {
    $result = 'unexpected';
    $reason = $error::class;
}
echo json_encode(['result' => $result, 'reason' => $reason, 'value' => is_array($value ?? null) ? $value : null,
    'connection_id' => $connection, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
