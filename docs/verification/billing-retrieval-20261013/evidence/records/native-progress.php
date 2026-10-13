<?php
$p = new PDO('mysql:unix_socket=/workspace/runtime/mysql-policy-run/mysql.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = $p->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=?');
$q->execute(['vaseyaudio_billing_retrieval_producer']);
echo json_encode(['owned_schema_table_count'=>(int)$q->fetchColumn(), 'threads'=>$p->query("SELECT ID, DB, COMMAND, TIME, STATE FROM information_schema.processlist WHERE DB='vaseyaudio_billing_retrieval_producer'")->fetchAll(PDO::FETCH_ASSOC)], JSON_PRETTY_PRINT), PHP_EOL;
