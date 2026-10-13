<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingAwardFence;
use App\Domain\Memberships\Billing\BillingException;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class BillingAwardFenceDefinitionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_old_trigger_snapshot_cannot_admit_a_removed_current_hint_guard(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL current trigger inventory through an earlier catalog snapshot.');
        }
        $pdo = DB::connection()->getPdo();
        $config = DB::connection()->getConfig();
        $address = empty($config['unix_socket']) ? 'host='.$config['host'].';port='.$config['port'] : 'unix_socket='.$config['unix_socket'];
        $other = new PDO('mysql:'.$address.';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $guard = (new BillingAwardFence)->eventGuardName();
        $create = $other->query('SHOW CREATE TRIGGER `'.$guard.'`')->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
        $catalog = "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '".$guard."'";
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $this->assertSame($guard, $pdo->query($catalog)->fetchColumn());
            $other->exec('DROP TRIGGER `'.$guard.'`');
            $this->assertSame($guard, $pdo->query($catalog)->fetchColumn(), 'The ordinary trigger catalog deliberately retains the old snapshot.');
            $this->assertSame([], $pdo->query("SHOW TRIGGERS WHERE `Trigger` = '".$guard."'")->fetchAll(PDO::FETCH_ASSOC), 'SHOW must see the current trigger inventory.');
            try {
                (new BillingAwardFence)->lock($pdo);
                $this->fail('The current hint guard is absent, so an award fence cannot be admitted.');
            } catch (BillingException $error) {
                $this->assertSame('award_fence_schema', $error->reason);
            }
        } finally {
            DB::rollBack();
            $other->exec(str_replace(' FOR EACH ROW ', ' FOR EACH ROW PRECEDES `production_membership_billing_events_insert` ', $create));
        }
    }

    public function test_old_catalog_snapshot_cannot_admit_a_fence_moved_to_myisam_before_its_open(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL current-definition check through an earlier catalog snapshot.');
        }
        $pdo = DB::connection()->getPdo();
        $config = DB::connection()->getConfig();
        $address = empty($config['unix_socket']) ? 'host='.$config['host'].';port='.$config['port'] : 'unix_socket='.$config['unix_socket'];
        $other = new PDO('mysql:'.$address.';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $table = (new BillingAwardFence)->table();
        $catalog = "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".BillingAwardFence::TABLE."'";
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $this->assertSame('InnoDB', $pdo->query($catalog)->fetchColumn());
            $other->exec('ALTER TABLE '.$table.' ENGINE=MyISAM');
            $this->assertSame('InnoDB', $pdo->query($catalog)->fetchColumn(), 'The ordinary catalog listing deliberately retains the old snapshot.');
            $this->assertStringContainsString('ENGINE=MyISAM', $other->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1]);
            try {
                (new BillingAwardFence)->lock($pdo);
                $this->fail('A current nontransactional fence must refuse despite the old InnoDB catalog snapshot.');
            } catch (BillingException $error) {
                $this->assertSame('award_fence_schema', $error->reason);
            }
        } finally {
            DB::rollBack();
            $other->exec('ALTER TABLE '.$table.' ENGINE=InnoDB');
        }
    }
}
