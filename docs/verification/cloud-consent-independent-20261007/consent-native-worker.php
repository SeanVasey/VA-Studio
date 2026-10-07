<?php

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Models\ConsentEvent;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Synthetic reviewer native worker only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$d = $input['directory'];
$name = $input['name'];
CustomerFixtures::configure();
ConsentFixtures::configure();
$actor = User::findOrFail($input['user']);
$principal = app(CustomerAccess::class)->principal($actor);
$await = function ($file) {
    $deadline = microtime(true) + 20;
    do {
        clearstatcache();
        if (is_file($file)) {
            return;
        }if (microtime(true) > $deadline) {
            throw new RuntimeException('Synthetic worker barrier timed out.');
        }usleep(10000);
    } while (true);
};
if ($input['pause'] ?? false) {
    ConsentEvent::created(function () use ($d, $name, $await) {
        touch($d.'/locked-'.$name);
        $await($d.'/release-'.$name);
    });
}
file_put_contents($d.'/ready-'.$name, json_encode(['pid' => getmypid(), 'connection' => (int) DB::selectOne('SELECT CONNECTION_ID() id')->id], JSON_THROW_ON_ERROR));
$await($d.'/start-'.$name);
$snapshot = null;
if ($input['snapshot'] ?? false) {
    DB::beginTransaction();
    $snapshot = DB::table('customer_consent_states')->value('revision') ?? 0;
    touch($d.'/snapshot-'.$name);
}
try {
    $dto = app(CustomerConsentPreferences::class)->change($principal, $actor, $input['grant'] ? ConsentFixtures::grant(0) : ConsentFixtures::withdraw(0));
    $result = 'saved';
    $status = null;
} catch (ConsentException $e) {
    $result = 'rejected';
    $status = $e->status;
} finally {
    if ($input['snapshot'] ?? false) {
        DB::rollBack();
    }
}
echo json_encode(['result' => $result, 'status' => $status, 'snapshot_revision' => $snapshot, 'transaction_level' => DB::transactionLevel(), 'isolation' => DB::selectOne('SELECT @@transaction_isolation level')->level], JSON_THROW_ON_ERROR);
