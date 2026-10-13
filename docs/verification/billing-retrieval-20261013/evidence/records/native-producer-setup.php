<?php
// Authorized private disposable schema only; never call before the schema lane releases its slot.
$socket = '/workspace/runtime/mysql-policy-run/mysql.sock';
$pdo = new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$metadata = $pdo->query('SELECT VERSION() AS mysql_version, @@port AS port, @@socket AS socket, @@datadir AS datadir')->fetch(PDO::FETCH_ASSOC);
if ((int) $metadata['port'] !== 3306 || $metadata['socket'] !== '/run/mysql-policy/mysql.sock' || rtrim($metadata['datadir'], '/') !== '/var/lib/mysql') {
    throw new RuntimeException('Unexpected private instance');
}
$pdo->exec('CREATE DATABASE IF NOT EXISTS `vaseyaudio_billing_retrieval_producer` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$metadata['client_socket'] = $socket;
$metadata['published_port'] = 33413;
$metadata['private_datadir_mount'] = '/workspace/runtime/mysql-policy-data';
$metadata['owned_schema'] = 'vaseyaudio_billing_retrieval_producer';
$metadata['php_version'] = PHP_VERSION;
require '/workspace/VA-Studio-membership-ledger/vendor/autoload.php';
$metadata['ledger_path'] = (new ReflectionClass(App\Domain\Memberships\Billing\BillingLedger::class))->getFileName();
if ($metadata['ledger_path'] !== '/workspace/VA-Studio-membership-ledger/app/Domain/Memberships/Billing/BillingLedger.php') {
    throw new RuntimeException('Unexpected source mapping');
}
echo json_encode($metadata, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR), PHP_EOL;
