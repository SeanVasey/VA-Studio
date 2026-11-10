<?php

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Delivery\ActivationPolicy;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\ManageTestDeliveryControl;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Delivery\DeliveryByteRange;
use App\Domain\Delivery\RedeemTestDelivery;
use App\Domain\Delivery\TestDeliveryCoverageLedger;
use App\Domain\Delivery\TransferTestDelivery;
use App\Domain\Customers\CustomerAccess;
use App\Models\User;
use Tests\Support\CustomerFixtures;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\PaymentFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') { throw new LogicException('Delivery races require test MySQL.'); }
    $directory = getenv('VASEY_DELIVERY_RACE_DIRECTORY'); $worker = getenv('VASEY_DELIVERY_RACE_WORKER');
    $mediaRoot = getenv('VASEY_DELIVERY_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing delivery race configuration.');
    }
    // Raw test tokens travel only over stdin; never command arguments, environment variables, or worker output.
    $input = json_decode(stream_get_contents(STDIN, 32768), true, 32, JSON_THROW_ON_ERROR);
    if (! in_array($input['mode'] ?? null, ['issue', 'redeem', 'block_redemption', 'transfer', 'admit_record'], true)) { throw new LogicException('Unknown delivery race.'); }
    config(['filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local'); in_array($input['mode'], ['transfer', 'admit_record'], true) ? DeliveryFixtures::configureV2() : DeliveryFixtures::configure();
    if ($input['mode'] === 'admit_record') { CustomerFixtures::configure(); }
    \Illuminate\Support\Carbon::setTestNow($input['now']); \Carbon\CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    $waitFor = function (string $path): void {
        $deadline = microtime(true) + 35;
        do { clearstatcache(true, $path); if (is_file($path)) { return; } usleep(10000); }
        while (microtime(true) < $deadline);
        throw new RuntimeException('Delivery worker barrier timed out.');
    };
    $gateway = PaymentFixtures::gateway(); $renderer = ContractFixtures::renderer(); $streams = DeliveryFixtures::observingStreams();
    $app->instance(StripeCheckoutGateway::class, $gateway); $app->instance(StripePaymentGateway::class, $gateway);
    $app->instance(ContractRenderer::class, $renderer); $app->instance(PrepareTestDeliveryStream::class, $streams);
    $streams->afterPrepare = function () use ($directory, $worker, $waitFor): void {
        ActivationPolicy::outsideTransactions();
        touch($directory.'/verified-'.$worker); $waitFor($directory.'/release');
    };
    file_put_contents($directory.'/ready-'.$worker, (string) $connectionId); $waitFor($directory.'/start');
    $authorizationId = null; $contentHash = null; $sizeBytes = null; $reason = null;
    try {
        if ($input['mode'] === 'block_redemption' && $worker === '1') {
            $waitFor($directory.'/verified-0');
            $control = app(ManageTestDeliveryControl::class)->handle($input['order_public_id'], true, 1, 'SYNTHETIC-RACE-BLOCK');
            if (! $control->blocked || $control->control_version !== 2) { throw new LogicException('Block did not commit.'); }
            file_put_contents($directory.'/blocked-1', (string) $control->control_version); $outcome = 'blocked';
        } elseif ($input['mode'] === 'admit_record' && $worker === '0') {
            // FP-077 P2-2: record() takes the authorization lock, then holds it until the signed-in admitter (worker 1) is
            // waiting for that lock, after its own plain reads fixed its REPEATABLE READ snapshot. Then this coverage commits.
            $admitter = (int) file_get_contents($directory.'/ready-1');
            app()->instance(TestDeliveryCoverageLedger::class, new class($directory, $admitter) extends TestDeliveryCoverageLedger {
                public function __construct(private string $directory, private int $admitter) {}

                protected function locked(): void
                {
                    touch($this->directory.'/locked-0');
                    $deadline = microtime(true) + 35;
                    do {
                        $waiting = DB::connection('mysql_race_probe')->selectOne("SELECT COUNT(*) AS c FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ? AND trx_state = 'LOCK WAIT'", [$this->admitter]);
                        if ((int) $waiting->c === 1) { touch($this->directory.'/admitter-waiting'); return; }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    // Report instead of failing here, so the test sees the admitter's own outcome and reason.
                    touch($this->directory.'/admitter-never-waited');
                }
            });
            config(['database.connections.mysql_race_probe' => config('database.connections.mysql')]);
            $waitFor($directory.'/verified-1');
            app(TestDeliveryCoverageLedger::class)->record($input['authorization_id'], $input['admission_id'], 0, 9);
            $outcome = is_file($directory.'/admitter-waiting') ? 'recorded' : 'recorded_without_admitter_wait';
        } elseif ($input['mode'] === 'admit_record') {
            $user = User::findOrFail($input['user_id']); $principal = app(CustomerAccess::class)->principal($user);
            $range = DeliveryByteRange::evaluate('bytes=10-', null, $input['size_bytes'], $input['sha256']);
            $transfer = app(TransferTestDelivery::class)->handle($input['order_public_id'], $principal->ownerKey,
                $input['authorization_public_id'], $input['session_secret'], $range, $user, $principal);
            if (! is_file($directory.'/admitter-waiting')) { throw new LogicException('Admission did not wait for the coverage writer.'); }
            $bytes = '';
            $transfer->writeTo(function (string $chunk) use (&$bytes): void { $bytes .= $chunk; });
            $contentHash = hash('sha256', $bytes); $sizeBytes = strlen($bytes); $outcome = 'transferred';
        } elseif ($input['mode'] === 'transfer') {
            // FP-077: both workers are admitted on one started v2 authorization, serve their range, then meet before either
            // records coverage, so the two coverage writers race on the authorization row lock.
            $range = DeliveryByteRange::evaluate($input['ranges'][(int) $worker], null, $input['size_bytes'], $input['sha256']);
            $transfer = app(TransferTestDelivery::class)->handle($input['order_public_id'], $input['owner_key'],
                $input['authorization_public_id'], $input['session_secret'], $range);
            $bytes = '';
            $transfer->writeTo(function (string $chunk) use (&$bytes, $range, $directory, $worker, $waitFor): void {
                $bytes .= $chunk;
                if (strlen($bytes) === $range->length()) {
                    touch($directory.'/served-'.$worker); $waitFor($directory.'/served-'.(1 - (int) $worker));
                }
            });
            if (strlen($bytes) !== $range->length()) { throw new LogicException('Transfer served the wrong length.'); }
            $contentHash = hash('sha256', $bytes); $sizeBytes = strlen($bytes); $outcome = 'transferred';
        } elseif ($input['mode'] === 'issue') {
            $issued = app(IssueTestDelivery::class)->handle($input['order_public_id'], $input['owner_key'],
                $input['grant_public_id'], 'contract', (string) Str::uuid());
            $authorizationId = $issued->authorizationId; $outcome = 'issued';
        } else {
            $prepared = app(RedeemTestDelivery::class)->handle($input['order_public_id'], $input['owner_key'],
                $input['authorization_public_id'], $input['token']);
            try {
                $bytes = stream_get_contents($prepared->stream());
                if (! is_string($bytes) || strlen($bytes) !== $prepared->sizeBytes || hash('sha256', $bytes) !== $prepared->sha256) {
                    throw new LogicException('Committed stream did not match the purchased original.');
                }
                $contentHash = $prepared->sha256; $sizeBytes = $prepared->sizeBytes; $outcome = 'redeemed';
            } finally { $prepared->close(); }
        }
    } catch (DeliveryException $error) { $outcome = 'denied'; $reason = $error->reason; }
    echo json_encode(['outcome' => $outcome, 'reason' => $reason, 'authorization_id' => $authorizationId, 'content_hash' => $contentHash, 'size_bytes' => $sizeBytes,
        'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(), 'provider_calls' => $gateway->calls, 'render_calls' => $renderer->calls,
        'verification_transaction_levels' => $streams->transactionLevels,
        'open_prepared_resources' => count(array_filter($streams->resources, 'is_resource'))], JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) {
    // Only this harness's own barrier messages are echoed; domain and framework messages stay private.
    $barrier = $error instanceof RuntimeException && str_contains($error->getMessage(), 'barrier') || str_contains($error->getMessage(), 'never waited');
    echo json_encode(['outcome' => 'worker_failed', 'exception' => $error::class, 'barrier' => $barrier ? $error->getMessage() : null,
        'at' => $error->getFile() === __FILE__ ? $error->getLine() : null], JSON_THROW_ON_ERROR); exit(1);
}
