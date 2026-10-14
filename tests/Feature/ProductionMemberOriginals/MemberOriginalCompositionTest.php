<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalBudget;
use App\Domain\Grants\Member\MemberOriginalReadyToken;
use App\Domain\Grants\Member\MemberOriginals;
use App\Domain\Grants\Member\SealedMemberGrantFacts;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use App\Domain\Memberships\Production\MembershipReservationContext;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionClass;
use ReflectionProperty;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberOriginalFixtures;
use Tests\TestCase;

/** Real owned composition. Reflection observes issued identities or narrows their original cap only. */
class MemberOriginalCompositionTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberOriginalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberOriginalSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_actual_final_frame_readiness_is_issuer_bound_and_its_capture_is_retired_before_return(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $operation = $this->memberOriginalOperation();
        $artifacts = $operation->artifactAuthority();
        $reservations = app(MembershipReservationAuthority::class);
        $calls = 0;
        $saved = [];
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($artifacts, &$calls, &$saved): void {
            $map = self::raw($artifacts, 'materializations');
            foreach ($map as $materialized => $entry) {
                if ($entry['inspection'] === null) {
                    continue;
                }
                $inspection = $entry['inspection'];
                $intent = self::raw($inspection, 'intent');
                $facts = self::raw($inspection, 'factsProof');
                $principal = self::raw($inspection, 'principal');
                $actor = self::raw($inspection, 'actor');
                $rows = self::raw($inspection, 'rows');
                $receipt = $artifacts->admitReady($materialized, $intent, $facts, $principal, $actor, $rows);
                $artifacts->proveReadyCurrent($receipt, $intent, $facts, $principal, $actor, $rows);
                $this->assertSame($entry['manifest'], $receipt->binding());
                $shaped = MemberOriginalReadyToken::value($entry['manifest']);
                $this->refused(fn () => $artifacts->proveReadyCurrent($shaped, $intent, $facts, $principal, $actor, $rows), 'foreign_readiness');
                $this->refused(fn () => $artifacts->proveReadyCurrent($receipt, $intent, $facts, $principal, new User, $rows), 'foreign_readiness');
                $foreignRows = (new ReflectionClass(MembershipRows::class))->newInstanceWithoutConstructor();
                $this->refused(fn () => $artifacts->proveReadyCurrent($receipt, $intent, $facts, $principal, $actor, $foreignRows), 'foreign_readiness');
                $saved = [$receipt, $intent, $facts, $principal, $actor, $rows, self::raw($inspection, 'reservation')];
                $calls++;
            }
        });
        $candidate = $this->prepareMemberOriginal($fixture, $operation);
        $this->assertSame(1, $calls);
        [$receipt, $intent, $facts, $principal, $actor, $rows, $capture] = $saved;
        $this->refused(fn () => $artifacts->proveReadyCurrent($receipt, $intent, $facts, $principal, $actor, $rows), 'foreign_readiness');
        try {
            $reservations->memberIntent($capture, $rows);
            $this->fail('A retired capture cannot expose its old producer objects.');
        } catch (MembershipException $error) {
            $this->assertSame('foreign_member_capture', $error->reason);
        }
        $this->assertSame($fixture['reservation']['redemption_id'], $candidate->redemptionId);
        $saved = [];
        unset($rows, $facts, $intent, $receipt, $capture);
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_original_workflow_budget_can_be_consumed_before_producers_but_never_renewed(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $operation = $this->memberOriginalOperation();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use ($operation, &$calls): void {
            $budget = self::workflowBudget($operation);
            // Synthetic cap consumption, not a wall-time/performance claim.
            $budget->shortenTo(hrtime(true) - 1);
            $calls++;
        });
        $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'source_refused');
        $this->assertSame(1, $calls);
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_original_physical_cap_is_carried_into_the_fresh_final_commit_boundary(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $operation = $this->memberOriginalOperation();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($operation, &$calls): void {
            foreach (self::raw($operation->artifactAuthority(), 'materializations') as $entry) {
                $this->assertSame(self::workflowBudget($operation), $entry['budget']);
                $this->assertSame($entry['observation']->observationStartedNs + 60_000_000_000, $entry['budget']->deadlineNs());
                // Narrow the actual original cap; no alternate issuer or renewed observation is installed.
                $entry['budget']->shortenTo(hrtime(true) - 1);
                $calls++;
            }
        });
        $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'source_refused');
        $this->assertSame(1, $calls);
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_completed_regular_commit_cannot_renew_the_original_materialization_cap(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $operation = $this->memberOriginalOperation();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use ($operation, &$calls): void {
            foreach (self::raw($operation->artifactAuthority(), 'materializations') as $entry) {
                $entry['budget']->shortenTo(hrtime(true) - 1);
                $calls++;
            }
        });
        $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'committed_source_refused');
        $this->assertSame(1, $calls);
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_facts_callback_cannot_withdraw_catalog_after_reservation_admission(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $operation = $this->memberOriginalOperation();
        $primary = DB::connection()->getPdo();
        $armed = false;
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use (&$armed): void {
            $armed = true;
        });
        $real = Crypt::getFacadeRoot();
        $observed = Mockery::mock();
        $observed->shouldReceive('decryptString')->andReturnUsing(function ($ciphertext) use ($real, $fixture, $primary, &$armed, &$calls) {
            $plain = $real->decryptString($ciphertext);
            if ($armed && self::inside(SealedMemberGrantFacts::class, 'currentCallbacks')) {
                $armed = false;
                $statement = $primary->prepare('UPDATE tracks SET status = ? WHERE id = ?');
                $statement->execute(['draft', $fixture['catalog']['track']->id]);
                $calls += $statement->rowCount();
            }

            return $plain;
        });
        Crypt::swap($observed);
        try {
            $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'source_refused');
        } finally {
            Crypt::swap($real);
        }
        $this->assertSame(1, $calls);
        $this->assertSame('published', DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->value('status'));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_reservation_callback_cannot_withdraw_member_configuration_before_its_raw_closure(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $operation = $this->memberOriginalOperation();
        $at = CarbonImmutable::now('UTC');
        $armed = false;
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use (&$armed): void {
            $armed = true;
        });
        CarbonImmutable::setTestNow(function () use ($at, &$armed, &$calls) {
            if ($armed && self::inside(MembershipReservationContext::class, 'checkDeadlines')) {
                $armed = false;
                config(['member-grants.enabled' => false]);
                $calls++;
            }

            return $at;
        });
        $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'source_refused');
        CarbonImmutable::setTestNow($at);
        $this->assertSame(1, $calls);
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_final_committed_callback_hook_is_refused_before_shared_finish_can_execute_it(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $operation = $this->memberOriginalOperation();
        $application = app();
        $repository = $application->make('config');
        $property = new ReflectionProperty(Container::class, 'beforeResolvingCallbacks');
        $original = $property->getValue($application);
        $at = CarbonImmutable::now('UTC');
        $armed = false;
        $installed = 0;
        $executed = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, static function () use (&$armed): void {
            $armed = true;
        });
        CarbonImmutable::setTestNow(function () use ($application, $repository, $at, &$armed, &$installed, &$executed) {
            if ($armed && self::inside(MembershipReservationContext::class, 'checkDeadlines')) {
                $armed = false;
                $installed++;
                $application->beforeResolving('config', function () use ($repository, &$executed): void {
                    if (self::inside(IdentityCommittedFrame::class, 'finish')) {
                        $executed++;
                        $repository->set('member-grants.enabled', false);
                    }
                });
            }

            return $at;
        });
        try {
            $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'committed_source_refused');
            $this->assertSame(1, $installed);
            // Earlier callback-capable identity work may resolve config; the hook must never
            // reach the shared finish or mutate its sibling configuration after raw closure.
            $this->assertSame(0, $executed);
        } finally {
            $property->setValue($application, $original);
            CarbonImmutable::setTestNow($at);
        }
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_regular_committed_delegate_cannot_replace_the_owned_dispatcher_and_receive_a_source(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $operation = $this->memberOriginalOperation();
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $replacement = new Dispatcher($this->app);
        $calls = 0;
        $original->listen(TransactionCommitted::class, function () use ($connection, $replacement, &$calls): void {
            $connection->setEventDispatcher($replacement);
            $calls++;
        });
        try {
            $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'committed_source_refused');
            $this->assertSame(1, $calls);
            $this->assertSame($replacement, $connection->getEventDispatcher());
        } finally {
            $connection->setEventDispatcher($original);
        }
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_last_current_callback_cannot_replace_the_dispatcher_after_observer_admission(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $operation = $this->memberOriginalOperation();
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $replacement = new Dispatcher($this->app);
        $at = CarbonImmutable::now('UTC');
        $armed = false;
        $calls = 0;
        $original->listen(TransactionCommitting::class, static function () use (&$armed): void {
            $armed = true;
        });
        CarbonImmutable::setTestNow(function () use ($connection, $replacement, $at, &$armed, &$calls) {
            if ($armed && self::inside(MembershipReservationContext::class, 'checkDeadlines')) {
                $armed = false;
                $connection->setEventDispatcher($replacement);
                $calls++;
            }

            return $at;
        });
        try {
            $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'source_refused');
            $this->assertSame(1, $calls);
            $this->assertSame($replacement, $connection->getEventDispatcher());
        } finally {
            $connection->setEventDispatcher($original);
            CarbonImmutable::setTestNow($at);
        }
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_initial_facts_lock_cannot_publish_a_source_after_withdrawing_its_reservation_catalog(): void
    {
        $this->assertFactsMintWithdrawal(1);
    }

    public function test_final_facts_lock_cannot_mint_readiness_after_withdrawing_its_reservation_catalog(): void
    {
        $this->assertFactsMintWithdrawal(2);
    }

    private function assertFactsMintWithdrawal(int $targetPhase): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $begins = 0;
        $commits = 0;
        $starts = 0;
        $changed = 0;
        $operation = $this->memberOriginalOperation(function () use (&$starts): void {
            $starts++;
        });
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $connection->getEventDispatcher()->listen(TransactionBeginning::class, static function () use (&$begins): void {
            $begins++;
        });
        $connection->getEventDispatcher()->listen(TransactionCommitting::class, static function () use (&$commits): void {
            $commits++;
        });
        $real = Crypt::getFacadeRoot();
        $observed = Mockery::mock();
        $observed->shouldReceive('decryptString')->andReturnUsing(function ($ciphertext) use ($real, $fixture, $primary, $targetPhase, &$begins, &$changed) {
            $plain = $real->decryptString($ciphertext);
            if ($begins === $targetPhase && $changed === 0 && self::inside(SealedMemberGrantFacts::class, 'lock')) {
                $statement = $primary->prepare('UPDATE tracks SET status = ? WHERE id = ?');
                $statement->execute(['draft', $fixture['catalog']['track']->id]);
                $changed += $statement->rowCount();
            }

            return $plain;
        });
        Crypt::swap($observed);
        try {
            $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'source_refused');
        } finally {
            Crypt::swap($real);
        }
        $this->assertSame(1, $changed, 'The real initial facts decrypt must withdraw the earlier reservation source.');
        $this->assertSame($targetPhase, $begins);
        // First capture refuses during its terminal observer proof. Final capture must refuse
        // in public readiness, before a second normal commit can be attempted.
        $this->assertSame(1, $commits);
        $this->assertSame($targetPhase - 1, $starts);
        $this->assertSame('published', DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->value('status'));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    private static function raw(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }

    private static function workflowBudget(MemberOriginals $operation): MemberOriginalBudget
    {
        $sources = self::raw($operation, 'sources');
        foreach (self::raw($sources, 'workflows') as $budget => $_) {
            return $budget;
        }
        throw new \RuntimeException('No original workflow budget was registered.');
    }

    private static function inside(string $class, string $function): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            if (($frame['class'] ?? null) === $class && ($frame['function'] ?? null) === $function) {
                return true;
            }
        }

        return false;
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected the owned composed proof to refuse.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
