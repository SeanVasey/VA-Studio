<?php

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\RefundDispute\RefundDisputeActions;
use App\Domain\Commerce\RefundDispute\RefundDisputeException;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\RefundDisputeFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';

/*
 * One independent process and MySQL connection per worker. A pausing worker stops inside its own transaction,
 * after it holds the Order row, at the audit insert that its write performs; the parent observes the other
 * worker's InnoDB lock wait before releasing it.
 */
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Refund/dispute races require test MySQL.');
    }
    $directory = getenv('VASEY_REFUND_RACE_DIRECTORY');
    $worker = getenv('VASEY_REFUND_RACE_WORKER');
    $mediaRoot = getenv('VASEY_REFUND_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing refund/dispute race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 65536), true, 32, JSON_THROW_ON_ERROR);
    $role = $input['roles'][(int) $worker];
    if (! in_array($role, ['suspend', 'issue', 'qualify', 'purchase'], true)) {
        throw new LogicException('Unknown refund/dispute race role.');
    }
    config(['filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local');
    RefundDisputeFixtures::configure($input['policy']);
    ExclusiveSelectionFixtures::configure();
    Carbon::setTestNow($input['now']);
    CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    $waitFor = function (string $path): void {
        $deadline = microtime(true) + 40;
        do {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Refund/dispute worker barrier timed out.');
    };
    $gateway = PaymentFixtures::gateway();
    $app->instance(StripeCheckoutGateway::class, $gateway);
    $app->instance(StripePaymentGateway::class, $gateway);
    $app->instance(ContractRenderer::class, ContractFixtures::renderer());
    $app->instance(PrepareTestDeliveryStream::class, DeliveryFixtures::observingStreams());
    $pause = $input['pause'][(int) $worker] ?? null;
    $paused = false;
    AuditEvent::creating(function (AuditEvent $event) use ($pause, &$paused, $directory, $worker, $waitFor): void {
        if ($pause !== null && ! $paused && $event->action === $pause) {
            $paused = true;
            if (DB::transactionLevel() < 1) {
                throw new LogicException('The pause point must be inside the write transaction.');
            }
            touch($directory.'/locked-'.$worker);
            $waitFor($directory.'/commit');
        }
    });
    file_put_contents($directory.'/ready-'.$worker, (string) $connectionId);
    $waitFor($directory.'/start-'.$worker);
    $outcome = null;
    $reason = null;
    $started = hrtime(true);
    try {
        $staff = User::findOrFail($input['staff_id']);
        $actions = app(RefundDisputeActions::class);
        if ($role === 'suspend') {
            $actions->suspendLine($input['order_public_id'], $staff, (string) Str::uuid(), $input['sequence'], $input['grant_public_id']);
            $outcome = 'suspended';
        } elseif ($role === 'qualify') {
            $actions->qualifyExclusiveReopening($input['order_public_id'], $staff, (string) Str::uuid(), $input['sequence'], $input['grant_public_id']);
            $outcome = 'qualified';
        } elseif ($role === 'issue') {
            app(IssueTestDelivery::class)->handle($input['order_public_id'], $input['owner_key'], $input['grant_public_id'], 'contract', (string) Str::uuid());
            $outcome = 'issued';
        } else {
            $quote = app(CreateQuote::class)->handle($input['competing_owner'], (string) Str::uuid(), $input['items']);
            app(PriceQuote::class)->create($quote->public_id, $input['competing_owner']);
            $outcome = 'reserved';
        }
    } catch (DeliveryException|RefundDisputeException $error) {
        $outcome = 'denied';
        $reason = $error->reason;
    } catch (QuoteException $error) {
        $outcome = 'denied';
        $reason = $error->errorCode;
    }
    echo json_encode(['outcome' => $outcome, 'reason' => $reason, 'pid' => getmypid(), 'connection_id' => $connectionId,
        'transaction_level' => DB::transactionLevel(), 'paused' => $paused, 'elapsed_ms' => intdiv(hrtime(true) - $started, 1_000_000)], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['outcome' => 'worker_failed', 'exception' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    exit(1);
}
