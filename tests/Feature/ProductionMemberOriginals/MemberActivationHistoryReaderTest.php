<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Grants\Member\MemberActivationHistoryReader;
use App\Domain\Grants\Member\MemberActivationHistorySnapshot;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipAwardFixtures;
use Tests\TestCase;

/** Actual awarded period and installed schema; an empty member history is observed, never defaulted. */
class MemberActivationHistoryReaderTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipAwardFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite registered-reader rehearsal; native query races are a separate selection.');
        }
        $this->awardSetup();
        config(['member-grants.provenance' => 'synthetic_rehearsal']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_actual_empty_history_keeps_exact_reader_rows_and_committed_frame_until_retirement(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $award = (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $reader = new MemberActivationHistoryReader;
        $foreign = new MemberActivationHistoryReader;
        $pdo = DB::connection()->getPdo();
        DB::beginTransaction();
        $rows = new MembershipRows;
        $snapshot = $reader->capturePeriod($award['period_id'], $rows);
        $this->assertSame($award['period_id'], $snapshot->scope()['period']['id']);
        $this->assertSame([$award['award_event_id']], array_column($snapshot->scope()['events'], 'id'));
        $this->assertSame([], $snapshot->scope()['redemptions']);
        $this->assertSame(array_fill_keys(['origins', 'artifacts', 'activations', 'profiles', 'definitions', 'plans'], []), $snapshot->value()->rows());
        $snapshot->value()->assertComplete([]);
        $reader->currentCallbacks($snapshot, $rows);
        $foreignRows = (new ReflectionClass(MembershipRows::class))->newInstanceWithoutConstructor();
        $shaped = new MemberActivationHistorySnapshot($snapshot->value(), $snapshot->scope());
        $this->refuses(fn () => $reader->currentRaw($snapshot, $foreignRows));
        $this->refuses(fn () => $foreign->currentRaw($snapshot, $rows));
        $this->refuses(fn () => $reader->currentRaw($shaped, $rows));

        $callbackGuard = true;
        DB::connection()->beforeExecuting(static function () use (&$callbackGuard): void {
            if ($callbackGuard) {
                throw new \RuntimeException('A framework query callback ran after the terminal callback phase.');
            }
        });
        try {
            $reader->currentRaw($snapshot, $rows);
            $this->assertTrue($pdo->inTransaction());
            DB::commit();
            $frame = IdentityCommittedFrame::begin(CurrentRows::committedReadOnly($pdo, 'sqlite'), hrtime(true) + 60_000_000_000);
            try {
                $this->refuses(fn () => $reader->committedRaw($snapshot, $frame));
                $reader->committedCallbacks($snapshot, $frame);
                $reader->committedRaw($snapshot, $frame);
                $this->assertTrue($pdo->inTransaction());
                $foreignFrame = (new ReflectionClass(IdentityCommittedFrame::class))->newInstanceWithoutConstructor();
                $this->refuses(fn () => $reader->committedRaw($snapshot, $foreignFrame));
                $this->refuses(fn () => $reader->currentRaw($snapshot, $rows));
                $frame->finish();
                $this->assertFalse($pdo->inTransaction());
                $this->refuses(fn () => $reader->committedRaw($snapshot, $frame), 'changed_primary');
                $reader->retire($snapshot);
                $this->refuses(fn () => $reader->committedRaw($snapshot, $frame));
                $this->assertSame($award['period_id'], $snapshot->scope()['period']['id']);
            } finally {
                $frame->close();
            }
        } finally {
            $callbackGuard = false;
            $reader->retire($snapshot);
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($pdo->inTransaction());
    }

    private function refuses(callable $operation, string $reason = 'history_snapshot'): void
    {
        try {
            $operation();
            $this->fail('A foreign or finished history closure was admitted.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
