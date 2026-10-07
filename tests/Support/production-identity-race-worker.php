<?php

use App\Domain\Customers\ProductionIdentity\CompleteIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityAcceptance;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityMail;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
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
    'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
    'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
Carbon::setTestNow($input['at']);
$directory = getenv('VA_PRODUCTION_IDENTITY_RACE_DIRECTORY');
$app->instance(IdentityNoticeTransport::class, new class($directory) implements IdentityNoticeTransport
{
    public function __construct(private string $directory) {}

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
        file_put_contents($this->directory.'/handoff', 'synthetic');

        return new IdentityAcceptance(str_repeat('a', 64));
    }
});
$connectionId = (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
DB::connection()->getPdo()->exec('SET SESSION innodb_lock_wait_timeout=15');
file_put_contents($directory.'/ready.tmp', json_encode(['connection_id' => $connectionId, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
rename($directory.'/ready.tmp', $directory.'/ready');
$deadline = microtime(true) + 20;
while (! is_file($directory.'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker barrier timed out.');
    } usleep(10000);
    clearstatcache();
}
try {
    if ($input['action'] === 'notice') {
        (new WorkIdentityNotice)->process($input['notice_id']);
    } else {
        (new CompleteIdentity)->complete(...$input['body']);
    }
    $result = 'saved';
} catch (IdentityException) {
    $result = 'denied';
} catch (Throwable $exception) {
    $result = 'unexpected';
    $error = $exception::class;
}
echo json_encode(['result' => $result, 'error_class' => $error ?? null, 'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
