<?php
// Three migrate:fresh runs in ONE process against a private MySQL: every run must succeed (the admission
// and ownership proofs run against cached clones on runs 2-3) and run 3 must declare no new classes.
$root = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo 'driver='.Illuminate\Support\Facades\DB::getDriverName().' version='.Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() v')->v."\n";
$counts = [];
for ($i = 1; $i <= 3; $i++) {
    $t = microtime(true);
    $rc = Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
    $out = Illuminate\Support\Facades\Artisan::output();
    $counts[$i] = count(get_declared_classes());
    printf("run %d rc=%d seconds=%.1f declared_classes=%d memory_mb=%.1f migrations_rows=%d\n", $i, $rc, microtime(true) - $t, $counts[$i], memory_get_usage(true) / 1048576, Illuminate\Support\Facades\DB::table('migrations')->count());
    if ($rc !== 0) { echo $out; exit(1); }
}
$ok = $counts[3] === $counts[2];
echo $ok ? "RESULT no new classes on run 3\n" : "RESULT run 3 declared ".($counts[3] - $counts[2])." new classes\n";
exit($ok ? 0 : 1);
