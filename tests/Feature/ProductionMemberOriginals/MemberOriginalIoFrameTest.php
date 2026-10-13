<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalIoFrame;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionProperty;
use Tests\TestCase;

/** Isolated native SQLite handles, no migrations or application data. */
class MemberOriginalIoFrameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'member_io', 'database.connections.member_io' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::connection()->getPdo();
    }

    public function test_idle_barrier_rechecks_all_captured_native_handles_without_framework_queries(): void
    {
        $connection = DB::connection();
        $read = new PDO('sqlite::memory:');
        $connection->setReadPdo($read);
        $frame = MemberOriginalIoFrame::captureIdle();
        $frame->assertStillIdle();
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertFalse($read->inTransaction());
    }

    public function test_direct_write_transaction_at_framework_zero_is_refused_and_left_untouched(): void
    {
        $pdo = DB::connection()->getPdo();
        $frame = MemberOriginalIoFrame::captureIdle();
        $pdo->beginTransaction();
        try {
            $this->assertSame(0, DB::transactionLevel());
            $this->refused(fn () => MemberOriginalIoFrame::captureIdle(), 'io_transaction');
            $this->refused(fn () => $frame->assertStillIdle(), 'io_transaction');
            $this->assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
        $frame->assertStillIdle();
    }

    public function test_direct_transaction_on_a_second_resolved_connection_is_refused(): void
    {
        config(['database.connections.member_io_second' => config('database.connections.member_io')]);
        $second = DB::connection('member_io_second');
        $pdo = $second->getPdo();
        $frame = MemberOriginalIoFrame::captureIdle();
        $pdo->beginTransaction();
        try {
            $this->assertSame(0, $second->transactionLevel());
            $this->refused(fn () => $frame->assertStillIdle(), 'io_transaction');
            $this->assertTrue($pdo->inTransaction());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_separate_read_handle_transaction_is_refused_without_resolving_it(): void
    {
        $connection = DB::connection();
        $read = new PDO('sqlite::memory:');
        $connection->setReadPdo($read);
        $frame = MemberOriginalIoFrame::captureIdle();
        $read->beginTransaction();
        try {
            $this->refused(fn () => $frame->assertStillIdle(), 'io_transaction');
            $this->assertTrue($read->inTransaction());
            $this->assertFalse($connection->getPdo()->inTransaction());
        } finally {
            $read->rollBack();
        }
    }

    public function test_framework_transaction_counter_is_not_replaced_by_an_idle_native_handle(): void
    {
        $connection = DB::connection();
        $frame = MemberOriginalIoFrame::captureIdle();
        $counter = new ReflectionProperty(Connection::class, 'transactions');
        $counter->setValue($connection, 1);
        try {
            $this->refused(fn () => $frame->assertStillIdle(), 'io_transaction');
            $this->assertSame(1, $counter->getValue($connection));
            $this->assertFalse($connection->getPdo()->inTransaction());
        } finally {
            $counter->setValue($connection, 0);
        }
    }

    public function test_lazy_native_slots_are_refused_without_invoking_the_resolver(): void
    {
        $connection = DB::connection();
        $calls = 0;
        $connection->setReadPdo(function () use (&$calls) {
            $calls++;

            return new PDO('sqlite::memory:');
        });
        $this->refused(fn () => MemberOriginalIoFrame::captureIdle(), 'io_frame');
        $this->assertSame(0, $calls);
        $connection->getReadPdo();
        $frame = MemberOriginalIoFrame::captureIdle();
        $frame->assertStillIdle();
        $this->assertSame(1, $calls);
    }

    public function test_connection_or_handle_created_after_capture_cannot_join_the_barrier(): void
    {
        config(['database.connections.member_io_late' => config('database.connections.member_io')]);
        $frame = MemberOriginalIoFrame::captureIdle();
        DB::connection('member_io_late')->getPdo();
        $this->refused(fn () => $frame->assertStillIdle(), 'io_frame');
        $frame = MemberOriginalIoFrame::captureIdle();
        DB::connection()->setPdo(new PDO('sqlite::memory:'));
        $this->refused(fn () => $frame->assertStillIdle(), 'io_frame');
        $frame = MemberOriginalIoFrame::captureIdle();
        DB::connection()->setReadPdo(new PDO('sqlite::memory:'));
        $this->refused(fn () => $frame->assertStillIdle(), 'io_frame');
    }

    public function test_configuration_repository_and_manager_identity_cannot_change(): void
    {
        $frame = MemberOriginalIoFrame::captureIdle();
        $enabled = config('member-grants.enabled');
        config(['member-grants.enabled' => ! $enabled]);
        $this->refused(fn () => $frame->assertStillIdle(), 'io_frame');
        config(['member-grants.enabled' => $enabled]);
        $repository = app('config');
        app()->instance('config', new Repository($repository->all()));
        $this->refused(fn () => $frame->assertStillIdle(), 'io_frame');
        app()->instance('config', $repository);
        $manager = app('db');
        app()->instance('db', clone $manager);
        $this->refused(fn () => $frame->assertStillIdle(), 'io_frame');
        app()->instance('db', $manager);
        $frame->assertStillIdle();
    }

    public function test_unexpected_framework_or_native_subclasses_are_not_called(): void
    {
        $manager = app('db');
        $property = new ReflectionProperty($manager, 'connections');
        $original = $property->getValue($manager);
        $connection = new class(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']) extends SQLiteConnection
        {
            public function transactionLevel(): int
            {
                throw new \LogicException('The barrier must not invoke a virtual getter.');
            }
        };
        $property->setValue($manager, ['member_io' => $connection]);
        try {
            $this->refused(fn () => MemberOriginalIoFrame::captureIdle(), 'io_frame');
        } finally {
            $property->setValue($manager, $original);
        }
        $pdo = new class('sqlite::memory:') extends PDO
        {
            public function inTransaction(): bool
            {
                throw new \LogicException('The barrier must not invoke a virtual PDO callback.');
            }
        };
        DB::connection()->setReadPdo($pdo);
        $this->refused(fn () => MemberOriginalIoFrame::captureIdle(), 'io_frame');
    }

    public function test_container_replacement_and_serialization_are_refused(): void
    {
        $frame = MemberOriginalIoFrame::captureIdle();
        $container = Container::getInstance();
        Container::setInstance(new Container);
        try {
            $this->refused(fn () => $frame->assertStillIdle(), 'io_frame');
        } finally {
            Container::setInstance($container);
        }
        foreach ([fn () => clone $frame, fn () => serialize($frame), fn () => json_encode($frame)] as $copy) {
            $this->refused($copy, 'private_io_frame');
        }
    }

    private function refused(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('The I/O barrier must refuse this state.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
