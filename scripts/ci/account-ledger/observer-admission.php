<?php

// Private credential loading only, from independently ROOT-created read-only mounts.
// This descriptor admits an observer probe, never candidate application execution.
requireProduct(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === $ip
    && preg_match('/\A[a-f0-9]{64}\z/D', $admissionPin) === 1);
$readOwned = static function (string $path, int $mode, int $maximum): array {
    requireProduct(realpath($path) === $path && !is_link($path));
    $link = lstat($path);
    $stream = fopen($path, 'rb');
    requireProduct(is_array($link) && is_resource($stream));
    try {
        $before = fstat($stream);
        requireProduct(is_array($before) && ($before['mode'] & 0170000) === 0100000
            && ($before['mode'] & 0777) === $mode && $before['uid'] === 0 && $before['gid'] === 1000
            && $before['nlink'] === 1 && $before['size'] <= $maximum
            && sameProductFile($link, $before));
        $bytes = stream_get_contents($stream, $maximum + 1);
        requireProduct(is_string($bytes) && strlen($bytes) <= $maximum && feof($stream)
            && sameProductFile($before, fstat($stream)));
        return [$bytes, $before];
    } finally {
        fclose($stream);
    }
};
[$admissionBytes] = $readOwned('/admission/observer.json', 0444, 16384);
requireProduct(hash('sha256', $admissionBytes) === $admissionPin);
$admission = json_decode($admissionBytes, true, 16, JSON_THROW_ON_ERROR);
requireProduct(is_array($admission) && array_keys($admission) === ['schema', 'kind', 'native_admitted',
    'container_id', 'server_uuid', 'ip', 'secret_metadata']
    && $admission['schema'] === 1 && $admission['kind'] === 'owned-observer-qualification-only'
    && $admission['native_admitted'] === false && $admission['ip'] === $ip
    && preg_match('/\A[0-9a-f]{64}\z/D', $admission['container_id']) === 1
    && preg_match('/\A[0-9a-f-]{36}\z/D', $admission['server_uuid']) === 1);
[$password, $secretStat] = $readOwned('/admission/fixture-password', 0440, 128);
requireProduct(strlen($password) >= 32 && !str_contains($password, "\n"));
foreach (['dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size'] as $key) {
    requireProduct($secretStat[$key] === $admission['secret_metadata'][$key]);
}
unset($readOwned, $admissionBytes, $secretStat, $link, $stream, $before, $bytes);
