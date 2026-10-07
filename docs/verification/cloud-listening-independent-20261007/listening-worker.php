<?php

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Listening\ListeningLibrary;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomerFixtures;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
CustomerFixtures::configure();
$customer = User::findOrFail((int) $argv[1]);
$principal = app(CustomerAccess::class)->principal($customer);
$role = $argv[2];
$sync = $argv[3];
if ($role === 'winner') {
    DB::listen(function (QueryExecuted $query) use ($sync): void {
        if (str_starts_with(strtolower($query->sql), 'insert into `customer_saved_tracks`')) {
            file_put_contents($sync.'/winner-held', 'held');
            $deadline = microtime(true) + 10;
            while (! file_exists($sync.'/release')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Independent contention synchronization expired.');
                }
                usleep(10000);
            }
        }
    });
}
file_put_contents($sync.'/'.$role.'-started', 'started');
try {
    $result = app(ListeningLibrary::class)->change($principal, $customer, [
        'action' => 'create-playlist', 'version' => 0, 'name' => 'Independent '.$role.' list',
    ]);
    echo json_encode(['status' => 200, 'version' => $result['version']], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (ListeningException $error) {
    echo json_encode(['status' => $error->status], JSON_THROW_ON_ERROR), PHP_EOL;
}
