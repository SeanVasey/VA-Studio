<?php
// Review probe: how the c5395d7c admission rule classifies stream kinds an adapter might return.
// Rule: fstat mode S_IFREG -> admitted without timeout; otherwise admitted only if stream_set_blocking AND stream_set_timeout succeed.
$dir = sys_get_temp_dir().'/free256-kinds-'.bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents("$dir/plain.bin", str_repeat('x', 1000));
file_put_contents("$dir/plain.gz", gzencode(str_repeat('x', 1000)));
posix_mkfifo("$dir/fifo", 0600);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$address = stream_socket_get_name($server, false);
final class ReviewWrapper { public $context; private $done = false;
    public function stream_open($p, $m, $o, &$op) { return true; }
    public function stream_read($n) { if ($this->done) { return ''; } $this->done = true; return 'x'; }
    public function stream_eof() { return $this->done; }
    public function stream_stat() { return false; }
    public function stream_set_option($o, $a1, $a2) { return false; } }
stream_wrapper_register('reviewwrap', ReviewWrapper::class);
$kinds = [
    'plain file' => fn () => fopen("$dir/plain.bin", 'rb'),
    'php://temp' => function () { $h = fopen('php://temp', 'w+b'); fwrite($h, 'x'); rewind($h); return $h; },
    'php://memory' => function () { $h = fopen('php://memory', 'w+b'); fwrite($h, 'x'); rewind($h); return $h; },
    'compress.zlib:// file' => fn () => fopen("compress.zlib://$dir/plain.gz", 'rb'),
    'tcp socket (http-like)' => fn () => stream_socket_client("tcp://$address"),
    'FIFO (O_NONBLOCK open, r+)' => fn () => fopen("$dir/fifo", 'r+b'),
    'user stream wrapper' => fn () => fopen('reviewwrap://x', 'rb'),
    '/dev/zero (char device)' => fn () => fopen('/dev/zero', 'rb'),
];
$result = [];
foreach ($kinds as $label => $open) {
    $h = $open();
    $stat = @fstat($h);
    $mode = is_array($stat) ? $stat['mode'] : null;
    $regular = is_int($mode) && ($mode & 0170000) === 0100000;
    $blocking = $regular ? null : @stream_set_blocking($h, true);
    $timeout = $regular ? null : @stream_set_timeout($h, 5);
    $result[$label] = ['fstat_mode' => $mode === null ? null : sprintf('%o', $mode), 'regular' => $regular,
        'set_blocking' => $blocking, 'set_timeout' => $timeout, 'admitted' => $regular || ($blocking && $timeout)];
    fclose($h);
}
echo json_encode($result, JSON_PRETTY_PRINT), "\n";
foreach (glob("$dir/*") as $f) { unlink($f); }
rmdir($dir);
