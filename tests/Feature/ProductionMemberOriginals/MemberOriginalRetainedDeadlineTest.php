<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalBudget;
use App\Domain\Grants\Member\MemberOriginals;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberOriginalCommittedClock;
use Tests\Support\MemberOriginalFixtures;
use Tests\TestCase;

require_once __DIR__.'/../../Support/MemberOriginalCommittedClock.php';

/** Actual issued budgets/windows, synthetically narrowed only; no physical elapsed-time claim. */
class MemberOriginalRetainedDeadlineTest extends TestCase
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
        MemberOriginalCommittedClock::$onFinish = null;
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_original_honor_cap_survives_retirement_and_backward_wall_time_before_detached_work(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $at = CarbonImmutable::now('UTC');
        CarbonImmutable::setTestNow($at->addMinute());
        $originalHonor = null;
        $detachedDeadline = null;
        $calls = 0;
        $operation = null;
        $operation = $this->memberOriginalOperation(function () use (&$operation, $at, &$detachedDeadline, &$calls): void {
            $budget = self::budget($operation);
            $detachedDeadline = $budget->deadlineNs();
            CarbonImmutable::setTestNow($at->subDay());
            // Deliberately consume the real original object after moving wall time backward.
            // No replacement source, budget, invoice window or observation is installed.
            $budget->shortenTo(hrtime(true) - 1);
            $calls++;
        });
        $reservations = app(MembershipReservationAuthority::class);
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($reservations, &$originalHonor): void {
            foreach (self::raw($reservations, 'memberCaptures') as $entry) {
                $context = $entry['context'];
                $binding = $context->reservationBinding();
                $honor = (int) (new \DateTimeImmutable($binding->honorDeadline, new \DateTimeZone('UTC')))->format('Uu');
                $originalHonor = self::raw($context, 'createdAtNs') + ($honor - self::raw($context, 'createdAtMicros')) * 1000;
            }
        });
        $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'workflow_deadline');
        $this->assertSame(1, $calls);
        $this->assertIsInt($originalHonor);
        $this->assertIsInt($detachedDeadline);
        $this->assertLessThanOrEqual($originalHonor, $detachedDeadline, 'Original honor must cap detached work.');
        $this->assertSame([], glob(config('filesystems.disks.local.root').'/contracts/production-member-v1/synthetic_rehearsal/'
            .$fixture['reservation']['redemption_id'].'/*/original.pdf'));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_original_invoice_window_expiring_during_shared_finish_refuses_the_source_ticket(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $starts = 0;
        $operation = $this->memberOriginalOperation(function () use (&$starts): void {
            $starts++;
        });
        $reservations = app(MembershipReservationAuthority::class);
        $consumed = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use ($operation, $reservations, &$consumed): void {
            foreach (self::raw($reservations, 'memberCaptures') as $entry) {
                $window = self::raw($entry['context'], 'invoiceFreshness');
                MemberOriginalCommittedClock::$onFinish = function () use ($window, $operation, &$consumed): void {
                    $this->assertGreaterThan(hrtime(true), $window->deadlineNs());
                    $this->assertGreaterThan(hrtime(true), self::budget($operation)->deadlineNs());
                    // Actual producer-issued window; only its private monotonic cap is narrowed.
                    (new ReflectionProperty($window, 'deadline'))->setValue($window, hrtime(true) - 1);
                    $consumed++;
                };
            }
        });
        $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'committed_source_refused');
        $this->assertSame(1, $consumed);
        $this->assertSame(0, $starts);
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    private static function raw(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }

    private static function budget(MemberOriginals $operation): MemberOriginalBudget
    {
        foreach (self::raw(self::raw($operation, 'sources'), 'workflows') as $budget => $_) {
            return $budget;
        }
        throw new \RuntimeException('Original workflow absent.');
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected original retained authority to expire.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
