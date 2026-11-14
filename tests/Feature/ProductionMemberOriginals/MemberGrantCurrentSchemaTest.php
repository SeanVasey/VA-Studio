<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Grants\Member\MemberGrantCurrentSchema;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Memberships\Production\MembershipRows;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Structural synthetic rehearsal; no document, customer or grant authority. */
class MemberGrantCurrentSchemaTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_complete_current_plan_and_committed_frame_preserve_all_installed_definitions(): void
    {
        $pdo = DB::connection()->getPdo();
        $before = $pdo->query('SELECT name,type,tbl_name,sql FROM main.sqlite_master')->fetchAll(\PDO::FETCH_ASSOC);
        DB::beginTransaction();
        $rows = new MembershipRows;
        $snapshot = MemberGrantCurrentSchema::pin($rows);
        // A callback-phase membership check reissues the shared native sentinel, without losing schema identity.
        $rows->assertCurrent();
        MemberGrantCurrentSchema::assertCurrent($snapshot, $rows);
        $this->assertSame('sqlite', MemberGrantCurrentSchema::captureDriver($snapshot));
        foreach (MemberGrantSchema::TABLES as $name) {
            $this->assertSame('`main`.`'.$name.'`', MemberGrantCurrentSchema::table($snapshot, $name));
        }
        DB::commit();
        $frame = IdentityCommittedFrame::begin(CurrentRows::committedReadOnly($pdo, 'sqlite'), hrtime(true) + 10_000_000_000);
        try {
            MemberGrantCurrentSchema::assertCommitted($snapshot, $frame);
            $this->assertTrue($pdo->inTransaction());
            $frame->finish();
            $this->assertFalse($pdo->inTransaction());
        } finally {
            $frame->close();
        }
        $this->assertSame($before, $pdo->query('SELECT name,type,tbl_name,sql FROM main.sqlite_master')->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function test_missing_required_guard_is_not_repaired(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('DROP TRIGGER production_member_profiles_update');
        DB::beginTransaction();
        try {
            $rows = new MembershipRows;
            $this->refuses(fn () => MemberGrantCurrentSchema::pin($rows));
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM main.sqlite_master WHERE name='production_member_profiles_update'")->fetchColumn());
        } finally {
            DB::rollBack();
        }
    }

    public function test_same_named_guard_with_changed_literal_bytes_is_refused(): void
    {
        $pdo = DB::connection()->getPdo();
        $sql = $pdo->query("SELECT sql FROM main.sqlite_master WHERE name='production_member_profiles_update'")->fetchColumn();
        $pdo->exec('DROP TRIGGER production_member_profiles_update');
        $pdo->exec(str_replace('Retain production member originals', 'Retain production member  originals', $sql));
        DB::beginTransaction();
        try {
            $this->refuses(fn () => MemberGrantCurrentSchema::pin(new MembershipRows));
        } finally {
            DB::rollBack();
        }
    }

    public function test_temp_reserved_guard_name_and_extra_owned_index_are_refused(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TEMP TABLE production_member_profiles_update (marker INTEGER)');
        DB::beginTransaction();
        try {
            $rows = new MembershipRows;
            $this->refuses(fn () => MemberGrantCurrentSchema::pin($rows));
            $pdo->exec('DROP TABLE temp.production_member_profiles_update');
            $pdo->exec('CREATE INDEX extra_member_seal ON production_member_profiles(seal)');
            $this->refuses(fn () => MemberGrantCurrentSchema::pin($rows));
        } finally {
            DB::rollBack();
        }
    }

    public function test_current_snapshot_refuses_replaced_native_transaction_without_rolling_it_back(): void
    {
        DB::beginTransaction();
        $pdo = DB::connection()->getPdo();
        try {
            $rows = new MembershipRows;
            $snapshot = MemberGrantCurrentSchema::pin($rows);
            $pdo->rollBack();
            $pdo->beginTransaction();
            $this->refuses(fn () => MemberGrantCurrentSchema::assertCurrent($snapshot, $rows));
            $this->assertTrue($pdo->inTransaction());
        } finally {
            DB::rollBack();
        }
    }

    public function test_key_configuration_drift_is_not_adopted_by_current_snapshot(): void
    {
        DB::beginTransaction();
        $prior = config('app.previous_keys');
        try {
            $rows = new MembershipRows;
            $snapshot = MemberGrantCurrentSchema::pin($rows);
            config(['app.previous_keys' => ['synthetic-unrelated-prior-key']]);
            $this->refuses(fn () => MemberGrantCurrentSchema::assertCurrent($snapshot, $rows));
        } finally {
            config(['app.previous_keys' => $prior]);
            DB::rollBack();
        }
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Changed member schema admitted.');
        } catch (MemberGrantException) {
            $this->addToAssertionCount(1);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite structural rehearsal; native cases have their own file.');
        }
        config(['production-memberships.provenance' => 'synthetic_rehearsal', 'member-grants.provenance' => 'synthetic_rehearsal']);
    }
}
