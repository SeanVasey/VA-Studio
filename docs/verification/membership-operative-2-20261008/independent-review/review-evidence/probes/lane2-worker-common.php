<?php

// Shared bootstrap for the reviewer's lane 2 native workers. Evidence only; not part of the suite.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures as F;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_REVIEW_LANE2_ONLY') !== '1') {
    throw new LogicException('Isolated native reviewer probe only.');
}
F::configure();
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$directory = getenv('VA_REVIEW_LANE2_DIRECTORY');
$worker = getenv('VA_REVIEW_LANE2_WORKER');
$pdo = DB::connection()->getPdo();
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=50');
$wait = function (string $name, int $seconds = 120) use ($directory): void {
    $deadline = microtime(true) + $seconds;
    while (! is_file($directory.'/'.$name)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Reviewer barrier timed out: '.$name);
        }
        usleep(2000);
        clearstatcache();
    }
};
$signal = function (string $name) use ($directory): void {
    file_put_contents($directory.'/'.$name.'.tmp', '1');
    rename($directory.'/'.$name.'.tmp', $directory.'/'.$name);
};
$ready = function () use ($directory, $worker, $connectionId): void {
    file_put_contents($directory.'/ready-'.$worker.'.tmp', (string) $connectionId);
    rename($directory.'/ready-'.$worker.'.tmp', $directory.'/ready-'.$worker);
};
