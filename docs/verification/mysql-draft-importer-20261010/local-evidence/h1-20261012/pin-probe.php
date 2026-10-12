<?php
declare(strict_types=1);
require '$REVIEW/vendor/autoload.php';
$app = require '$REVIEW/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Domain\Migration\CatalogOnboarding\CatalogDatabaseEvidence;
$schema = 'vaseyaudio_importer_review';
$socket = '$SCRATCH/mysql-run/mysql.sock';
$admin = new PDO('mysql:unix_socket='.$socket, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE IF NOT EXISTS '.$schema);
$config = config('database.connections.mysql');
$config['database'] = $schema;
$config['unix_socket'] = $socket;
$config['host'] = 'invalid-host-socket-must-win.invalid';
$config['port'] = 1;
$config['username'] = 'root';
$config['password'] = '';
$config['options'][PDO::ATTR_PERSISTENT] = true;
$config['options'][PDO::MYSQL_ATTR_INIT_COMMAND] = "SET @importer_review_marker = 'configured-options-preserved'";
config(['database.default' => 'mysql', 'database.connections.mysql' => $config]);
DB::purge('mysql');
$writer = DB::connection()->getPdo();
$tables = ['users', 'tracks', 'audit_events', 'catalog_import_batches', 'catalog_import_mappings', 'media_assets', 'rights_declarations', 'offers', 'offer_revisions', 'catalog_discovery_epoch', 'migrations', 'we`ird table'];
$quote = static fn(string $name): string => '`'.str_replace('`', '``', $name).'`';
foreach ($tables as $table) { $writer->exec('CREATE TABLE '.$quote($table).' (id INT PRIMARY KEY) ENGINE=InnoDB'); }
$factory = $app->make('db.factory');
$spy = new class($factory) {
    public ?PDO $observer = null;
    public function __construct(private object $original) {}
    public function make(array $config): object {
        $connection = $this->original->make($config);
        $this->observer = $connection->getPdo();
        return $connection;
    }
};
$app->instance('db.factory', $spy);
$foreign = new PDO('mysql:unix_socket='.$socket.';dbname='.$schema, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$foreign->exec('SET SESSION lock_wait_timeout=1');
$checks = 0;
$check = static function(bool $ok, string $description) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL: '.$description); }
    ++$checks;
    echo 'PASS '.$description."\n";
};
$originalWait = (int)$writer->query('SELECT @@session.lock_wait_timeout')->fetchColumn();
try {
    $writer->exec('SET SESSION lock_wait_timeout=2');
    DB::beginTransaction();
    (new CatalogDatabaseEvidence)->lockedStorage();
    $check((int)$writer->query('SELECT @@session.lock_wait_timeout')->fetchColumn() === 2, 'success restores shorter writer wait');
    $check($spy->observer?->getAttribute(PDO::ATTR_PERSISTENT) === false, 'observer is nonpersistent despite persistent writer config');
    $check($spy->observer?->query('SELECT @importer_review_marker')->fetchColumn() === 'configured-options-preserved', 'configured PDO init option and Unix socket preserved');
    $check($spy->observer?->query('SELECT CONNECTION_ID()')->fetchColumn() !== $writer->query('SELECT CONNECTION_ID()')->fetchColumn(), 'observer and writer are distinct sessions');
    $check($spy->observer?->inTransaction() === false && (int)$spy->observer?->query('SELECT @@autocommit')->fetchColumn() === 1, 'observer remains autocommit outside a transaction');
    $foreign->exec('INSERT INTO migrations VALUES (1)');
    $check((int)$writer->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 1, 'pin leaves first writer snapshot until after all metadata locks');
    $blocked = false;
    try { $foreign->exec('ALTER TABLE '.$quote('we`ird table').' ENGINE=MyISAM'); }
    catch (PDOException $error) { $blocked = (int)($error->errorInfo[1] ?? 0) === 1205; }
    $check($blocked, 'escaped non-evidence table holds metadata lock until transaction end');
    DB::rollBack();
    $foreign->exec('ALTER TABLE '.$quote('we`ird table').' ENGINE=MyISAM');
    $check($foreign->query('SHOW CREATE TABLE '.$quote('we`ird table'))->fetch(PDO::FETCH_NUM)[1] !== false, 'rollback releases pin metadata lock');
    DB::beginTransaction();
    $refusal = null;
    try { (new CatalogDatabaseEvidence)->lockedStorage(); }
    catch (RuntimeException $error) { $refusal = $error->getMessage(); }
    $check($refusal === 'catalog_target_invalid', 'non-InnoDB engine is refused');
    $check((int)$writer->query('SELECT @@session.lock_wait_timeout')->fetchColumn() === 2, 'engine refusal restores writer wait');
    DB::rollBack();
    $foreign->exec('ALTER TABLE '.$quote('we`ird table').' ENGINE=InnoDB');
    $writer->exec('CREATE TEMPORARY TABLE '.$quote('we`ird table').' (id INT)');
    DB::beginTransaction();
    $refusal = null;
    try { (new CatalogDatabaseEvidence)->lockedStorage(); }
    catch (RuntimeException $error) { $refusal = $error->getMessage(); }
    $check($refusal === 'catalog_target_invalid', 'escaped non-evidence temporary shadow is refused');
    $check((int)$writer->query('SELECT @@session.lock_wait_timeout')->fetchColumn() === 2, 'shadow refusal restores writer wait');
    DB::rollBack();
    $writer->exec('DROP TEMPORARY TABLE '.$quote('we`ird table'));
    echo 'RESULT '.$checks."/12 checks passed\n";
    echo 'MYSQL '.$writer->query('SELECT VERSION()')->fetchColumn()."\n";
} finally {
    if (DB::transactionLevel() > 0) { DB::rollBack(); }
    $writer->exec('SET SESSION lock_wait_timeout='.$originalWait);
    $writer->exec('DROP TEMPORARY TABLE IF EXISTS '.$quote('we`ird table'));
    $admin->exec('DROP DATABASE '.$schema);
}
