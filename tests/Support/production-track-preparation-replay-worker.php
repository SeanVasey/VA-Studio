<?php

use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Domain\Commerce\ProductionPreparation\SaveProductionTrackPreparationPacket;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Packet replay workers require disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$directory = getenv('VASEY_PACKET_REPLAY_DIRECTORY');
$worker = getenv('VASEY_PACKET_REPLAY_WORKER');
if (! is_string($directory) || ! str_starts_with($directory, storage_path('framework/testing/production-packet-replay-'))
    || ! is_dir($directory) || is_link($directory) || (fileperms($directory) & 07777) !== 0700 || ! in_array($worker, ['0', '1'], true)) {
    throw new LogicException('Invalid private packet replay directory.');
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$actor = User::findOrFail($input['actor_id']);
$originalEncrypter = Crypt::getFacadeRoot();
$encrypter = new class($originalEncrypter->getKey(), config('app.cipher')) extends Encrypter
{
    public int $encryptions = 0;

    public function encryptString(#[SensitiveParameter] $value)
    {
        $this->encryptions++;

        return parent::encryptString($value);
    }
};
$encrypter->previousKeys($originalEncrypter->getPreviousKeys());
Crypt::swap($encrypter);
$publish = function (string $name, array $value) use ($directory): void {
    $bytes = json_encode($value, JSON_THROW_ON_ERROR);
    if (file_put_contents($directory.'/'.$name.'.tmp', $bytes) !== strlen($bytes) || ! rename($directory.'/'.$name.'.tmp', $directory.'/'.$name)) {
        throw new RuntimeException('Cannot publish complete packet replay barrier.');
    }
};
$wait = function (string $name) use ($directory): void {
    $deadline = microtime(true) + 20;
    do {
        clearstatcache();
        if (is_file($directory.'/'.$name)) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Packet replay barrier timed out.');
};
$missed = false;
$paused = false;
Event::listen(TransactionCommitted::class, function () use ($worker, $connection, $publish, $wait, &$missed): void {
    if ($missed) {
        return;
    }
    $missed = true;
    // The first apply transaction is the standalone authenticated historical
    // lookup. Its real commit releases the actor lock before either may create.
    $count = (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM '.PacketEvidence::PACKETS)->fetchColumn();
    $publish('missed-'.$worker, ['connection_id' => $connection, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(), 'packet_count' => $count]);
    $wait('create-'.$worker);
});
DB::listen(function ($query) use ($input, $worker, $connection, $publish, $wait, &$missed, &$paused): void {
    if ($missed && ! $paused && $input['pause_creation'] && preg_match('/\Aselect\b/i', $query->sql)
        && str_contains($query->sql, 'from `users`') && str_contains($query->sql, 'for update')) {
        $paused = true;
        $publish('locked-'.$worker, ['connection_id' => $connection, 'actor_id' => $input['actor_id']]);
        $wait('release-'.$worker);
    }
});
$packet = null;
try {
    $model = app(SaveProductionTrackPreparationPacket::class)->applyReviewed($input['capture'], $actor);
    $packet = ['id' => $model->id, 'public_id' => $model->public_id, 'payload_hash' => $model->payload_hash, 'row_hash' => CanonicalJson::hash($model->getAttributes())];
    $status = 'saved';
} catch (AuthorizationException) {
    $status = 'denied';
} catch (ValidationException) {
    $status = 'blocked';
}
echo json_encode(['status' => $status, 'packet' => $packet, 'connection_id' => $connection, 'pid' => getmypid(), 'paused' => $paused,
    'transaction_level' => DB::transactionLevel(), 'encryptions' => $encrypter->encryptions], JSON_THROW_ON_ERROR);
