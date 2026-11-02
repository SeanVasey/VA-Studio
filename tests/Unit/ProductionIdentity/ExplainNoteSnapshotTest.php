<?php

namespace Tests\Unit\ProductionIdentity;

use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\ExplainNoteSnapshot;
use Tests\Support\ExplainNoteTransport;

final class ExplainNoteSnapshotTest extends TestCase
{
    private function valid(): array
    {
        return ['counts' => ['prepare' => 0, 'execute' => 1, 'first_fetch' => 1, 'eof' => 1], 'rows' => [['level' => 'Note', 'code' => 1003, 'message_bytes' => 17]], 'offender_eof' => true, 'restored' => true, 'diagnostic_closed' => true, 'getter_warnings' => 0];
    }

    public function test_exact_complete_note_is_the_only_accepted_classification(): void
    {
        ExplainNoteSnapshot::requireOnlyExplainNote($this->valid());
        self::assertTrue(true);
        $bad = [];
        $s = $this->valid();
        $s['rows'] = [];
        $bad[] = $s;
        $s = $this->valid();
        $s['rows'][] = $s['rows'][0];
        $bad[] = $s;
        foreach ([['Note', 1004], ['Warning', 1003], ['Error', 1003], ['UNKNOWN_PRIVATE', 1003], ['Note', '1003'], ['Note', true]] as [$level, $code]) {
            $s = $this->valid();
            $s['rows'][0]['level'] = $level;
            $s['rows'][0]['code'] = $code;
            $bad[] = $s;
        }
        $s = $this->valid();
        $s['rows'][] = ['level' => 'Warning', 'code' => 1292, 'message_bytes' => 1];
        $s['counts'] = ['prepare' => 0, 'execute' => 2, 'first_fetch' => 2, 'eof' => 2];
        $bad[] = $s;
        foreach (['offender_eof', 'restored', 'diagnostic_closed'] as $field) {
            $s = $this->valid();
            $s[$field] = false;
            $bad[] = $s;
        }
        foreach (['prepare', 'execute', 'first_fetch', 'eof'] as $field) {
            foreach (['1', true, -1, 9] as $value) {
                $s = $this->valid();
                $s['counts'][$field] = $value;
                $bad[] = $s;
            }
        }
        $s = $this->valid();
        $s['counts'] = ['prepare' => 0, 'execute' => 0, 'first_fetch' => 0, 'eof' => 0];
        $s['rows'] = [];
        $bad[] = $s;
        $s = $this->valid();
        unset($s['rows'][0]['message_bytes']);
        $bad[] = $s;
        $s = $this->valid();
        $s['rows'][0]['Message'] = 'PRIVATE_SQL_SENTINEL';
        $bad[] = $s;
        $s = $this->valid();
        $s['rows'][0]['message_bytes'] = 131073;
        $bad[] = $s;
        $s = $this->valid();
        $s['rows'][0]['message_bytes'] = '17';
        $bad[] = $s;
        $s = $this->valid();
        $s['getter_warnings'] = 1;
        $bad[] = $s;
        $s = $this->valid();
        $s['extra'] = true;
        $bad[] = $s;
        foreach ($bad as $snapshot) {
            try {
                ExplainNoteSnapshot::requireOnlyExplainNote($snapshot);
                self::fail('Unexpected or incomplete EXPLAIN diagnostic accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_complete_transport_orders_eof_getter_restore_and_close_without_retaining_message(): void
    {
        $transport = new ExplainNoteFake;
        $snapshot = $this->capture($transport);
        ExplainNoteSnapshot::requireOnlyExplainNote($snapshot);
        self::assertSame(['mode', 'eof', 'count', 'set_true', 'mode', 'show', 'count', 'set_false', 'mode', 'close_diagnostic'], $transport->operations);
        self::assertFalse($transport->emulation);
        self::assertTrue($transport->diagnosticClosed);
        self::assertFalse($transport->offenderClosed, 'Original successful caller still performs its real close assertion.');
        self::assertStringNotContainsString('PRIVATE_SQL_SENTINEL', serialize($snapshot));
        self::assertSame(strlen('PRIVATE_SQL_SENTINEL'), $snapshot['rows'][0]['message_bytes']);
    }

    public function test_malformed_extra_mixed_incomplete_unknown_and_oversized_wire_rows_refuse(): void
    {
        $valid = ['Level' => 'Note', 'Code' => 1003, 'Message' => 'PRIVATE_SQL_SENTINEL'];
        $cases = [[], [$valid, $valid], [['Level' => 'UNKNOWN', 'Code' => 1003, 'Message' => 'x']], [['Level' => 'Note', 'Code' => '1003', 'Message' => 'x']], [['Level' => 'Note', 'Code' => 0, 'Message' => 'x']], [['Level' => 'Note', 'Code' => 1003, 'Message' => []]], [['Level' => 'Note', 'Code' => 1003]], [['Level' => 'Note', 'Code' => 1003, 'Message' => 'x', 'extra' => 1]], [['Code' => 1003, 'Level' => 'Note', 'Message' => 'x']], [['Level' => 'Note', 'Code' => 1003, 'Message' => str_repeat('x', 131073)]], array_fill(0, 9, $valid)];
        foreach ($cases as $rows) {
            $transport = new ExplainNoteFake;
            $transport->rows = $rows;
            $this->refuses($transport);
            self::assertTrue($transport->diagnosticClosed);
            self::assertTrue($transport->offenderClosed);
            self::assertFalse($transport->emulation);
        }
        $transport = new ExplainNoteFake;
        $transport->rows = [$valid, ['Level' => 'Warning', 'Code' => 1292, 'Message' => 'x']];
        $transport->count = 2;
        $snapshot = $this->capture($transport, ['prepare' => 0, 'execute' => 2, 'first_fetch' => 2]);
        try {
            ExplainNoteSnapshot::requireOnlyExplainNote($snapshot);
            self::fail('Mixed complete Note and Warning accepted.');
        } catch (LogicException) {
            self::assertTrue(true);
        }
    }

    public function test_count_changes_extra_offender_rows_and_partial_generator_never_supply_a_snapshot(): void
    {
        foreach (['extra_offender', 'changed_count', 'count_overcap', 'rows_partial_throw'] as $fault) {
            $transport = new ExplainNoteFake;
            $transport->fault = $fault;
            $this->refuses($transport);
            self::assertTrue($transport->offenderClosed);
            self::assertTrue($transport->diagnosticClosed);
            self::assertFalse($transport->emulation);
            if ($fault !== 'rows_partial_throw') {
                self::assertNotContains('show', $transport->operations);
            }
        }
        foreach ([['prepare' => 1, 'execute' => 1, 'first_fetch' => 1], ['prepare' => 0, 'execute' => '1', 'first_fetch' => 1], ['prepare' => 0, 'execute' => 1, 'first_fetch' => 2]] as $counts) {
            $transport = new ExplainNoteFake;
            $this->refuses($transport, $counts);
            self::assertNotContains('show', $transport->operations);
        }
    }

    public function test_transition_query_restore_and_close_failures_are_final_but_cleanup_is_attempted(): void
    {
        foreach (['initial_mode', 'set_true', 'wrong_true', 'rows_throw', 'getter_warning', 'set_false', 'wrong_false', 'close_false', 'close_throw'] as $fault) {
            $transport = new ExplainNoteFake;
            $transport->fault = $fault;
            $this->refuses($transport);
            self::assertContains('close_diagnostic', $transport->operations);
            self::assertContains('close_offender', $transport->operations);
            self::assertTrue($transport->offenderClosed);
            if ($fault !== 'initial_mode') {
                self::assertContains('set_false', $transport->operations);
            }
        }
    }

    private function capture(ExplainNoteFake $transport, array $counts = ['prepare' => 0, 'execute' => 1, 'first_fetch' => 1]): array
    {
        return (new ReflectionMethod(ExplainNoteSnapshot::class, 'run'))->invoke(null, $transport, $counts);
    }

    private function refuses(ExplainNoteFake $transport, array $counts = ['prepare' => 0, 'execute' => 1, 'first_fetch' => 1]): void
    {
        try {
            $this->capture($transport, $counts);
            self::fail('Unsafe EXPLAIN capture returned a snapshot.');
        } catch (LogicException $e) {
            self::assertNull($e->getPrevious(), 'Native diagnostic payloads never escape in an exception chain.');
            self::assertStringNotContainsString('PRIVATE_SQL_SENTINEL', $e->getMessage());
        }
    }
}

/** Pure fake of a CLOSED test protocol, never accepted by public native capture(). No PDO or SQL. */
final class ExplainNoteFake implements ExplainNoteTransport
{
    public array $operations = [];

    public array $rows = [['Level' => 'Note', 'Code' => 1003, 'Message' => 'PRIVATE_SQL_SENTINEL']];

    public int $count = 1;

    public bool $emulation = false;

    public bool $diagnosticClosed = false;

    public bool $offenderClosed = false;

    public string $fault = '';

    public function emulated(): mixed
    {
        $this->operations[] = 'mode';
        if ($this->fault === 'initial_mode' && count($this->operations) === 1) {
            throw new RuntimeException('Synthetic initial mode error.');
        }
        if ($this->fault === 'wrong_true' && $this->emulation) {
            return false;
        }
        if ($this->fault === 'wrong_false' && in_array('set_false', $this->operations, true)) {
            return true;
        }

        return $this->emulation;
    }

    public function setEmulated(bool $value): bool
    {
        $this->operations[] = $value ? 'set_true' : 'set_false';
        $this->emulation = $value;

        return ! ($this->fault === ($value ? 'set_true' : 'set_false'));
    }

    public function finishOffender(): bool
    {
        $this->operations[] = 'eof';

        return $this->fault !== 'extra_offender';
    }

    public function warningCount(): int
    {
        $this->operations[] = 'count';

        if (in_array('show', $this->operations, true)) {
            return $this->fault === 'getter_warning' ? 1 : 0;
        }

        return $this->fault === 'changed_count' ? 2 : ($this->fault === 'count_overcap' ? 9 : $this->count);
    }

    public function warningRows(): iterable
    {
        $this->operations[] = 'show';
        if ($this->fault === 'rows_throw') {
            throw new RuntimeException('Synthetic PRIVATE_SQL_SENTINEL query error.');
        }
        foreach ($this->rows as $row) {
            yield $row;
            if ($this->fault === 'rows_partial_throw') {
                throw new RuntimeException('Synthetic PRIVATE_SQL_SENTINEL incomplete stream.');
            }
        }
    }

    public function closeDiagnostic(): bool
    {
        $this->operations[] = 'close_diagnostic';
        if ($this->fault === 'close_throw') {
            throw new RuntimeException('Synthetic close failure.');
        }
        $this->diagnosticClosed = true;

        return $this->fault !== 'close_false';
    }

    public function closeOffender(): bool
    {
        $this->operations[] = 'close_offender';
        $this->offenderClosed = true;

        return true;
    }
}
