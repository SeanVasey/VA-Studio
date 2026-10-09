<?php
$root = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
var_export(config('filesystems.disks.local')); echo "\nbase=", base_path(), "\n";
try { $w = (new App\Domain\Contracts\ContractFiles)->createRendererWorkspace(); echo "ok ", $w->path, "\n"; } catch (Throwable $e) { echo get_class($e), ' ', $e->getMessage(), "\n"; }
