<?php
// MDL / TEMPORARY / session-counter probe on the private 8.4.11 server (lane G1). Synthetic schema only.
$dsn = 'mysql:host=127.0.0.1;port=3371;dbname=g1probe';
$root = new PDO('mysql:host=127.0.0.1;port=3371', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS g1probe'); $root->exec('CREATE DATABASE g1probe');
function conn($dsn) { $p = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); return $p; }
function reset_schema($dsn) {
    $p = conn($dsn);
    foreach (['v','v2'] as $v) $p->exec("DROP VIEW IF EXISTS $v");
    foreach (['other','c','u','t2','t','p'] as $t) $p->exec("DROP TABLE IF EXISTS $t");
    $p->exec('CREATE TABLE p (id BIGINT PRIMARY KEY) ENGINE=InnoDB');
    $p->exec('CREATE TABLE t (id BIGINT PRIMARY KEY, pid BIGINT, CONSTRAINT t_f FOREIGN KEY (pid) REFERENCES p(id)) ENGINE=InnoDB');
    $p->exec('CREATE TABLE u (id BIGINT PRIMARY KEY) ENGINE=InnoDB');
    $p->exec('CREATE TRIGGER t_existing BEFORE UPDATE ON t FOR EACH ROW SET @x=1');
}
$xs = ['none' => [], 'select_limit0' => ['SELECT * FROM t LIMIT 0'], 'consistent_read' => ['SELECT id FROM t LIMIT 1'],
  'show_create' => ['SHOW CREATE TABLE t'], 'show_index' => ['SHOW INDEX FROM t'],
  'is_columns' => ["SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='g1probe' AND TABLE_NAME='t'"],
  'is_tables' => ["SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='g1probe' AND TABLE_NAME='t'"],
  'for_update_child_write' => ['INSERT INTO u VALUES (1)'],
  'select_parent_p' => ['SELECT * FROM p LIMIT 0']];
$ys = ['alter_add_column' => 'ALTER TABLE t ADD COLUMN z INT', 'create_trigger_on_t' => 'CREATE TRIGGER t_new BEFORE INSERT ON t FOR EACH ROW SET @y=1',
  'drop_trigger_on_t' => 'DROP TRIGGER t_existing', 'rename_t' => 'RENAME TABLE t TO t2', 'drop_t' => 'DROP TABLE t',
  'child_fk_to_t' => 'CREATE TABLE other (id BIGINT PRIMARY KEY, tid BIGINT, CONSTRAINT o_f FOREIGN KEY (tid) REFERENCES t(id)) ENGINE=InnoDB',
  'view_on_t' => 'CREATE VIEW v AS SELECT * FROM t', 'trigger_on_u_body_t' => 'CREATE TRIGGER u_new BEFORE INSERT ON u FOR EACH ROW INSERT INTO t (id) VALUES (NEW.id)',
  'alter_parent_p' => 'ALTER TABLE p ADD COLUMN z INT', 'create_index_t' => 'CREATE INDEX t_extra ON t (pid)'];
$only = $argv[1] ?? null;
foreach ($xs as $xn => $xsql) {
  if ($only && $only !== 'mdl') break;
  foreach ($ys as $yn => $ysql) {
    reset_schema($dsn);
    $a = conn($dsn); $b = conn($dsn);
    $b->exec('SET SESSION lock_wait_timeout=1');
    $a->beginTransaction();
    foreach ($xsql as $s) { $st = $a->query($s); if ($st) $st->fetchAll(); }
    $t0 = microtime(true);
    try { $b->exec($ysql); $r = 'not_blocked'; } catch (PDOException $e) { $r = ($e->errorInfo[1] ?? 0) == 1205 ? 'BLOCKED' : 'error:'.$e->errorInfo[1]; }
    $a->rollBack();
    printf("%-24s %-22s %s (%.2fs)\n", $xn, $yn, $r, microtime(true) - $t0);
  }
}
