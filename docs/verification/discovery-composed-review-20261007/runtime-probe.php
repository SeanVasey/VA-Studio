<?php

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$lock = json_decode(file_get_contents($root.'/composer.lock'), true, 128, JSON_THROW_ON_ERROR);
$graph = [];
foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
    $name = $package['name'];
    $reference = $package['source']['reference'] ?? $package['dist']['reference'] ?? null;
    if (Composer\InstalledVersions::getPrettyVersion($name) !== $package['version']
        || Composer\InstalledVersions::getReference($name) !== $reference) {
        throw new RuntimeException('Installed graph differs from lock: '.$name);
    }
    $graph[$name] = ['version' => $package['version'], 'reference' => $reference];
}
$origins = [];
foreach ([App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot::class,
    App\Domain\Catalog\Discovery\DiscoveryEpoch::class,
    App\Domain\Catalog\PublicationReadiness::class,
    App\Domain\Commerce\Inventory\SelectionInventory::class,
    Tests\Feature\CurrentEligibleTrackSnapshotTest::class] as $class) {
    $path = (new ReflectionClass($class))->getFileName();
    if (! str_starts_with($path, $root.'/')) {
        throw new RuntimeException('Foreign application/test autoload source.');
    }
    $origins[$class] = $path;
}
echo json_encode(['runtime' => PHP_VERSION, 'source_root' => $root,
    'verified_packages' => count($graph), 'graph' => $graph,
    'origins' => $origins], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
