<?php

namespace Tests\Feature;

use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\TestCase;

class PaidTaxNativeBoundaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
    }

    public function test_native_case_distinct_provider_payments_retain_two_valid_same_buyer_orders_and_exact_replays(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL collation can conflate byte-distinct provider identities.');
        }
        $first = $this->paidTaxOrder();
        $second = $this->secondPaidTaxOrder($first, 'cs_test_synthetictax', 'pi_synthetictax');
        $grants = new PaidTaxGrants;
        $a = $grants->finalize($first['buyer']['principal'], $first['buyer']['user'], $first['order']['orderId']);
        $b = null;
        $collision = null;
        try {
            $b = $grants->finalize($second['buyer']['principal'], $second['buyer']['user'], $second['order']['orderId']);
        } catch (\PDOException $error) {
            $collision = $error;
        }
        $this->assertNull($collision, 'Byte-distinct, verified provider payments collided in the owned consumer schema.');
        $this->assertNotSame($a['id'], $b['id']);
        $this->assertSame([$first['order']['orderId'], $second['order']['orderId']], [$a['orderId'], $b['orderId']]);
        $this->assertSame($a, $grants->finalize($first['buyer']['principal'], $first['buyer']['user'], $first['order']['orderId']));
        $this->assertSame($b, $grants->finalize($second['buyer']['principal'], $second['buyer']['user'], $second['order']['orderId']));
        $rows = DB::table('paid_tax_order_origins')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $this->assertSame(['pi_SYNTHETICTAX', 'pi_synthetictax'], array_column($rows, 'provider_payment_id'));
        $this->assertSame(['pi_SYNTHETICTAX', 'pi_synthetictax'], array_map(fn (array $row): string => PaidTaxRecords::decode($row)['source_identity']['provider_payment_id'], $rows));
        $this->assertDatabaseCount('paid_tax_grant_origins', 2);
        $this->assertDatabaseCount('paid_order_origins', 0);
    }
}
