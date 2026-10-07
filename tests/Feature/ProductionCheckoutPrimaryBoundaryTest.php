<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\PrimaryBoundary;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class ProductionCheckoutPrimaryBoundaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static function tables(): array
    {
        return [['users'], ['tracks'], ['production_track_policy_versions'], [CheckoutSchema::TABLES['order']], [CheckoutSchema::TABLES['payment']]];
    }

    #[DataProvider('tables')]
    public function test_temporary_shadow_cannot_supply_authority_or_redirect_financial_retention(string $table): void
    {
        $pdo = DB::connection()->getPdo();
        $native = DB::getDriverName() === 'mysql';
        $pdo->exec('CREATE TEMPORARY TABLE '.$table.' (synthetic_marker INTEGER)');
        try {
            CommandTransaction::run(function (): never {
                $this->fail('Checkout command consumed a temporary shadow.');
            });
        } catch (CheckoutException $error) {
            $this->assertContains($error->reason, ['temporary_shadow', 'temporary_or_foreign_schema']);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $pdo->exec($native ? 'DROP TEMPORARY TABLE `'.$table.'`' : 'DROP TABLE temp.'.$table);
        }
        PrimaryBoundary::prove($pdo, DB::getDriverName());
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 0);
    }

    public function test_shadow_installed_during_callback_is_refused_by_terminal_boundary(): void
    {
        $pdo = DB::connection()->getPdo();
        $native = DB::getDriverName() === 'mysql';
        $table = CheckoutSchema::TABLES['payment'];
        try {
            CommandTransaction::run(function () use ($pdo, $table): void {
                $pdo->exec('CREATE TEMPORARY TABLE '.$table.' (synthetic_marker INTEGER)');
            });
            $this->fail('Post-callback shadow bypassed terminal boundary.');
        } catch (CheckoutException $error) {
            $this->assertContains($error->reason, ['temporary_shadow', 'temporary_or_foreign_schema']);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            // SQLite transactional DDL rolls back the shadow; native temporary DDL retains it.
            $pdo->exec($native ? 'DROP TEMPORARY TABLE IF EXISTS `'.$table.'`' : 'DROP TABLE IF EXISTS temp.'.$table);
        }
        PrimaryBoundary::prove($pdo, DB::getDriverName());
        $this->assertDatabaseCount($table, 0);
    }

    public function test_postcommit_callback_shadow_suppresses_result_and_keeps_original_permanent_graph(): void
    {
        $pdo = DB::connection()->getPdo();
        $native = DB::getDriverName() === 'mysql';
        $table = CheckoutSchema::TABLES['payment'];
        $state = (object) ['active' => true];
        app('events')->listen(TransactionCommitted::class, function () use ($pdo, $table, $state): void {
            if ($state->active) {
                $state->active = false;
                $pdo->exec('CREATE TEMPORARY TABLE '.$table.' (synthetic_marker INTEGER)');
            }
        });
        try {
            CommandTransaction::run(static fn (): string => 'RESULT_MUST_NOT_ESCAPE');
            $this->fail('Commit callback shadow escaped the final boundary.');
        } catch (CheckoutException $error) {
            $this->assertContains($error->reason, ['temporary_shadow', 'temporary_or_foreign_schema']);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $state->active = false;
            $pdo->exec($native ? 'DROP TEMPORARY TABLE IF EXISTS `'.$table.'`' : 'DROP TABLE IF EXISTS temp.'.$table);
        }
        PrimaryBoundary::prove($pdo, DB::getDriverName());
        $this->assertDatabaseCount($table, 0);
    }
}
