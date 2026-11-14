<?php
$p = new PDO('mysql:unix_socket=/workspace/runtime/mysql-policy-run/mysql.sock;dbname=vaseyaudio_billing_retrieval_producer', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = $p->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '%billing%' ORDER BY TABLE_NAME");
echo json_encode($q->fetchAll(PDO::FETCH_COLUMN), JSON_PRETTY_PRINT), PHP_EOL;
