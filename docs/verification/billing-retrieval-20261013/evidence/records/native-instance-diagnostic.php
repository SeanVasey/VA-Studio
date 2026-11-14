<?php
$p = new PDO('mysql:unix_socket=/workspace/runtime/mysql-policy-run/mysql.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo json_encode($p->query('SELECT VERSION() AS mysql_version, @@port AS port, @@socket AS socket, @@datadir AS datadir')->fetch(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
