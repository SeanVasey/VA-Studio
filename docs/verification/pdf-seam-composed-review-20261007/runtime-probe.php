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
foreach ([App\Domain\Contracts\ContractRenderProfile::class, App\Domain\Contracts\TcpdfV2ContractRenderer::class,
    App\Domain\Contracts\VersionedContractRenderer::class, Tests\Feature\TestContractProfileSuccessorTest::class] as $class) {
    $file = (new ReflectionClass($class))->getFileName();
    if (! str_starts_with($file, $root.'/')) {
        throw new RuntimeException('Foreign autoload source.');
    }
    $origins[$class] = $file;
}
echo json_encode(['runtime' => PHP_VERSION, 'source_root' => $root, 'verified_packages' => count($graph),
    'graph' => $graph, 'origins' => $origins, 'current_profile' => App\Domain\Contracts\ContractRenderProfile::VERSION,
    'current_policy' => App\Domain\Contracts\ContractIssuancePolicy::CONTRACT], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
