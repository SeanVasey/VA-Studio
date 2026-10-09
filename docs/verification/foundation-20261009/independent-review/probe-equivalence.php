<?php
// Probe: cached clone vs fresh require give identical reflected outputs; objects carry no own state.
$root = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Support\MigrationDefinitions;
$call = fn ($o, $m, ...$a) => (new ReflectionMethod($o, $m))->invoke($o, ...$a);
$state = function ($o) { $r = []; foreach ((new ReflectionObject($o))->getProperties() as $p) { $r[$p->getDeclaringClass()->getName() === get_class($o) ? 'own:'.$p->getName() : 'base:'.$p->getName()] = $p->isInitialized($o) ? var_export($p->getValue($o), true) : 'uninit'; } return $r; };
$ok = true;
foreach (['2026_10_06_000040_customer_accounts.php', '2026_10_07_250000_customer_consent.php', '2026_10_07_242000_customer_saved_tracks.php'] as $f) {
    $fresh = require database_path('migrations/'.$f);
    $a = MigrationDefinitions::load($f); $b = MigrationDefinitions::load($f);
    printf("%s props=%s same_class_a_b=%s a!==b=%s\n", $f, json_encode($state($a)), var_export(get_class($a) === get_class($b), true), var_export($a !== $b, true));
    $ok = $ok && $state($a) === $state($fresh) && get_class($a) === get_class($b) && $a !== $b;
    $pairs = [];
    if (str_contains($f, 'accounts')) { $pairs[] = ['guards']; }
    if (str_contains($f, 'consent')) { foreach (['sqlite', 'mysql'] as $d) { $pairs[] = ['triggers', $d]; foreach (['customer_consent_policies', 'customer_consent_events', 'customer_consent_states'] as $t) { $pairs[] = ['definition', $d, $t]; } } }
    if (str_contains($f, 'saved_tracks')) { foreach (['sqlite', 'mysql'] as $d) { $pairs[] = ['definition', $d]; } }
    foreach ($pairs as $p) {
        $m = array_shift($p);
        $x = $call($fresh, $m, ...$p); $y = $call($a, $m, ...$p); $z = $call($b, $m, ...$p);
        $same = $x === $y && $y === $z;
        $ok = $ok && $same;
        printf("  %s(%s) identical=%s sha256=%s\n", $m, implode(',', $p), var_export($same, true), hash('sha256', serialize($y)));
    }
}
echo $ok ? "RESULT equivalent\n" : "RESULT DIFFERENT\n";
exit($ok ? 0 : 1);
