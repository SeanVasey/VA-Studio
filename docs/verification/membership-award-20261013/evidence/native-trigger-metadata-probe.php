<?php
$dsn = 'mysql:unix_socket=/workspace/runtime/mysql-policy-run/mysql.sock;dbname=vaseyaudio_award_ddl_probe;charset=utf8mb4';
$a = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$b = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$a->exec('CREATE TABLE events (id INTEGER PRIMARY KEY) ENGINE=InnoDB');
$a->exec('CREATE TRIGGER events_old BEFORE INSERT ON events FOR EACH ROW SET @probe_only = 1');
$a->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$a->beginTransaction();
$query = "SELECT TRIGGER_NAME,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'events'";
echo json_encode(['version' => $a->query('SELECT VERSION()')->fetchColumn(), 'before' => $a->query($query)->fetchAll(PDO::FETCH_ASSOC)])."\n";
$b->exec('DROP TRIGGER events_old');
$b->exec('CREATE TRIGGER events_new BEFORE INSERT ON events FOR EACH ROW SET @probe_only = 2');
foreach (['plain' => $query, 'show' => "SHOW TRIGGERS WHERE `Table` = 'events'", 'locking' => $query.' FOR UPDATE', 'sharing' => $query.' FOR SHARE',
    'show_old' => 'SHOW CREATE TRIGGER events_old', 'show_new' => 'SHOW CREATE TRIGGER events_new'] as $name => $sql) {
    try {
        echo json_encode([$name => $a->query($sql)->fetchAll(PDO::FETCH_ASSOC)])."\n";
    } catch (PDOException $e) {
        echo json_encode([$name => ['state' => $e->getCode(), 'number' => $e->errorInfo[1], 'message' => $e->errorInfo[2]]])."\n";
    }
}
$a->rollBack();
$b->exec('DROP TABLE events');
