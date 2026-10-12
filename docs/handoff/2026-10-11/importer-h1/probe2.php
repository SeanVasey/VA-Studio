<?php
$dsn='mysql:host=127.0.0.1;port=33177';
$mk=function() use($dsn){$p=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec('SET SESSION lock_wait_timeout=1, innodb_lock_wait_timeout=1');return $p;};
$a=$mk();$a->exec('DROP DATABASE IF EXISTS probe2');$a->exec('CREATE DATABASE probe2');$a->exec('USE probe2');
foreach(['a_t','b_t','m_t'] as $t) $a->exec("CREATE TABLE $t (id int primary key) ENGINE=InnoDB");
$b=$mk();$b->exec('USE probe2');
$list="SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE'";
$a->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$a->beginTransaction();
$l1=$a->query($list)->fetchAll(PDO::FETCH_KEY_PAIR); echo "list1 ".json_encode($l1)."\n";
// window between list1 and the open of m_t
$b->exec('ALTER TABLE m_t ENGINE=MyISAM'); $b->exec('CREATE TABLE new_t (id int) ENGINE=MyISAM');
echo "B now sees: ".json_encode($b->query($list)->fetchAll(PDO::FETCH_KEY_PAIR))."\n";
foreach(array_keys($l1) as $t) $a->query("SELECT 1 FROM `$t` LIMIT 0")->fetchAll();
$l2=$a->query($list)->fetchAll(PDO::FETCH_KEY_PAIR); echo "A list2 ".json_encode($l2)."\n";
$v=$a->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='m_t'")->fetchColumn(); echo "A engine m_t via I_S: $v\n";
$sc=$a->query("SHOW CREATE TABLE m_t")->fetch(PDO::FETCH_NUM)[1]; echo "A SHOW CREATE m_t: ".(str_contains($sc,'MyISAM')?'MyISAM':'InnoDB')."\n";
$st=$a->query("SHOW TABLE STATUS LIKE 'm_t'")->fetch(PDO::FETCH_ASSOC); echo "A SHOW TABLE STATUS m_t: {$st['Engine']}\n";
$a->rollBack();
// control: same with a fresh transaction (no snapshot)
$a->beginTransaction(); echo "fresh txn list ".json_encode($a->query($list)->fetchAll(PDO::FETCH_KEY_PAIR))."\n"; $a->rollBack();
// variant: does a plain InnoDB consistent read BEFORE list1 matter? -> test whether snapshot is created by the I_S read itself
$b->exec('ALTER TABLE m_t ENGINE=InnoDB');$b->exec('DROP TABLE new_t');
$a->beginTransaction(); $a->query('SELECT 1 FROM a_t LIMIT 0')->fetchAll(); // no read view expected
$b->exec('ALTER TABLE m_t ENGINE=MyISAM');
echo "after LIMIT0 open then B alter, A list: ".json_encode($a->query($list)->fetchAll(PDO::FETCH_KEY_PAIR))."\n";
$a->rollBack();
$a->exec('DROP DATABASE probe2');
