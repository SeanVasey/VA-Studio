<?php

use App\Domain\Catalog\DiscoverySitemap\SitemapException;
use App\Domain\Catalog\DiscoverySitemap\SitemapStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Dedicated synthetic native contender. Private control file avoids putting the capability in argv/output.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || count($argv) !== 2 || ! is_file($argv[1]) || is_link($argv[1]) || (fileperms($argv[1]) & 0077) !== 0) {
    exit(2);
}
$input = json_decode(file_get_contents($argv[1]), true, 20, JSON_THROW_ON_ERROR);
config($input['configuration']);
$pdo = DB::connection()->getPdo();
echo json_encode(['ready' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()], JSON_THROW_ON_ERROR)."\n";
flush();
try {
    $store = app(SitemapStore::class);
    $result = $input['operation'] === 'step' ? $store->step($input['request'], 1) : ['generation' => $store->publish($input['request'], 0)];
    echo json_encode(['ok' => $result], JSON_THROW_ON_ERROR)."\n";
} catch (SitemapException $error) {
    echo json_encode(['refused' => $error->reason], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['failed_class' => get_class($error)], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
