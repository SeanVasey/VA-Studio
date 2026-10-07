<?php

use App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Discovery workers require disposable native MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$directory = $input['directory'];
if (! is_string($directory) || ! str_starts_with($directory, storage_path('framework/testing/discovery-race-'))
    || ! is_dir($directory) || is_link($directory) || (fileperms($directory) & 07777) !== 0700) {
    throw new LogicException('Invalid discovery barrier directory.');
}
config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32)), 'filesystems.disks.local.root' => $input['media_root'], 'media' => $input['media'], 'commerce' => $input['commerce']]);
Storage::forgetDisk('local');
Illuminate\Support\Carbon::setTestNow($input['now']);
CarbonImmutable::setTestNow($input['now']);
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$publish = function (string $name, array $value) use ($directory): void {
    $bytes = json_encode($value, JSON_THROW_ON_ERROR);
    if (file_put_contents($directory.'/'.$name.'.tmp', $bytes) !== strlen($bytes) || ! rename($directory.'/'.$name.'.tmp', $directory.'/'.$name)) {
        throw new RuntimeException('Cannot publish discovery witness.');
    }
};
$wait = function () use ($directory): void {
    $deadline = microtime(true) + 20;
    do {
        clearstatcache(true, $directory.'/release');
        if (is_file($directory.'/release')) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Discovery barrier timed out.');
};
$publish($input['role'].'-started', ['connection' => $connection, 'pid' => getmypid(), 'depth' => DB::transactionLevel()]);
if ($input['role'] === 'writer') {
    DB::table('tracks')->where('id', $input['track_id'])->update(['title' => 'Synthetic native writer']);
    echo json_encode(['status' => 'written', 'connection' => $connection, 'depth' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
    exit;
}
$barrier = function () use ($publish, $wait, $connection): void {
    $publish('reader-ready', ['connection' => $connection, 'pid' => getmypid(), 'depth' => DB::transactionLevel()]);
    $wait();
};
if ($input['phase'] === 'before-fence') {
    $root = Crypt::getFacadeRoot();
    $wrapper = new class($root->getKey(), config('app.cipher'), $barrier) extends Encrypter
    {
        public function __construct($key, $cipher, private Closure $barrier)
        {
            parent::__construct($key, $cipher);
        }

        public function encryptString(#[SensitiveParameter] $value)
        {
            if (str_contains($value, '"expires_at"')) {
                ($this->barrier)();
            }

            return parent::encryptString($value);
        }
    };
    Crypt::swap($wrapper);
} else {
    // Observe the genuine final epoch fence, before its real commit releases it.
    Event::listen(TransactionCommitting::class, $barrier);
}
try {
    $snapshot = app(CurrentEligibleTrackSnapshot::class)->capture();
    $result = ['status' => 'captured', 'paths' => $snapshot->paths(), 'evidence' => $snapshot->evidence()];
} catch (LogicException $error) {
    $result = ['status' => 'refused', 'reason' => $error->getMessage()];
}
echo json_encode($result + ['connection' => $connection, 'depth' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
