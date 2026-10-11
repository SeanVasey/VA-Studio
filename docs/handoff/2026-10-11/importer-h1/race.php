<?php
// Deterministic race against lockedStorage(): hold the first pinned table with LOCK TABLES WRITE so the
// importer's open blocks after its first listing; meanwhile move `migrations` (not yet opened) to MyISAM.
$root = '/home/user/wt-fu-importer';
chdir($root);
require $root.'/vendor/autoload.php';
if (($argv[1] ?? '') === 'old') { require __DIR__.'/OldEvidence.php'; }
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
$pdo = DB::connection()->getPdo();
$tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
sort($tables, SORT_STRING);
$first = $tables[0];
echo "tables=".count($tables)." first=$first migrations_index=".array_search('migrations', $tables)."\n";
DB::disconnect();
$mk = fn () => new PDO('mysql:host=127.0.0.1;port=33177;dbname=vaseyaudio_catalog_import', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$b = $mk();
$b->exec('LOCK TABLES `'.$first.'` WRITE');
$pid = pcntl_fork();
if ($pid === 0) {
    DB::purge(); DB::reconnect();
    DB::beginTransaction();
    try {
        (new App\Domain\Migration\CatalogOnboarding\CatalogDatabaseEvidence)->lockedStorage();
        $engine = DB::connection()->getPdo()->query("SHOW CREATE TABLE migrations")->fetch(PDO::FETCH_NUM)[1];
        echo "CHILD: lockedStorage ADMITTED; migrations is actually ".(str_contains($engine, 'ENGINE=MyISAM') ? 'MyISAM' : 'InnoDB')."\n";
    } catch (Throwable $e) {
        echo "CHILD: refused: ".get_class($e).' '.$e->getMessage()."\n";
    }
    DB::rollBack();
    exit(0);
}
sleep(3); // child has listed and is now waiting on the open of $first
$c = $mk();
$c->exec('SET SESSION lock_wait_timeout = 5');
$c->exec('ALTER TABLE migrations ENGINE=MyISAM');
echo "PARENT: migrations moved to MyISAM while the child waits on `$first`\n";
$b->exec('UNLOCK TABLES');
pcntl_waitpid($pid, $status);
$c->exec('ALTER TABLE migrations ENGINE=InnoDB');
