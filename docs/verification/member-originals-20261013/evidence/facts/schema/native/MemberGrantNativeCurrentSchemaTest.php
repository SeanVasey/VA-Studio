<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Grants\Member\MemberGrantCurrentSchema;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Memberships\Production\MembershipRows;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Private native schema only. Provider/host behavior and unknown extra-trigger currentness are not claimed. */
class MemberGrantNativeCurrentSchemaTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_actual_show_definitions_and_read_only_frame_are_accepted(): void
    {
        DB::beginTransaction();
        $pdo = DB::connection()->getPdo();
        try {
            $rows = new MembershipRows;
            $snapshot = MemberGrantCurrentSchema::pin($rows);
            MemberGrantCurrentSchema::assertCurrent($snapshot, $rows);
            $this->assertSame('mysql', MemberGrantCurrentSchema::captureDriver($snapshot));
            DB::commit();
            $frame = IdentityCommittedFrame::begin(CurrentRows::committedReadOnly($pdo, 'mysql'), hrtime(true) + 10_000_000_000);
            try {
                MemberGrantCurrentSchema::assertCommitted($snapshot, $frame);
                $this->assertTrue($pdo->inTransaction());
                $frame->finish();
                $this->assertFalse($pdo->inTransaction());
            } finally {
                $frame->close();
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public static function beforePinChanges(): array
    {
        return [['table'], ['missing_guard'], ['changed_guard']];
    }

    #[DataProvider('beforePinChanges')]
    public function test_current_definitions_refuse_changes_hidden_by_an_older_rr_dictionary_snapshot(string $change): void
    {
        $pdo = DB::connection()->getPdo();
        $writer = $this->independentPrimary();
        $writer->exec('SET SESSION lock_wait_timeout=5');
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $guard = 'production_member_profiles_update';
        $guardSql = $pdo->query('SHOW CREATE TRIGGER `'.$schema.'`.`'.$guard.'`')->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $rows = new MembershipRows;
            // This dictionary read happens before any open of a 258 table. The writer completes before pin.
            $beforeColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_member_profiles' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
            $beforeGuard = $pdo->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='production_member_profiles_update'")->fetchColumn();
            $this->assertNotEmpty($beforeColumns);
            $this->assertIsString($beforeGuard);
            if ($change === 'table') {
                $writer->exec('ALTER TABLE production_member_profiles ADD COLUMN extra_synthetic VARCHAR(8) NULL');
            } else {
                $writer->exec('DROP TRIGGER production_member_profiles_update');
                if ($change === 'changed_guard') {
                    $replacement = str_replace('0 = 1', '1 = 1', $guardSql, $count);
                    $this->assertSame(1, $count);
                    $writer->exec($replacement);
                }
            }
            // Prove the regression condition rather than relying on the session isolation label.
            $this->assertSame($beforeColumns, $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_member_profiles' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame($beforeGuard, $pdo->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='production_member_profiles_update'")->fetchColumn());
            $refused = false;
            try {
                // The external old-source runner selects only this existing API, never an absent new method.
                if (getenv('MEMBER_SCHEMA_BASELINE') === '1') {
                    (new MemberGrantSchema)->assertOwned($pdo);
                } else {
                    MemberGrantCurrentSchema::pin($rows);
                }
            } catch (MemberGrantException) {
                $refused = true;
            }
            $this->assertTrue($refused, 'Changed current member definition was admitted from the old RR dictionary snapshot.');
        } finally {
            DB::rollBack();
            if ($change === 'table') {
                $writer->exec('ALTER TABLE production_member_profiles DROP COLUMN extra_synthetic');
            } else {
                $writer->exec('DROP TRIGGER IF EXISTS production_member_profiles_update');
                $writer->exec($guardSql);
            }
        }
    }

    private function independentPrimary(): PDO
    {
        $config = DB::connection()->getConfig();
        $dsn = empty($config['unix_socket']) ? 'mysql:host='.$config['host'].';port='.$config['port'] : 'mysql:unix_socket='.$config['unix_socket'];

        return new PDO($dsn.';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the root-coordinated private native MySQL lane.');
        }
    }
}
