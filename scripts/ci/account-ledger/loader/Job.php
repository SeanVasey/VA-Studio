<?php
declare(strict_types=1);

namespace AccountLedgerPortable;

use RuntimeException;

/** ROOT-issued closed admission; workers have no producer or write authority. */
final class Job
{
    private static function need(bool $ok): void
    {
        if (!$ok) {
            throw new RuntimeException('Ledger job admission refused.');
        }
    }

    private static function owned(string $path, int $mode, int $maximum): array
    {
        self::need(realpath($path) === $path && !is_link($path) && !is_writable($path));
        $link = lstat($path);
        $stream = fopen($path, 'rb');
        self::need(is_array($link) && is_resource($stream));
        try {
            $before = fstat($stream);
            self::need(is_array($before) && ($before['mode'] & 0170000) === 0100000
                && ($before['mode'] & 0777) === $mode && $before['uid'] === 0 && $before['gid'] === 1000
                && $before['nlink'] === 1 && $before['size'] <= $maximum);
            foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
                self::need($before[$key] === $link[$key]);
            }
            $bytes = stream_get_contents($stream, $maximum + 1);
            self::need(is_string($bytes) && strlen($bytes) <= $maximum && feof($stream));
            $after = fstat($stream);
            foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
                self::need($before[$key] === $after[$key]);
            }
            return [$bytes, $before];
        } finally {
            fclose($stream);
        }
    }

    public static function load(): array
    {
        self::need(\posix_geteuid() === 1000 && \posix_getegid() === 1000
            && !is_link('/admission') && realpath('/admission') === '/admission'
            && fileowner('/admission') === 0 && !is_writable('/admission'));
        [$raw] = self::owned('/admission/job.json', 0444, 16384);
        $pin = getenv('VA_ACCOUNT_LEDGER_ADMISSION_PIN');
        self::need(is_string($pin) && preg_match('/\A[0-9a-f]{64}\z/D', $pin) === 1
            && hash('sha256', $raw) === $pin);
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::need(is_array($value) && array_keys($value) === ['schema', 'kind', 'native_admitted',
            'candidate_sha', 'case', 'source_tree_oid', 'binding', 'service', 'runtime', 'qualification', 'dependency', 'secret_metadata']
            && $value['schema'] === 1 && $value['kind'] === 'required-supervised-ledger-job'
            && $value['native_admitted'] === true && preg_match('/\A[0-9a-f]{40}\z/D', $value['candidate_sha']) === 1
            && preg_match('/\A[0-9a-f]{40}\z/D', $value['source_tree_oid']) === 1
            && in_array($value['case'], ['up-overlap', 'down-overlap', 'up-end', 'down-end'], true));
        self::need($value['binding'] === ['candidate_root' => '/candidate', 'canonical_root' => '/dependencies',
            'vendor_realpath' => '/dependencies/vendor']);
        self::need(array_keys($value['service']) === ['container_id', 'server_uuid', 'ip', 'runtime_image']
            && preg_match('/\A[0-9a-f]{64}\z/D', $value['service']['container_id']) === 1
            && preg_match('/\A[0-9a-f-]{36}\z/D', $value['service']['server_uuid']) === 1
            && filter_var($value['service']['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === $value['service']['ip']
            && preg_match('/\Asha256:[0-9a-f]{64}\z/D', $value['service']['runtime_image']) === 1);
        self::need($value['qualification'] === ['controller_profile' => true, 'runtime_profile' => true,
            'source_verified' => true, 'same_account_observer' => true, 'locked_dependencies' => true]);
        self::need(array_keys($value['runtime']) === ['php_version', 'php_binary_sha256', 'python_version', 'python_binary_sha256']
            && $value['runtime']['php_version'] === '8.4.27' && $value['runtime']['python_version'] === '3.13.5');
        foreach (['php_binary_sha256', 'python_binary_sha256'] as $key) {
            self::need(is_string($value['runtime'][$key]) && preg_match('/\A[0-9a-f]{64}\z/D', $value['runtime'][$key]) === 1);
        }
        self::need(array_keys($value['dependency']) === ['manifest_sha256', 'composer_lock_sha256']);
        foreach ($value['dependency'] as $digest) {
            self::need(is_string($digest) && preg_match('/\A[0-9a-f]{64}\z/D', $digest) === 1);
        }
        self::need(array_keys($value['secret_metadata']) === ['dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size']);
        foreach ($value['secret_metadata'] as $number) { self::need(is_int($number)); }
        self::need(hash_file('sha256', '/candidate/composer.lock') === $value['dependency']['composer_lock_sha256']);
        return $value;
    }

    public static function password(array $record): string
    {
        [$password, $metadata] = self::owned('/admission/fixture-password', 0440, 128);
        self::need(strlen($password) >= 32 && !str_contains($password, "\n"));
        foreach (['dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size'] as $key) {
            self::need($metadata[$key] === $record['secret_metadata'][$key]);
        }
        return $password;
    }
}
