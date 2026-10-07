<?php

// Deliberately omit Composer's application-wide autoload files and all Laravel bootstrap hooks.
$root = dirname(__DIR__);
require $root.'/vendor/composer/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader($root.'/vendor');
$namespaces = require $root.'/vendor/composer/autoload_psr4.php';
foreach ($namespaces as $prefix => $paths) {
    if (str_starts_with($prefix, 'Com\\Tecnick\\')) {
        $loader->setPsr4($prefix, $paths);
    }
}
foreach (['ContractRenderer', 'RenderedContract', 'ContractIssuanceException', 'ContractIssuancePolicy',
    'ContractRenderProfile', 'ContractRenderProfileRegistry', 'ContractText', 'TcpdfContractRenderer',
    'TcpdfV2ContractRenderer', 'VersionedContractRenderer'] as $name) {
    $loader->addClassMap(['App\\Domain\\Contracts\\'.$name => $root.'/app/Domain/Contracts/'.$name.'.php']);
}
$loader->addClassMap([
    'App\\Support\\CanonicalJson' => $root.'/app/Support/CanonicalJson.php',
    'Composer\\InstalledVersions' => $root.'/vendor/composer/InstalledVersions.php',
]);
$loader->register();
