<?php

use Composer\Autoload\ClassLoader;

// Fixed child-only class map; no application bootstrap, Composer files, .env or database classes.
$root = dirname(__DIR__);
require $root.'/vendor/composer/ClassLoader.php';
$loader = new ClassLoader($root.'/vendor');
$namespaces = require $root.'/vendor/composer/autoload_psr4.php';
foreach ($namespaces as $prefix => $paths) {
    if (str_starts_with($prefix, 'Com\\Tecnick\\')) {
        $loader->setPsr4($prefix, $paths);
    }
}
foreach (['ContractRenderer', 'RenderedContract', 'ContractIssuanceException', 'ContractIssuancePolicy',
    'ContractRenderProfile', 'ContractRenderProfileRegistry'] as $name) {
    $loader->addClassMap(['App\\Domain\\Contracts\\'.$name => $root.'/app/Domain/Contracts/'.$name.'.php']);
}
foreach (['MemberGrantRenderProfileRegistry', 'MemberOriginalRenderInput', 'MemberOriginalText', 'MemberOriginalPdfRenderer'] as $name) {
    $loader->addClassMap(['App\\Domain\\Grants\\Member\\'.$name => $root.'/app/Domain/Grants/Member/'.$name.'.php']);
}
$loader->addClassMap([
    'App\\Support\\CanonicalJson' => $root.'/app/Support/CanonicalJson.php',
    'Composer\\InstalledVersions' => $root.'/vendor/composer/InstalledVersions.php',
]);
$loader->register();
