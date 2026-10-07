<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Models\ProductionConsentState;
use App\Domain\Customers\ProductionFeatures\Models\ProductionListeningLibrary as LibraryRow;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;
use Throwable;

class ProductionFeatureTransactionOwnershipTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    #[DataProvider('features')]
    public function test_saved_callback_commit_reopen_cannot_make_cleanup_rollback_a_foreign_transaction(string $feature): void
    {
        $owner = $this->featureIdentity($feature);
        $service = $feature === 'listening_library' ? new ProductionListeningLibrary : new ProductionConsentPreferences;
        $service->initialize($owner);
        $command = $feature === 'listening_library'
            ? ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC durable private original'] : $this->productionGrant();
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $primary->exec('CREATE '.(DB::getDriverName() === 'mysql' ? 'TEMPORARY ' : '').'TABLE feature_foreign_transaction (marker INTEGER PRIMARY KEY)');
        $fired = false;
        $model = $feature === 'listening_library' ? LibraryRow::class : ProductionConsentState::class;
        $model::saved(function () use (&$fired, $primary) {
            $fired = true;
            $primary->commit();
            $primary->beginTransaction();
            $primary->exec('INSERT INTO feature_foreign_transaction (marker) VALUES (1)');
        });
        $refusal = null;
        try {
            try {
                $service->change($owner, $command);
            } catch (Throwable $error) {
                $refusal = $error;
            }
            $this->assertTrue($fired);
            $this->assertNotNull($refusal);
            $this->assertInstanceOf(ProductionFeatureException::class, $refusal);
            $this->assertSame(503, $refusal->status);
            $this->assertTrue($primary->inTransaction(), 'Failure cleanup must leave the replacement transaction active.');
            $this->assertSame(1, (int) $primary->query('SELECT COUNT(*) FROM feature_foreign_transaction')->fetchColumn());
            $this->assertSame(0, $connection->transactionLevel());
            $primary->rollBack();
            $this->assertSame(0, (int) $primary->query('SELECT COUNT(*) FROM feature_foreign_transaction')->fetchColumn());
            $model::flushEventListeners();
            $fresh = $service->read($owner);
            $this->assertTrue($fresh['initialized']);
            $this->assertSame(1, $feature === 'listening_library' ? $fresh['library']['version'] : $fresh['preferences']['purposes'][0]['version']);
        } finally {
            $model::flushEventListeners();
            if ($primary->inTransaction()) {
                $primary->rollBack();
            }
            $connection->setPdo($primary);
            $primary->exec('DROP TABLE feature_foreign_transaction');
        }
    }

    public static function features(): array
    {
        return [['listening_library'], ['consent_preferences']];
    }
}
