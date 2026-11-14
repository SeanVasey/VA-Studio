<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Production\MembershipException;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Runtime-only SQLite compatibility probe for the metadata batching delta. */
class BillingParentFloorCompatibilityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_missing_identity_guard_retains_the_billing_parent_floor_refusal_without_repair(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $pdo = DB::connection()->getPdo();
        $pdo->exec('DROP TRIGGER pi_verifications_insert');
        $before = $pdo->query('SELECT type, name, tbl_name, sql FROM main.sqlite_master ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC);
        try {
            (new BillingSchema)->up();
            $this->fail('The absent parent guard must refuse billing schema admission.');
        } catch (BillingException $error) {
            $this->assertSame('parent_floor', $error->reason);
        }
        $this->assertSame($before, $pdo->query('SELECT type, name, tbl_name, sql FROM main.sqlite_master ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM production_membership_billing_subscriptions')->fetchColumn());
    }

    public function test_an_unrelated_membership_refusal_keeps_its_class_and_reason(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TEMPORARY TABLE production_membership_paid_periods (marker INTEGER)');
        $pdo->exec('INSERT INTO temp.production_membership_paid_periods VALUES (4242)');
        try {
            try {
                (new BillingSchema)->up(false);
                $this->fail('The temporary membership table must refuse admission.');
            } catch (MembershipException $error) {
                $this->assertSame('shadowed_schema', $error->reason);
            }
            $this->assertSame(4242, (int) $pdo->query('SELECT marker FROM temp.production_membership_paid_periods')->fetchColumn());
        } finally {
            $pdo->exec('DROP TABLE temp.production_membership_paid_periods');
        }
    }
}
