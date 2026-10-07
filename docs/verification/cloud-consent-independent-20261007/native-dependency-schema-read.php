<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$pdo = DB::connection()->getPdo();
$result = ['source' => getenv('CONSENT_REVIEW_SOURCE'), 'version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'tables' => []];
foreach (['users', 'customer_accounts', 'quote_owners'] as $t) {
    $row = ['ddl' => $pdo->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_ASSOC)];
    foreach (['metadata' => 'SELECT ENGINE,TABLE_TYPE,TABLE_COMMENT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', 'columns' => 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', 'indexes' => 'SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,INDEX_TYPE,SUB_PART,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', 'foreign' => 'SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME', 'guards' => 'SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME'] as $name => $sql) {
        $s = $pdo->prepare($sql);
        $s->execute([$t]);
        $row[$name] = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    $result['tables'][$t] = $row;
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),"\n";
