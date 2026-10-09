<?php

// Independent-review probe: in ONE long-lived process with a simulated non-CLI SAPI, does a replaced binary re-probe?
// Cases: (1) atomic rename of a different-version file over the validated path; (2) in-place rewrite preserving size and
// mtime (ctime still changes); (3) symlink retarget. Also checks PhpCliProcess::factory refusals and pass-through.
require '/home/user/rv-m16/vendor/autoload.php';

use App\Support\PhpCliBinary;
use App\Support\PhpCliBinaryUnavailable;
use App\Support\PhpCliProcess;

$dir = sys_get_temp_dir().'/review-cache-'.bin2hex(random_bytes(4));
mkdir($dir, 0700);
$build = (PHP_ZTS ? '1' : '0').(PHP_DEBUG ? '1' : '0');
$fake = function (string $path, string $version) use ($build): void {
    file_put_contents($path, "#!/bin/sh\necho run >> ".escapeshellarg($path.'.runs')."\nprintf '%s' ".escapeshellarg("cli $version $build")."\n");
    chmod($path, 0700);
};
$verdict = function (string $configured): string {
    try {
        return 'ok '.basename((new PhpCliBinary($configured, 'fpm-fcgi', '/usr/sbin/php-fpm8.4'))->path());
    } catch (PhpCliBinaryUnavailable $e) {
        return 'refused '.$e->reason;
    }
};
$runs = fn (string $p) => is_file($p.'.runs') ? count(file($p.'.runs')) : 0;

$bin = $dir.'/php-cli';
$fake($bin, PHP_VERSION);
echo "1a first:            ", $verdict($bin), " probes=", $runs($bin), "\n";
echo "1b cached:           ", $verdict($bin), " probes=", $runs($bin), "\n";
$fake($dir.'/next', '8.4.99');
rename($dir.'/next', $bin);
echo "1c renamed-over 8.4.99: ", $verdict($bin), " probes=", $runs($bin), "\n";

$fake($bin, PHP_VERSION);
echo "2a restored:         ", $verdict($bin), "\n";
$stat = stat($bin);
$contents = file_get_contents($bin);
$swapped = str_replace(PHP_VERSION, str_pad('8.4.0', strlen(PHP_VERSION), '0'), $contents);
file_put_contents($bin, $swapped);
touch($bin, $stat['mtime']);
clearstatcache();
$after = stat($bin);
printf("2b in-place rewrite: size same=%s mtime same=%s ctime same=%s -> %s\n", $after['size'] === $stat['size'] ? 'yes' : 'no',
    $after['mtime'] === $stat['mtime'] ? 'yes' : 'no', $after['ctime'] === $stat['ctime'] ? 'yes' : 'no', $verdict($bin));

$good = $dir.'/good';
$fake($good, PHP_VERSION);
$bad = $dir.'/bad';
$fake($bad, '8.3.6');
$link = $dir.'/php';
symlink($good, $link);
echo "3a link->good:       ", $verdict($link), "\n";
unlink($link);
symlink($bad, $link);
echo "3b link->bad:        ", $verdict($link), "\n";

// Factory: refusals and exact pass-through.
$factory = PhpCliProcess::factory(new PhpCliBinary(PHP_BINARY, 'fpm-fcgi', '/usr/sbin/php-fpm8.4'), '/usr/sbin/php-fpm8.4');
foreach ([['/bin/sh', '-n', '-c', 'id'], ['/usr/sbin/php-fpm8.4', '-r', 'echo 1;'], ['/usr/sbin/php-fpm8.4'], []] as $command) {
    try {
        $factory($command, '/', [], '');
        echo "4 factory accepted ", json_encode($command), "\n";
    } catch (PhpCliBinaryUnavailable $e) {
        echo "4 factory refused  ", json_encode($command), " reason=", $e->reason, "\n";
    }
}
$process = $factory(['/usr/sbin/php-fpm8.4', '-n', '-d', 'x=1', '/script.php'], '/cwd', ['A' => 'b', 'LD_LIBRARY_PATH' => '/hostile', 'Z' => false], 'stdin-bytes');
$env = (new ReflectionProperty($process, 'env'))->getValue($process);
echo "4 factory built: ", json_encode((new ReflectionProperty($process, 'commandline'))->getValue($process)), " cwd=", $process->getWorkingDirectory(),
    " timeout=", $process->getTimeout(), " input=", $process->getInput(), " env=", json_encode($env), "\n";
echo "4 libraries(realpath(PHP_BINARY))=", json_encode(PhpCliProcess::libraries(realpath(PHP_BINARY))), " libraries(/opt/none/bin/php)=", json_encode(PhpCliProcess::libraries('/opt/none/bin/php')), "\n";

array_map('unlink', glob($dir.'/*'));
rmdir($dir);
