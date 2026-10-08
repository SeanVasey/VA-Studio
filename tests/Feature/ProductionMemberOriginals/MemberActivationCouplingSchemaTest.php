<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberCreditEventFixtures as F;
use Tests\TestCase;

/**
 * Inverts the review characterization of finding F2: an activation row can no longer exist without
 * the exact 257 consume event. Structural fixtures only; none of these rows is a usable grant.
 */
class MemberActivationCouplingSchemaTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_activation_without_any_credit_event_is_refused(): void
    {
        $graph = F::graph();
        $this->refused(F::activation($graph['origin'], str_repeat('a', 64), str_repeat('b', 64)));
        $this->assertSame(0, $this->rowCount('production_membership_credit_events'));
        $this->assertSame(0, $this->rowCount('production_member_activations'));
    }

    public function test_activation_with_only_award_and_reserve_is_refused(): void
    {
        $graph = F::graph();
        [, $reserve] = F::reserve($graph['redemption']['id']);
        $this->refused(F::activation($graph['origin'], str_repeat('a', 64), $reserve['seal']));
        $this->assertSame(0, $this->rowCount('production_member_activations'));
    }

    public function test_exact_consume_admits_one_activation_and_every_mismatch_is_refused(): void
    {
        $graph = F::graph();
        $receipt = hash('sha256', 'synthetic readiness receipt');
        [, $reserve] = F::reserve($graph['redemption']['id']);
        F::consume($reserve, $graph['origin']['id'], $receipt);
        $exact = F::activation($graph['origin'], $receipt, $reserve['seal']);
        // Another readiness receipt than the one the consume event sealed.
        $this->refused([...$exact, 'readiness_receipt_hash' => hash('sha256', 'other receipt')]);
        // reservation_event_hash that is not the seal of the consumed reserve event.
        $this->refused([...$exact, 'reservation_event_hash' => hash('sha256', 'other reserve seal')]);
        $this->assertSame(0, $this->rowCount('production_member_activations'));
        F::insert(MemberGrantSchema::TABLES[4], $exact);
        $this->assertSame(1, $this->rowCount('production_member_activations'));
        $this->refused([...$exact, 'id' => (string) Str::uuid()]);
    }

    public function test_consume_for_another_origin_does_not_couple(): void
    {
        $graph = F::graph();
        $receipt = hash('sha256', 'synthetic readiness receipt');
        [, $reserve] = F::reserve($graph['redemption']['id']);
        F::consume($reserve, (string) Str::uuid(), $receipt);
        $this->refused(F::activation($graph['origin'], $receipt, $reserve['seal']));
        $this->assertSame(0, $this->rowCount('production_member_activations'));
    }

    public function test_consume_for_another_redemption_does_not_couple(): void
    {
        $graph = F::graph();
        $receipt = hash('sha256', 'synthetic readiness receipt');
        // A second redemption in the same period is reserved and consumed under this origin's id.
        $other = F::redemption($graph['period']);
        [, $reserve] = F::reserve($other['id']);
        F::consume($reserve, $graph['origin']['id'], $receipt);
        $this->refused(F::activation($graph['origin'], $receipt, $reserve['seal']));
        $this->assertSame(0, $this->rowCount('production_member_activations'));
    }

    public function test_released_reservation_cannot_be_consumed_or_activated(): void
    {
        $graph = F::graph();
        $receipt = hash('sha256', 'synthetic readiness receipt');
        [, $reserve] = F::reserve($graph['redemption']['id']);
        F::release($reserve);
        try {
            F::consume($reserve, $graph['origin']['id'], $receipt);
            $this->fail('A released reservation has a terminal event already.');
        } catch (PDOException) {
            $this->assertSame(3, $this->rowCount('production_membership_credit_events'));
        }
        $this->refused(F::activation($graph['origin'], $receipt, $reserve['seal']));
        $this->assertSame(0, $this->rowCount('production_member_activations'));
    }

    public function test_consume_after_activation_time_does_not_couple(): void
    {
        $graph = F::graph();
        $receipt = hash('sha256', 'synthetic readiness receipt');
        [, $reserve] = F::reserve($graph['redemption']['id']);
        F::consume($reserve, $graph['origin']['id'], $receipt, '2026-10-07 00:00:05');
        $this->refused(F::activation($graph['origin'], $receipt, $reserve['seal']));
    }

    public function test_additive_successor_completes_an_empty_pre_coupling_install(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('DROP TRIGGER production_member_activations_consume');
        $this->assertFalse($this->guardExists());
        $this->successor()->up();
        $this->assertTrue($this->guardExists());
        (new MemberGrantSchema)->assertOwned($pdo);
        // Idempotent: a second run inspects the complete graph and writes nothing.
        $this->successor()->up();
        $this->assertTrue($this->guardExists());
    }

    public function test_additive_successor_refuses_to_adopt_an_uncoupled_activation(): void
    {
        $pdo = DB::connection()->getPdo();
        $graph = F::graph();
        $pdo->exec('DROP TRIGGER production_member_activations_consume');
        // Pre-coupling 258 admitted an activation with zero credit events (the reviewed characterization).
        $activation = F::activation($graph['origin'], str_repeat('a', 64), str_repeat('b', 64));
        F::insert(MemberGrantSchema::TABLES[4], $activation);
        try {
            $this->successor()->up();
            $this->fail('A data-bearing uncoupled activation table must not be adopted.');
        } catch (MemberGrantException $error) {
            $this->assertSame('retained_unguarded_schema', $error->reason);
        }
        $this->assertFalse($this->guardExists());
        $this->assertSame(1, $this->rowCount('production_member_activations'));
        $this->assertSame(0, $this->rowCount('production_membership_credit_events'));
    }

    public function test_successor_down_refuses(): void
    {
        $this->expectException(\LogicException::class);
        $this->successor()->down();
    }

    private function successor(): object
    {
        return require database_path('migrations/2026_10_07_258100_couple_member_activation_consume.php');
    }

    private function refused(array $activation): void
    {
        try {
            F::insert(MemberGrantSchema::TABLES[4], $activation);
            $this->fail('An activation must be coupled to its exact consume event.');
        } catch (PDOException) {
            $this->assertTrue(true);
        }
    }

    private function rowCount(string $table): int
    {
        return (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }

    private function guardExists(): bool
    {
        $s = DB::connection()->getPdo()->prepare(DB::getDriverName() === 'sqlite' ? "SELECT 1 FROM main.sqlite_master WHERE type='trigger' AND name=?"
            : 'SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
        $s->execute(['production_member_activations_consume']);

        return $s->fetchColumn() !== false;
    }
}
