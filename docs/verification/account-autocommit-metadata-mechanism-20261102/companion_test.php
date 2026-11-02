<?php

$count = 0;
$check = function (bool $ok) use (&$count): void {
    $count++;
    if (! $ok) {
        throw new RuntimeException('Pure companion contract failed: '.$count);
    }
};
$sql = specs(['token' => '012345abcdef']);
foreach (['app.primitive', 'plain.tables', 'plain.triggers', 'plain.routines', 'plain.events', 'plain.indexes', 'plain.constraints', 'plain.peer_scope', 'context', 'warnings', 'questions', 'relation.pad'] as $id) {
    $check(isset($sql[$id]) && companionOperation($id));
}
foreach (['begin', 'anchor', 'pin', 'rollback', 'savepoint', 'primary.temp', 'publish.table', 'connect.selected', 'plain.unknown', 'plain.tables.extra'] as $id) {
    $check(! companionOperation($id));
}
$check(! preg_match('/\b(?:DROP|GRANT|CREATE USER|SET GLOBAL|READ COMMITTED)\b/', implode("\n", $sql)));
$check(substr_count($sql['context'], 'autocommit') === 2);
$check(str_contains($sql['connect.strict'], 'STRICT_TRANS_TABLES'));
$check(count(catalog('fixed')) === 7);
$check(! isset($sql['share.tables']) && ! isset($sql['update.tables']));
foreach ($sql as $id => $query) {
    if (companionOperation($id) && $id !== 'app.primitive') {
        $check(preg_match('/\A(?:SELECT|SHOW)\b/', $query) === 1);
    }
}
foreach (['publish.check', 'publish.table', 'base.visible.fk', 'create.selected'] as $id) {
    $check(publicationWaitAllowed('publisher', $id));
    $check(! publicationWaitAllowed('primary', $id));
    $check(! publicationWaitAllowed('companion', $id));
}
foreach (['publish.unknown', 'warnings', 'plain.tables', 'context', 'publish.check.extra'] as $id) {
    $check(! publicationWaitAllowed('publisher', $id));
}
// Audit every declared key shape per table, irrespective of uniqueness/name.
// An identical PRIMARY shape on a different table is not a duplicate index.
$shapes = [];
$add = function (string $table, string $columns) use (&$shapes, $check): void {
    $shape = str_replace(' ', '', $columns);
    $check(! isset($shapes[$table][$shape]));
    $shapes[$table][$shape] = true;
};
foreach ($sql as $id => $query) {
    if (preg_match('/\ACREATE TABLE ([^ (]+)\((.*)\) ENGINE=InnoDB\z/', $query, $table)) {
        if (str_contains($table[2], 'PRIMARY KEY')) {
            preg_match('/\A([a-z_]+) INT PRIMARY KEY/', $table[2], $primary);
            $add($table[1], $primary[1]);
        }
        preg_match_all('/(?:UNIQUE )?KEY [a-z_]+\(([^)]+)\)/', $table[2], $keys);
        foreach ($keys[1] as $columns) {
            $add($table[1], $columns);
        }
    } elseif (preg_match('/\ACREATE INDEX [a-z_]+ ON ([^ (]+)\(([^)]+)\)\z/', $query, $index)) {
        $add($index[1], $index[2]);
    } elseif (preg_match('/\AALTER TABLE ([^ (]+) ADD CONSTRAINT [a-z_]+ UNIQUE\(([^)]+)\)\z/', $query, $unique)) {
        $add($unique[1], $unique[2]);
    }
}
$target = qi(schemas('012345abcdef')[0]).'.foreign_target';
$targetShapes = array_keys($shapes[$target]);
sort($targetShapes);
$check($targetShapes === ['id', 'parent_id', 'reserved_value', 'unique_value', 'visible_value']);
$check(str_contains($sql['base.visible.index'], '(visible_value)'));
$check(str_contains($sql['publish.index'], '(reserved_value)'));
$check(str_contains($sql['publish.unique'], '(unique_value)'));
$check(str_contains($sql['base.target'], 'KEY plain_parent_support(parent_id)'));
$check(str_contains($sql['publish.fk'], 'FOREIGN KEY(parent_id)'));
emit(['pure_companion_contract' => 'PASS', 'assertions' => $count, 'operation_ids' => count($sql), 'database_connections' => 0, 'native_mechanism_execution' => false]);
