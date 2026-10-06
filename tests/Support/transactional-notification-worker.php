<?php

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Notifications\NotificationException;
use App\Domain\Notifications\NotificationLease;
use App\Domain\Notifications\PrivateNotificationCapture;
use App\Domain\Notifications\TestTransactionalNotifications;
use App\Support\Audit\AuditEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DeliveryFixtures;
use Tests\Support\TransactionalNotificationFixtures as F;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Notification race worker requires disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$directory = getenv('VASEY_NOTIFICATION_RACE_DIRECTORY');
if (! is_string($directory) || realpath($directory) !== $directory || (fileperms($directory) & 0777) !== 0700
    || ! in_array($input['name'] ?? null, ['winner', 'follower'], true)) {
    throw new LogicException('Private notification race barrier refused.');
}
$name = $input['name'];
$signal = function (string $suffix, array $value = []) use ($directory, $name): void {
    $path = $directory.'/'.$name.'-'.$suffix;
    file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
$wait = function (string $suffix) use ($directory, $name): void {
    $deadline = microtime(true) + 20;
    while (! is_file($directory.'/'.$name.'-'.$suffix)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Notification race barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
Carbon::setTestNow($input['at']);
F::configure();
DeliveryFixtures::configure();
Http::preventStrayRequests();
Mail::fake();
Notification::fake();
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$hold = $input['hold_event'] ?? null;
if ($hold !== null) {
    AuditEvent::created(function (AuditEvent $event) use ($hold, $signal, $wait): void {
        if ($event->action === $hold) {
            $signal('held');
            $wait('release');
        }
    });
}
$transport = new class($signal, $wait, $input['hold_capture'] ?? false) extends PrivateNotificationCapture
{
    public int $calls = 0;

    public function __construct(private Closure $signal, private Closure $wait, private bool $hold) {}

    public function store(string $id, string $bytes): array
    {
        $this->calls++;
        if ($this->hold) {
            ($this->signal)('before-capture');
            ($this->wait)('capture-release');
        }

        return parent::store($id, $bytes);
    }
};
app()->instance(PrivateNotificationCapture::class, $transport);
$locks = [];
DB::listen(function ($query) use (&$locks): void {
    if (str_contains($query->sql, 'for update') && preg_match('/from `([a-z_]+)`/', $query->sql, $match)) {
        $locks[] = $match[1];
    }
});
$signal('ready', ['connection_id' => $connection, 'pid' => getmypid()]);
$wait('start');
$service = app(TestTransactionalNotifications::class);
try {
    $result = match ($input['operation']) {
        'enqueue' => $service->enqueueOrderReady($input['order_id'], new CustomerPrincipal(...$input['principal'])),
        'dispatch' => $service->dispatch($input['notification_id']),
        'reconcile' => $service->reconcile($input['notification_id']),
        'complete' => $service->complete(new NotificationLease($input['notification_id'], $input['attempt_id'],
            CarbonImmutable::parse($input['expires_at']), $input['token'], $input['capture']), $input['receipt_hash']),
        default => throw new LogicException('Unknown notification race operation.'),
    };
    $outcome = 'saved';
} catch (NotificationException|CustomerAccessException $error) {
    $outcome = 'denied';
    $result = null;
    $errorClass = $error::class;
} catch (Throwable $error) {
    $outcome = 'unexpected';
    $result = null;
    $errorClass = $error::class;
}
Mail::assertNothingSent();
Notification::assertNothingSent();
echo json_encode(['outcome' => $outcome, 'result' => $result, 'error_class' => $errorClass ?? null,
    'connection_id' => $connection, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(),
    'locks' => $locks, 'capture_calls' => $transport->calls, 'provider_calls' => 0], JSON_THROW_ON_ERROR);
