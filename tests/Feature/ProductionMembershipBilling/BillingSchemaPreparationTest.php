<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Production\MembershipSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDOException;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Structural synthetic rows only; no row here is a subscription approval, a paid invoice or an award. */
class BillingSchemaPreparationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_fresh_schema_is_owned_without_identity_child_dependencies_and_retry_is_idempotent(): void
    {
        $pdo = DB::connection()->getPdo();
        (new BillingSchema)->up();
        (new BillingSchema)->assertOwned($pdo);
        (new MembershipSchema)->assertOwned($pdo);
        [$present] = (new IdentityMigrationOwnership)->inspect($pdo, DB::getDriverName());
        $this->assertNotContains(false, $present);
        foreach (BillingSchema::TABLES as $table) {
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
        }
        $sql = DB::getDriverName() === 'sqlite'
            ? $pdo->query("SELECT group_concat(sql, ' ') FROM main.sqlite_master WHERE name LIKE 'production_membership_billing%'")->fetchColumn()
            : $pdo->query("SELECT GROUP_CONCAT(ACTION_STATEMENT SEPARATOR ' ') FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE LIKE 'production\\_membership\\_billing%'")->fetchColumn();
        $this->assertStringNotContainsString('production_identity_', (string) $sql);
    }

    public function test_bindings_invoices_observations_and_events_are_immutable(): void
    {
        F::configure();
        $binding = F::binding();
        $invoice = $this->invoice($binding);
        $this->insert(BillingSchema::TABLES[2], $this->observation($invoice, 1, str_repeat('0', 64), 'unknown'));
        $this->insert(BillingSchema::TABLES[3], $this->event());
        $pdo = DB::connection()->getPdo();
        foreach (BillingSchema::TABLES as $table) {
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
            $this->pdoRefuses(fn () => $pdo->exec('UPDATE '.$table.' SET created_at = created_at'));
            $this->pdoRefuses(fn () => $pdo->exec('DELETE FROM '.$table));
        }
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[0], [...$binding, 'id' => (string) Str::uuid()]));
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM production_membership_billing_subscriptions')->fetchColumn());
    }

    /**
     * Review R-6: the append refuses an observation whose retrieval began before the one that produced the tail, and the guard
     * trigger enforces the same rule below the application. An equal start is admitted (microsecond ties cannot be ordered).
     */
    public function test_an_observation_that_began_before_the_tail_is_refused_by_the_guard_and_an_equal_or_later_start_is_admitted(): void
    {
        F::configure();
        $binding = F::binding();
        $invoice = $this->invoice($binding);
        $first = [...$this->observation($invoice, 1, str_repeat('0', 64), 'unknown'), 'retrieval_started_at' => '2026-10-07 00:00:05.250000'];
        $this->insert(BillingSchema::TABLES[2], $first);
        $older = [...$this->observation($invoice, 2, $first['seal'], 'unknown'), 'retrieval_started_at' => '2026-10-07 00:00:05.249999'];
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $older));
        $equal = [...$older, 'retrieval_started_at' => '2026-10-07 00:00:05.250000'];
        $this->insert(BillingSchema::TABLES[2], $equal);
        $later = [...$this->observation($invoice, 3, $equal['seal'], 'unknown'), 'retrieval_started_at' => '2026-10-07 00:00:06.000000'];
        $this->insert(BillingSchema::TABLES[2], $later);
        $this->assertSame(3, (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM production_membership_billing_observations')->fetchColumn());
    }

    public function test_the_retrieval_start_must_be_a_microsecond_timestamp(): void
    {
        F::configure();
        $binding = F::binding();
        $invoice = $this->invoice($binding);
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2],
            [...$this->observation($invoice, 1, str_repeat('0', 64), 'unknown'), 'retrieval_started_at' => '2026-10-07 00:00:01']));
        $this->insert(BillingSchema::TABLES[2], $this->observation($invoice, 1, str_repeat('0', 64), 'unknown'));
        $this->assertSame(1, (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM production_membership_billing_observations')->fetchColumn());
    }

    public function test_binding_requires_existing_plan_version_of_same_provenance_and_active_verified_owner(): void
    {
        F::configure();
        $binding = F::binding();
        $other = [...$binding, 'id' => (string) Str::uuid(), 'subscription_ref_hash' => hash('sha256', 'other synthetic subscription')];
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[0], [...$other, 'plan_version_id' => (string) Str::uuid()]));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[0], [...$other, 'provenance' => 'verified_production']));
        DB::table('users')->where('id', $binding['user_id'])->update(['email_verified_at' => null]);
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[0], $other));
    }

    public function test_invoice_identity_is_unique_and_scoped_to_its_binding_account_and_mode(): void
    {
        F::configure();
        $binding = F::binding();
        $invoice = $this->invoice($binding);
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[1], [...$invoice, 'id' => (string) Str::uuid(), 'source_invoice_hash' => hash('sha256', 'x')]));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[1], [...$invoice, 'id' => (string) Str::uuid(), 'invoice_ref_hash' => hash('sha256', 'y')]));
        $fresh = [...$invoice, 'id' => (string) Str::uuid(), 'invoice_ref_hash' => hash('sha256', 'a'), 'source_invoice_hash' => hash('sha256', 'b')];
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[1], [...$fresh, 'mode' => 'live']));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[1], [...$fresh, 'provider_account_hash' => hash('sha256', 'foreign account')]));
        $this->insert(BillingSchema::TABLES[1], $fresh);
    }

    public function test_observations_are_contiguous_hash_linked_and_settled_requires_period_and_amount(): void
    {
        F::configure();
        $invoice = $this->invoice(F::binding());
        $first = $this->observation($invoice, 1, str_repeat('0', 64), 'unknown');
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], [...$this->observation($invoice, 2, str_repeat('0', 64), 'unknown')]));
        $this->insert(BillingSchema::TABLES[2], $first);
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $this->observation($invoice, 3, $first['seal'], 'unknown')));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $this->observation($invoice, 2, hash('sha256', 'not the prior seal'), 'unknown')));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $this->observation($invoice, 2, $first['seal'], 'settled')));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $this->observation($invoice, 2, $first['seal'], 'awarded')));
        $settled = [...$this->observation($invoice, 2, $first['seal'], 'settled'), 'line_period_start' => '2026-10-07 00:00:00',
            'line_period_end' => '2026-11-07 00:00:00', 'amount_minor' => F::AMOUNT, 'currency' => F::CURRENCY];
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], [...$settled, 'amount_minor' => 0]));
        $this->insert(BillingSchema::TABLES[2], $settled);
        $this->assertSame(2, (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM production_membership_billing_observations')->fetchColumn());
    }

    public function test_events_deduplicate_by_provider_event_identity(): void
    {
        $event = $this->event();
        $this->insert(BillingSchema::TABLES[3], $event);
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[3], [...$event, 'id' => (string) Str::uuid()]));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[3], [...$event, 'id' => (string) Str::uuid(), 'provider_event_ref_hash' => hash('sha256', 'e2'), 'disposition' => 'awarded']));
    }

    public function test_contiguous_empty_tail_restarts_but_a_data_bearing_unguarded_prefix_is_not_adopted(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('DROP TRIGGER production_membership_billing_events_delete');
        (new BillingSchema)->up();
        $this->assertTrue($this->guardExists('production_membership_billing_events_delete'));
        F::configure();
        F::binding();
        $pdo->exec('DROP TRIGGER production_membership_billing_subscriptions_delete');
        $this->refuses(fn () => (new BillingSchema)->up());
        $this->assertFalse($this->guardExists('production_membership_billing_subscriptions_delete'));
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM production_membership_billing_subscriptions')->fetchColumn());
    }

    public function test_reserved_guard_name_collision_refuses_and_retains_foreign_marker(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE production_membership_billing_invoices_update (marker INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO production_membership_billing_invoices_update VALUES (4242)');
        $this->refuses(fn () => (new BillingSchema)->up());
        $this->assertSame(4242, (int) $pdo->query('SELECT marker FROM production_membership_billing_invoices_update')->fetchColumn());
    }

    public function test_down_refuses_without_mutation(): void
    {
        F::configure();
        $binding = F::binding();
        try {
            (new BillingSchema)->down();
            $this->fail('No operational rollback of billing evidence.');
        } catch (LogicException) {
            $this->assertSame($binding['id'], DB::connection()->getPdo()->query('SELECT id FROM production_membership_billing_subscriptions')->fetchColumn());
        }
    }

    private function event(): array
    {
        return ['id' => (string) Str::uuid(), 'provider_event_ref_hash' => hash('sha256', 'evt'), 'type' => 'invoice.paid', 'mode' => 'test',
            'provider_account_hash' => hash('sha256', 'account'), 'invoice_ref_hash' => null, 'received_at' => '2026-10-07 00:00:00',
            'payload_hash' => hash('sha256', 'payload'), 'disposition' => 'no_invoice_hint', 'payload_ciphertext' => 'synthetic placeholder',
            'seal' => hash('sha256', 'seal'), 'created_at' => '2026-10-07 00:00:00'];
    }

    private function invoice(array $binding): array
    {
        $id = (string) Str::uuid();
        $row = ['id' => $id, 'subscription_binding_id' => $binding['id'], 'invoice_ref_hash' => hash('sha256', 'invoice'.$id),
            'source_invoice_hash' => hash('sha256', 'source'.$id), 'provider_account_hash' => $binding['provider_account_hash'], 'mode' => 'test',
            'payload_ciphertext' => 'synthetic placeholder', 'seal' => hash('sha256', 'seal'.$id), 'created_at' => '2026-10-07 00:00:00'];
        $this->insert(BillingSchema::TABLES[1], $row);

        return $row;
    }

    private function observation(array $invoice, int $sequence, string $prior, string $outcome): array
    {
        $id = (string) Str::uuid();

        return ['id' => $id, 'invoice_id' => $invoice['id'], 'sequence' => $sequence, 'outcome' => $outcome, 'facts_hash' => hash('sha256', 'facts'.$id),
            'line_period_start' => null, 'line_period_end' => null, 'amount_minor' => null, 'currency' => null,
            'retrieved_at' => '2026-10-07 00:00:01', 'retrieval_started_at' => '2026-10-07 00:00:00.500000', 'freshness_deadline' => '2026-10-07 00:10:01', 'api_version' => '2026-08-26.dahlia',
            'sdk_reference' => '0d8b075e1a97d15c5324353a5277d0ea686ea525', 'prior_seal' => $prior, 'payload_ciphertext' => 'synthetic placeholder',
            'seal' => hash('sha256', 'seal'.$id), 'created_at' => '2026-10-07 00:00:02'];
    }

    private function insert(string $table, array $row): void
    {
        DB::connection()->getPdo()->prepare('INSERT INTO '.(new BillingSchema)->table($table).' ('.implode(',', array_map(fn ($k) => '`'.$k.'`', array_keys($row)))
            .') VALUES ('.implode(',', array_fill(0, count($row), '?')).')')->execute(array_values($row));
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Must refuse changed or incomplete billing schema.');
        } catch (BillingException) {
            $this->assertTrue(true);
        }
    }

    private function pdoRefuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Immutable billing evidence must refuse.');
        } catch (PDOException) {
            $this->assertTrue(true);
        }
    }

    private function guardExists(string $name): bool
    {
        $s = DB::connection()->getPdo()->prepare(DB::getDriverName() === 'sqlite' ? "SELECT 1 FROM main.sqlite_master WHERE type='trigger' AND name=?"
            : 'SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
        $s->execute([$name]);

        return $s->fetchColumn() !== false;
    }
}
