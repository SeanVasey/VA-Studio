<?php
// Lists every `Vendor\Class::class, 'name'` pair in app/ (imports resolved) and checks that the class still
// declares that property or method in the installed vendor tree.
require getcwd().'/vendor/autoload.php';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(getcwd().'/app'));
$pairs = [];
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $src = file_get_contents($f->getPathname());
    preg_match('/^namespace\s+([^;]+);/m', $src, $ns);
    $uses = [];
    if (preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $u) { $alias = $u[2] ?? ''; if ($alias === '') { $parts = explode('\\', $u[1]); $alias = end($parts); } $uses[$alias] = $u[1]; }
    }
    if (!preg_match_all("/(\\\\?[A-Z][\\w\\\\]*)::class,\\s*'([A-Za-z_]\\w*)'/", $src, $mm, PREG_SET_ORDER)) continue;
    foreach ($mm as $p) {
        $name = $p[1];
        if ($name[0] === '\\') $fq = ltrim($name, '\\');
        else { $first = explode('\\', $name)[0]; $fq = isset($uses[$first]) ? $uses[$first].substr($name, strlen($first)) : (($ns[1] ?? '').'\\'.$name); }
        if (!preg_match('/^(Illuminate|Symfony|Stripe|Filament|Livewire|Laravel|Inertia|Composer|Carbon|PDO)\b/', $fq)) continue;
        $pairs[$fq.'::'.$p[2]][] = str_replace(getcwd().'/', '', $f->getPathname());
    }
}
ksort($pairs);
$missing = 0;
foreach ($pairs as $key => $files) {
    [$class, $member] = explode('::', $key);
    if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) { echo "CLASS-MISSING $key\n"; $missing++; continue; }
    $r = new ReflectionClass($class);
    $kind = $r->hasProperty($member) ? 'property' : ($r->hasMethod($member) ? 'method' : ($r->hasConstant($member) ? 'constant' : null));
    if ($kind === null) { echo "NOT-A-MEMBER $key (".count(array_unique($files))." files, e.g. ".$files[0].")\n"; $missing++; continue; }
    echo "ok $kind $key\n";
}
echo count($pairs)." distinct vendor class/member pairs; ".$missing." not declared\n";
