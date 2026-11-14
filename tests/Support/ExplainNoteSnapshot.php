<?php

namespace Tests\Support;

use LogicException;
use Pdo\Mysql;
use PDOStatement;
use Throwable;

/** EXPLAIN test provenance ONLY. Ordinary production queries still require zero diagnostics. */
final class ExplainNoteSnapshot
{
    public static function capture(Mysql $pdo, PDOStatement $offender, int $beforeExecute, int $execute, int $firstFetch): array
    {
        return self::run(new NativeExplainNoteTransport($pdo, $offender), ['before_execute' => $beforeExecute, 'execute' => $execute, 'first_fetch' => $firstFetch]);
    }

    private static function run(ExplainNoteTransport $transport, array $counts): array
    {
        $stage = 'initial';
        $failure = null;
        $restored = false;
        $closed = false;
        $restoreRequired = false;
        $rows = [];
        $getterWarnings = null;
        try {
            if (array_keys($counts) !== ['before_execute', 'execute', 'first_fetch'] || $counts['before_execute'] !== 0
                || ! is_int($counts['execute']) || ! is_int($counts['first_fetch'])
                || $counts['execute'] < 0 || $counts['execute'] > 8 || $counts['first_fetch'] < 0 || $counts['first_fetch'] > 8
                || $transport->emulated() !== false) {
                throw new LogicException('Initial EXPLAIN diagnostics refused.');
            }
            $restoreRequired = true; // Original mode was proved EXACTfalse; restore only that owned transition.
            $stage = 'offender_eof';
            if (! $transport->finishOffender()) {
                throw new LogicException('EXPLAIN extra row refused.');
            }
            $counts['eof'] = $transport->warningCount();
            if ($counts['eof'] < 0 || $counts['eof'] > 8 || $counts['execute'] !== $counts['first_fetch'] || $counts['first_fetch'] !== $counts['eof']) {
                throw new LogicException('EXPLAIN warning provenance changed.');
            }
            $stage = 'com_query_transition';
            if (! $transport->setEmulated(true) || $transport->emulated() !== true) {
                throw new LogicException('EXPLAIN warning transport refused.');
            }
            $stage = 'complete_diagnostic';
            $bytes = 0;
            foreach ($transport->warningRows() as $row) {
                if (count($rows) >= 8 || ! is_array($row) || array_keys($row) !== ['Level', 'Code', 'Message']
                    || ! in_array($row['Level'], ['Note', 'Warning', 'Error'], true) || ! is_int($row['Code'])
                    || $row['Code'] < 1 || $row['Code'] > 65535 || ! is_string($row['Message'])) {
                    throw new LogicException('Malformed EXPLAIN diagnostic refused.');
                }
                $size = strlen($row['Message']);
                $bytes += $size;
                if ($size > 131072 || $bytes > 1048576) {
                    throw new LogicException('EXPLAIN diagnostic receive cap refused.');
                }
                $rows[] = ['level' => $row['Level'], 'code' => $row['Code'], 'message_bytes' => $size];
                unset($row); // Never retain/print/hash native message text or expanded SQL.
            }
            $getterWarnings = $transport->warningCount();
            if ($getterWarnings !== 0) {
                throw new LogicException('EXPLAIN warning getter itself produced diagnostics.');
            }
            if (count($rows) !== $counts['eof']) {
                throw new LogicException('Incomplete EXPLAIN diagnostics refused.');
            }
        } catch (Throwable) {
            $failure = $stage;
        } finally {
            try {
                $restored = $restoreRequired && $transport->setEmulated(false) && $transport->emulated() === false;
            } catch (Throwable) {
                $restored = false;
            }
            try {
                $closed = $transport->closeDiagnostic();
            } catch (Throwable) {
                $closed = false;
            }
            if ($failure !== null || ! $restored || ! $closed) {
                try {
                    $transport->closeOffender();
                } catch (Throwable) { /* Failure is already final; external native audit remains required. */
                }
            }
        }
        if ($failure !== null || ! $restored || ! $closed) {
            // No exception chain/native messages/SQL/arguments escape a transport failure.
            throw new LogicException('EXPLAIN snapshot refused at '.($failure ?? 'restore_or_close').'.');
        }

        return ['counts' => $counts, 'rows' => $rows, 'offender_eof' => true, 'restored' => $restored, 'diagnostic_closed' => $closed, 'getter_warnings' => $getterWarnings];
    }

    public static function requireOnlyExplainNote(array $snapshot): void
    {
        $counts = $snapshot['counts'] ?? null;
        $rows = $snapshot['rows'] ?? null;
        if (array_keys($snapshot) !== ['counts', 'rows', 'offender_eof', 'restored', 'diagnostic_closed', 'getter_warnings']
            || ! is_array($counts) || array_keys($counts) !== ['before_execute', 'execute', 'first_fetch', 'eof']
            || $counts !== ['before_execute' => 0, 'execute' => 1, 'first_fetch' => 1, 'eof' => 1]
            || $snapshot['getter_warnings'] !== 0 || $snapshot['offender_eof'] !== true || $snapshot['restored'] !== true || $snapshot['diagnostic_closed'] !== true
            || ! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1
            || ! is_array($rows[0]) || array_keys($rows[0]) !== ['level', 'code', 'message_bytes']
            || $rows[0]['level'] !== 'Note' || $rows[0]['code'] !== 1003 || ! is_int($rows[0]['message_bytes'])
            || $rows[0]['message_bytes'] < 0 || $rows[0]['message_bytes'] > 131072) {
            throw new LogicException('Expected exactly one matched EXPLAIN Note 1003.');
        }
    }
}
