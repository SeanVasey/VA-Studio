<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Only run inside with-mysql-test-server.sh, on its explicitly disposable schema.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'vaseyaudio_test'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_USERNAME') !== 'root') {
    throw new RuntimeException('Disposable test-server environment required.');
}
$root = new PDO('mysql:host=127.0.0.1;port='.getenv('DB_PORT'), 'root', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$facts = $root->query('SELECT VERSION() AS version, @@global.log_bin AS binlog')->fetch(PDO::FETCH_ASSOC);
if (! str_starts_with($facts['version'], '8.4.') || (int) $facts['binlog'] !== 1) {
    throw new RuntimeException('Native MySQL 8.4 with binary logging required.');
}
echo json_encode($facts, JSON_THROW_ON_ERROR), PHP_EOL;
$password = bin2hex(random_bytes(24));
$root->exec("CREATE USER 'vasey_app'@'127.0.0.1' IDENTIFIED BY ".$root->quote($password));
$root->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER, CREATE TEMPORARY TABLES, LOCK TABLES ON `vaseyaudio_test`.* TO 'vasey_app'@'127.0.0.1'");
$appConnection = new PDO('mysql:host=127.0.0.1;port='.getenv('DB_PORT').';dbname=vaseyaudio_test', 'vasey_app', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('SET GLOBAL log_bin_trust_function_creators = OFF');
$appConnection->exec('CREATE TABLE trigger_privilege_probe (id BIGINT PRIMARY KEY)');
$code = null;
try {
    $appConnection->exec('CREATE TRIGGER trigger_privilege_probe_insert BEFORE INSERT ON trigger_privilege_probe FOR EACH ROW SET NEW.id = NEW.id');
} catch (PDOException $error) {
    $code = $error->errorInfo[1] ?? null;
}
if ($code !== 1419) {
    throw new RuntimeException('Expected non-SUPER trigger refusal 1419.');
}
echo 'PASS: native non-SUPER trigger creation refused with 1419 while trust is OFF.', PHP_EOL;
$root->exec('SET GLOBAL log_bin_trust_function_creators = ON');
$appConnection->exec('CREATE TRIGGER trigger_privilege_probe_insert BEFORE INSERT ON trigger_privilege_probe FOR EACH ROW SET NEW.id = NEW.id');
$appConnection->exec('DROP TRIGGER trigger_privilege_probe_insert');
$appConnection->exec('DROP TABLE trigger_privilege_probe');
echo 'PASS: same schema-scoped account creates a trigger after trust is ON.', PHP_EOL;
foreach (['DB_USERNAME' => 'vasey_app', 'DB_PASSWORD' => $password] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
unset($password);
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
foreach ([1, 2] as $run) {
    $status = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    if ($status !== 0) {
        throw new RuntimeException('Nonroot migration failed.');
    }
    if ($run === 2 && ! str_contains(Artisan::output(), 'Nothing to migrate')) {
        throw new RuntimeException('Repeat migration was not a no-op.');
    }
    echo 'PASS: nonroot migration run ', $run, ' exited 0', $run === 2 ? ' (Nothing to migrate).' : '.', PHP_EOL;
}
$facts = DB::selectOne('SELECT CURRENT_USER() AS authenticated_account, (SELECT COUNT(*) FROM migrations) AS migrations, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()) AS tables, (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()) AS triggers');
if ($facts->authenticated_account !== 'vasey_app@127.0.0.1' || $facts->triggers < 500) {
    throw new RuntimeException('Native migration/account evidence incomplete.');
}
echo json_encode($facts, JSON_THROW_ON_ERROR), PHP_EOL;
