<?php

namespace Tests\Unit\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\CatalogWriterDescriptor;
use Illuminate\Support\ConfigurationUrlParser;
use LogicException;
use PDO;
use Pdo\Mysql;
use PHPUnit\Framework\TestCase;

final class CatalogWriterDescriptorTest extends TestCase
{
    private function config(): array
    {
        return ['driver' => 'mysql', 'url' => null, 'host' => '127.0.0.1', 'port' => '3306',
            'database' => 'synthetic_catalog', 'username' => 'synthetic', 'password' => '',
            'unix_socket' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'prefix_indexes' => true, 'strict' => true, 'engine' => null,
            'timezone' => '+00:00', 'options' => []];
    }

    public function test_canonical_tcp_and_laravel_socket_precedence_preserve_tls(): void
    {
        $raw = $this->config();
        $raw['options'] = [Mysql::ATTR_SSL_CA => '/synthetic/ca.pem', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true];
        $native = CatalogWriterDescriptor::validate($raw, $raw + ['name' => 'mysql']);
        self::assertSame('mysql:host=127.0.0.1;port=3306;dbname=synthetic_catalog;charset=utf8mb4', $native['dsn']);
        self::assertSame('/synthetic/ca.pem', $native['options'][Mysql::ATTR_SSL_CA]);
        self::assertTrue($native['options'][Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
        self::assertFalse($native['options'][PDO::ATTR_EMULATE_PREPARES]);
        $raw['unix_socket'] = '/synthetic/mysql.sock';
        self::assertSame('mysql:unix_socket=/synthetic/mysql.sock;dbname=synthetic_catalog;charset=utf8mb4', CatalogWriterDescriptor::validate($raw, $raw)['dsn']);
    }

    public function test_empty_url_handoff_matches_real_laravel_no_url_parser_without_redirecting_writer(): void
    {
        foreach ([null, ''] as $url) {
            $raw = $this->config();
            $raw['url'] = $url;
            $resolved = (new ConfigurationUrlParser)->parseConfiguration($raw);
            self::assertArrayNotHasKey('url', $resolved);
            $native = CatalogWriterDescriptor::validate($raw, $resolved + ['name' => 'mysql']);
            self::assertSame('mysql:host=127.0.0.1;port=3306;dbname=synthetic_catalog;charset=utf8mb4', $native['dsn']);
            $raw['unix_socket'] = '/synthetic/mysql.sock';
            $raw['options'] = [Mysql::ATTR_SSL_CA => '/synthetic/ca.pem'];
            $resolved = (new ConfigurationUrlParser)->parseConfiguration($raw);
            $native = CatalogWriterDescriptor::validate($raw, $resolved);
            self::assertStringStartsWith('mysql:unix_socket=/synthetic/mysql.sock;', $native['dsn']);
            self::assertSame('/synthetic/ca.pem', $native['options'][Mysql::ATTR_SSL_CA]);
        }
    }

    public function test_untrusted_shapes_options_injection_and_writer_mismatch_refuse(): void
    {
        $changes = [
            ['host', ['127.0.0.1']], ['host', '127.0.0.1;dbname=foreign'], ['port', '3306;'], ['port', 0],
            ['database', 'owned;host=foreign'], ['database', "bad\0name"], ['url', 'mysql://foreign'], ['url', '0'], ['url', false],
            ['read', ['host' => 'foreign']], ['write', ['host' => 'foreign']], ['strict', false],
            ['timezone', '+01:00'], ['prefix', 'other_'], ['charset', 'latin1'],
            ['options', [Mysql::ATTR_INIT_COMMAND => 'SELECT 1']], ['options', [PDO::ATTR_PERSISTENT => true]], ['options', [PDO::ATTR_CASE => PDO::CASE_LOWER]],
            ['options', [PDO::ATTR_ORACLE_NULLS => PDO::NULL_TO_STRING]],
            ['options', [Mysql::ATTR_LOCAL_INFILE => true]], ['options', [Mysql::ATTR_MULTI_STATEMENTS => true]],
            ['options', [Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false]], ['options', [123456 => true]],
            ['options', [Mysql::ATTR_SSL_CA => new \stdClass]], ['unix_socket', '/tmp/db;dbname=foreign'],
        ];
        foreach ($changes as [$key, $value]) {
            $raw = $this->config();
            $raw[$key] = $value;
            try {
                CatalogWriterDescriptor::validate($raw, $raw);
                self::fail('Unsupported writer admitted: '.$key);
            } catch (LogicException $error) {
                self::assertSame('Canonical metadata writer configuration required.', $error->getMessage());
            }
        }
        $this->expectException(LogicException::class);
        $raw = $this->config();
        CatalogWriterDescriptor::validate($raw, array_replace($raw, ['host' => 'foreign']));
    }
}
