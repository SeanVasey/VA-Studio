<?php

namespace App\Domain\Customers\ProductionIdentity;

use LogicException;
use PDO;
use Pdo\Mysql;

/** Pure closed scalar writer validation; it never reads config, resolves services or connects. */
final class CatalogWriterDescriptor
{
    /** Returned secrets are solely the caller-supplied values, never a runtime-config accessor. */
    public static function validate(#[\SensitiveParameter] array $raw, #[\SensitiveParameter] array $resolved): array
    {
        $keys = ['driver', 'url', 'host', 'port', 'database', 'username', 'password', 'unix_socket', 'charset', 'collation', 'prefix', 'prefix_indexes', 'strict', 'engine', 'timezone', 'options', 'name'];
        if (\array_diff(\array_keys($raw), $keys) !== [] || \array_diff(\array_keys($resolved), $keys) !== []) {
            self::refuse();
        }
        foreach ($keys as $key) {
            if (! \in_array($key, ['name', 'url'], true) && ($raw[$key] ?? null) !== ($resolved[$key] ?? null)) {
                self::refuse();
            }
        }
        if (($resolved['driver'] ?? null) !== 'mysql' || ! \in_array($raw['url'] ?? null, [null, ''], true)
            || ! \in_array($resolved['url'] ?? null, [null, ''], true)
            || ($resolved['charset'] ?? null) !== 'utf8mb4' || ($resolved['collation'] ?? null) !== 'utf8mb4_unicode_ci'
            || ($resolved['prefix'] ?? null) !== '' || ($resolved['strict'] ?? null) !== true
            || ($resolved['timezone'] ?? null) !== '+00:00' || ($resolved['engine'] ?? null) !== null
            || (isset($resolved['prefix_indexes']) && $resolved['prefix_indexes'] !== true)) {
            self::refuse();
        }
        foreach (['database', 'username', 'password'] as $key) {
            if (! \is_string($resolved[$key] ?? null) || \str_contains($resolved[$key], "\0")) {
                self::refuse();
            }
        }
        if (\preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/D', $resolved['database']) !== 1 || $resolved['username'] === '') {
            self::refuse();
        }
        $socket = $resolved['unix_socket'] ?? '';
        if (! \is_string($socket) || \strpbrk($socket, ";\0\r\n") !== false) {
            self::refuse();
        }
        // Laravel's canonical socket takes precedence over otherwise unused host/port defaults.
        if ($socket !== '') {
            if (! \str_starts_with($socket, '/') || \strlen($socket) > 107) {
                self::refuse();
            }
            $transport = 'unix_socket='.$socket;
        } else {
            $host = $resolved['host'] ?? null;
            $port = $resolved['port'] ?? null;
            if (! \is_string($host) || \preg_match('/\A[A-Za-z0-9_.:-]{1,253}\z/D', $host) !== 1
                || (! \is_int($port) && (! \is_string($port) || \preg_match('/\A[0-9]{1,5}\z/D', $port) !== 1))
                || (int) $port < 1 || (int) $port > 65535) {
                self::refuse();
            }
            $transport = 'host='.$host.';port='.(int) $port;
        }
        $options = $resolved['options'] ?? [];
        if (! \is_array($options)) {
            self::refuse();
        }
        $tls = [Mysql::ATTR_SSL_CA, Mysql::ATTR_SSL_CAPATH, Mysql::ATTR_SSL_CERT, Mysql::ATTR_SSL_KEY, Mysql::ATTR_SSL_CIPHER];
        $fixed = [PDO::ATTR_CASE => PDO::CASE_NATURAL, PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_PERSISTENT => false,
            Mysql::ATTR_LOCAL_INFILE => false, Mysql::ATTR_MULTI_STATEMENTS => false,
            Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true];
        foreach ($options as $key => $value) {
            if (! \is_int($key)) {
                self::refuse();
            }
            if (\in_array($key, $tls, true)) {
                if (! \is_string($value) || $value === '' || \str_contains($value, "\0")) {
                    self::refuse();
                }
            } elseif (! \array_key_exists($key, $fixed) || $fixed[$key] !== $value) {
                self::refuse();
            }
        }
        // Preserve supported TLS options exactly. Fixed safe attributes cannot be overridden.
        $options += $fixed;
        $options[PDO::ATTR_TIMEOUT] = 2;

        return ['dsn' => 'mysql:'.$transport.';dbname='.$resolved['database'].';charset=utf8mb4',
            'username' => $resolved['username'], 'password' => $resolved['password'], 'options' => $options,
            'database' => $resolved['database'], 'socket' => $socket,
            'host' => $socket === '' ? $host : null, 'port' => $socket === '' ? (int) $port : null];
    }

    private static function refuse(): never
    {
        throw new LogicException('Canonical metadata writer configuration required.');
    }
}
