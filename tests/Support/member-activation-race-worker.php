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
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use App\Domain\Memberships\Production\MembershipReservations;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use App\Domain\Memberships\Production\SealedMembershipPolicyFacts;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || getenv('VA_MEMBER_ACTIVATION_RACE_ONLY') !== '1'
    || config('app.key') !== 'base64:'.base64_encode(str_repeat('P', 32))) {
    throw new LogicException('Isolated synthetic member activation worker only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
if (array_keys($input['configuration']) !== ['production-customer-identity', 'production-memberships',
    'production-membership-billing', 'member-grants', 'filesystems.disks.local', 'media']) {
    throw new LogicException('Unknown synthetic activation configuration.');
}
config($input['configuration']);
// Distinct synthetic confirmation walls; hrtime and every original admission budget remain real.
CarbonImmutable::setTestNow(CarbonImmutable::parse($input['at'], 'UTC'));
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
$directory = getenv('VA_MEMBER_ACTIVATION_RACE_DIRECTORY');
$worker = getenv('VA_MEMBER_ACTIVATION_RACE_WORKER');
$actor = User::query()->findOrFail($input['user_id']);
$principal = ProductionCustomerPrincipal::forUser($actor);
$issuer = $operation->authorizations();
$display = $issuer->review($input['definition_id'], $input['redemption_id'], $principal, $actor);
$confirmStarted = hrtime(true);
$ticket = $issuer->confirm($input['definition_id'], $input['redemption_id'],
    MemberLicenseConfirmationInput::from(['display_hash' => $display->hash(), 'affirmed' => true]), $principal, $actor);
// Observation only: the actual opaque ticket never leaves this worker or enters another issuer.
// Do not mutate the registry, mint a ticket, call claim manually or supply this document as authority.
$issued = (new ReflectionProperty(MemberLicenseAuthorizations::class, 'tickets'))->getValue($issuer)[$ticket]['authorization'];
$ready = ['connection_id' => $connectionId, 'pid' => getmypid(), 'ready_ns' => hrtime(true),
    'confirm_started_ns' => $confirmStarted, 'at' => $input['at'], 'authorization_id' => $issued['authorization_id'],
    'confirmed_at' => $issued['confirmed_at'], 'authorization_hash' => CanonicalJson::hash($issued),
    'transaction_level' => DB::transactionLevel(), 'pdo_transaction' => $pdo->inTransaction()];
unset($issued);
file_put_contents($directory.'/ready-'.$worker, json_encode($ready, JSON_THROW_ON_ERROR));
$deadline = hrtime(true) + 90_000_000_000;
while (! is_file($directory.'/start-'.$worker)) {
    if (hrtime(true) >= $deadline) {
        throw new RuntimeException('Synthetic activation start barrier timed out.');
    }
    usleep(5000);
    clearstatcache();
}
$value = null;
$errors = [];
$entered = hrtime(true);
try {
    $value = get_object_vars($operation->activate($input['definition_id'], $input['redemption_id'], $ticket, $principal, $actor));
} catch (Throwable $error) {
    do {
        $errors[] = ['class' => get_class($error), 'reason' => property_exists($error, 'reason') ? $error->reason : null];
        $error = $error->getPrevious();
    } while ($error !== null && count($errors) < 12);
}
echo json_encode(['value' => $value, 'errors' => $errors, 'entered_ns' => $entered, 'finished_ns' => hrtime(true),
    'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(),
    'pdo_transaction' => $pdo->inTransaction()], JSON_THROW_ON_ERROR);
