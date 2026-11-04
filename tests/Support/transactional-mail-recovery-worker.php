<?php

use App\Domain\Notifications\Mail\OrderReadyMail;
use App\Domain\Notifications\Mail\TransactionalMailAdapter;
use App\Domain\Notifications\Mail\TransactionalMailScanProgress;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
$sent = 0;
try {
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $directory = $input['directory'];
    $database = DB::getDatabaseName();
    $declared = getenv('VA_LOCAL_DISPOSABLE_MYSQL') === $database
        || (getenv('CI') === 'true' && getenv('VA_CI_DISPOSABLE_MYSQL') === $database);
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || $database !== getenv('DB_DATABASE')
        || ! $declared || ! in_array($input['mode'], ['reserve', 'deliver'], true)
        || ! is_string($directory) || ! str_starts_with($directory, storage_path('framework/testing/cx-mail-native-'))
        || realpath($directory) !== $directory || is_link($directory) || (fileperms($directory) & 0777) !== 0700) {
        throw new LogicException('Isolated original mail native worker only.');
    }
    config(['filesystems.disks.local.root' => $input['private_root'], 'filesystems.disks.local.serve' => false]);
    $pdo = DB::getPdo();
    $connection = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $waits = $pdo->query('SELECT @@session.lock_wait_timeout, @@session.innodb_lock_wait_timeout')->fetch(PDO::FETCH_NUM);
    $publish = static function (string $name, array $value) use ($directory): void {
        $bytes = json_encode($value, JSON_THROW_ON_ERROR);
        if (file_put_contents($directory.'/'.$name.'.tmp', $bytes) !== strlen($bytes)
            || ! rename($directory.'/'.$name.'.tmp', $directory.'/'.$name)) {
            throw new RuntimeException('Worker witness could not be retained.');
        }
    };
    $wait = static function (string $name) use ($directory): void {
        $end = hrtime(true) + 15_000_000_000;
        do {
            clearstatcache(true, $directory.'/'.$name);
            if (is_file($directory.'/'.$name)) {
                return;
            }
            usleep(10000);
        } while (hrtime(true) < $end);
        throw new RuntimeException('Worker barrier expired.');
    };
    if ($input['mode'] === 'deliver') {
        config(['transactional-mail' => $input['policy'], 'mail.mailers.'.$input['mailer_name'] => $input['mailer']]);
        $manager = app('mail.manager');
        Mail::swap(new class($manager, $publish, $wait) extends MailFake
        {
            public function __construct($manager, private Closure $publish, private Closure $wait)
            {
                parent::__construct($manager);
            }

            protected function sendMail($view, $shouldQueue = false)
            {
                ($this->publish)('transport', ['pid' => getmypid()]);
                ($this->wait)('release');

                return parent::sendMail($view, $shouldQueue);
            }
        });
    }
    $publish('ready', ['pid' => getmypid(), 'connection' => $connection]);
    $wait('start');
    if ($input['mode'] === 'reserve') {
        $progress = new TransactionalMailScanProgress;
        $cycle = $progress->start('queued_wakeups');
        $result = $progress->reserve('queued_wakeups', $cycle);
        $stale = $progress->reserve('queued_wakeups', $cycle - 1);
    } else {
        $result = (new TransactionalMailAdapter)->deliver($input['delivery_id']);
        $sent = Mail::getFacadeRoot()->sent(OrderReadyMail::class)->count();
    }
    echo json_encode(['status' => 'ok', 'result' => $result, 'stale' => $stale ?? null, 'sent' => $sent,
        'pid' => getmypid(), 'connection' => $connection, 'transaction_level' => DB::transactionLevel(),
        'in_transaction' => $pdo->inTransaction(), 'waits_unchanged' => $waits === $pdo->query('SELECT @@session.lock_wait_timeout, @@session.innodb_lock_wait_timeout')->fetch(PDO::FETCH_NUM)], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['status' => 'error', 'class' => $error::class, 'sent' => $sent], JSON_THROW_ON_ERROR);
    exit(2);
}
