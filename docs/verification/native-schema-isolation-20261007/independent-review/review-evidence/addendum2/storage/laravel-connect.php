<?php
// Reviewer check (addendum 2): can the application's own MySQL connection select a database whose
// name carries a delimiter? Laravel's MySqlConnector sends the name in the DSN (dbname=) and then
// runs "use `<name>`;" without escaping. Run from the worktree with the private-instance env and
// DB_DATABASE set to the candidate name.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
try {
    printf("config=%s DATABASE()=%s\n", json_encode($db->getDatabaseName()), json_encode($db->selectOne('SELECT DATABASE() d')->d));
} catch (Throwable $e) {
    printf("config=%s refused: %s: %s\n", json_encode($db->getDatabaseName()), get_class($e), preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 220)));
}
