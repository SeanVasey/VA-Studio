<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use PDOException;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Only synthetic structural fixtures. Ciphertext placeholders never authorize grants or legal originals. */
class MemberOriginalSchemaPreparationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_owned_retry_preserves_all_parent_originals_without_identity_child_dependencies(): void
    {
        $pdo = DB::connection()->getPdo();
        $profile = $this->profile();
        (new MemberGrantSchema)->up();
        (new MembershipSchema)->assertOwned($pdo);
        [$present] = (new IdentityMigrationOwnership)->inspect($pdo, DB::getDriverName());
        $this->assertNotContains(false, $present);
        $this->assertSame($profile, $pdo->query('SELECT * FROM production_member_profiles')->fetch(PDO::FETCH_ASSOC));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM production_member_activations')->fetchColumn());
    }

    public function test_contiguous_empty_tail_can_restart_but_an_earlier_guard_hole_is_not_owned(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('DROP TRIGGER production_member_activations_delete');
        (new MemberGrantSchema)->up();
        $pdo->exec('DROP TRIGGER production_member_profiles_update');
        $this->refuses(fn () => (new MemberGrantSchema)->up());
        $this->assertFalse($this->guardExists('production_member_profiles_update'));
        $this->assertTrue($this->guardExists('production_member_profiles_delete'));
    }

    public function test_data_bearing_unguarded_prefix_cannot_be_adopted_or_deleted(): void
    {
        $pdo = DB::connection()->getPdo();
        $profile = $this->profile();
        foreach (array_reverse(array_slice(MemberGrantSchema::TABLES, 1)) as $table) {
            $pdo->exec('DROP TABLE '.$table);
        }
        $pdo->exec('DROP TRIGGER production_member_profiles_delete');
        $this->refuses(fn () => (new MemberGrantSchema)->up());
        $this->assertSame($profile, $pdo->query('SELECT * FROM production_member_profiles')->fetch(PDO::FETCH_ASSOC));
        $this->assertFalse($this->guardExists('production_member_profiles_delete'));
    }

    public function test_reserved_guard_table_collision_retains_foreign_marker_on_both_drivers(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE production_member_origins_update (marker INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO production_member_origins_update VALUES (9123)');
        $this->refuses(fn () => (new MemberGrantSchema)->up());
        $this->assertSame(9123, (int) $pdo->query('SELECT marker FROM production_member_origins_update')->fetchColumn());
    }

    public function test_profiles_definitions_cannot_adopt_an_old_family_or_rewrite_original_terms(): void
    {
        $profile = $this->profile();
        $definition = $this->definition($profile);
        $bad = [...$definition, 'id' => (string) Str::uuid(), 'definition_hash' => hash('sha256', 'different'),
            'family' => 'production-paid-origin-v1'];
        $this->pdoRefuses(fn () => $this->insert(MemberGrantSchema::TABLES[1], $bad));
        $this->pdoRefuses(fn () => DB::connection()->getPdo()->exec('UPDATE production_member_profiles SET original_terms_hash=profile_hash'));
        $this->pdoRefuses(fn () => DB::connection()->getPdo()->exec('DELETE FROM production_member_definitions'));
        $this->assertSame(1, (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM production_member_definitions')->fetchColumn());
    }

    public function test_pending_original_requires_bounded_contiguous_artifacts_before_structural_activation(): void
    {
        $origin = $this->origin();
        $activation = $this->activation($origin);
        $this->pdoRefuses(fn () => $this->insert(MemberGrantSchema::TABLES[4], $activation));
        $artifact = $this->artifact($origin, 0, 'member_contract');
        $this->insert(MemberGrantSchema::TABLES[3], $artifact);
        $this->pdoRefuses(fn () => $this->insert(MemberGrantSchema::TABLES[3], $this->artifact($origin, 2, 'gap')));
        $this->pdoRefuses(fn () => $this->insert(MemberGrantSchema::TABLES[3], $this->artifact($origin, 1, 'member_contract')));
        $this->pdoRefuses(fn () => $this->insert(MemberGrantSchema::TABLES[4], $activation));
        $this->insert(MemberGrantSchema::TABLES[3], $this->artifact($origin, 1, 'licensed_audio'));
        $this->insert(MemberGrantSchema::TABLES[4], $activation);
        $this->pdoRefuses(fn () => $this->insert(MemberGrantSchema::TABLES[4], [...$activation, 'id' => (string) Str::uuid()]));
        $this->pdoRefuses(fn () => DB::connection()->getPdo()->exec('DELETE FROM production_member_artifacts'));
        $this->assertSame(1, (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM production_member_activations')->fetchColumn());
        // No private files or producer readiness exist: this row is not an operative usable grant.
    }

    public function test_down_refuses_before_mutating_originals_or_parent_history(): void
    {
        $profile = $this->profile();
        try {
            (new MemberGrantSchema)->down();
            $this->fail('A preparation rollback cannot discard originals.');
        } catch (LogicException) {
            $this->assertSame($profile, DB::connection()->getPdo()->query('SELECT * FROM production_member_profiles')->fetch(PDO::FETCH_ASSOC));
        }
    }

    public function test_native_global_check_reservation_is_inspected_before_the_first_owned_ddl(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native schema-global CHECK namespace required.');
        }
        $pdo = DB::connection()->getPdo();
        foreach (array_reverse(MemberGrantSchema::TABLES) as $table) {
            $pdo->exec('DROP TABLE '.$table);
        }
        $pdo->exec('CREATE TABLE foreign_member_original_check (marker INT PRIMARY KEY, CONSTRAINT production_member_activations_bounds CHECK (marker>0)) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO foreign_member_original_check VALUES (5)');
        $this->refuses(fn () => (new MemberGrantSchema)->up());
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_member_profiles'")->fetchColumn());
        $this->assertSame(5, (int) $pdo->query('SELECT marker FROM foreign_member_original_check')->fetchColumn());
    }

    private function profile(): array
    {
        $id = (string) Str::uuid();
        $row = ['id' => $id, 'profile_hash' => hash('sha256', $id), 'original_terms_hash' => str_repeat('d', 64),
            'implementation_hash' => str_repeat('e', 64), 'font_manifest_hash' => str_repeat('f', 64),
            'provenance' => 'synthetic_rehearsal', 'payload_ciphertext' => 'synthetic placeholder, not member terms/profile approval',
            'seal' => hash('sha256', 'profile'.$id), 'created_at' => '2026-10-07 00:00:00'];
        $this->insert(MemberGrantSchema::TABLES[0], $row);

        return $row;
    }

    private function definition(array $profile): array
    {
        $id = (string) Str::uuid();
        $row = ['id' => $id, 'profile_id' => $profile['id'], 'definition_hash' => hash('sha256', $id),
            'original_terms_hash' => $profile['original_terms_hash'], 'policy_hash' => str_repeat('a', 64),
            'license_manifest_hash' => str_repeat('b', 64), 'asset_manifest_hash' => str_repeat('c', 64),
            'retention_policy_hash' => str_repeat('a', 64), 'family' => MemberGrantIntent::FAMILY,
            'purpose' => MemberGrantIntent::PURPOSE, 'version' => 1, 'provenance' => 'synthetic_rehearsal',
            'payload_ciphertext' => 'synthetic placeholder, not benefit or licensing facts',
            'seal' => hash('sha256', 'definition'.$id), 'created_at' => '2026-10-07 00:00:00'];
        $this->insert(MemberGrantSchema::TABLES[1], $row);

        return $row;
    }

    private function origin(): array
    {
        $profile = $this->profile();
        $definition = $this->definition($profile);
        $period = $this->period();
        $redemption = $this->redemption($period);
        $id = (string) Str::uuid();
        $row = ['id' => $id, 'redemption_id' => $redemption['id'], 'definition_id' => $definition['id'],
            'profile_id' => $profile['id'], 'account_id' => $period['account_id'], 'user_id' => $period['user_id'],
            'identity_origin_id' => 1, 'invoice_identity_hash' => $period['source_invoice_hash'],
            'owner_binding_hash' => $redemption['owner_binding_hash'], 'intent_hash' => $redemption['intent_hash'],
            'license_manifest_hash' => $redemption['license_manifest_hash'], 'asset_manifest_hash' => $redemption['asset_manifest_hash'],
            'original_terms_hash' => $redemption['original_terms_hash'], 'artifact_count' => 2,
            'artifact_manifest_hash' => str_repeat('f', 64), 'honor_deadline' => $redemption['honor_deadline'],
            'provenance' => 'synthetic_rehearsal', 'payload_ciphertext' => 'synthetic placeholder, not original buyer authority',
            'seal' => hash('sha256', 'origin'.$id), 'created_at' => '2026-10-07 00:00:02'];
        $this->insert(MemberGrantSchema::TABLES[2], $row);

        return $row;
    }

    private function artifact(array $origin, int $ordinal, string $role): array
    {
        $id = (string) Str::uuid();

        return ['id' => $id, 'origin_id' => $origin['id'], 'ordinal' => $ordinal, 'role' => $role,
            'sha256' => hash('sha256', $id), 'bytes' => 1, 'storage_policy_hash' => str_repeat('a', 64),
            'payload_ciphertext' => 'synthetic placeholder, no private path or bytes present',
            'seal' => hash('sha256', 'artifact'.$id), 'created_at' => '2026-10-07 00:00:03'];
    }

    private function activation(array $origin): array
    {
        $id = (string) Str::uuid();

        return ['id' => $id, 'origin_id' => $origin['id'], 'redemption_id' => $origin['redemption_id'],
            'artifact_manifest_hash' => $origin['artifact_manifest_hash'], 'readiness_receipt_hash' => str_repeat('a', 64),
            'reservation_event_hash' => str_repeat('b', 64), 'purpose' => MemberGrantIntent::PURPOSE,
            'payload_ciphertext' => 'synthetic placeholder, never a ready producer receipt',
            'seal' => hash('sha256', 'activation'.$id), 'created_at' => '2026-10-07 00:00:04'];
    }

    private function membershipPlan(): array
    {
        $id = (string) Str::uuid();
        $p = ['id' => $id, 'policy_hash' => hash('sha256', $id), 'original_terms_hash' => str_repeat('a', 64), 'provenance' => 'synthetic_rehearsal', 'payload_ciphertext' => 'synthetic encrypted placeholder, not policy proof', 'seal' => hash('sha256', 'plan'.$id), 'created_at' => '2026-10-07 00:00:00'];
        $this->insert(MembershipSchema::TABLES[0], $p);

        return $p;
    }

    private function period(): array
    {
        $c = CustomerFixtures::account();
        $plan = $this->membershipPlan();
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

    private function insert(string $table, array $row): void
    {
        $schema = in_array($table, MemberGrantSchema::TABLES, true) ? new MemberGrantSchema : new MembershipSchema;
        $pdo = DB::connection()->getPdo();
        $statement = $pdo->prepare('INSERT INTO '.$schema->table($table).' ('.implode(',', array_map(fn ($k) => '`'.$k.'`', array_keys($row))).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Must refuse changed or incomplete original schema.');
        } catch (MemberGrantException) {
            $this->assertTrue(true);
        }
    }

    private function pdoRefuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Immutable original structure must refuse.');
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
}
