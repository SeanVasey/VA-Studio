<?php
// Does an open REPEATABLE READ transaction see another session's later DDL through information_schema / SHOW? (synthetic g1probe)
$dsn='mysql:host=127.0.0.1;port=3371;dbname=g1probe'; $o=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
$s=new PDO($dsn,'root','',$o);
$q=fn(PDO $p,string $sql)=>$p->query($sql)->fetchAll(PDO::FETCH_NUM);
foreach (['first: consistent read of a user table'=>'SELECT id FROM u LIMIT 1', 'first: information_schema read only'=>"SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='g1probe'", 'first: nothing (BEGIN only)'=>null, 'first: START TRANSACTION WITH CONSISTENT SNAPSHOT'=>'SNAP'] as $label=>$first) {
  foreach (['v_new','t_new','trg_new'] as $o2) { try { $s->exec("DROP VIEW IF EXISTS v_new"); $s->exec("DROP TABLE IF EXISTS t_new"); $s->exec("DROP TRIGGER IF EXISTS trg_new"); } catch (PDOException) {} }
  $a=new PDO($dsn,'root','',$o); $b=new PDO($dsn,'root','',$o);
  if ($first==='SNAP') { $a->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT'); } else { $a->beginTransaction(); if ($first) $q($a,$first); }
  $b->exec('CREATE VIEW v_new AS SELECT id FROM u'); $b->exec('CREATE TABLE t_new (id INT)'); $b->exec('CREATE TRIGGER trg_new BEFORE INSERT ON p FOR EACH ROW SET @x=1');
  $r=['I_S.VIEWS'=>count($q($a,"SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA='g1probe' AND TABLE_NAME='v_new'")),
      'I_S.TABLES(view)'=>count($q($a,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA='g1probe' AND TABLE_NAME='v_new'")),
      'I_S.TABLES(table)'=>count($q($a,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA='g1probe' AND TABLE_NAME='t_new'")),
      'I_S.TRIGGERS'=>count($q($a,"SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='g1probe' AND TRIGGER_NAME='trg_new'"))];
  foreach (['SHOW CREATE TABLE t_new','SHOW CREATE VIEW v_new','SHOW CREATE TRIGGER trg_new'] as $show) { try { $q($a,$show); $r[$show]=1; } catch (PDOException $e) { $r[$show]='err'.$e->errorInfo[1]; } }
  $a->rollBack();
  echo str_pad($label,52), json_encode($r), "\n";
}
