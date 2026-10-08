<?php

namespace Tests\Feature;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipSchema;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Disposable structural evidence only: no invoice, customer proof, award or usable grant. */
class MembershipForeignSchemaCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_foreign_schema_user_reference_is_refused_before_missing_suffix_ddl(): void
    {
        $pdo = DB::connection()->getPdo();
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $foreign = getenv('MEMBERSHIP_FOREIGN_SCHEMA');
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('vaseyaudio_service_review', $database);
        $this->assertTrue(is_string($foreign) && preg_match('/\A[a-z0-9_]+\z/D', $foreign) === 1 && $foreign !== $database);

        $id = '11111111-2222-4333-8444-555555555555';
        $plan = [
            'id' => $id,
            'policy_hash' => hash('sha256', 'synthetic policy marker'),
            'original_terms_hash' => str_repeat('a', 64),
            'provenance' => 'synthetic_rehearsal',
            'payload_ciphertext' => 'retained synthetic marker, not approved terms or encrypted policy proof',
            'seal' => hash('sha256', 'synthetic plan marker'),
            'created_at' => '2026-10-07 00:00:00',
        ];
        $insert = $pdo->prepare('INSERT INTO production_membership_plan_versions ('.implode(',', array_keys($plan)).') VALUES ('.implode(',', array_fill(0, count($plan), '?')).')');
        $insert->execute(array_values($plan));
        $pdo->exec('ALTER TABLE production_membership_paid_periods DROP FOREIGN KEY production_membership_paid_periods_f1');
        try {
            $pdo->exec('ALTER TABLE production_membership_paid_periods ADD CONSTRAINT production_membership_paid_periods_f1 FOREIGN KEY (user_id) REFERENCES `'.$foreign.'`.users(id) ON UPDATE RESTRICT ON DELETE RESTRICT');
            $pdo->exec('DROP TRIGGER production_membership_credit_events_delete');
            $before = $this->snapshot($pdo);
            $refused = false;
            $reason = null;
            try {
                (new MembershipSchema)->up();
            } catch (MembershipException $error) {
                $refused = true;
                $reason = $error->getMessage();
            }
            $after = $this->snapshot($pdo);
            $reference = $pdo->query("SELECT REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE BINARY CONSTRAINT_SCHEMA=BINARY DATABASE() AND TABLE_NAME='production_membership_paid_periods' AND CONSTRAINT_NAME='production_membership_paid_periods_f1'")->fetchAll(PDO::FETCH_ASSOC);
            $evidence = [
                'original_source_label' => 'b635e12992dc91f5131f35e5c46b8dab6ff30eee',
                'actual_native_version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
                'database' => $database,
                'foreign_schema' => $foreign,
                'reference' => $reference,
                'refused' => $refused,
                'reason' => $reason,
                'before' => $before,
                'after' => $after,
            ];
            file_put_contents(__DIR__.'/foreign-schema-snapshot.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            $this->assertSame([['REFERENCED_TABLE_SCHEMA' => $foreign, 'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'id']], $reference);
            $this->assertSame([$plan], $after['rows']['production_membership_plan_versions']);
            $this->assertTrue($refused, 'A foreign-database users reference must be refused before any owned suffix DDL.');
            $this->assertSame($before, $after, 'Refusal must retain every owned definition, guard and row.');
        } finally {
            // Remove the cross-schema dependency even when the original product assertion is red.
            $existing = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='production_membership_paid_periods' AND CONSTRAINT_NAME='production_membership_paid_periods_f1' AND CONSTRAINT_TYPE='FOREIGN KEY'")->fetchColumn();
            if ((int) $existing !== 0) {
                $pdo->exec('ALTER TABLE production_membership_paid_periods DROP FOREIGN KEY production_membership_paid_periods_f1');
            }
            $pdo->exec('ALTER TABLE production_membership_paid_periods ADD CONSTRAINT production_membership_paid_periods_f1 FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE RESTRICT ON DELETE RESTRICT');
        }
    }

    private function snapshot(PDO $pdo): array
    {
        $tables = [];
        $rows = [];
        foreach (MembershipSchema::TABLES as $table) {
            $tables[$table] = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
            $rows[$table] = $pdo->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        $guards = $pdo->query("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT, SQL_MODE, DEFINER, CHARACTER_SET_CLIENT, COLLATION_CONNECTION, DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA=BINARY DATABASE() AND EVENT_OBJECT_TABLE IN ('production_membership_plan_versions','production_membership_paid_periods','production_membership_redemptions','production_membership_credit_events') ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_ASSOC);

        return ['tables' => $tables, 'guards' => $guards, 'rows' => $rows];
    }
}
