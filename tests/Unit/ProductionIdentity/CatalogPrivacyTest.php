<?php

namespace Tests\Unit\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\CurrentCatalog;
use LogicException;
use Pdo\Mysql;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class CatalogPrivacyTest extends TestCase
{
    public function test_live_identity_comparison_requires_same_writer_principal_roles_and_schema(): void
    {
        $catalog = (new ReflectionClass(CurrentCatalog::class))->newInstanceWithoutConstructor();
        $compare = new ReflectionMethod(CurrentCatalog::class, 'compareIdentities');
        $primary = ['server_uuid' => 'writer', 'principal' => 'app@host', 'login' => 'app@client', 'roles' => 'NONE', 'database_name' => 'owned', 'connection_id' => 1];
        $companion = array_replace($primary, ['connection_id' => 2]);
        $compare->invoke($catalog, $companion, $primary);
        self::assertTrue(true);
        foreach (['server_uuid', 'principal', 'login', 'roles', 'database_name'] as $field) {
            $bad = $companion;
            $bad[$field] = 'foreign';
            try {
                $compare->invoke($catalog, $bad, $primary);
                self::fail('Foreign live identity accepted: '.$field);
            } catch (LogicException $error) {
                self::assertSame('Current private catalog admission required.', $error->getMessage());
            }
        }
    }

    public function test_primary_tls_cannot_lose_all_tls_material_before_any_constructor_attempt(): void
    {
        $check = new ReflectionMethod(CurrentCatalog::class, 'tlsRequest');
        $tls = ['Ssl_cipher' => 'TLS_AES_256_GCM_SHA384', 'Ssl_version' => 'TLSv1.3'];
        $supported = ['options' => [Mysql::ATTR_SSL_CA => '/synthetic/ca.pem', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true]];
        $check->invoke(null, $supported, $tls);
        self::assertTrue(true);
        foreach ([['options' => [Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true]], ['options' => [Mysql::ATTR_SSL_CA => '/synthetic/ca.pem', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false]]] as $removed) {
            try {
                $check->invoke(null, $removed, $tls);
                self::fail('TLS request downgrade admitted.');
            } catch (LogicException) {
                self::assertSame(0, CurrentCatalog::accounting()['connection_attempts']);
            }
        }
        $check->invoke(null, ['options' => [Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true]], ['Ssl_cipher' => '', 'Ssl_version' => '']);
        self::assertTrue(true);
    }

    public function test_private_capability_debug_and_serialization_do_not_export_configuration_or_pdo(): void
    {
        $catalog = (new ReflectionClass(CurrentCatalog::class))->newInstanceWithoutConstructor();
        self::assertSame(['catalog' => 'private'], $catalog->__debugInfo());
        $this->expectException(LogicException::class);
        serialize($catalog);
    }
}
