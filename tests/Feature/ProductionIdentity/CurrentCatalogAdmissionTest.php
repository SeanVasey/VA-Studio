<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\AccountAdmissionGuards;
use App\Domain\Customers\ProductionIdentity\CatalogLease;
use App\Domain\Customers\ProductionIdentity\CatalogRead;
use App\Domain\Customers\ProductionIdentity\CatalogWriterDescriptor;
use App\Domain\Customers\ProductionIdentity\CurrentCatalog;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\SchemaProofFrame;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use Pdo\Mysql;
use ReflectionProperty;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;
use WeakReference;

final class CurrentCatalogAdmissionTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL catalog companion sessions and primary RR authority.');
        }
        $this->identitySetup();
    }

    public function test_native_nested_boundary_reuses_one_companion_and_last_close_releases_server_session(): void
    {
        $pdo = DB::connection()->getPdo();
        $before = CurrentCatalog::accounting();
        $outer = CatalogLease::open($pdo);
        $inner = CatalogLease::open($pdo);
        $catalog = (new ReflectionProperty($outer, 'catalog'))->getValue($outer);
        $weak = WeakReference::create($catalog);
        $native = (new ReflectionProperty($catalog, 'catalog'))->getValue($catalog);
        $id = (int) $native->query('SELECT CONNECTION_ID()')->fetchColumn();
        unset($native, $catalog);
        try {
            self::assertNotEmpty(CatalogLease::read($pdo, CatalogRead::Q3C5185ED551F, ['customer_accounts']));
            $outer->close();
            self::assertNotNull($weak->get());
            self::assertSame($before['connections'] + 1, CurrentCatalog::accounting()['connections']);
        } finally {
            $outer->close();
            $inner->close();
        }
        self::assertNull($weak->get());
        $statement = $pdo->prepare('SELECT COUNT(*) FROM performance_schema.threads WHERE PROCESSLIST_ID=?');
        $statement->execute([$id]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function test_native_companion_statement_accounting_matches_server_including_constructor_setup_and_context(): void
    {
        $pdo = DB::connection()->getPdo();
        $before = CurrentCatalog::statementCount();
        $lease = CatalogLease::open($pdo);
        try {
            $catalog = (new ReflectionProperty($lease, 'catalog'))->getValue($lease);
            $native = (new ReflectionProperty($catalog, 'catalog'))->getValue($catalog);
            $actual = (int) $native->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
            self::assertSame(CurrentCatalog::statementCount() - $before + 1, $actual, 'Include exactly this observer query, not an unmeasured constructor allowance.');
            CatalogLease::read($pdo, CatalogRead::Q3C5185ED551F, ['customer_accounts']);
            $actual = (int) $native->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
            self::assertSame(CurrentCatalog::statementCount() - $before + 2, $actual);
            unset($native, $catalog);
        } finally {
            $lease->close();
        }
    }

    public function test_native_primary_attribute_changes_invalidate_retained_companion_without_reconnect(): void
    {
        $pdo = DB::connection()->getPdo();
        foreach ([PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_CASE => PDO::CASE_LOWER, PDO::ATTR_ORACLE_NULLS => PDO::NULL_TO_STRING] as $attribute => $unsafe) {
            $safe = $pdo->getAttribute($attribute);
            $lease = CatalogLease::open($pdo);
            $before = CurrentCatalog::accounting()['connections'];
            try {
                $pdo->setAttribute($attribute, $unsafe);
                try {
                    CatalogLease::read($pdo, CatalogRead::Q3C5185ED551F, ['customer_accounts']);
                    self::fail('Changed primary read semantics were admitted.');
                } catch (LogicException $error) {
                    self::assertSame('Current private catalog admission required.', $error->getMessage());
                    self::assertSame($before, CurrentCatalog::accounting()['connections']);
                }
            } finally {
                $pdo->setAttribute($attribute, $safe);
                try {
                    $lease->close();
                } catch (LogicException) { /* Failed capability stays invalid. */
                }
            }
        }
    }

    public function test_native_failed_memo_ends_companion_and_restoration_uses_complete_unframed_proof(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $connection->beginTransaction();
        $pdo->exec('SAVEPOINT catalog_failed_frame');
        $frame = SchemaProofFrame::open($connection, $pdo, static function () use ($pdo): void {
            $pdo->exec('RELEASE SAVEPOINT catalog_failed_frame');
            $pdo->exec('SAVEPOINT catalog_failed_frame');
        });
        $calls = 0;
        $proof = function () use ($pdo, &$calls): void {
            $calls++;
            AccountAdmissionGuards::stable($pdo, 'mysql');
        };
        try {
            SchemaProofFrame::prove($pdo, 'catalog-restoration', ['customer_accounts'], $proof);
            SchemaProofFrame::prove($pdo, 'catalog-restoration', ['customer_accounts'], $proof);
            self::assertSame(1, $calls);
            $pdo->exec('SET SESSION foreign_key_checks=0');
            try {
                SchemaProofFrame::prove($pdo, 'catalog-restoration', ['customer_accounts'], $proof);
                self::fail('Changed primary state was admitted.');
            } catch (LogicException) {
                self::assertTrue((new ReflectionProperty($frame, 'closed'))->getValue($frame));
            } finally {
                $pdo->exec('SET SESSION foreign_key_checks=1');
            }
            SchemaProofFrame::prove($pdo, 'catalog-restoration', ['customer_accounts'], $proof);
            self::assertSame(2, $calls);
            self::assertTrue($pdo->inTransaction());
            $pdo->exec('RELEASE SAVEPOINT catalog_failed_frame');
        } finally {
            $frame->close();
            $connection->rollBack();
        }
    }

    public function test_native_coherent_endpoint_and_feasible_tls_downgrade_refuse_before_constructor_attempt(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $positive = CatalogLease::open($pdo);
        $positive->close();
        $name = DB::getDefaultConnection();
        $raw = config('database.connections.'.$name);
        $property = new ReflectionProperty(Connection::class, 'config');
        $resolved = $property->getValue($connection);
        $cases = [['host' => '198.51.100.77'], ['port' => ((int) $resolved['port'] === 3307 ? 3308 : 3307)], ['unix_socket' => '/synthetic/foreign-mysql.sock']];
        $tls = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')")->fetchAll(PDO::FETCH_KEY_PAIR);
        fwrite(STDERR, 'catalog constructor TLS downgrade subcase: '.(($tls['Ssl_cipher'] ?? '') !== '' ? 'SELECTED_PRIMARY_TLS' : 'UNEXECUTED_NO_PRIMARY_TLS_PROFILE')."\n");
        if (($tls['Ssl_cipher'] ?? '') !== '') {
            $withoutTls = $raw['options'] ?? [];
            foreach ([Mysql::ATTR_SSL_CA, Mysql::ATTR_SSL_CAPATH, Mysql::ATTR_SSL_CERT, Mysql::ATTR_SSL_KEY, Mysql::ATTR_SSL_CIPHER] as $key) {
                unset($withoutTls[$key]);
            }
            $cases[] = ['options' => $withoutTls];
        }
        foreach ($cases as $change) {
            // Both mutable config maps agree, so config equality alone cannot establish target authority.
            $badRaw = array_replace($raw, $change, ['password' => 'synthetic-never-send-this-marker']);
            $badResolved = array_replace($resolved, $change, ['password' => 'synthetic-never-send-this-marker']);
            self::assertNotEmpty(CatalogWriterDescriptor::validate($badRaw, $badResolved)['dsn']);
            $before = CurrentCatalog::accounting()['connection_attempts'];
            config(['database.connections.'.$name => $badRaw]);
            $property->setValue($connection, $badResolved);
            try {
                try {
                    CatalogLease::open($pdo);
                    self::fail('Coherently changed endpoint/TLS descriptor was admitted.');
                } catch (LogicException $error) {
                    self::assertSame($before, CurrentCatalog::accounting()['connection_attempts']);
                    self::assertNull($error->getPrevious());
                    self::assertStringNotContainsString('synthetic-never-send-this-marker', $error->getMessage());
                }
            } finally {
                config(['database.connections.'.$name => $raw]);
                $property->setValue($connection, $resolved);
            }
        }
    }

    public function test_native_raw_config_and_resolver_callbacks_refuse_before_factory_or_callback_execution(): void
    {
        $pdo = DB::connection()->getPdo();
        $name = DB::getDefaultConnection();
        $original = config('database.connections.'.$name);
        $calls = 0;
        $before = CurrentCatalog::accounting()['connections'];
        config(['database.connections.'.$name.'.host' => function () use (&$calls): string {
            $calls++;

            return 'foreign';
        }]);
        try {
            try {
                CatalogLease::open($pdo);
                self::fail('Config callback admitted.');
            } catch (LogicException) {
                self::assertSame(0, $calls);
            }
        } finally {
            config(['database.connections.'.$name => $original]);
        }
        $application = app();
        $property = new ReflectionProperty(Container::class, 'beforeResolvingCallbacks');
        $saved = $property->getValue($application);
        $application->beforeResolving('db', function () use (&$calls): void {
            $calls++;
        });
        try {
            try {
                CatalogLease::open($pdo);
                self::fail('Resolver callback admitted.');
            } catch (LogicException) {
                self::assertSame(0, $calls);
            }
        } finally {
            $property->setValue($application, $saved);
        }
        self::assertSame($before, CurrentCatalog::accounting()['connections']);
    }

    public function test_native_unframed_complete_proofs_refuse_new_namespace_under_early_primary_snapshot(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $config = $connection->getConfig();
        $address = empty($config['unix_socket']) ? 'host='.$config['host'].';port='.$config['port'] : 'unix_socket='.$config['unix_socket'];
        $other = new PDO('mysql:'.$address.';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->beginTransaction();
        $created = false;
        try {
            $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            AccountAdmissionGuards::stable($pdo, 'mysql');
            $pdo->exec('SAVEPOINT catalog_unframed');
            $other->exec('CREATE TABLE customer_accounts_insert (id integer PRIMARY KEY)');
            $created = true;
            foreach ([fn () => AccountAdmissionGuards::stable($pdo, 'mysql'), fn () => (new IdentityMigrationOwnership)->inspect($pdo, 'mysql')] as $proof) {
                try {
                    $proof();
                    self::fail('Complete proof admitted new reserved namespace.');
                } catch (LogicException) {
                    self::assertTrue($pdo->inTransaction());
                }
            }
            $pdo->exec('RELEASE SAVEPOINT catalog_unframed');
            self::assertSame('REPEATABLE-READ', $pdo->query('SELECT @@SESSION.transaction_isolation')->fetchColumn());
        } finally {
            $connection->rollBack();
            if ($created) {
                $other->exec('DROP TABLE customer_accounts_insert');
            }
            $other = null;
        }
    }
}
