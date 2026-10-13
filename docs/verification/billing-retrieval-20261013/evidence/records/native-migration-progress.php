<?php
$p = new PDO('mysql:unix_socket=/workspace/runtime/mysql-policy-run/mysql.sock;dbname=vaseyaudio_billing_retrieval_producer', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo json_encode($p->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 4')->fetchAll(PDO::FETCH_COLUMN), JSON_PRETTY_PRINT), PHP_EOL;
