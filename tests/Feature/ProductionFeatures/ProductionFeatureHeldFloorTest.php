<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Models\ProductionListeningLibrary as LibraryRow;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;
use Throwable;

class ProductionFeatureHeldFloorTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    public function test_missing_identity_floor_cannot_mint_a_module_context_or_write(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $before = (array) DB::table('production_listening_libraries')->sole();
        DB::unprepared('DROP TRIGGER pi_verifications_insert');
        try {
            try {
                $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC floor refusal']);
                $this->fail('Typed identity entry must reject its missing floor before any module write.');
            } catch (IdentityException) {
                $this->assertSame($before, (array) DB::table('production_listening_libraries')->sole());
                $this->assertSame(0, DB::transactionLevel());
            }
        } finally {
            $this->restoreGuard();
        }
    }

    public function test_saved_hook_identity_floor_withdrawal_is_refused_with_original_history_preserved(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $before = (array) DB::table('production_account_feature_bindings')->sole();
        $fired = false;
        LibraryRow::saved(function () use (&$fired): void {
            $fired = true;
            DB::unprepared('DROP TRIGGER pi_verifications_insert');
        });
        $refusal = null;
        try {
            try {
                $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC held floor late refusal']);
            } catch (Throwable $error) {
                $refusal = $error;
            }
            $this->assertTrue($fired);
            $this->assertInstanceOf(DB::getDriverName() === 'mysql' ? ProductionFeatureException::class : IdentityException::class, $refusal);
            // Native DDL commits the prior valid write; it cannot be represented as rolled back.
            $this->assertSame(DB::getDriverName() === 'mysql' ? 1 : 0, (int) DB::table('production_listening_libraries')->value('version'));
            $this->assertSame($before, (array) DB::table('production_account_feature_bindings')->sole());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        } finally {
            LibraryRow::flushEventListeners();
            if (DB::getDriverName() === 'mysql') {
                $this->restoreGuard();
            }
        }
    }

    public function test_commit_event_identity_floor_withdrawal_withholds_projection_and_preserves_durable_write(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $fired = false;
        Event::listen(TransactionCommitted::class, function () use (&$fired): void {
            if (! $fired) {
                $fired = true;
                DB::unprepared('DROP TRIGGER pi_verifications_insert');
            }
        });
        try {
            try {
                $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC retained commit-floor write']);
                $this->fail('The mandatory same-source committed identity floor must reject the missing guard.');
            } catch (ProductionFeatureException $error) {
                $this->assertSame(503, $error->status);
                $this->assertTrue($fired);
                $this->assertSame(1, (int) DB::table('production_listening_libraries')->value('version'));
            }
        } finally {
            $this->restoreGuard();
        }
        $this->assertSame(1, $library->read($owner)['library']['version']);
    }

    private function restoreGuard(): void
    {
        DB::unprepared(IdentitySchema::guards(DB::getDriverName())['pi_verifications_insert']['sql']);
    }
}
