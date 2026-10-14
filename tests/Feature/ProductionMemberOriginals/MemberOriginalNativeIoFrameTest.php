<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalIoFrame;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionProperty;
use Tests\TestCase;

/** Actual MySQL handles only, no migrations, schema changes or application records. */
class MemberOriginalNativeIoFrameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Private native MySQL handles required; SQLite counterparts are separate tests.');
        }
        $pdo = DB::connection()->getPdo();
        $this->assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function test_native_write_read_and_direct_transactions_are_refused_without_cleanup(): void
    {
        $connection = DB::connection();
        $write = $connection->getPdo();
        $read = $this->separateNative();
        $direct = $this->separateNative();
        $connection->setReadPdo($read);
        $connection->setDirectPdo($direct);
        $ids = array_map(fn (PDO $pdo) => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), [$write, $read, $direct]);
        $this->assertCount(3, array_unique($ids));
        $frame = MemberOriginalIoFrame::captureIdle();
        foreach ([$write, $read, $direct] as $pdo) {
            $pdo->beginTransaction();
            try {
                $this->assertSame(0, $connection->transactionLevel());
                $this->refused(fn () => $frame->assertStillIdle(), 'io_transaction');
                $this->refused(fn () => MemberOriginalIoFrame::captureIdle(), 'io_transaction');
                $this->assertTrue($pdo->inTransaction());
            } finally {
                $pdo->rollBack();
            }
            $frame->assertStillIdle();
        }
        fwrite(STDOUT, 'MEMBER_IO_NATIVE '.json_encode(['version' => $write->query('SELECT VERSION()')->fetchColumn(),
            'connection_ids' => $ids, 'all_idle' => ! $write->inTransaction() && ! $read->inTransaction() && ! $direct->inTransaction()], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_native_direct_replacement_and_lazy_resolution_refuse_before_callbacks(): void
    {
        $connection = DB::connection();
        $connection->setDirectPdo($this->separateNative());
        $replacement = $this->separateNative();
        $frame = MemberOriginalIoFrame::captureIdle();
        $connection->setDirectPdo($replacement);
        $this->refused(fn () => $frame->assertStillIdle(), 'io_frame');
        $calls = 0;
        $connection->setDirectPdo(function () use (&$calls, $replacement) {
            $calls++;

            return $replacement;
        });
        $this->refused(fn () => MemberOriginalIoFrame::captureIdle(), 'io_frame');
        $this->assertSame(0, $calls);
        $connection->setDirectPdo($replacement);
    }

    public function test_native_foreign_facade_manager_transaction_is_never_admitted_or_rolled_back(): void
    {
        $manager = app('db');
        $foreign = clone $manager;
        $pdo = $this->separateNative();
        $connection = new MySqlConnection($pdo, DB::connection()->getDatabaseName(), '', DB::connection()->getConfig());
        (new ReflectionProperty(DatabaseManager::class, 'connections'))->setValue($foreign, [DB::getDefaultConnection() => $connection]);
        $frame = MemberOriginalIoFrame::captureIdle();
        DB::swap($foreign);
        app()->instance('db', $manager);
        $pdo->beginTransaction();
        try {
            $this->assertSame($foreign, DB::getFacadeRoot());
            $this->assertSame($manager, app('db'));
            $this->refused(fn () => $frame->assertStillIdle(), 'io_frame');
            $this->refused(fn () => MemberOriginalIoFrame::captureIdle(), 'io_frame');
            $this->assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
            DB::swap($manager);
        }
    }

    private function separateNative(): PDO
    {
        $config = DB::connection()->getConfig();
        $name = 'member_io_native_temporary';
        config(['database.connections.'.$name => $config]);
        $pdo = DB::connection($name)->getPdo();
        DB::purge($name);

        return $pdo;
    }

    private function refused(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('The native I/O barrier must refuse this state.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
