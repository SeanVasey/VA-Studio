<?php

namespace Tests\Unit;

use App\Domain\Commerce\RefundDispute\RefundDisputeState;
use PHPUnit\Framework\TestCase;

class RefundDisputeStateTest extends TestCase
{
    private function object(string $kind, string $seed, int $amount, string $status, string $currency = 'USD'): array
    {
        return ['kind' => $kind, 'hash' => hash('sha256', $seed), 'amount' => $amount, 'currency' => $currency, 'status' => $status];
    }

    public function test_states_are_derived_from_the_complete_snapshot(): void
    {
        $refund = fn (int $amount, string $status, string $seed = 'r1') => $this->object('refund', $seed, $amount, $status);
        $dispute = fn (string $status, string $seed = 'd1') => $this->object('dispute', $seed, 1000, $status);
        $cases = [
            [[], ['none', 'none', 0, 0]],
            [[$refund(400, 'succeeded')], ['partial', 'none', 400, 0]],
            [[$refund(400, 'succeeded'), $refund(600, 'pending', 'r2')], ['partial', 'none', 400, 600]],
            [[$refund(400, 'succeeded'), $refund(600, 'succeeded', 'r2')], ['full', 'none', 1000, 0]],
            [[$refund(1000, 'failed'), $refund(1000, 'canceled', 'r2')], ['none', 'none', 0, 0]],
            [[$refund(1000, 'requires_action')], ['none', 'none', 0, 1000]],
            [[$dispute('warning_needs_response')], ['none', 'open', 0, 0]],
            [[$dispute('won'), $dispute('under_review', 'd2')], ['none', 'open', 0, 0]],
            [[$dispute('won'), $dispute('lost', 'd2')], ['none', 'lost', 0, 0]],
            [[$dispute('won')], ['none', 'won', 0, 0]],
            [[$dispute('prevented'), $dispute('warning_closed', 'd2')], ['none', 'closed', 0, 0]],
        ];
        foreach ($cases as [$observed, $expected]) {
            $result = RefundDisputeState::evaluate(1000, 'USD', [], $observed);
            $this->assertTrue($result['valid']);
            $this->assertSame($expected, [$result['refund_state'], $result['dispute_state'], $result['succeeded_minor'], $result['pending_minor']]);
        }
    }

    public function test_impossible_snapshots_are_refused_with_a_bounded_reason(): void
    {
        $prior = [$this->object('refund', 'r1', 400, 'succeeded'), $this->object('dispute', 'd1', 1000, 'lost')];
        $cases = [
            'object_vanished' => [$prior[1]],
            'amount_changed' => [$this->object('refund', 'r1', 401, 'succeeded'), $prior[1]],
            'status_regressed' => [$this->object('refund', 'r1', 400, 'pending'), $prior[1]],
            'currency_mismatch' => [$this->object('refund', 'r1', 400, 'succeeded', 'EUR'), $prior[1]],
            'refunds_exceed_paid' => [...$prior, $this->object('refund', 'r2', 601, 'pending')],
            'object_exceeds_paid' => [...$prior, $this->object('dispute', 'd2', 1001, 'needs_response')],
            'object_shape' => [...$prior, $prior[0]],
        ];
        foreach ($cases as $reason => $observed) {
            $result = RefundDisputeState::evaluate(1000, 'USD', $prior, $observed);
            $this->assertSame([false, $reason, null, null], [$result['valid'], $result['reason'], $result['refund_state'], $result['dispute_state']], $reason);
        }
        $this->assertSame('payment_shape', RefundDisputeState::evaluate(1000, 'EUR', [], [])['reason']);
        $this->assertSame('payment_shape', RefundDisputeState::evaluate(0, 'USD', [], [])['reason']);
        $this->assertSame('object_shape', RefundDisputeState::evaluate(1000, 'USD', [], [['kind' => 'refund']])['reason']);
        $this->assertSame('object_shape', RefundDisputeState::evaluate(1000, 'USD', [], [$this->object('refund', 'r1', 400, 'won')])['reason']);
    }

    public function test_every_transition_outside_the_table_is_refused_and_every_listed_one_accepted(): void
    {
        foreach (RefundDisputeState::TRANSITIONS as $kind => $table) {
            foreach (array_keys($table) as $from) {
                foreach (array_keys($table) as $to) {
                    $result = RefundDisputeState::evaluate(1000, 'USD', [$this->object($kind, 'x', 500, $from)], [$this->object($kind, 'x', 500, $to)]);
                    $this->assertSame($from === $to || in_array($to, $table[$from], true), $result['valid'], "{$kind} {$from} -> {$to}");
                }
            }
        }
        // Terminal outcomes stay terminal: a lost dispute never reopens and a failed refund never succeeds.
        $this->assertFalse(RefundDisputeState::evaluate(1000, 'USD', [$this->object('dispute', 'x', 500, 'lost')], [$this->object('dispute', 'x', 500, 'won')])['valid']);
        $this->assertFalse(RefundDisputeState::evaluate(1000, 'USD', [$this->object('refund', 'x', 500, 'failed')], [$this->object('refund', 'x', 500, 'succeeded')])['valid']);
    }
}
