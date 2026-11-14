<?php

namespace Tests\Unit\ProductionMemberOriginals;

use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Grants\Member\MemberActivationHistory;
use App\Domain\Grants\Member\MemberActivationHistoryReader;
use App\Domain\Grants\Member\MemberActivationHistorySnapshot;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Memberships\Production\MembershipRows;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Unissued handles never enter source getters, SQL, crypto or a caller-owned frame. */
class MemberActivationHistoryHandlesTest extends TestCase
{
    public function test_snapshot_detaches_nested_references_and_returned_scope_values(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $period = ['id' => &$id, 'account_id' => 4];
        $event = ['id' => &$id, 'grant_origin_id' => null];
        $redemption = ['id' => &$id, 'credit_amount' => 1];
        $history = MemberActivationHistory::empty();
        $snapshot = new MemberActivationHistorySnapshot($history,
            ['period' => &$period, 'events' => [&$event], 'redemptions' => [&$redemption]]);
        $expected = $snapshot->scope();
        $id = '22222222-2222-4222-8222-222222222222';
        $period['account_id'] = 5;
        $event['grant_origin_id'] = $id;
        $redemption['credit_amount'] = 9;
        $returned = $snapshot->scope();
        $returned['period']['account_id'] = '6';
        $returned['events'][0]['grant_origin_id'] = $id;
        $returned['redemptions'][0]['credit_amount'] = '8';
        $this->assertSame($expected, $snapshot->scope());
        $this->assertSame('4', $snapshot->scope()['period']['account_id']);
        $this->assertSame(null, $snapshot->scope()['events'][0]['grant_origin_id']);
        $this->assertSame($history, $snapshot->value());
        $reader = new MemberActivationHistoryReader;
        $rows = (new ReflectionClass(MembershipRows::class))->newInstanceWithoutConstructor();
        $this->refuses(fn () => $reader->currentRaw($snapshot, $rows));
    }

    public function test_an_unissued_snapshot_cannot_select_rows_or_a_committed_frame(): void
    {
        $reader = new MemberActivationHistoryReader;
        $snapshot = (new ReflectionClass(MemberActivationHistorySnapshot::class))->newInstanceWithoutConstructor();
        $rows = (new ReflectionClass(MembershipRows::class))->newInstanceWithoutConstructor();
        $frame = (new ReflectionClass(IdentityCommittedFrame::class))->newInstanceWithoutConstructor();
        foreach ([fn () => $reader->currentCallbacks($snapshot, $rows), fn () => $reader->currentRaw($snapshot, $rows),
            fn () => $reader->committedCallbacks($snapshot, $frame), fn () => $reader->committedRaw($snapshot, $frame)] as $operation) {
            $this->refuses($operation);
        }
        $reader->retire($snapshot);
        $this->refuses(fn () => $reader->currentRaw($snapshot, $rows));
    }

    public function test_reader_and_snapshot_cannot_be_cloned(): void
    {
        $reader = new MemberActivationHistoryReader;
        $snapshot = (new ReflectionClass(MemberActivationHistorySnapshot::class))->newInstanceWithoutConstructor();
        $this->refuses(fn () => clone $reader);
        $this->refuses(fn () => clone $snapshot);
    }

    public function test_reader_and_snapshot_cannot_be_serialized(): void
    {
        $reader = new MemberActivationHistoryReader;
        $snapshot = (new ReflectionClass(MemberActivationHistorySnapshot::class))->newInstanceWithoutConstructor();
        $this->refuses(fn () => serialize($reader));
        $this->refuses(fn () => serialize($snapshot));
        $this->refuses(fn () => json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function test_snapshot_unserialize_never_populates_retained_values_or_registration(): void
    {
        $class = MemberActivationHistorySnapshot::class;
        $this->refuses(fn () => unserialize('O:'.strlen($class).':"'.$class.'":0:{}'));
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An unissued history handle was admitted.');
        } catch (MemberGrantException $error) {
            $this->assertSame('history_snapshot', $error->reason);
        }
    }
}
