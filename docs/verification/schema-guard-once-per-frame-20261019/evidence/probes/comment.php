<?php
$dsn = 'mysql:host=127.0.0.1;port=3371;dbname=g1probe'; $o=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
$s = new PDO($dsn,'root','',$o); $s->exec('DROP TABLE IF EXISTS q'); $s->exec('CREATE TABLE q (owner_key CHAR(64) PRIMARY KEY) ENGINE=InnoDB');
foreach (["ALTER TABLE q COMMENT='synthetic'", "ALTER TABLE q COMMENT=''", 'CREATE TRIGGER q_t BEFORE INSERT ON q FOR EACH ROW SET @x=1', 'ALTER TABLE q ADD COLUMN z INT'] as $ddl) {
  foreach (['plain' => [], 'savepoint_cycle' => ['SAVEPOINT m', 'SELECT * FROM q LIMIT 0', 'RELEASE SAVEPOINT m', 'SAVEPOINT m'], 'pin' => ['SELECT * FROM q LIMIT 0']] as $n => $stmts) {
    $a = new PDO($dsn,'root','',$o); $b = new PDO($dsn,'root','',$o); $b->exec('SET SESSION lock_wait_timeout=1');
    $a->beginTransaction(); foreach ($stmts as $x) { $st=$a->query($x); if ($st) $st->fetchAll(); }
    try { $b->exec($ddl); $r='not_blocked'; } catch (PDOException $e) { $r = $e->errorInfo[1]==1205?'BLOCKED':'err'.$e->errorInfo[1]; }
    $a->rollBack(); try { $s->exec('DROP TRIGGER IF EXISTS q_t'); } catch (PDOException) {}
    printf("%-60s %-16s %s\n", $ddl, $n, $r);
  }
}
