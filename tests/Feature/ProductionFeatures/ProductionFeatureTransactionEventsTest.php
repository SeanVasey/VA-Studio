<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;
use Throwable;

class ProductionFeatureTransactionEventsTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    public function test_beginning_listener_cannot_make_the_feature_adopt_or_commit_a_foreign_transaction(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $connection = DB::connection();
        $primary = $connection->getRawPdo();
        $primary->exec('CREATE '.(DB::getDriverName() === 'mysql' ? 'TEMPORARY ' : '').'TABLE feature_begin_foreign (marker INTEGER PRIMARY KEY)');
        $fired = false;
        Event::listen(TransactionBeginning::class, function () use ($primary, &$fired): void {
            if (! $fired) {
                $fired = true;
                $primary->commit();
                $primary->beginTransaction();
                $primary->exec('INSERT INTO feature_begin_foreign VALUES (1)');
            }
        });
        $projection = null;
        $refusal = null;
        try {
            try {
                $projection = $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC refused foreign begin']);
            } catch (Throwable $error) {
                $refusal = $error;
            }
            $this->assertTrue($fired);
            $this->assertNull($projection, 'An operation cannot adopt the physical transaction opened by its beginning listener.');
            $this->assertInstanceOf(ProductionFeatureException::class, $refusal);
            $this->assertSame(503, $refusal->status);
            $this->assertTrue($primary->inTransaction());
            $this->assertSame(1, (int) $primary->query('SELECT COUNT(*) FROM feature_begin_foreign')->fetchColumn());
            $this->assertSame(0, $connection->transactionLevel());
            $primary->rollBack();
            $this->assertSame(0, (int) $primary->query('SELECT COUNT(*) FROM feature_begin_foreign')->fetchColumn());
            $this->assertSame(0, (int) DB::table('production_listening_libraries')->value('version'));
        } finally {
            if ($primary->inTransaction()) {
                $primary->rollBack();
            }
            $connection->setPdo($primary);
            $primary->exec('DROP TABLE feature_begin_foreign');
        }
    }

    public function test_committing_listener_purpose_withdrawal_prevents_a_durable_grant(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $command = $this->productionGrant();
        $fired = false;
        Event::listen(TransactionCommitting::class, function () use (&$fired): void {
            $fired = true;
            config(['production-customer-preferences.grants_enabled' => false]);
        });
        $projection = null;
        $refusal = null;
        try {
            $projection = $preferences->change($owner, $command);
        } catch (Throwable $error) {
            $refusal = $error;
        }
        $this->assertTrue($fired);
        $this->assertNull($projection);
        $this->assertSame(0, DB::table('production_consent_events')->count(), 'Purpose withdrawal before physical commit must roll back the grant.');
        $this->assertInstanceOf(ConsentException::class, $refusal);
        $this->assertSame(503, $refusal->status);
        $this->assertSame(0, DB::table('production_consent_states')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
    }

    public function test_regular_begin_commit_and_aftercommit_callback_order_is_preserved(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $events = [];
        Event::listen(TransactionBeginning::class, function () use (&$events): void {
            $events[] = 'began';
            DB::connection()->afterCommit(function () use (&$events): void {
                $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
                $this->assertSame(1, (int) DB::table('production_listening_libraries')->value('version'));
                $events[] = 'after_commit';
            });
        });
        Event::listen(TransactionCommitting::class, function () use (&$events): void {
            $events[] = 'committing';
        });
        Event::listen(TransactionCommitted::class, function () use (&$events): void {
            $events[] = 'committed';
        });
        $result = $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC normal events']);
        $this->assertSame(1, $result['library']['version']);
        $this->assertSame(['began', 'committing', 'after_commit', 'committed'], $events);
    }

    public function test_pre_start_foreign_transaction_is_refused_without_rollback_or_source_adoption(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $connection = DB::connection();
        $primary = $connection->getRawPdo();
        $primary->exec('CREATE '.(DB::getDriverName() === 'mysql' ? 'TEMPORARY ' : '').'TABLE feature_prestart_foreign (marker INTEGER PRIMARY KEY)');
        $fired = false;
        $connection->beforeStartingTransaction(function () use ($primary, &$fired): void {
            if (! $fired) {
                $fired = true;
                $primary->beginTransaction();
                $primary->exec('INSERT INTO feature_prestart_foreign VALUES (1)');
            }
        });
        try {
            try {
                $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC no pre-start adoption']);
                $this->fail('A transaction opened by the pre-start hook is foreign.');
            } catch (ProductionFeatureException $error) {
                $this->assertSame(503, $error->status);
                $this->assertTrue($fired);
                $this->assertTrue($primary->inTransaction());
                $this->assertSame(1, (int) $primary->query('SELECT COUNT(*) FROM feature_prestart_foreign')->fetchColumn());
                $this->assertSame(0, $connection->transactionLevel());
                $primary->rollBack();
                $this->assertSame(0, (int) $primary->query('SELECT COUNT(*) FROM feature_prestart_foreign')->fetchColumn());
                $this->assertSame(0, $library->read($owner)['library']['version']);
            }
        } finally {
            if ($primary->inTransaction()) {
                $primary->rollBack();
            }
            $connection->setPdo($primary);
            $primary->exec('DROP TABLE feature_prestart_foreign');
        }
    }

    public function test_committing_replacement_preserves_foreign_transaction_and_discards_its_unacknowledged_callbacks(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $connection = DB::connection();
        $primary = $connection->getRawPdo();
        $primary->exec('CREATE '.(DB::getDriverName() === 'mysql' ? 'TEMPORARY ' : '').'TABLE feature_commit_foreign (marker INTEGER PRIMARY KEY)');
        $fired = false;
        $afterCommit = 0;
        Event::listen(TransactionBeginning::class, function () use (&$afterCommit): void {
            DB::connection()->afterCommit(function () use (&$afterCommit): void {
                $afterCommit++;
            });
        });
        Event::listen(TransactionCommitting::class, function () use ($primary, &$fired): void {
            if (! $fired) {
                $fired = true;
                $primary->commit();
                $primary->beginTransaction();
                $primary->exec('INSERT INTO feature_commit_foreign VALUES (1)');
            }
        });
        try {
            try {
                $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC durable unknown commit-hook write']);
                $this->fail('Committing replacement cannot adopt or rollback the foreign transaction.');
            } catch (ProductionFeatureException $error) {
                $this->assertSame(503, $error->status);
                $this->assertTrue($fired);
                $this->assertTrue($primary->inTransaction());
                $this->assertSame(1, (int) $primary->query('SELECT COUNT(*) FROM feature_commit_foreign')->fetchColumn());
                $this->assertSame(0, $connection->transactionLevel());
                $this->assertSame(0, $afterCommit);
                $primary->rollBack();
                Event::forget(TransactionBeginning::class);
                $this->assertSame(1, $library->read($owner)['library']['version']);
                $this->assertSame(0, $afterCommit, 'The aborted frame must not run its queued callback in a later successful operation.');
            }
        } finally {
            if ($primary->inTransaction()) {
                $primary->rollBack();
            }
            $connection->setPdo($primary);
            $primary->exec('DROP TABLE feature_commit_foreign');
        }
    }

    public static function driftingBindings(): array
    {
        return [['manager'], ['dispatcher']];
    }

    #[DataProvider('driftingBindings')]
    public function test_committing_manager_or_dispatcher_drift_refuses_and_restores_original_bindings(string $which): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $connection = DB::connection();
        $property = new ReflectionProperty(Connection::class, 'transactionsManager');
        $manager = $property->getValue($connection);
        $dispatcher = $connection->getEventDispatcher();
        $fired = false;
        Event::listen(TransactionCommitting::class, function () use ($which, $connection, &$fired): void {
            $fired = true;
            if ($which === 'manager') {
                $connection->setTransactionManager(new DatabaseTransactionsManager);
            } else {
                $connection->setEventDispatcher(new Dispatcher);
            }
        });
        try {
            $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC rejected committing binding drift']);
            $this->fail('The observed manager and dispatcher must remain the captured bindings.');
        } catch (ProductionFeatureException $error) {
            $this->assertSame(503, $error->status);
            $this->assertTrue($fired);
            $this->assertSame(0, (int) DB::table('production_listening_libraries')->value('version'));
            $this->assertSame($manager, $property->getValue($connection));
            $this->assertSame($dispatcher, $connection->getEventDispatcher());
            $this->assertFalse($connection->getRawPdo()->inTransaction());
        }
    }

    public function test_original_managers_other_pending_callbacks_are_preserved_byte_for_byte_and_still_execute_once(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $connection = DB::connection();
        $manager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        $this->assertInstanceOf(DatabaseTransactionsManager::class, $manager);
        $fired = 0;
        $manager->begin('unrelated_feature_review', 1);
        $manager->addCallback(function () use (&$fired): void {
            $fired++;
        });
        $pending = $manager->getPendingTransactions()->all();
        $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC scoped bookkeeping']);
        $this->assertSame($pending, $manager->getPendingTransactions()->all());
        $this->assertSame(0, $fired);
        $this->assertSame($manager, (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection));
        $manager->commit('unrelated_feature_review', 1, 0);
        $this->assertSame(1, $fired);
        $this->assertSame([], $manager->getPendingTransactions()->all());
    }
}
