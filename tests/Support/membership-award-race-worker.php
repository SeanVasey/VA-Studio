<?php

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use App\Domain\Memberships\Production\SealedMembershipPolicyFacts;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Stripe\WebhookSignature;
use Tests\Support\BillingStripeFixtures as F;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_MEMBERSHIP_AWARD_RACE_ONLY') !== '1') {
    throw new LogicException('Isolated native award fixture only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
if (! in_array($input['operation'] ?? null, ['award', 'hint'], true)
    || array_keys($input['configuration']) !== ['production-customer-identity', 'production-memberships', 'production-membership-billing']
    || config('app.key') !== 'base64:'.base64_encode(str_repeat('P', 32))) {
    throw new LogicException('Unknown synthetic award worker configuration.');
}
config($input['configuration']);
CarbonImmutable::setTestNow('2026-10-13 12:00:00 UTC');
Queue::fake();
$app->instance(MembershipPaidInvoiceAuthority::class, new SealedMembershipPaidInvoice);
$app->instance(MembershipPolicyFactsAuthority::class, new SealedMembershipPolicyFacts);
// Same deliberately unusable test-only capabilities as MembershipAwardFixtures; never runtime registration.
$license = Mockery::mock(MembershipEligibleLicenseAuthority::class);
$license->shouldNotReceive('lock');
$license->shouldNotReceive('proveCurrent');
$grant = Mockery::mock(MemberGrantAuthority::class);
$grant->shouldNotReceive('prepare');
$grant->shouldNotReceive('proveReadyCurrent');
$app->instance(MembershipEligibleLicenseAuthority::class, $license);
$app->instance(MemberGrantAuthority::class, $grant);
$pdo = DB::connection()->getPdo();
// Allow the competing owned operation its original 60-second admission budget.
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 60');
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$directory = getenv('VA_MEMBERSHIP_AWARD_RACE_DIRECTORY');
$worker = getenv('VA_MEMBERSHIP_AWARD_RACE_WORKER');
if ($input['operation'] === 'award') {
    $actor = User::query()->findOrFail($input['user_id']);
    $principal = ProductionCustomerPrincipal::forUser($actor);
}
file_put_contents($directory.'/ready-'.$worker, json_encode(['connection_id' => $connectionId, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
$deadline = hrtime(true) + 90_000_000_000;
while (! is_file($directory.'/start-'.$worker)) {
    if (hrtime(true) >= $deadline) {
        throw new RuntimeException('Synthetic award worker barrier timed out.');
    }
    usleep(5000);
    clearstatcache();
}
$value = null;
$errorClass = null;
$reason = null;
try {
    if ($input['operation'] === 'award') {
        $value = (new MembershipAwards)->award($input['invoice_id'], $principal, $actor);
    } else {
        $payload = json_encode(['id' => 'evt_SYNTHETICNATIVEAWARD'.$worker, 'object' => 'event', 'type' => 'invoice.updated', 'livemode' => false,
            'account' => null, 'api_version' => BillingProviderPin::API_VERSION, 'created' => CarbonImmutable::now('UTC')->timestamp,
            'data' => ['object' => ['id' => F::INVOICE, 'object' => 'invoice',
                'parent' => ['subscription_details' => ['subscription' => F::SUBSCRIPTION]]]]], JSON_THROW_ON_ERROR);
        $hint = (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, 'whsec_SYNTHETICREHEARSAL'));
        $value = ['id' => $hint['event']['id'], 'hint_position' => $hint['event']['hint_position'], 'duplicate' => $hint['duplicate']];
    }
} catch (Throwable $error) {
    $errorClass = get_class($error);
    $reason = property_exists($error, 'reason') ? $error->reason : null;
}
echo json_encode(['value' => $value, 'error_class' => $errorClass, 'reason' => $reason,
    'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(), 'pdo_transaction' => $pdo->inTransaction()], JSON_THROW_ON_ERROR);
