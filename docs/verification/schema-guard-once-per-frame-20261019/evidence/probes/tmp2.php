<?php
// Shadow paths that do not CREATE inside the frame: rename/alter a temporary table made before the frame; FLUSH STATUS reset.
$dsn = 'mysql:host=127.0.0.1;port=3371;dbname=g1probe';
$o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
function c(PDO $p) { return $p->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_create_table','Com_alter_table','Com_rename_table')")->fetchAll(PDO::FETCH_KEY_PAIR); }
$paths = ['alter_rename' => 'ALTER TABLE x RENAME TO t', 'rename_table' => 'RENAME TABLE x TO t', 'alter_add_column_temp' => 'ALTER TABLE x ADD COLUMN y INT',
  'flush_status' => 'FLUSH STATUS', 'flush_local_status' => 'FLUSH LOCAL STATUS', 'drop_temporary' => 'DROP TEMPORARY TABLE x', 'truncate_temp' => 'TRUNCATE TABLE x',
  'create_index_temp' => 'CREATE INDEX xi ON x (id)', 'lock_tables_temp' => 'LOCK TABLES x WRITE'];
foreach ($paths as $name => $sql) {
    $p = new PDO($dsn, 'root', '', $o);
    $p->exec('CREATE TEMPORARY TABLE x (id INT)');
    $p->beginTransaction(); $p->exec('INSERT INTO u VALUES (43)'); $p->exec('SAVEPOINT marker');
    $b = c($p);
    try { $p->exec($sql); $r = 'ok'; } catch (PDOException $e) { $r = 'error:'.$e->errorInfo[1]; }
    $a = c($p);
    try { $p->exec('RELEASE SAVEPOINT marker'); $sp = 'savepoint_survives'; } catch (PDOException $e) { $sp = 'savepoint_lost(implicit_commit)'; }
    $tShadow = 'no'; try { $sh = $p->query('SHOW CREATE TABLE t')->fetch(PDO::FETCH_NUM)[1]; $tShadow = str_starts_with($sh, 'CREATE TEMPORARY') ? 'yes' : 'base'; } catch (PDOException) {}
    try { $p->exec('UNLOCK TABLES'); } catch (PDOException) {}
    if ($p->inTransaction()) $p->rollBack();
    $kept = (int) (new PDO($dsn, 'root', '', $o))->query('SELECT COUNT(*) FROM u WHERE id=43')->fetchColumn();
    printf("%-22s %-10s %-32s t_shadow=%s insert_committed=%s counters %s -> %s\n", $name, $r, $sp, $tShadow, $kept ? 'YES' : 'no', json_encode($b), json_encode($a));
    (new PDO($dsn, 'root', '', $o))->exec('DELETE FROM u WHERE id=43');
}
