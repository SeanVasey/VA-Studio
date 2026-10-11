<?php
$dsn='mysql:host=127.0.0.1;port=33177';
$mk=function() use($dsn){$p=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec('SET SESSION lock_wait_timeout=1, innodb_lock_wait_timeout=1');return $p;};
$a=$mk();$a->exec('DROP DATABASE IF EXISTS probe');$a->exec('CREATE DATABASE probe');$a->exec('CREATE DATABASE IF NOT EXISTS probe_other');$a->exec('USE probe');
$a->exec('CREATE TABLE t1 (id int primary key) ENGINE=InnoDB');$a->exec('CREATE TABLE `we``ird ta-ble` (id int) ENGINE=InnoDB');
$a->exec("CREATE VIEW v1 AS SELECT * FROM t1");
$b=$mk();$b->exec('USE probe');
$list="SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE'";
$a->beginTransaction();
$names=$a->query($list)->fetchAll(PDO::FETCH_COLUMN);
echo "listed: ".json_encode($names)."\n";
foreach($names as $t){$a->query('SELECT 1 FROM `'.str_replace('`','``',$t).'` LIMIT 0')->fetchAll();}
echo "MDL held by A: ".json_encode($a->query("SELECT OBJECT_NAME, LOCK_TYPE, LOCK_DURATION FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA='probe' AND OWNER_THREAD_ID=PS_CURRENT_THREAD_ID()")->fetchAll(PDO::FETCH_NUM))."\n";
foreach(['ALTER TABLE t1 ENGINE=MyISAM','ALTER TABLE `we``ird ta-ble` ENGINE=MyISAM','CREATE TRIGGER tr AFTER INSERT ON t1 FOR EACH ROW SET @x=1','RENAME TABLE t1 TO t1x','DROP TABLE t1'] as $ddl){
 try{$b->exec($ddl);echo "B ok (NOT blocked): $ddl\n";}catch(PDOException $e){echo "B blocked: $ddl -> ".$e->errorInfo[1]."\n";}
}
$b->exec('CREATE TABLE created_after (id int) ENGINE=MyISAM'); echo "B created MyISAM table after pin\n";
$b->exec('CREATE TABLE probe_other.x (id int) ENGINE=MyISAM');
$after=$a->query($list)->fetchAll(PDO::FETCH_KEY_PAIR);
echo "A second listing sees: ".json_encode($after)."\n";
$a->rollBack();
// pending exclusive request blocks new shared requests (queue stall)
$a->beginTransaction(); $a->query('SELECT 1 FROM t1 LIMIT 0')->fetchAll();
$pid=pcntl_fork();
if($pid===0){$c=$mk();$c->exec('USE probe');$c->exec('SET SESSION lock_wait_timeout=5');try{$c->exec('ALTER TABLE t1 ADD COLUMN y int');}catch(PDOException $e){}exit(0);}
usleep(800000);
$d=$mk();$d->exec('USE probe');$t=microtime(true);
try{$d->query('SELECT * FROM t1')->fetchAll();echo "D plain read not blocked\n";}catch(PDOException $e){printf("D plain read blocked behind pending ALTER: %d after %.1fs\n",$e->errorInfo[1],microtime(true)-$t);}
$a->rollBack(); pcntl_waitpid($pid,$s);
$a->exec('DROP DATABASE probe');$a->exec('DROP DATABASE probe_other');
