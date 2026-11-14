<?php
$p = new PDO('mysql:unix_socket=/workspace/runtime/mysql-policy-run/mysql.sock;dbname=vaseyaudio_billing_retrieval_producer', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$threads = $p->query("SELECT p.ID,p.COMMAND,p.TIME,p.STATE,t.trx_state,t.trx_wait_started FROM information_schema.PROCESSLIST p LEFT JOIN information_schema.INNODB_TRX t ON t.trx_mysql_thread_id=p.ID WHERE p.DB='vaseyaudio_billing_retrieval_producer' AND p.ID<>CONNECTION_ID()")->fetchAll(PDO::FETCH_ASSOC);
$last = $p->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1')->fetchColumn();
echo json_encode(['sampled_utc'=>gmdate('Y-m-d H:i:s'),'threads'=>$threads,'latest_migration'=>$last], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR), PHP_EOL;
