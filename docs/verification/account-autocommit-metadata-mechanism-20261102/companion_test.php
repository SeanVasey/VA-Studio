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
emit(['pure_companion_contract' => 'PASS', 'assertions' => $count, 'operation_ids' => count($sql), 'database_connections' => 0, 'native_mechanism_execution' => false]);
