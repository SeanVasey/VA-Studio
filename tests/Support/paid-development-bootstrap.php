<?php

// Explicit isolated development only. Integrated Composer source always takes priority.
$project = dirname(__DIR__, 2);
require_once $project.'/vendor/autoload.php';
if (! is_file($project.'/app/Domain/Commerce/ProductionCheckout/ProductionPaidOrderSourceV1.php')) {
    $snapshot = getenv('VA_PAID_DEVELOPMENT_DEPENDENCY');
    if (! is_string($snapshot) || $snapshot === '' || ! is_file($snapshot.'/autoload.php')) {
        throw new LogicException('An explicit attributed development dependency is required for this isolated lane.');
    }
    require_once $snapshot.'/autoload.php';
}
