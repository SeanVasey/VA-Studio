<?php
$p = new PDO('mysql:unix_socket=/workspace/runtime/mysql-policy-run/mysql.sock;dbname=vaseyaudio_billing_retrieval_producer', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$p->exec('SET SESSION lock_wait_timeout = 2');
$tables = ['production_membership_billing_positions', 'production_membership_billing_observations'];
$captured = false;
for ($attempt = 0; $attempt < 100 && !$captured; $attempt++) {
$p->beginTransaction();
try {
    $result = [];
    foreach ($tables as $table) {
        $p->query('SELECT * FROM `'.$table.'` LIMIT 0')->fetchAll(PDO::FETCH_ASSOC);
        $result[$table] = $p->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_ASSOC)['Create Table'];
    }
    if (!str_contains($result[$tables[0]], '`allocation_token`') || !str_contains($result[$tables[1]], '`start_allocation_token`')) {
        throw new RuntimeException('Transient pre263 setup; no current definition captured');
    }
    file_put_contents('/workspace/runtime/billing-retrieval-records-evidence/native-table-definitions.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    echo "Captured both complete263 definitions.\n";
$captured = true;
} catch (PDOException|RuntimeException $error) {
    if ($attempt === 99) { throw $error; }
} finally {
    $p->rollBack();
}
if (!$captured) { usleep(500000); }
}
