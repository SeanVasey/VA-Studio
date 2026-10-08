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

    public function test_bindings_invoices_observations_events_and_positions_are_immutable(): void
    {
        F::configure();
        $binding = F::binding();
        $invoice = $this->invoice($binding);
        $this->insert(BillingSchema::TABLES[2], $this->observation($invoice, 1, str_repeat('0', 64), 'unknown'));
        $this->insert(BillingSchema::TABLES[3], $this->event());
        $pdo = DB::connection()->getPdo();
        // One row each, and three positions: the observation's retrieval start and end and the event's hint.
        foreach (array_combine(BillingSchema::TABLES, [1, 1, 1, 1, 3]) as $table => $count) {
            $this->assertSame($count, (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
            $this->pdoRefuses(fn () => $pdo->exec('UPDATE '.$table.' SET created_at = created_at'));
            $this->pdoRefuses(fn () => $pdo->exec('DELETE FROM '.$table));
        }
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[0], [...$binding, 'id' => (string) Str::uuid()]));
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM production_membership_billing_subscriptions')->fetchColumn());
    }

    /**
     * Review R-6 and Codex P1 on PR #54 (review L2-3, then `BillingReconciliation.php:42`): the guard trigger refuses an observation
     * whose retrieval began (start position) before the tail's read ended (end position), below the application. Positions are
     * unique, so a reused one is refused too, and `retrieval_started_at` (a worker clock) orders nothing: an earlier clock reading
     * with a later position is admitted.
     */
    public function test_an_observation_from_an_earlier_or_reused_position_is_refused_by_the_guard_and_the_worker_clock_orders_nothing(): void
    {
        F::configure();
        $binding = F::binding();
        $invoice = $this->invoice($binding);
        $earlier = $this->position('retrieval');
        $first = [...$this->observation($invoice, 1, str_repeat('0', 64), 'unknown'), 'retrieval_started_at' => '2026-10-07 00:00:05.250000'];
        $this->insert(BillingSchema::TABLES[2], $first);
        $older = [...$this->observation($invoice, 2, $first['seal'], 'unknown', $earlier), 'retrieval_started_at' => '2026-10-07 00:00:09.000000'];
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $older));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], [...$older, 'retrieval_position' => $first['retrieval_position']]));
        $clockBehind = [...$this->observation($invoice, 2, $first['seal'], 'unknown'), 'retrieval_started_at' => '2026-10-07 00:00:01.000000'];
        $this->insert(BillingSchema::TABLES[2], $clockBehind);
        $later = $this->observation($invoice, 3, $clockBehind['seal'], 'unknown');
        $this->insert(BillingSchema::TABLES[2], $later);
        $this->assertSame(3, (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM production_membership_billing_observations')->fetchColumn());
    }

    public function test_positions_are_issued_only_by_the_database_and_each_backs_one_row_of_its_own_kind(): void
    {
        F::configure();
        $binding = F::binding();
        $invoice = $this->invoice($binding);
        $pdo = DB::connection()->getPdo();
        $first = $this->position('retrieval');
        $second = $this->position('hint');
        $this->assertGreaterThan($first, $second);
        // An explicit id (which could back-date a position into a gap), an unknown kind and a malformed time are refused.
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[4], ['id' => $second + 100, 'kind' => 'retrieval', 'created_at' => '2026-10-07 00:00:00']));
        $this->pdoRefuses(fn () => $pdo->exec("INSERT INTO production_membership_billing_positions (kind, created_at) VALUES ('awarded', '2026-10-07 00:00:00')"));
        $this->pdoRefuses(fn () => $pdo->exec("INSERT INTO production_membership_billing_positions (kind, created_at) VALUES ('hint', '2026-10-07')"));
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM production_membership_billing_positions')->fetchColumn());
        // An observation needs an existing retrieval position; an event needs an existing hint position; neither may borrow the other's.
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $this->observation($invoice, 1, str_repeat('0', 64), 'unknown', $second)));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $this->observation($invoice, 1, str_repeat('0', 64), 'unknown', $second + 1000)));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[3], $this->event($first)));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[3], $this->event($second + 1000)));
        $this->insert(BillingSchema::TABLES[2], $this->observation($invoice, 1, str_repeat('0', 64), 'unknown', $first));
        $this->insert(BillingSchema::TABLES[3], $this->event($second));
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[3], [...$this->event($second), 'provider_event_ref_hash' => hash('sha256', 'another synthetic event')]));
        $this->assertSame([1, 1], [(int) $pdo->query('SELECT COUNT(*) FROM production_membership_billing_observations')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM production_membership_billing_events')->fetchColumn()]);
    }

    /**
     * Codex P1 on PR #54 (`BillingReconciliation.php:42`): each observation is bracketed by a start and an end retrieval position.
     * The guard requires the end to exist with kind `retrieval`, to be above the start and unused as any observation's start or end,
     * and admits an observation only when its start is above the tail's END, so a read that overlapped the tail's read is refused.
     */
    public function test_the_end_position_must_be_an_unused_later_retrieval_position_and_a_read_must_begin_after_the_tails_read_ended(): void
    {
        F::configure();
        $binding = F::binding();
        $invoice = $this->invoice($binding);
        $other = $this->invoice($binding);
        $zero = str_repeat('0', 64);
        $spare = $this->position('retrieval');
        $start = $this->position('retrieval');
        $mid = $this->position('retrieval');
        $hint = $this->position('hint');
        $end = $this->position('retrieval');
        $row = fn (array $for, int $sequence, string $prior, int $from, mixed $to): array => [...$this->observation($for, $sequence, $prior, 'unknown', $from, 1), 'retrieval_end_position' => $to];

        $missing = $row($invoice, 1, $zero, $start, $end);
        unset($missing['retrieval_end_position']);
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $missing));
        foreach ([null, 0, -1, $start, $spare, $hint, $end + 1000] as $invalid) {
            // Missing, non-positive, equal to the start, below the start, a hint position, or never issued.
            $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $row($invoice, 1, $zero, $start, $invalid)));
        }
        $first = $row($invoice, 1, $zero, $start, $end);
        $this->insert(BillingSchema::TABLES[2], $first);

        // Neither position may back another observation, as its start or as its end, on any invoice.
        foreach ([[$spare, $end], [$start, $this->position('retrieval')], [$end, $this->position('retrieval')], [$spare, $start]] as [$from, $to]) {
            $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $row($other, 1, $zero, $from, $to)));
        }
        // A read that began before the tail's read ended (mid < end) is refused; one that began after it is admitted.
        $this->pdoRefuses(fn () => $this->insert(BillingSchema::TABLES[2], $row($invoice, 2, $first['seal'], $mid, $this->position('retrieval'))));
        $after = $this->position('retrieval');
        $this->insert(BillingSchema::TABLES[2], $row($invoice, 2, $first['seal'], $after, $this->position('retrieval')));
        $this->assertSame(2, (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM production_membership_billing_observations')->fetchColumn());
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
        $pdo->exec('DROP TRIGGER production_membership_billing_positions_delete');
        (new BillingSchema)->up();
        $this->assertTrue($this->guardExists('production_membership_billing_positions_delete'));
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

    private function event(?int $position = null): array
    {
        return ['id' => (string) Str::uuid(), 'provider_event_ref_hash' => hash('sha256', 'evt'), 'type' => 'invoice.paid', 'mode' => 'test',
            'provider_account_hash' => hash('sha256', 'account'), 'invoice_ref_hash' => null, 'received_at' => '2026-10-07 00:00:00',
            'hint_position' => $position ?? $this->position('hint'),
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

    private function observation(array $invoice, int $sequence, string $prior, string $outcome, ?int $position = null, ?int $end = null): array
    {
        $id = (string) Str::uuid();
        $position ??= $this->position('retrieval');
        // The end position is allocated after the start, as BillingLedger::endRetrieval() allocates it after the provider reads.
        $end ??= $this->position('retrieval');

        return ['id' => $id, 'invoice_id' => $invoice['id'], 'sequence' => $sequence, 'outcome' => $outcome, 'facts_hash' => hash('sha256', 'facts'.$id),
            'line_period_start' => null, 'line_period_end' => null, 'amount_minor' => null, 'currency' => null,
            'retrieved_at' => '2026-10-07 00:00:01', 'retrieval_started_at' => '2026-10-07 00:00:00.500000', 'retrieval_position' => $position, 'retrieval_end_position' => $end, 'freshness_deadline' => '2026-10-07 00:10:01', 'api_version' => '2026-08-26.dahlia',
            'sdk_reference' => '0d8b075e1a97d15c5324353a5277d0ea686ea525', 'prior_seal' => $prior, 'payload_ciphertext' => 'synthetic placeholder',
            'seal' => hash('sha256', 'seal'.$id), 'created_at' => '2026-10-07 00:00:02'];
    }

    /** A database-allocated position, as BillingLedger::position() takes one (structural rows only). */
    private function position(string $kind): int
    {
        $pdo = DB::connection()->getPdo();
        $pdo->prepare('INSERT INTO '.(new BillingSchema)->table(BillingSchema::TABLES[4]).' (kind, created_at) VALUES (?, ?)')->execute([$kind, '2026-10-07 00:00:00']);

        return (int) $pdo->lastInsertId();
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
