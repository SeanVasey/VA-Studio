<?php

use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\ManageTestDeliveryControl;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DeliveryFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// One side of the automatic-enable race (D-19 amendment, 2026-11-12): an independent MySQL connection that either runs the automatic
// enable or the console/staff two-step (provision blocked at version 0, then enable from version 0), released by the parent together.
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Automatic-enable races require test MySQL.');
    }
    $directory = getenv('VASEY_DELIVERY_RACE_DIRECTORY');
    $worker = getenv('VASEY_DELIVERY_RACE_WORKER');
    $mediaRoot = getenv('VASEY_DELIVERY_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 32768), true, 32, JSON_THROW_ON_ERROR);
    $role = $input['roles'][(int) $worker] ?? null;
    if (! in_array($role, ['auto', 'staff'], true)) {
        throw new LogicException('Unknown race role.');
    }
    config(['filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local');
    DeliveryFixtures::configure();
    config(['delivery.test_auto_enable_enabled' => true, 'delivery.test_auto_enable_policy' => $input['policy']]);
    Carbon::setTestNow($input['now']);
    CarbonImmutable::setTestNow($input['now']);
    file_put_contents($directory.'/ready-'.$worker, (string) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id);
    $deadline = microtime(true) + 35;
    while (! is_file($directory.'/start')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Race barrier timed out.');
        }
        usleep(5000);
        clearstatcache();
    }
    $manage = app(ManageTestDeliveryControl::class);
    $steps = [];
    if ($role === 'auto') {
        try {
            $steps[] = $manage->enableAutomatically($input['order_public_id']) ? 'enabled' : 'unchanged';
        } catch (DeliveryException $error) {
            $steps[] = 'refused_'.$error->reason;
        }
    } else {
        foreach ([true, false] as $blocked) {
            try {
                $control = $manage->handle($input['order_public_id'], $blocked, 0, 'SYNTHETIC-RACE-STAFF');
                $steps[] = ($control->blocked ? 'blocked' : 'enabled').'_'.$control->control_version;
            } catch (DeliveryException $error) {
                $steps[] = 'refused_'.$error->reason;
            }
        }
    }
    echo json_encode(['role' => $role, 'steps' => $steps, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage());
    exit(1);
}
