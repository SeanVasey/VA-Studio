<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalFinishBoundary;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

/** Real shared committed-frame route, isolated SQLite and no migrations/application rows. */
class MemberOriginalFinishBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'member_finish', 'database.connections.member_finish' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::connection()->getPdo();
    }

    public function test_plain_cached_route_finishes_the_real_read_only_frame_once(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $boundary = MemberOriginalFinishBoundary::capture();
        $frame = IdentityCommittedFrame::begin(new CurrentRows($pdo, 'sqlite'), hrtime(true) + 1_000_000_000);
        try {
            $boundary->assertCurrent();
            $this->assertTrue($pdo->inTransaction());
            $frame->finish();
            $boundary->assertCurrent();
            $this->assertFalse($pdo->inTransaction());
            $this->assertSame(0, $connection->transactionLevel());
        } finally {
            $frame->close();
        }
    }

    public function test_config_and_global_hooks_cannot_execute_between_raw_proof_and_finish(): void
    {
        foreach ([true, false] as $global) {
            $application = app();
            $pdo = DB::connection()->getPdo();
            $boundary = MemberOriginalFinishBoundary::capture();
            $frame = IdentityCommittedFrame::begin(new CurrentRows($pdo, 'sqlite'), hrtime(true) + 1_000_000_000);
            $property = new ReflectionProperty(Container::class, $global ? 'globalBeforeResolvingCallbacks' : 'beforeResolvingCallbacks');
            $original = $property->getValue($application);
            $calls = 0;
            $repository = $application->make('config');
            $hook = function () use (&$calls, $repository) {
                $calls++;
                // Represents a sibling raw dependency being withdrawn after its final comparison.
                $repository->set('member-grants.enabled', false);
            };
            if ($global) {
                $application->beforeResolving($hook);
            } else {
                $application->beforeResolving('config', $hook);
            }
            try {
                $this->refused(function () use ($boundary, $frame) {
                    $boundary->assertCurrent();
                    $frame->finish();
                }, 'finish_callbacks');
                $this->assertSame(0, $calls);
                $this->assertTrue($pdo->inTransaction());
            } finally {
                $property->setValue($application, $original);
                $frame->close();
            }
            $this->assertFalse($pdo->inTransaction());
        }
    }

    public function test_positive_control_shared_finish_reaches_unchanged_config_hooks_without_the_member_boundary(): void
    {
        $application = app();
        $pdo = DB::connection()->getPdo();
        $repository = $application->make('config');
        $repository->set('member-grants.enabled', true);
        $frame = IdentityCommittedFrame::begin(new CurrentRows($pdo, 'sqlite'), hrtime(true) + 1_000_000_000);
        $property = new ReflectionProperty(Container::class, 'beforeResolvingCallbacks');
        $original = $property->getValue($application);
        $states = [];
        $application->beforeResolving('config', function () use (&$states, $pdo, $repository) {
            $states[] = $pdo->inTransaction();
            $repository->set('member-grants.enabled', false);
        });
        try {
            $frame->finish();
            $this->assertContains(true, $states);
            $this->assertContains(false, $states);
            $this->assertFalse($repository->get('member-grants.enabled'));
            $this->assertFalse($pdo->inTransaction());
        } finally {
            $property->setValue($application, $original);
            $frame->close();
        }
    }

    public function test_earlier_callback_work_can_complete_and_be_removed_before_final_admission(): void
    {
        $application = app();
        $pdo = DB::connection()->getPdo();
        $property = new ReflectionProperty(Container::class, 'beforeResolvingCallbacks');
        $original = $property->getValue($application);
        $calls = 0;
        $application->beforeResolving('config', function () use (&$calls) {
            $calls++;
        });
        try {
            $boundary = MemberOriginalFinishBoundary::capture();
            $this->assertSame(0, $calls);
            $application->make('config');
            $this->assertSame(1, $calls);
            $this->refused(fn () => $boundary->assertCurrent(), 'finish_callbacks');
        } finally {
            $property->setValue($application, $original);
        }
        $frame = IdentityCommittedFrame::begin(new CurrentRows($pdo, 'sqlite'), hrtime(true) + 1_000_000_000);
        try {
            $boundary->assertCurrent();
            $frame->finish();
            $boundary->assertCurrent();
            $this->assertSame(1, $calls);
        } finally {
            $frame->close();
        }
    }

    public function test_contextual_config_resolution_is_refused_before_factory_execution(): void
    {
        $application = app();
        $boundary = MemberOriginalFinishBoundary::capture();
        $calls = 0;
        $application->contextual['']['config'] = function () use (&$calls) {
            $calls++;

            return null;
        };
        try {
            $this->refused(fn () => $boundary->assertCurrent(), 'finish_callbacks');
            $this->assertSame(0, $calls);
        } finally {
            $application->contextual = [];
        }
    }

    private function refused(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('The terminal route must refuse callback-capable configuration resolution.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
