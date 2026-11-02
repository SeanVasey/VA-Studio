<?php

namespace App\Domain\Customers\ProductionIdentity;

use LogicException;
use PDO;
use ReflectionClass;
use ReflectionFunction;
use Throwable;

/** Linux/mysqlnd direct literal-IP TCP only; no connection, packet IO, provider injection or other PID. */
final class CatalogSocketBinding
{
    private static bool $tainted = false;

    private const MAX_BYTES = 1048576;

    private const MAX_ROWS = 8192;

    private const MAX_FDS = 4096;

    public static function requireUntainted(): void
    {
        if (self::$tainted) {
            self::refuse();
        }
        self::internalFunctions();
    }

    private static function internalFunctions(): void
    {
        // Disabled functions may be redeclared in PHP. Qualification alone does not make them native.
        foreach (['stream_wrapper_restore', 'set_error_handler', 'restore_error_handler', 'stream_context_create',
            'stream_context_get_options', 'stream_context_get_params', 'opendir', 'readdir', 'readlink', 'closedir',
            'fopen', 'stream_get_meta_data', 'stream_get_contents', 'feof', 'fclose', 'is_resource', 'is_string',
            'is_int', 'is_array', 'is_object', 'is_numeric', 'in_array', 'count', 'array_is_list', 'array_all',
            'array_values', 'array_unique', 'array_shift', 'array_slice', 'array_map', 'array_keys', 'array_column',
            'array_fill', 'array_fill_keys', 'array_merge', 'array_combine', 'array_diff', 'array_key_exists',
            'preg_match', 'preg_split', 'preg_replace_callback', 'inet_pton', 'inet_ntop', 'pack', 'hexdec',
            'explode', 'implode', 'rtrim', 'trim', 'str_split', 'strlen', 'substr', 'substr_count', 'str_replace',
            'strpbrk', 'str_contains', 'str_starts_with', 'str_ends_with', 'filter_var', 'ksort', 'hrtime'] as $name) {
            try {
                if (! (new ReflectionFunction($name))->isInternal()) {
                    self::$tainted = true;
                    self::refuse();
                }
            } catch (Throwable) {
                self::$tainted = true;
                self::refuse();
            }
        }
    }

    public static function attest(PDO $primary, #[\SensitiveParameter] array $writer, array $context, string $status): array
    {
        self::requireUntainted();
        if (\PHP_VERSION !== '8.4.27' || \PHP_OS_FAMILY !== 'Linux' || ! \in_array($primary::class, [PDO::class, 'Pdo\\Mysql'], true)
            || ! (new ReflectionClass($primary))->isInternal()
            || ! \str_starts_with((string) $primary->getAttribute(PDO::ATTR_CLIENT_VERSION), 'mysqlnd ')) {
            self::refuse();
        }
        // Refuse unsupported descriptor/provider profiles before any filesystem access or constructor.
        self::requested($writer, $context, $status);
        [$inodes, $tcp, $tcp6] = self::snapshot();

        return self::prove($writer, $context, $status, $inodes, $tcp, $tcp6);
    }

    /** Pure proof over bounded native snapshots. This method never constructs a PDO or accepts a provider/path. */
    public static function prove(#[\SensitiveParameter] array $writer, array $context, string $status, array $inodes, string $tcp, string $tcp6): array
    {
        [$host, $port, $client, $sourcePort] = self::requested($writer, $context, $status);
        if ($inodes === [] || \count($inodes) > self::MAX_FDS || ! \array_is_list($inodes)) {
            self::refuse();
        }
        $owned = [];
        foreach ($inodes as $inode) {
            if (! \is_string($inode) || \preg_match('/\A[1-9][0-9]{0,19}\z/D', $inode) !== 1) {
                self::refuse();
            }
            $owned[$inode] = true;
        }
        $matches = [];
        $seen = [];
        foreach ([...self::table($tcp, 8, $owned), ...self::table($tcp6, 32, $owned)] as $row) {
            if (isset($seen[$row['inode']])) {
                self::refuse();
            }
            $seen[$row['inode']] = true;
            // Choose primary by its OWN client endpoint FIRST, never by whichever socket matches the request.
            if ($row['state'] === '01' && $row['local_port'] === $sourcePort && \inet_pton($row['local_ip']) === \inet_pton($client)) {
                $matches[$row['inode']] = $row;
            }
        }
        if (\count($matches) !== 1) {
            self::refuse();
        }
        $actual = \array_values($matches)[0];
        if (\inet_pton($actual['remote_ip']) !== \inet_pton($host) || $actual['remote_port'] !== $port) {
            self::refuse();
        }

        return $actual;
    }

    private static function requested(#[\SensitiveParameter] array $writer, array $context, string $status): array
    {
        $host = $writer['host'] ?? null;
        $port = $writer['port'] ?? null;
        $clientEndpoint = $context['client_endpoint'] ?? null;
        $serverPort = $context['server_port'] ?? null;
        if (($writer['socket'] ?? null) !== '' || ! \is_string($host) || \filter_var($host, \FILTER_VALIDATE_IP) === false
            || ! \is_int($port) || $port < 1 || $port > 65535 || ! \is_int($serverPort) || $serverPort !== $port
            || ! \str_ends_with($status, ' via TCP/IP') || ! \is_string($clientEndpoint)
            || \preg_match('/\A(.+):([1-9][0-9]{0,4})\z/D', $clientEndpoint, $match) !== 1 || (int) $match[2] > 65535) {
            self::refuse();
        }
        $client = $match[1];
        if (\str_starts_with($client, '[') && \str_ends_with($client, ']')) {
            $client = \substr($client, 1, -1);
        }
        $statusHost = \substr($status, 0, -\strlen(' via TCP/IP'));
        if (\filter_var($client, \FILTER_VALIDATE_IP) === false || \filter_var($statusHost, \FILTER_VALIDATE_IP) === false
            || \inet_pton($statusHost) !== \inet_pton($host)) {
            self::refuse();
        }

        return [$host, $port, $client, (int) $match[2]];
    }

    private static function table(string $text, int $width, array $owned): array
    {
        if (\strlen($text) > self::MAX_BYTES || \str_contains($text, "\0")) {
            self::refuse();
        }
        $lines = \explode("\n", \rtrim($text, "\n"));
        if (\count($lines) > self::MAX_ROWS || \preg_match('/\A\s*sl\s+local_address\s+(?:rem_address|remote_address)\s+st\s+tx_queue\s+rx_queue\s+tr\s+tm->when\s+retrnsmt\s+uid\s+timeout\s+inode\s*\z/D', \array_shift($lines)) !== 1) {
            self::refuse();
        }
        $rows = [];
        foreach ($lines as $line) {
            if (\strlen($line) > 512) {
                self::refuse();
            }
            $fields = \preg_split('/\s+/', \trim($line));
            if (\count($fields) < 10 || \count($fields) > 20 || \preg_match('/\A[0-9]+:\z/D', $fields[0]) !== 1
                || \preg_match('/\A[0-9]+\z/D', $fields[9]) !== 1) {
                self::refuse();
            }
            if (! isset($owned[$fields[9]])) {
                continue;
            }
            if (\preg_match('/\A[0-9A-F]{2}\z/D', $fields[3]) !== 1
                || \preg_match('/\A[0-9A-F]{8}:[0-9A-F]{8}\z/D', $fields[4]) !== 1
                || \preg_match('/\A[0-9A-F]{2}:[0-9A-F]{8}\z/D', $fields[5]) !== 1
                || \preg_match('/\A[0-9A-F]{8}\z/D', $fields[6]) !== 1
                || \preg_match('/\A[0-9]+\z/D', $fields[7]) !== 1 || \preg_match('/\A[0-9]+\z/D', $fields[8]) !== 1) {
                self::refuse();
            }
            if ($fields[3] === '01') {
                if (\count($fields) !== 17 || \preg_match('/\A[0-9]+\z/D', $fields[10]) !== 1 || \preg_match('/\A[0-9A-Fa-f]{8,16}\z/D', $fields[11]) !== 1) {
                    self::refuse();
                }
                foreach (\array_slice($fields, 12) as $extra) {
                    if (\preg_match('/\A-?[0-9]+\z/D', $extra) !== 1) {
                        self::refuse();
                    }
                }
            }
            [$local, $localPort] = self::endpoint($fields[1], $width);
            [$remote, $remotePort] = self::endpoint($fields[2], $width);
            $rows[] = ['inode' => $fields[9], 'state' => $fields[3], 'local_ip' => $local, 'local_port' => $localPort, 'remote_ip' => $remote, 'remote_port' => $remotePort];
        }

        return $rows;
    }

    private static function endpoint(string $value, int $width): array
    {
        if (\preg_match('/\A([0-9A-F]{'.$width.'}):([0-9A-F]{4})\z/D', $value, $parts) !== 1) {
            self::refuse();
        }
        $binary = '';
        foreach (\str_split($parts[1], 8) as $word) {
            // /proc prints each native-endian 32-bit address word; pack restores network bytes.
            $binary .= \pack('L', \hexdec($word));
        }
        $ip = \inet_ntop($binary);
        $port = \hexdec($parts[2]);
        if ($ip === false || $port > 65535) {
            self::refuse();
        }

        return [$ip, $port];
    }

    private static function nativeProvider(): void
    {
        self::requireUntainted();
        $seen = false;
        \set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            return self::providerNotice($severity, $message, $seen);
        });
        try {
            $result = \stream_wrapper_restore('file');
            self::providerOutcome($result, $seen);
        } catch (Throwable) {
            self::$tainted = true;
            self::refuse();
        } finally {
            if (! \restore_error_handler()) {
                self::$tainted = true;
                self::refuse();
            }
        }
    }

    private static function providerNotice(int $severity, string $message, bool &$seen): bool
    {
        if (! $seen && $severity === \E_NOTICE && $message === 'stream_wrapper_restore(): file:// was never changed, nothing to restore') {
            $seen = true;

            return true;
        }
        self::$tainted = true;
        self::refuse();
    }

    private static function providerOutcome(mixed $result, bool $seen): void
    {
        if ($result !== true || ! $seen) {
            // PHP may ALREADY have restored an altered request-local wrapper on this unsupported branch.
            // Poison survives repair: no later no-op can authorize a retry in this execution lifetime.
            self::$tainted = true;
            self::refuse();
        }
    }

    private static function freshContext(): mixed
    {
        self::requireUntainted();
        $context = \stream_context_create([]);
        if (! \is_resource($context) || \stream_context_get_options($context) !== []
            || \stream_context_get_params($context) !== ['options' => []]) {
            self::refuse();
        }

        return $context;
    }

    private static function snapshot(): array
    {
        self::requireUntainted();
        // Fixed own handler covers provider attestation AND every proc operation; caller handler is never invoked.
        \set_error_handler(static function (): never {
            self::$tainted = true;
            self::refuse();
        });
        $directory = null;
        try {
            self::nativeProvider();
            $context = self::freshContext();
            $directory = \opendir('/proc/self/fd', $context);
            if (! \is_resource($directory) || (\stream_get_meta_data($directory)['wrapper_type'] ?? null) !== 'plainfile') {
                self::refuse();
            }
            $inodes = [];
            $count = 0;
            while (($entry = \readdir($directory)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (++$count > self::MAX_FDS || \preg_match('/\A[0-9]+\z/D', $entry) !== 1) {
                    self::refuse();
                }
                $target = \readlink('/proc/self/fd/'.$entry);
                if (! \is_string($target)) {
                    self::refuse();
                }
                if (\preg_match('/\Asocket:\[([1-9][0-9]{0,19})\]\z/D', $target, $match) === 1) {
                    $inodes[] = $match[1];
                }
            }

            return [\array_values(\array_unique($inodes)), self::fixedFile(false, $context), self::fixedFile(true, $context)];
        } catch (Throwable) {
            self::$tainted = true;
            self::refuse();
        } finally {
            try {
                if (\is_resource($directory)) {
                    \closedir($directory);
                }
            } finally {
                if (! \restore_error_handler()) {
                    self::$tainted = true;
                    self::refuse();
                }
            }
        }
    }

    private static function fixedFile(bool $ipv6, mixed $context): string
    {
        $file = \fopen($ipv6 ? '/proc/self/net/tcp6' : '/proc/self/net/tcp', 'rb', false, $context);
        if (! \is_resource($file)) {
            self::refuse();
        }
        try {
            if ((\stream_get_meta_data($file)['wrapper_type'] ?? null) !== 'plainfile') {
                self::refuse();
            }
            $text = \stream_get_contents($file, self::MAX_BYTES + 1);
            if (! \is_string($text) || \strlen($text) > self::MAX_BYTES || ! \feof($file)) {
                self::refuse();
            }

            return $text;
        } finally {
            if (! \fclose($file)) {
                self::$tainted = true;
                self::refuse();
            }
        }
    }

    private static function refuse(): never
    {
        throw new LogicException('Provable owned direct TCP metadata target required.');
    }
}
