<?php
require '/home/user/wt-m16/vendor/autoload.php';
use App\Support\{PhpCliBinary, PhpCliProcess};
foreach (array_slice($argv, 1) as $bin) {
    $b = new PhpCliBinary($bin, 'fpm-fcgi');
    $libs = PhpCliProcess::libraries(realpath($bin));
    try { $path = $b->path(); } catch (Throwable $e) { echo "$bin probe REFUSED\n"; continue; }
    $f = PhpCliProcess::factory($b, '/usr/sbin/php-fpm8.4');
    $proc = $f(['/usr/sbin/php-fpm8.4', '-n', '-r', 'echo 1;'], sys_get_temp_dir(), ['LANG' => 'C', 'LD_LIBRARY_PATH' => '/evil'], '');
    $env = $proc->getEnv();
    echo "$bin\n  probe-rule libraries=", json_encode($libs), "\n  child argv0=", explode(' ', $proc->getCommandLine())[0], "\n  child LD_LIBRARY_PATH=", var_export($env['LD_LIBRARY_PATH'] ?? null, true), "\n";
}
echo "colon split demo: ", json_encode(explode(PATH_SEPARATOR, implode(PATH_SEPARATOR, PhpCliProcess::libraries($argv[0] === '' ? '' : getenv('COLON_BIN'))))), "\n";
