<?php
// Usage: php dump-guards.php <worktree>  -> JSON {driver: {guard: {sql, body, event}}} for every MemberGrantSchema table.
$root = $argv[1];
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Grants\Member\MemberGrantSchema;
use Illuminate\Support\Facades\DB;
$out = [];
foreach (['sqlite', 'mysql'] as $driver) {
    config(['database.default' => $driver]);
    DB::purge($driver);
    $schema = new MemberGrantSchema;
    $m = new ReflectionMethod(MemberGrantSchema::class, 'guards');
    foreach (MemberGrantSchema::TABLES as $logical) {
        $name = DB::connection()->getTablePrefix().$logical;
        $guards = $m->invoke($schema, $logical, $driver, $name, $schema->table($logical));
        foreach ($guards as $guard => $spec) { $out[$driver][$guard] = $spec; }
    }
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
