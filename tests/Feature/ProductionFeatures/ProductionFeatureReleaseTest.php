<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Models\ProductionListeningLibrary as LibraryRow;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeaturePreparation;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
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
        LibraryRow::saved(function () use ($connection, $foreignEvents, $foreignManager): void {
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

    public static function features(): array
    {
        return [['listening_library'], ['consent_preferences']];
    }
}
