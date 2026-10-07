<?php
$pdo = new PDO('sqlite:'.getenv('DB_DATABASE'));
$schema = $pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);
$tables = [];
foreach ($schema as $object) {
    if ($object['type'] === 'table') {
        $quote = chr(34);
        $table = $quote.str_replace($quote, $quote.$quote, $object['name']).$quote;
        $tables[$object['name']] = $pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
}
echo json_encode(['schema' => $schema, 'tables' => $tables, 'sequences' => $pdo->query('SELECT name,seq FROM sqlite_sequence ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR);
