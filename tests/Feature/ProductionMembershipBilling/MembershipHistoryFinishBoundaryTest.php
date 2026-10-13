<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Production\MembershipAwardContext;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipReservationContext;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipReservationFixtures;
use Tests\TestCase;

/** Genuine ordinary wrappers must close the shared finish route after their complete history proof. */
class MembershipHistoryFinishBoundaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipReservationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reservationSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function operations(): array
    {
        return ['award' => ['award'], 'reservation' => ['reservation']];
    }

    #[DataProvider('operations')]
    public function test_late_configuration_hook_is_refused_before_the_ordinary_shared_finish(string $operation): void
    {
        $fixture = $this->reservationFixture(honorOverrides: ['duration_seconds' => 300]);
        $application = $this->app;
        $repository = $application->make('config');
        $map = new ReflectionProperty(Container::class, 'beforeResolvingCallbacks');
        $original = $map->getValue($application);
        $armed = false;
        $installed = 0;
        $executed = 0;
        $context = $operation === 'award' ? MembershipAwardContext::class : MembershipReservationContext::class;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, static function () use (&$armed): void {
            $armed = true;
        });
        $real = Crypt::getFacadeRoot();
        $observed = Mockery::mock();
        $observed->shouldReceive('decryptString')->andReturnUsing(function ($ciphertext) use ($real, $application, $repository, $context, &$armed, &$installed, &$executed) {
            $plain = $real->decryptString($ciphertext);
            if ($armed && (self::inside($context, 'proveCommitted') || self::inside($context, 'committedCallbacks'))) {
                $armed = false;
                $installed++;
                $application->beforeResolving('config', function () use ($repository, &$executed): void {
                    if (self::inside(IdentityCommittedFrame::class, 'finish')) {
                        $executed++;
                        $repository->set('member-grants.enabled', false);
                    }
                });
            }

            return $plain;
        });
        Crypt::swap($observed);
        try {
            try {
                if ($operation === 'award') {
                    (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
                } else {
                    $this->reserveFixture($fixture);
                }
                $this->fail('A late executable finish hook was admitted.');
            } catch (MembershipException $error) {
                $this->assertSame('committed_receipt_refused', $error->reason);
            }
            $this->assertSame(1, $installed);
            $this->assertSame(0, $executed, 'Refuse before the callback can run inside physical finish.');
        } finally {
            $map->setValue($application, $original);
            Crypt::swap($real);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame(1, DB::table('production_membership_paid_periods')->count());
        $this->assertSame($operation === 'award' ? 1 : 2, DB::table('production_membership_credit_events')->count());
        $this->assertSame($operation === 'award' ? 0 : 1, DB::table('production_membership_redemptions')->count());
        $this->assertSame(0, DB::table('production_member_origins')->count());
    }

    private static function inside(string $class, string $method): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 55) as $frame) {
            if (($frame['class'] ?? null) === $class && ($frame['function'] ?? null) === $method) {
                return true;
            }
        }

        return false;
    }
}
