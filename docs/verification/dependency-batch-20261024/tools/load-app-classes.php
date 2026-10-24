<?php
// Loads every class-like file under app/ so PHP checks inheritance and signature compatibility against vendor.
require getcwd().'/vendor/autoload.php';
$n = 0; $fail = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(getcwd().'/app'));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $rel = substr($f->getPathname(), strlen(getcwd().'/app/'), -4);
    $class = 'App\\'.str_replace('/', '\\', $rel);
    try {
        if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) { $n++; }
        else { echo "NOT-FOUND $class\n"; }
    } catch (Throwable $e) { $fail++; echo "ERROR $class: ".$e->getMessage()."\n"; }
}
echo "loaded $n; errors $fail\n";
