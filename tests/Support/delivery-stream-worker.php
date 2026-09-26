<?php

use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) { throw new RuntimeException('Only isolated synthetic streaming is supported.'); }
$payload = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $payload['root'], 'visibility' => 'private', 'serve' => false]]);
$root = $payload['root'];
if ($payload['action'] === 'crash_copy') {
    $adapter = new class extends PrepareTestDeliveryStream {
        protected function copyTarget(array $target, $destination, int $deadline): void { fwrite($destination, 'CRASH RESIDUE'); exit(73); }
    };
    $adapter->handle($payload['target']);
    throw new RuntimeException('Synthetic crash did not occur.');
}
if (in_array($payload['action'], ['hold', 'once', 'short_write'], true)) {
    if ($payload['action'] === 'short_write') {
        pcntl_signal(SIGXFSZ, SIG_IGN);
        if (! posix_setrlimit(POSIX_RLIMIT_FSIZE, 1024, 1024)) { throw new RuntimeException('Synthetic size limit failed.'); }
    }
    try {
        $prepared = app(PrepareTestDeliveryStream::class)->handle($payload['target']);
        if ($payload['action'] === 'hold') {
            file_put_contents($payload['barrier'].'/ready-'.$payload['worker'], (string) getmypid());
            $deadline = hrtime(true) + 15000000000;
            while (! is_file($payload['barrier'].'/release')) {
                if (hrtime(true) > $deadline) { throw new RuntimeException('Synthetic spool barrier expired.'); }
                usleep(10000); clearstatcache();
            }
        }
        $bytes = 0; $hash = hash_init('sha256');
        $prepared->writeTo(function (string $chunk) use (&$bytes, $hash): void { $bytes += strlen($chunk); hash_update($hash, $chunk); });
        echo json_encode(['outcome' => 'prepared', 'pid' => getmypid(), 'bytes' => $bytes,
            'matches' => hash_equals($payload['target']['sha256'], hash_final($hash))], JSON_THROW_ON_ERROR);
    } catch (DeliveryException $error) { echo json_encode(['outcome' => $error->reason, 'pid' => getmypid()], JSON_THROW_ON_ERROR); }
    exit;
}
if ($payload['action'] !== 'large') { throw new RuntimeException('Unsupported synthetic action.'); }
$relative = 'media/revisions/99999999-9999-4999-8999-999999999999/master.wav';
$directory = $root;
foreach (explode('/', dirname($relative)) as $part) { $directory .= '/'.$part; if (! is_dir($directory)) { mkdir($directory, 0700); } }
$path = $root.'/'.$relative; $output = fopen($path, 'xb'); $chunk = str_repeat('S', 1048576); $hash = hash_init('sha256');
for ($bytes = 0; $bytes < DeliveryAssetFiles::MAX_BYTES; $bytes += strlen($chunk)) {
    if (fwrite($output, $chunk) !== strlen($chunk)) { throw new RuntimeException('Synthetic fixture write failed.'); }
    hash_update($hash, $chunk);
}
fclose($output); chmod($path, 0400); unset($chunk); $expected = hash_final($hash);
$target = ['id' => 1, 'track_id' => 1, 'kind' => 'master_wav', 'role' => 'master_wav', 'disk' => 'local', 'scan_scope' => 'test-only',
    'storage_path' => $relative, 'sha256' => $expected, 'size_bytes' => DeliveryAssetFiles::MAX_BYTES];
$before = memory_get_usage(true); memory_reset_peak_usage();
$prepared = app(PrepareTestDeliveryStream::class)->handle($target); $received = 0; $hash = hash_init('sha256');
$prepared->writeTo(function (string $chunk) use (&$received, $hash): void { $received += strlen($chunk); hash_update($hash, $chunk); });
echo json_encode(['bytes' => $received, 'matches' => hash_equals($expected, hash_final($hash)),
    'peak_delta' => memory_get_peak_usage(true) - $before, 'named_snapshots' => count(glob($root.'/delivery/spool/*.snapshot'))], JSON_THROW_ON_ERROR);
