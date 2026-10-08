<?php

// Independent review of PR #57: one IdentityRequests::request() in its own process and MySQL session, under the
// APP_KEY / APP_PREVIOUS_KEYS this process was started with (a rolling deploy runs mixed configurations).

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\IdentityRequests;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityAcceptance;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityMail;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Isolated native identity test only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
    'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => false,
    'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
Carbon::setTestNow($input['at']);
$directory = getenv('VA_PRODUCTION_IDENTITY_RACE_DIRECTORY');
$app->instance(IdentityNoticeTransport::class, new class implements IdentityNoticeTransport
{
    public function provenance(): string
    {
        return IdentityPolicy::REHEARSAL;
    }

    public function capabilityVersion(): string
    {
        return LoopbackSmtp::CAPABILITY;
    }

    public function submit(IdentityMail $mail): IdentityAcceptance
    {
        throw new RuntimeException('No delivery in this review worker.');
    }
});
$connectionId = (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
DB::connection()->getPdo()->exec('SET SESSION innodb_lock_wait_timeout=15');
file_put_contents($directory.'/ready.tmp', json_encode(['connection_id' => $connectionId, 'pid' => getmypid(),
    'keys' => count(IdentityPolicy::keys())], JSON_THROW_ON_ERROR));
rename($directory.'/ready.tmp', $directory.'/ready');
$deadline = microtime(true) + 20;
while (! is_file($directory.'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker barrier timed out.');
    } usleep(2000);
    clearstatcache();
}
try {
    (new IdentityRequests)->request($input['purpose'], $input['email'], $input['request_key']);
    $result = 'saved';
} catch (IdentityException) {
    $result = 'denied';
} catch (Throwable $exception) {
    $result = 'unexpected';
    $error = $exception::class.': '.$exception->getMessage();
}
echo json_encode(['result' => $result, 'error_class' => $error ?? null, 'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
