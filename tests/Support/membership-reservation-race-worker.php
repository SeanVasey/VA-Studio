<?php

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Production\CurrentEligibleLicenses;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use App\Domain\Memberships\Production\SealedMembershipPolicyFacts;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || getenv('VA_MEMBERSHIP_RESERVATION_RACE_ONLY') !== '1') {
    throw new LogicException('Isolated native reservation fixture only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
if (! in_array($input['operation'] ?? null, ['reserve', 'release', 'expire'], true)
    || array_keys($input['configuration']) !== ['production-customer-identity', 'production-memberships',
        'production-membership-billing', 'filesystems.disks.local', 'media']
    || config('app.key') !== 'base64:'.base64_encode(str_repeat('P', 32))) {
    throw new LogicException('Unknown synthetic reservation worker configuration.');
}
config($input['configuration']);
// Wall-time equality is deliberate synthetic input; monotonic admission and process waits stay real.
CarbonImmutable::setTestNow(CarbonImmutable::parse($input['at'], 'UTC'));
Queue::fake();
$app->instance(MembershipPaidInvoiceAuthority::class, new SealedMembershipPaidInvoice);
$app->instance(MembershipPolicyFactsAuthority::class, new SealedMembershipPolicyFacts);
$app->instance(MembershipEligibleLicenseAuthority::class, new CurrentEligibleLicenses);
// Same unused fixture-only C4 gate as MembershipReservationFixtures, never grant authority/readiness.
$grant = Mockery::mock(MemberGrantAuthority::class);
$grant->shouldNotReceive('prepare');
$grant->shouldNotReceive('proveReadyCurrent');
$app->instance(MemberGrantAuthority::class, $grant);
$pdo = DB::connection()->getPdo();
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 60');
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$directory = getenv('VA_MEMBERSHIP_RESERVATION_RACE_DIRECTORY');
$worker = getenv('VA_MEMBERSHIP_RESERVATION_RACE_WORKER');
$actor = User::query()->findOrFail($input['user_id']);
$principal = ProductionCustomerPrincipal::forUser($actor);
file_put_contents($directory.'/ready-'.$worker, json_encode(['connection_id' => $connectionId, 'pid' => getmypid(),
    'ready_ns' => hrtime(true), 'at' => $input['at']], JSON_THROW_ON_ERROR));
$deadline = hrtime(true) + 90_000_000_000;
while (! is_file($directory.'/start-'.$worker)) {
    if (hrtime(true) >= $deadline) {
        throw new RuntimeException('Synthetic reservation worker barrier timed out.');
    }
    usleep(5000);
    clearstatcache();
}
$value = null;
$errorClass = null;
$reason = null;
$entered = hrtime(true);
try {
    $operations = new MembershipReservationOperations;
    $value = match ($input['operation']) {
        'reserve' => $operations->reserve($input['invoice_id'], $input['selection'], $input['expected_revision'],
            $input['request_key'], $principal, $actor),
        'release' => $operations->release($input['invoice_id'], $input['redemption_id'], $principal, $actor),
        'expire' => $operations->expire($input['invoice_id'], $principal, $actor),
    };
} catch (Throwable $error) {
    $errorClass = get_class($error);
    $reason = property_exists($error, 'reason') ? $error->reason : null;
}
echo json_encode(['value' => $value, 'error_class' => $errorClass, 'reason' => $reason,
    'operation' => $input['operation'], 'at' => $input['at'], 'entered_ns' => $entered, 'finished_ns' => hrtime(true),
    'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(),
    'pdo_transaction' => $pdo->inTransaction()], JSON_THROW_ON_ERROR);
