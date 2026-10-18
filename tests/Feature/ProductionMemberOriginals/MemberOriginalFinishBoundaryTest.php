<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalFinishBoundary;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use PDO;
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

    /**
     * Positive control in a configuration the identity metadata admission accepts. That admission (#127)
     * captures only app/database/production-customer-identity metadata, so a callback-free member-grants
     * withdrawal after frame birth still reaches a completed shared finish. Member configuration freshness
     * therefore remains the member route's own proof, with this boundary closing callbacks around it.
     */
    public function test_positive_control_shared_finish_completes_member_configuration_changes_outside_identity_admission(): void
    {
        $application = app();
        $pdo = DB::connection()->getPdo();
        $repository = $application->make('config');
        $repository->set('member-grants.enabled', true);
        $frame = IdentityCommittedFrame::begin(new CurrentRows($pdo, 'sqlite'), hrtime(true) + 1_000_000_000);
        try {
            $this->assertTrue($pdo->inTransaction());
            $repository->set('member-grants.enabled', false);
            $frame->finish();
            $this->assertFalse($repository->get('member-grants.enabled'));
            $this->assertFalse($pdo->inTransaction());
            $this->assertSame(0, DB::connection()->transactionLevel());
        } finally {
            $frame->close();
        }
    }

    /**
     * Before #127 the shared finish executed an unchanged config hook inside and after its native commit.
     * Its identity metadata admission now refuses that hook before the hook can run, even without this
     * boundary; the boundary's own earlier `finish_callbacks` refusal is covered above.
     */
    public function test_shared_finish_refuses_unchanged_config_hooks_before_execution_without_the_member_boundary(): void
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
            try {
                $frame->finish();
                $this->fail('The shared committed frame must refuse an executable config hook.');
            } catch (IdentityException $error) {
                // Reason-less: raised by IdentityMetadataAdmission, not by the frame's committed_frame_required checks.
                $this->assertSame('unavailable', $error->reason);
            }
            $this->assertSame([], $states);
            $this->assertTrue($repository->get('member-grants.enabled'));
            $this->assertTrue($pdo->inTransaction());
        } finally {
            $property->setValue($application, $original);
            $frame->close();
        }
        $this->assertFalse($pdo->inTransaction());
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

    public function test_only_the_captured_primary_can_be_active_during_current_and_committed_raw_proofs(): void
    {
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $read = new PDO('sqlite::memory:');
        $direct = new PDO('sqlite::memory:');
        $connection->setReadPdo($read);
        $connection->setDirectPdo($direct);
        $boundary = MemberOriginalFinishBoundary::capture();
        $connection->beginTransaction();
        try {
            $boundary->assertFrame($primary, false);
            foreach ([$read, $direct] as $foreign) {
                $foreign->beginTransaction();
                try {
                    $this->refused(fn () => $boundary->assertFrame($primary, false), 'changed_transaction');
                    $this->assertTrue($foreign->inTransaction());
                    $this->assertTrue($primary->inTransaction());
                } finally {
                    $foreign->rollBack();
                }
            }
        } finally {
            $connection->rollBack();
        }
        $frame = IdentityCommittedFrame::begin(new CurrentRows($primary, 'sqlite'), hrtime(true) + 1_000_000_000);
        try {
            $boundary->assertFrame($primary, true);
            $direct->beginTransaction();
            $this->refused(fn () => $boundary->assertFrame($primary, true), 'changed_transaction');
            $this->assertTrue($direct->inTransaction());
        } finally {
            $direct->rollBack();
            $frame->close();
        }
        $this->assertFalse($primary->inTransaction());
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
