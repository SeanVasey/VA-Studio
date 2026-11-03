<?php

use App\Domain\Customers\ProductionIdentity\CatalogSocketBinding;
use App\Domain\Customers\ProductionIdentity\CurrentCatalog;

// Fixed isolated pure CLI fixture: no application bootstrap, PDO, network, provider or filesystem read.
$target = $argv[1] ?? '';
if (! in_array($target, ['stream_wrapper_restore', 'set_error_handler', 'fopen', 'opendir'], true)) {
    exit(2);
}
$GLOBALS['catalog_replacement_calls'] = 0;
if ($target === 'stream_wrapper_restore') {
    function stream_wrapper_restore(...$args): bool
    {
        $GLOBALS['catalog_replacement_calls']++;

        return true;
    }
} elseif ($target === 'set_error_handler') {
    function set_error_handler(...$args): mixed
    {
        $GLOBALS['catalog_replacement_calls']++;

        return null;
    }
} elseif ($target === 'fopen') {
    function fopen(...$args): false
    {
        $GLOBALS['catalog_replacement_calls']++;

        return false;
    }
} else {
    function opendir(...$args): false
    {
        $GLOBALS['catalog_replacement_calls']++;

        return false;
    }
}
require dirname(__DIR__, 4).'/vendor/autoload.php';
$gate = new ReflectionMethod(CatalogSocketBinding::class, 'nativeProvider');
$refused = false;
try {
    $gate->invoke(null);
} catch (LogicException) {
    $refused = true;
}
$again = false;
try {
    $gate->invoke(null);
} catch (LogicException) {
    $again = true;
}
$attempts = CurrentCatalog::accounting()['connection_attempts'];
$ok = $refused && $again && $GLOBALS['catalog_replacement_calls'] === 0 && $attempts === 0;
echo json_encode(['refused' => $refused, 'tainted_retry_refused' => $again, 'replacement_calls' => $GLOBALS['catalog_replacement_calls'], 'constructor_attempts' => $attempts, 'database_connections' => 0, 'network_connections' => 0], JSON_THROW_ON_ERROR)."\n";
exit($ok ? 0 : 1);
