<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Models\ProductionListeningLibrary as LibraryRow;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeaturePreparation;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;
use Throwable;

class ProductionFeatureReleaseTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    #[DataProvider('features')]
    public function test_prepared_projection_has_no_open_frame_and_closes_original_authority_once(string $feature): void
    {
        $identity = $this->featureIdentity($feature);
        $service = $feature === 'listening_library' ? new ProductionListeningLibrary : new ProductionConsentPreferences;
        $calls = 0;
        $prepared = new ProductionFeaturePreparation(function () use (&$calls): void {
            $calls++;
        }, 3 * 1024 * 1024);
        $projection = $service->read($identity, $prepared);
        $this->assertSame(['initialized' => false], $projection);
        $this->assertSame($projection, json_decode($prepared->bytes(), true, 16, JSON_THROW_ON_ERROR));
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
        $before = $calls;
        $prepared->finish();
        $this->assertGreaterThan($before, $calls);
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(ProductionFeatureException::class);
        $prepared->finish();
    }

    public function test_late_guard_withdrawal_refuses_but_durable_mutation_is_reconciled_by_fresh_read(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $admitted = true;
        $prepared = new ProductionFeaturePreparation(function () use (&$admitted): void {
            if (! $admitted) {
                throw new ProductionFeatureException;
            }
        }, 3 * 1024 * 1024);
        $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC uncertain durable write'], $prepared);
        $admitted = false;
        try {
            $prepared->finish();
            $this->fail('Late withdrawal must refuse release.');
        } catch (ProductionFeatureException $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        $this->assertSame(1, $library->read($owner)['library']['version']);
        $this->assertSame(1, DB::table('production_listening_libraries')->count());
    }

    public function test_unused_preparation_never_opens_a_closing_transaction(): void
    {
        $owner = $this->featureIdentity();
        $prepared = new ProductionFeaturePreparation(static function (): void {}, 3 * 1024 * 1024);
        (new ProductionListeningLibrary)->read($owner, $prepared);
        unset($prepared);
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
    }

    public function test_unproduced_preparation_cannot_claim_a_closing_proof(): void
    {
        $prepared = new ProductionFeaturePreparation(static function (): void {}, 4096);
        $this->expectException(ProductionFeatureException::class);
        $prepared->finish();
    }

    public function test_saved_replacement_dispatcher_and_manager_are_preserved(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $connection = DB::connection();
        $originalEvents = $connection->getEventDispatcher();
        $property = new ReflectionProperty(Connection::class, 'transactionsManager');
        $originalManager = $property->getValue($connection);
        $foreignEvents = new Dispatcher(app());
        $foreignManager = new DatabaseTransactionsManager;
        $foreignRollbacks = 0;
        $foreignCallbacks = 0;
        $ownCallbacks = 0;
        $ownRollbacks = 0;
        $reopenedCallbacks = 0;
        $reopenedRecords = [];
        $ownedManager = null;
        $foreignEvents->listen(TransactionRolledBack::class, function () use (&$foreignRollbacks): void {
            $foreignRollbacks++;
        });
        $foreignManager->begin($connection->getName(), 1);
        $foreignManager->addCallback(function () use (&$foreignCallbacks): void {
            $foreignCallbacks++;
        });
        $foreignRecords = $foreignManager->getPendingTransactions()->all();
        Event::listen(TransactionBeginning::class, function () use ($connection, &$ownCallbacks, &$ownRollbacks): void {
            $connection->afterCommit(function () use (&$ownCallbacks): void {
                $ownCallbacks++;
            });
            $connection->afterRollBack(function () use (&$ownRollbacks): void {
                $ownRollbacks++;
            });
        });
        LibraryRow::saved(function () use ($connection, $property, $foreignEvents, $foreignManager, &$ownedManager, &$reopenedCallbacks, &$reopenedRecords): void {
            $ownedManager = $property->getValue($connection);
            // An unrelated same-name level-one record even has a stale original parent link.
            $ownedManager->begin($connection->getName(), 1);
            $ownedManager->addCallback(function () use (&$reopenedCallbacks): void {
                $reopenedCallbacks++;
            });
            $reopenedRecords = array_slice($ownedManager->getPendingTransactions()->all(), 1, null, true);
            $connection->setEventDispatcher($foreignEvents);
            $connection->setTransactionManager($foreignManager);
        });
        try {
            $refused = null;
            try {
                $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC replaced callbacks']);
            } catch (Throwable $error) {
                $refused = $error;
            }
            $this->assertInstanceOf(ProductionFeatureException::class, $refused);
            $this->assertSame($foreignEvents, $connection->getEventDispatcher());
            $this->assertSame($foreignManager, $property->getValue($connection));
            $this->assertSame($foreignRecords, $foreignManager->getPendingTransactions()->all());
            $this->assertSame(0, $foreignRollbacks);
            $this->assertSame(0, $foreignCallbacks);
            $this->assertSame($reopenedRecords, $ownedManager->getPendingTransactions()->all());
            $this->assertSame(0, $ownCallbacks);
            $this->assertSame(0, $ownRollbacks, 'Native-only abort must never invoke displaced runtime callbacks.');
            $ownedManager->commit($connection->getName(), 1, 0);
            $this->assertSame(1, $reopenedCallbacks);
            $this->assertSame(0, $ownCallbacks, 'The original record cannot run when its caller restores/reuses its manager.');
            $foreignManager->commit($connection->getName(), 1, 0);
            $this->assertSame(1, $foreignCallbacks, 'Only its owner consumes the retained foreign record.');
        } finally {
            LibraryRow::flushEventListeners();
            $connection->setEventDispatcher($originalEvents);
            $originalManager === null ? $connection->unsetTransactionManager() : $connection->setTransactionManager($originalManager);
            if ($connection->getRawPdo()->inTransaction()) {
                $connection->getRawPdo()->rollBack();
                $connection->setPdo($connection->getRawPdo());
            }
        }
    }

    public function test_before_start_metadata_withdrawal_refuses_before_original_physical_begin(): void
    {
        $owner = $this->featureIdentity();
        $connection = DB::connection();
        $primary = $connection->getRawPdo();
        $key = config('app.key');
        $fired = false;
        $connection->beforeStartingTransaction(function () use (&$fired): void {
            if (! $fired) {
                $fired = true;
                config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
            }
        });
        try {
            $refused = null;
            try {
                (new ProductionListeningLibrary)->initialize($owner);
            } catch (Throwable $error) {
                $refused = $error;
            }
            $this->assertNotNull($refused);
            $this->assertTrue($fired);
            $this->assertFalse($primary->inTransaction(), 'A pure tail refuses before PDO begin.');
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
        } finally {
            config(['app.key' => $key]);
            if ($primary->inTransaction()) {
                $primary->rollBack();
                $connection->setPdo($primary);
            }
        }
        $this->assertSame(['initialized' => false], (new ProductionListeningLibrary)->read($owner));
    }

    public function test_owned_framework_rollback_keeps_normal_afterrollback_callback_once(): void
    {
        $owner = $this->featureIdentity();
        $calls = 0;
        Event::listen(TransactionBeginning::class, function () use (&$calls): void {
            DB::connection()->afterRollBack(function () use (&$calls): void {
                $calls++;
            });
        });
        $prepared = new ProductionFeaturePreparation(static function (): void {
            throw new ProductionFeatureException;
        }, 4096);
        try {
            (new ProductionListeningLibrary)->initialize($owner, $prepared);
            $this->fail('Original ordinary callback refusal must roll back.');
        } catch (ProductionFeatureException) {
            $this->assertSame(1, $calls);
            $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
        }
    }

    public function test_preparation_start_cannot_move_feature_writes_into_a_foreign_frame(): void
    {
        $owner = $this->featureIdentity();
        $connection = DB::connection();
        $primary = $connection->getRawPdo();
        $primary->exec('CREATE '.(DB::getDriverName() === 'mysql' ? 'TEMPORARY ' : '').'TABLE release_foreign_probe (marker INTEGER PRIMARY KEY)');
        $fired = false;
        $prepared = new ProductionFeaturePreparation(function () use ($primary, &$fired): void {
            $fired = true;
            $primary->commit();
            $primary->beginTransaction();
            $primary->exec('INSERT INTO release_foreign_probe VALUES (1)');
        }, 3 * 1024 * 1024);
        try {
            $refused = null;
            try {
                (new ProductionListeningLibrary)->initialize($owner, $prepared);
            } catch (Throwable $error) {
                $refused = $error;
            }
            $this->assertNotNull($refused);
            $this->assertTrue($fired);
            $this->assertTrue($primary->inTransaction());
            $this->assertSame(1, (int) $primary->query('SELECT COUNT(*) FROM release_foreign_probe')->fetchColumn());
            $this->assertSame(0, (int) $primary->query('SELECT COUNT(*) FROM production_account_feature_bindings')->fetchColumn());
            $this->assertSame(0, (int) $primary->query('SELECT COUNT(*) FROM production_listening_libraries')->fetchColumn());
            $this->assertSame(1, $connection->transactionLevel());
        } finally {
            if ($primary->inTransaction()) {
                $primary->rollBack();
            }
            $connection->setPdo($primary);
            $primary->exec('DROP TABLE release_foreign_probe');
        }
    }

    public static function features(): array
    {
        return [['listening_library'], ['consent_preferences']];
    }
}
