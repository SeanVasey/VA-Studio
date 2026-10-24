<?php
// Same-session TEMPORARY creation paths: savepoint survival and session counters (lane G1 probe, synthetic schema).
$dsn = 'mysql:host=127.0.0.1;port=3371;dbname=g1probe';
$o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$s = new PDO($dsn, 'root', '', $o);
foreach (['mk_tmp' => 'PROCEDURE', 'fn_tmp' => 'FUNCTION'] as $n => $k) $s->exec("DROP $k IF EXISTS $n");
$s->exec('CREATE PROCEDURE mk_tmp() BEGIN CREATE TEMPORARY TABLE t (id INT); END');
try { $s->exec('CREATE FUNCTION fn_tmp() RETURNS INT NOT DETERMINISTIC MODIFIES SQL DATA BEGIN CREATE TEMPORARY TABLE t (id INT); RETURN 1; END'); $fn = true; } catch (PDOException $e) { echo "function create: ".$e->getMessage()."\n"; $fn = false; }
echo 'performance_schema=', $s->query('SELECT @@performance_schema')->fetchColumn(), "\n";
function counters(PDO $p): string {
    try { $rows = $p->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_create_table','Com_drop_table','Com_stmt_execute','Com_execute_sql','Com_call_procedure')")->fetchAll(PDO::FETCH_KEY_PAIR); }
    catch (PDOException $e) { return 'SHOW SESSION STATUS failed: '.$e->getMessage(); }
    return json_encode($rows);
}
$paths = [
  'direct' => fn(PDO $p) => $p->exec('CREATE TEMPORARY TABLE t (id INT)'),
  'like' => fn(PDO $p) => $p->exec('CREATE TEMPORARY TABLE t LIKE u'),
  'as_select' => fn(PDO $p) => $p->exec('CREATE TEMPORARY TABLE t AS SELECT 1 id'),
  'qualified' => fn(PDO $p) => $p->exec('CREATE TEMPORARY TABLE g1probe.t (id INT)'),
  'sql_prepare' => function (PDO $p) { $p->exec("PREPARE ps FROM 'CREATE TEMPORARY TABLE t (id INT)'"); $p->exec('EXECUTE ps'); $p->exec('DEALLOCATE PREPARE ps'); },
  'pdo_native_prepare' => function (PDO $p) { $p->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); $p->prepare('CREATE TEMPORARY TABLE t (id INT)')->execute(); $p->setAttribute(PDO::ATTR_EMULATE_PREPARES, true); },
  'call_procedure' => fn(PDO $p) => $p->exec('CALL mk_tmp()'),
];
if ($fn) $paths['stored_function'] = fn(PDO $p) => $p->query('SELECT fn_tmp()')->fetchAll();
foreach ($paths as $name => $make) {
    $p = new PDO($dsn, 'root', '', $o);
    $p->beginTransaction();
    $p->exec('INSERT INTO u VALUES (42)');
    $p->exec('SAVEPOINT marker');
    $before = counters($p);
    try { $make($p); $made = 'created'; } catch (PDOException $e) { $made = 'error:'.$e->errorInfo[1]; }
    $after = counters($p);
    try { $p->exec('RELEASE SAVEPOINT marker'); $p->exec('SAVEPOINT marker'); $sp = 'savepoint_survives'; } catch (PDOException $e) { $sp = 'savepoint_lost'; }
    $shown = $p->query('SHOW CREATE TABLE t')->fetch(PDO::FETCH_NUM)[1] ?? '';
    $p->exec('DROP TEMPORARY TABLE IF EXISTS t');
    try { $p->exec('RELEASE SAVEPOINT marker'); $p->exec('SAVEPOINT marker'); $sp2 = 'survives_drop_temporary'; } catch (PDOException $e) { $sp2 = 'lost_after_drop'; }
    $p->rollBack();
    $kept = (int) $p->query('SELECT COUNT(*) FROM u WHERE id=42')->fetchColumn();
    printf("%-20s %s %s %s temporary=%s rolled_back_insert=%s\n  before=%s\n  after =%s\n", $name, $made, $sp, $sp2, str_starts_with($shown, 'CREATE TEMPORARY') ? 'yes' : 'no', $kept === 0 ? 'yes' : 'no(committed!)', $before, $after);
    $s->exec('DELETE FROM u WHERE id=42');
}
// session variables that the identity inspection reads
$p = new PDO($dsn, 'root', '', $o); $p->beginTransaction(); $p->exec('SAVEPOINT m');
$p->exec('SET SESSION foreign_key_checks=0'); $p->exec('USE mysql');
try { $p->exec('RELEASE SAVEPOINT m'); echo "SET foreign_key_checks / USE keep savepoint: yes, now fk=", $p->query('SELECT @@foreign_key_checks, DATABASE()')->fetch(PDO::FETCH_NUM)[0], "\n"; } catch (PDOException $e) { echo "lost\n"; }
$p->rollBack();
