<?php

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Grants\Member\MemberGrantActivations;
use App\Domain\Grants\Member\MemberGrantFactsAuthority;
use App\Domain\Grants\Member\MemberLicenseAuthorizations;
use App\Domain\Grants\Member\MemberLicenseConfirmationInput;
use App\Domain\Grants\Member\MemberOriginalArtifactAuthority;
use App\Domain\Grants\Member\MemberOriginalFiles;
use App\Domain\Grants\Member\MemberOriginalRendererProcess;
use App\Domain\Grants\Member\SealedMemberGrantFacts;
use App\Domain\Memberships\Production\CurrentEligibleLicenses;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\MembershipReservations;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use App\Domain\Memberships\Production\SealedMembershipPolicyFacts;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || getenv('VA_MEMBER_ACTIVATION_RELEASE_RACE_ONLY') !== '1'
    || config('app.key') !== 'base64:'.base64_encode(str_repeat('P', 32))) {
    throw new LogicException('Isolated synthetic activation/release worker only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
if (array_keys($input['configuration']) !== ['production-customer-identity', 'production-memberships',
    'production-membership-billing', 'member-grants', 'filesystems.disks.local', 'media']
    || ! in_array($input['role'], ['activate', 'release'], true) || ! is_bool($input['hold'])) {
    throw new LogicException('Unknown synthetic activation/release configuration.');
}
config($input['configuration']);
CarbonImmutable::setTestNow(CarbonImmutable::parse($input['confirm_at'], 'UTC'));
Queue::fake();
$app->instance(MembershipPaidInvoiceAuthority::class, new SealedMembershipPaidInvoice);
$app->instance(MembershipPolicyFactsAuthority::class, new SealedMembershipPolicyFacts);
$app->instance(MembershipEligibleLicenseAuthority::class, new CurrentEligibleLicenses);
$facts = new SealedMemberGrantFacts;
$reservations = new MembershipReservations;
$operation = new MemberGrantActivations($facts, $reservations, new MemberOriginalRendererProcess, new MemberOriginalFiles);
$app->instance(MemberGrantFactsAuthority::class, $facts);
$app->instance(MembershipReservationAuthority::class, $reservations);
$app->instance(MemberOriginalArtifactAuthority::class, $operation->artifactAuthority());
$app->instance(MemberGrantAuthority::class, $operation->grantAuthority());
$app->instance(MemberLicenseAuthorizations::class, $operation->authorizations());
$pdo = DB::connection()->getPdo();
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 60');
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$directory = getenv('VA_MEMBER_ACTIVATION_RELEASE_RACE_DIRECTORY');
$role = $input['role'];
$actor = User::query()->findOrFail($input['user_id']);
$principal = ProductionCustomerPrincipal::forUser($actor);
$ticket = null;
$authorization = null;
if ($role === 'activate') {
    $issuer = $operation->authorizations();
    $display = $issuer->review($input['definition_id'], $input['redemption_id'], $principal, $actor);
    $ticket = $issuer->confirm($input['definition_id'], $input['redemption_id'],
        MemberLicenseConfirmationInput::from(['display_hash' => $display->hash(), 'affirmed' => true]), $principal, $actor);
    // Read-only observation of the genuine issuer; never mutate, mint, transfer or replace authority.
    $issued = (new ReflectionProperty(MemberLicenseAuthorizations::class, 'tickets'))->getValue($issuer)[$ticket]['authorization'];
    $authorization = ['id' => $issued['authorization_id'], 'hash' => CanonicalJson::hash($issued), 'at' => $issued['confirmed_at']];
    unset($issued);
}
$ready = ['role' => $role, 'connection_id' => $connectionId, 'pid' => getmypid(), 'ready_ns' => hrtime(true),
    'authorization' => $authorization, 'transaction_level' => DB::transactionLevel(), 'pdo_transaction' => $pdo->inTransaction()];
file_put_contents($directory.'/ready-'.$role, json_encode($ready, JSON_THROW_ON_ERROR));
$startDeadline = hrtime(true) + 120_000_000_000;
while (! is_file($directory.'/start-'.$role)) {
    if (hrtime(true) >= $startDeadline) {
        throw new RuntimeException('Synthetic terminal-operation start barrier timed out.');
    }
    usleep(5000);
    clearstatcache();
}
// Only the disclosed synthetic wall changes. hrtime and every original product cap remain real.
CarbonImmutable::setTestNow(CarbonImmutable::parse($input['operation_at'], 'UTC'));
$held = null;
$armed = $input['hold'];
DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$armed, &$held, $role, $input, $directory, $pdo): void {
    if (! $armed) {
        return;
    }
    $effect = $role === 'activate'
        ? DB::table('production_member_activations')->where('redemption_id', $input['redemption_id'])->exists()
        : DB::table(MembershipPeriodRecords::EVENTS)->where('redemption_id', $input['redemption_id'])->where('kind', 'release')->exists();
    if (! $effect) {
        return;
    }
    $armed = false;
    $held = ['role' => $role, 'entered_ns' => hrtime(true), 'transaction_level' => DB::transactionLevel(),
        'pdo_transaction' => $pdo->inTransaction(), 'wall' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')];
    file_put_contents($directory.'/held-'.$role, json_encode($held, JSON_THROW_ON_ERROR));
    $deadline = hrtime(true) + 20_000_000_000;
    while (! is_file($directory.'/commit-'.$role)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Synthetic final committing delegate barrier timed out.');
        }
        usleep(5000);
        clearstatcache();
    }
    $held['released_ns'] = hrtime(true);
});
$value = null;
$errors = [];
$entered = hrtime(true);
try {
    $value = $role === 'activate'
        ? get_object_vars($operation->activate($input['definition_id'], $input['redemption_id'], $ticket, $principal, $actor))
        : (new MembershipReservationOperations)->release($input['invoice_id'], $input['redemption_id'], $principal, $actor);
} catch (Throwable $error) {
    do {
        $errors[] = ['class' => get_class($error), 'reason' => property_exists($error, 'reason') ? $error->reason : null];
        $error = $error->getPrevious();
    } while ($error !== null && count($errors) < 12);
}
echo json_encode(['role' => $role, 'value' => $value, 'errors' => $errors, 'held' => $held,
    'entered_ns' => $entered, 'finished_ns' => hrtime(true), 'connection_id' => $connectionId, 'pid' => getmypid(),
    'transaction_level' => DB::transactionLevel(), 'pdo_transaction' => $pdo->inTransaction()], JSON_THROW_ON_ERROR);
