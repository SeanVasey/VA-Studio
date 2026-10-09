<?php
// argv[1]: 'old' loads the 214256a4 resolver first, 'new' uses head; argv[2..]: configured paths
if ($argv[1] === 'old') { require $argv[2]; }
require '/home/user/wt-m16/vendor/autoload.php';
foreach (array_slice($argv, 3) as $configured) {
    try { $p = (new App\Support\PhpCliBinary($configured, 'fpm-fcgi'))->path(); echo "$configured => OK $p\n"; }
    catch (App\Support\PhpCliBinaryUnavailable $e) { echo "$configured => REFUSED ", $e->getMessage(), " prev=", var_export($e->getPrevious() !== null, true), "\n"; }
}
