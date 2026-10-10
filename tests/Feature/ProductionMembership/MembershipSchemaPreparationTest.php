<?php

namespace Tests\Feature\ProductionMembership;

use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\MembershipSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use PDOException;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Synthetic structural fixtures are not an invoice, original owner proof, award or usable grant. */
class MembershipSchemaPreparationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_fresh_schema_owned_retry_preserves_t23_and_has_no_incoming_child_dependencies(): void
    {
        $pdo = DB::connection()->getPdo();
        (new MembershipSchema)->up();
        [$present] = (new IdentityMigrationOwnership)->inspect($pdo, DB::getDriverName());
        $this->assertNotContains(false, $present);
        (new MembershipSchema)->assertOwned($pdo);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM production_membership_paid_periods')->fetchColumn());
    }

    public function test_owned_contiguous_empty_prefix_resumes_but_hole_before_retained_guard_refuses(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('DROP TRIGGER production_membership_credit_events_delete');
        (new MembershipSchema)->up();
        (new MembershipSchema)->assertOwned($pdo);
        $pdo->exec('DROP TRIGGER production_membership_paid_periods_update');
        $this->refuses(fn () => (new MembershipSchema)->up());
        $this->assertFalse($this->guardExists('production_membership_paid_periods_update'));
        $this->assertTrue($this->guardExists('production_membership_paid_periods_delete'));
    }

    public function test_foreign_table_same_as_reserved_guard_refuses_without_dropping_marker(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE production_membership_paid_periods_update (marker INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO production_membership_paid_periods_update VALUES (9123)');
        $this->refuses(fn () => (new MembershipSchema)->up());
        $this->assertSame(9123, (int) $pdo->query('SELECT marker FROM production_membership_paid_periods_update')->fetchColumn());
    }

    public function test_native_foreign_check_reserves_actual_global_symbol_before_any_owned_ddl(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native schema-global CHECK namespace required.');
        }
        $pdo = DB::connection()->getPdo();
        $this->dropEmptyOwned();
        $pdo->exec('CREATE TABLE foreign_member_check (marker INT PRIMARY KEY, CONSTRAINT production_membership_credit_events_bounds CHECK (marker>0)) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO foreign_member_check VALUES (5)');
        $this->refuses(fn () => (new MembershipSchema)->up());
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_membership_plan_versions'")->fetchColumn());
        $this->assertSame(5, (int) $pdo->query('SELECT marker FROM foreign_member_check')->fetchColumn());
    }

    public function test_native_foreign_table_local_unique_does_not_pollute_owned_fk_dictionary(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native independent UNIQUE/FK namespaces required.');
        }
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE foreign_member_unique (marker INT, CONSTRAINT production_membership_paid_periods_f0 UNIQUE (marker)) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO foreign_member_unique VALUES (8)');
        (new MembershipSchema)->assertOwned($pdo);
        $this->assertSame(8, (int) $pdo->query('SELECT marker FROM foreign_member_unique')->fetchColumn());
        $this->assertNotNull($pdo->query('SHOW CREATE TABLE production_membership_paid_periods')->fetchColumn(1));
    }

    public function test_native_changed_enum_check_is_not_normalized_into_owned_provenance(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native enum dictionary validation required.');
        }
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE production_membership_plan_versions DROP CHECK production_membership_plan_versions_bounds, ADD CONSTRAINT production_membership_plan_versions_bounds CHECK (length(id)=36 AND length(seal)=64 AND length(policy_hash)=64 AND length(original_terms_hash)=64 AND provenance IN ('synthetic_rehearsal','unverified_claim'))");
        $this->refuses(fn () => (new MembershipSchema)->assertOwned($pdo));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM production_membership_plan_versions')->fetchColumn());
    }

    public function test_data_bearing_partial_guard_prefix_refuses_and_retains_original_plan(): void
    {
        $plan = $this->plan();
        $pdo = DB::connection()->getPdo();
        $this->dropEmptyDependentMemberOriginals();
        foreach (array_reverse(array_slice(MembershipSchema::TABLES, 1)) as $table) {
            $pdo->exec('DROP TABLE '.$table);
        }
        $pdo->exec('DROP TRIGGER production_membership_plan_versions_delete');
        $this->refuses(fn () => (new MembershipSchema)->up());
        $this->assertSame($plan, $pdo->query('SELECT * FROM production_membership_plan_versions')->fetch(PDO::FETCH_ASSOC));
        $this->assertFalse($this->guardExists('production_membership_plan_versions_delete'));
    }

    public function test_single_invoice_is_global_immutable_and_cannot_bind_different_persisted_owner(): void
    {
        $period = $this->period();
        $other = CustomerFixtures::account();
        $changed = [...$period, 'id' => (string) Str::uuid(), 'account_id' => $other['account']->id, 'user_id' => $other['user']->id];
        $this->pdoRefuses(fn () => $this->insert(MembershipSchema::TABLES[1], $changed));
        $changed['source_invoice_hash'] = hash('sha256', 'different invoice');
        $changed['user_id'] = $period['user_id'];
        $this->pdoRefuses(fn () => $this->insert(MembershipSchema::TABLES[1], $changed));
        $this->assertSame(1, DB::table(MembershipSchema::TABLES[1])->count());
        $this->pdoRefuses(fn () => DB::connection()->getPdo()->exec('UPDATE production_membership_paid_periods SET allowance=2'));
        $this->pdoRefuses(fn () => DB::connection()->getPdo()->exec('DELETE FROM production_membership_paid_periods'));
    }

    public function test_reserved_intent_stays_pending_until_exact_distinct_member_purpose_terminal_event(): void
    {
        $p = $this->period();
        $award = $this->event($p, 'award', 1, null, null, ['available' => 2, 'reserved' => 0, 'consumed' => 0, 'expired' => 0]);
        $this->insert(MembershipSchema::TABLES[3], $award);
        $r = $this->redemption($p);
        $reserve = $this->event($p, 'reserve', 2, $award, $r, ['available' => 1, 'reserved' => 1, 'consumed' => 0, 'expired' => 0]);
        $this->insert(MembershipSchema::TABLES[3], $reserve);
        $this->assertSame('reserve', DB::table(MembershipSchema::TABLES[3])->orderByDesc('sequence')->value('kind'));
        $bad = $this->event($p, 'consume', 3, $reserve, $r, ['available' => 1, 'reserved' => 0, 'consumed' => 1, 'expired' => 0]);
        $bad['reservation_event_id'] = $reserve['id'];
        $this->pdoRefuses(fn () => $this->insert(MembershipSchema::TABLES[3], $bad));
        $bad['grant_origin_id'] = (string) Str::uuid();
        $bad['grant_receipt_hash'] = hash('sha256', 'synthetic receipt');
        $bad['grant_purpose'] = 'paid-license-grant-v1';
        $this->pdoRefuses(fn () => $this->insert(MembershipSchema::TABLES[3], $bad));
        $this->assertSame(2, DB::table(MembershipSchema::TABLES[3])->count());
        $bad['grant_purpose'] = MemberGrantIntent::PURPOSE;
        $this->insert(MembershipSchema::TABLES[3], $bad);
        $duplicate = [...$bad, 'id' => (string) Str::uuid(), 'sequence' => 4, 'prior_seal' => $bad['seal'], 'key_hash' => hash('sha256', 'duplicate')];
        $this->pdoRefuses(fn () => $this->insert(MembershipSchema::TABLES[3], $duplicate));
        $this->assertSame(3, DB::table(MembershipSchema::TABLES[3])->count());
        // Only a structural fixture; no producer supplies readiness and no real credit/grant is activated.
        $this->pdoRefuses(fn () => DB::connection()->getPdo()->exec('DELETE FROM production_membership_credit_events'));
    }

    public function test_request_key_cannot_be_reused_across_periods_or_award_fabricate_grant_fields(): void
    {
        $p = $this->period();
        $r = $this->redemption($p);
        $p2 = $this->period();
        $r2 = [...$r, 'id' => (string) Str::uuid(), 'period_id' => $p2['id'], 'owner_binding_hash' => $p2['owner_binding_hash']];
        $this->pdoRefuses(fn () => $this->insert(MembershipSchema::TABLES[2], $r2));
        $a = $this->event($p, 'award', 1, null, null, ['available' => 2, 'reserved' => 0, 'consumed' => 0, 'expired' => 0]);
        $a['grant_origin_id'] = (string) Str::uuid();
        $this->pdoRefuses(fn () => $this->insert(MembershipSchema::TABLES[3], $a));
        $this->assertSame(0, DB::table(MembershipSchema::TABLES[3])->count());
    }

    public function test_raw_reader_closes_after_framework_commit_and_direct_pdo_commit_reopen(): void
    {
        $rows = DB::transaction(function () {
            $r = new MembershipRows;
            $this->assertSame([], $r->rows(MembershipSchema::TABLES[1], '1=1', [], 2));

            return $r;
        });
        $this->refuses(fn () => $rows->assertCurrent());
        DB::beginTransaction();
        try {
            $r = new MembershipRows;
            $pdo = $r->identity();
            $pdo->commit();
            $pdo->beginTransaction();
            $this->refuses(fn () => $r->assertCurrent());
        } finally {
            DB::rollBack();
        }
    }

    public function test_raw_reader_refuses_shadow_floor_and_preserves_owned_rows(): void
    {
        $plan = $this->plan();
        DB::transaction(function () use ($plan) {
            $r = new MembershipRows;
            $pdo = $r->identity();
            $pdo->exec((DB::getDriverName() === 'mysql' ? 'CREATE TEMPORARY TABLE ' : 'CREATE TEMP TABLE ').'production_membership_paid_periods (marker INT)');
            try {
                $this->refuses(fn () => $r->assertCurrent());
                $this->assertSame($plan['id'], DB::table(MembershipSchema::TABLES[0])->value('id'));
            } finally {
                // Plain DROP TABLE commits implicitly on MySQL even for a temporary table and would end
                // the surrounding transaction before its own commit; DROP TEMPORARY TABLE does not.
                $pdo->exec(DB::getDriverName() === 'sqlite' ? 'DROP TABLE temp.production_membership_paid_periods' : 'DROP TEMPORARY TABLE production_membership_paid_periods');
            }
        });
    }

    public function test_down_refuses_without_query_or_schema_mutation(): void
    {
        $plan = $this->plan();
        $before = DB::connection()->getPdo()->query('SELECT * FROM production_membership_plan_versions')->fetchAll(PDO::FETCH_ASSOC);
        try {
            (new MembershipSchema)->down();
            $this->fail('No operational rollback.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $this->assertSame($before, DB::connection()->getPdo()->query('SELECT * FROM production_membership_plan_versions')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame($plan['id'], $before[0]['id']);
    }

    private function plan(): array
    {
        $id = (string) Str::uuid();
        $p = ['id' => $id, 'policy_hash' => hash('sha256', $id), 'original_terms_hash' => str_repeat('a', 64), 'provenance' => 'synthetic_rehearsal', 'payload_ciphertext' => 'synthetic encrypted placeholder, not policy proof', 'seal' => hash('sha256', 'plan'.$id), 'created_at' => '2026-10-07 00:00:00'];
        $this->insert(MembershipSchema::TABLES[0], $p);

        return $p;
    }

    private function period(): array
    {
        $c = CustomerFixtures::account();
        $plan = $this->plan();
        $id = (string) Str::uuid();
        $p = ['id' => $id, 'account_id' => $c['account']->id, 'user_id' => $c['user']->id, 'identity_origin_id' => 1, 'plan_version_id' => $plan['id'], 'source_invoice_hash' => hash('sha256', 'invoice'.$id), 'invoice_graph_hash' => hash('sha256', 'graph'.$id), 'owner_binding_hash' => hash('sha256', 'owner'.$id), 'allowance' => 2, 'period_start' => '2026-10-07 00:00:00', 'period_end' => '2026-11-07 00:00:00', 'credit_expires_at' => null, 'payload_ciphertext' => 'synthetic placeholder, not invoice proof', 'seal' => hash('sha256', 'period'.$id), 'created_at' => '2026-10-07 00:00:00'];
        $this->insert(MembershipSchema::TABLES[1], $p);

        return $p;
    }

    private function redemption(array $p): array
    {
        $id = (string) Str::uuid();
        $r = ['id' => $id, 'period_id' => $p['id'], 'request_key_hash' => hash('sha256', 'request'.$id), 'owner_binding_hash' => $p['owner_binding_hash'], 'credit_amount' => 1, 'selection_hash' => str_repeat('a', 64), 'license_manifest_hash' => str_repeat('b', 64), 'asset_manifest_hash' => str_repeat('c', 64), 'original_terms_hash' => str_repeat('d', 64), 'intent_hash' => hash('sha256', 'intent'.$id), 'honor_deadline' => '2026-10-08 00:00:00', 'payload_ciphertext' => 'synthetic placeholder, not eligible license proof', 'seal' => hash('sha256', 'redemption'.$id), 'created_at' => '2026-10-07 00:00:01'];
        $this->insert(MembershipSchema::TABLES[2], $r);

        return $r;
    }

    private function event(array $p, string $kind, int $sequence, ?array $previous, ?array $redemption, array $after): array
    {
        $id = (string) Str::uuid();
        $e = ['id' => $id, 'period_id' => $p['id'], 'sequence' => $sequence, 'kind' => $kind, 'amount' => $kind === 'award' ? 2 : 1, 'redemption_id' => $redemption['id'] ?? null, 'reservation_event_id' => null, 'grant_origin_id' => null, 'grant_receipt_hash' => null, 'grant_purpose' => null, 'key_hash' => hash('sha256', 'key'.$id), 'request_hash' => hash('sha256', 'request'.$id), 'prior_seal' => $previous['seal'] ?? str_repeat('0', 64), 'actor_binding_hash' => hash('sha256', 'actor'.$id), 'seal' => hash('sha256', 'event'.$id), 'created_at' => $kind === 'award' ? $p['created_at'] : '2026-10-07 00:00:02'];
        foreach (['available', 'reserved', 'consumed', 'expired'] as $b) {
            $e['before_'.$b] = $previous['after_'.$b] ?? 0;
            $e['after_'.$b] = $after[$b];
        }

        return $e;
    }

    private function insert(string $table, array $row): void
    {
        $pdo = DB::connection()->getPdo();
        $statement = $pdo->prepare('INSERT INTO '.(new MembershipSchema)->table($table).' ('.implode(',', array_map(fn ($k) => '`'.$k.'`', array_keys($row))).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Must refuse changed or incomplete source.');
        } catch (MembershipException) {
            $this->assertTrue(true);
        }
    }

    private function pdoRefuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Immutable structural evidence must refuse.');
        } catch (PDOException) {
            $this->assertTrue(true);
        }
    }

    private function guardExists(string $name): bool
    {
        $pdo = DB::connection()->getPdo();
        $s = $pdo->prepare(DB::getDriverName() === 'sqlite' ? "SELECT 1 FROM main.sqlite_master WHERE type='trigger' AND name=?" : 'SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
        $s->execute([$name]);

        return $s->fetchColumn() !== false;
    }

    private function dropEmptyOwned(): void
    {
        $this->dropEmptyDependentMemberOriginals();
        // Composed with empty Billing259, whose subscription bindings reference 257 plan versions (drift D2).
        foreach (array_reverse(BillingSchema::TABLES) as $table) {
            DB::connection()->getPdo()->exec('DROP TABLE IF EXISTS '.$table);
        }
        foreach (array_reverse(MembershipSchema::TABLES) as $table) {
            DB::connection()->getPdo()->exec('DROP TABLE '.$table);
        }
    }

    /** Composed with empty schema 258, whose tables reference 257 parents; MySQL refuses parent drops first. */
    private function dropEmptyDependentMemberOriginals(): void
    {
        foreach (array_reverse(MemberGrantSchema::TABLES) as $table) {
            DB::connection()->getPdo()->exec('DROP TABLE IF EXISTS '.$table);
        }
    }
}
