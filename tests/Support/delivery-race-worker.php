<?php

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Delivery\ActivationPolicy;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\ManageTestDeliveryControl;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Delivery\RedeemTestDelivery;
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
    if (! in_array($input['mode'] ?? null, ['issue', 'redeem', 'block_redemption'], true)) { throw new LogicException('Unknown delivery race.'); }
    config(['filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local'); DeliveryFixtures::configure();
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
    echo json_encode(['outcome' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR); exit(1);
}
