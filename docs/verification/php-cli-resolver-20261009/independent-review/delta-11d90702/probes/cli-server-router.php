<?php
require '/home/user/wt-m16/vendor/autoload.php';
header('Content-Type: text/plain');
$b = new App\Support\PhpCliBinary(null);
echo 'sapi=', PHP_SAPI, "\nPHP_BINARY=", PHP_BINARY, "\nisCli=", var_export($b->isCli(), true), "\npath=", $b->path(), "\n";
$p = new Symfony\Component\Process\Process([$b->path(), '-n', '-r', 'echo PHP_SAPI, " ", PHP_VERSION;'], null, ['LANG'=>'C']);
$p->run(); echo 'child=', $p->getOutput(), ' rc=', $p->getExitCode(), "\n";
try { (new App\Support\PhpCliBinary(null, 'fpm-fcgi'))->path(); } catch (App\Support\PhpCliBinaryUnavailable $e) { echo 'fpm-unset=', $e->getMessage(), "\n"; }
