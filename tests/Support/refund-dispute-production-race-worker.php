<?php

use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\RefundDispute\RefundDisputeException;
use App\Domain\Commerce\RefundDispute\RefundDisputeLedger;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\PaidTax\PaidTaxDownloads;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ProductionTaxFinancialFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';

/*
 * BL-42 native race worker for the production tax chain: one independent process and MySQL connection per worker, driven by
 * tests/Support/RefundDisputeRace. A pausing worker stops inside its own write transaction, after it holds the batch row, at a named
 * audit insert; the parent observes the other worker's InnoDB wait on that exact row before releasing it. Synthetic data only; the
 * "provider" is the read-only fixture and nothing reaches Stripe.
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
    $input = json_decode(stream_get_contents(STDIN, 1048576), true, 32, JSON_THROW_ON_ERROR);
    $role = $input['roles'][(int) $worker];
    if (! in_array($role, ['observe', 'authorize', 'redeem'], true)) {
        throw new LogicException('Unknown refund/dispute race role.');
    }
    foreach ($input['config'] as $key => $value) {
        config([$key => $value]);
    }
    config(['filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local');
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
    $gateway = ProductionTaxFinancialFixtures::gateway($input['account'], $input['payment'], $input['gross']);
    $gateway->refunds = $input['refunds'];
    $gateway->disputes = $input['disputes'];
    $app->instance(StripeFinancialInspectionGateway::class, $gateway);
    $buyer = null;
    if ($role !== 'observe') {
        $buyer = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123');
        if ($buyer === null) {
            throw new LogicException('Actual enrolled synthetic buyer authentication failed.');
        }
    }
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
    $status = null;
    $started = hrtime(true);
    try {
        if ($role === 'observe') {
            $staff = User::findOrFail($input['staff_ids'][(int) $worker]);
            $result = app(RefundDisputeLedger::class)->observe($input['order_id'], $staff, (string) Str::uuid(), $input['sequence']);
            $outcome = $result['status'] === 'recorded' ? 'observed' : $result['status'];
        } elseif ($role === 'authorize') {
            $downloads = new PaidTaxDownloads;
            $downloads->authorize($input['order_id'], $input['line_id'], $input['request'], $buyer['principal'], $buyer['user']);
            $outcome = 'authorized';
        } else {
            $transfer = (new PaidTaxDownloads)->redeem($input['order_id'], $input['authorization_id'], $input['token'], $buyer['principal'], $buyer['user']);
            $transfer->writeTo(static function (string $chunk): void {});
            $outcome = 'delivered';
        }
    } catch (RefundDisputeException $error) {
        $outcome = 'denied';
        $reason = $error->reason;
    } catch (PaidTaxException $error) {
        $outcome = 'denied';
        $reason = $error->reason;
        $status = $error->status;
    }
    echo json_encode(['outcome' => $outcome, 'reason' => $reason, 'status' => $status, 'pid' => getmypid(), 'connection_id' => $connectionId,
        'transaction_level' => DB::transactionLevel(), 'paused' => $paused, 'elapsed_ms' => intdiv(hrtime(true) - $started, 1_000_000)], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['outcome' => 'worker_failed', 'exception' => $error::class, 'message' => $error->getMessage(),
        'location' => basename($error->getFile()).':'.$error->getLine()], JSON_THROW_ON_ERROR);
    exit(1);
}
