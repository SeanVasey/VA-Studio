<?php

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Memberships\CreditLedger;
use App\Domain\Memberships\MembershipPlans;
use App\Domain\Memberships\Models\MembershipPlan;
use App\Domain\Memberships\Models\MembershipPlanVersion;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Membership race workers require isolated testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$directory = getenv('VASEY_MEMBERSHIP_RACE_DIRECTORY');
if (! is_string($directory) || ! is_dir($directory)) {
    throw new LogicException('Missing private membership worker barrier.');
}
config(['customer.test_accounts_enabled' => true, 'memberships.test_mode_enabled' => true]);
$panel = Filament::getPanel('admin');
$panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
CarbonImmutable::setTestNow(CarbonImmutable::parse($input['at']));
Carbon::setTestNow(CarbonImmutable::parse($input['at']));
$actor = User::findOrFail($input['actor_id']);
$principal = isset($input['principal']) ? new CustomerPrincipal(...$input['principal']) : null;
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$locks = [];
DB::listen(function ($query) use (&$locks): void {
    if (str_contains(strtolower($query->sql), 'for update') && preg_match('/from [\x60"]?([a-z_]+)/i', $query->sql, $match)) {
        $locks[] = $match[1];
    }
});
$await = function (string $marker) use ($directory): void {
    $deadline = microtime(true) + 20;
    do {
        clearstatcache();
        if (is_file($directory.'/'.$marker)) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Membership worker exceeded its original 20-second barrier.');
};
file_put_contents($directory.'/ready', json_encode(['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
if ($input['hold'] ?? false) {
    $held = false;
    AuditEvent::created(function (AuditEvent $event) use (&$held, $directory, $connection, $await): void {
        if (! $held && str_starts_with($event->action, 'membership.')) {
            $held = true;
            file_put_contents($directory.'/held', json_encode(['connection_id' => $connection, 'pid' => getmypid(), 'audit_id' => $event->id,
                'action' => $event->action, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR));
            $await('release');
        }
    });
}
$await('start');
$ledger = app(CreditLedger::class);
$plans = app(MembershipPlans::class);
try {
    $result = match ($input['operation']) {
        'grant' => $ledger->grantSynthetic(MembershipPlanVersion::findOrFail($input['version_id']), CustomerAccount::findOrFail($input['account_id']), $input['source'], $actor),
        'reserve' => $ledger->reserve($input['bucket_id'], $input['amount'], $input['resource'], $input['key'], $principal, $actor),
        'consume' => $ledger->consume($input['event_id'], $input['key'], $principal, $actor),
        'release' => $ledger->release($input['event_id'], $input['key'], $principal, $actor),
        'reverse' => $ledger->reverse($input['event_id'], $input['key'], $actor),
        'expire' => $ledger->expire($input['bucket_id'], $input['key'], $actor),
        'apply' => $plans->applyReviewedRevision($input['review'], $actor),
        'review' => $plans->reviewRevision(MembershipPlan::findOrFail($input['plan_id']), $input['replacement'], $actor),
        'read' => $ledger->read($input['bucket_id'], $principal, $actor),
        default => throw new LogicException('Unknown bounded membership race operation.'),
    };
    $output = ['result' => 'success', 'value' => $result];
} catch (AuthorizationException|CustomerAccessException|ValidationException $error) {
    $output = ['result' => 'denied', 'exception' => $error::class, 'fields' => $error instanceof ValidationException ? array_keys($error->errors()) : []];
} catch (Throwable $error) {
    $output = ['result' => 'error', 'exception' => $error::class, 'file' => basename($error->getFile()), 'line' => $error->getLine()];
}
echo json_encode($output + ['connection_id' => $connection, 'pid' => getmypid(), 'locks' => $locks, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR).PHP_EOL;
