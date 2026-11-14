<?php

namespace Tests\Support;

use LogicException;
use Pdo\Mysql;
use PDOStatement;
use Throwable;

/** Test-only unexecuted EXPLAIN prepare provenance; no ordinary production waiver. */
final class ExplainPrepareSnapshot
{
    public static function capture(Mysql $pdo, PDOStatement $offender, int $count): array
    {
        $snapshot = self::run(new NativeExplainNoteTransport($pdo, $offender), $count);
        self::requireOnlyResolutionNotes($snapshot);

        return $snapshot;
    }

    private static function run(ExplainNoteTransport $transport, int $count): array
    {
        $stage = 'initial';
        $failure = null;
        $restored = false;
        $closed = false;
        $restoreRequired = false;
        $rows = [];
        $getterWarnings = null;
        try {
            if ($count < 0 || $count > 32 || $transport->emulated() !== false) {
                throw new LogicException('Initial EXPLAIN diagnostics refused.');
            }
            $restoreRequired = true; // Original mode was proved EXACTfalse; restore only that owned transition.
            $stage = 'com_query_transition';
            if (! $transport->setEmulated(true) || $transport->emulated() !== true) {
                throw new LogicException('EXPLAIN warning transport refused.');
            }
            $stage = 'complete_diagnostic';
            $bytes = 0;
            foreach ($transport->prepareWarningRows() as $row) {
                if (count($rows) >= 32 || ! is_array($row) || array_keys($row) !== ['Level', 'Code', 'Message']
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
            self::requireOnlyResolutionRows($rows);
            if (count($rows) !== $count) {
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

        return ['phase' => 'prepare', 'count' => $count, 'rows' => $rows, 'offender_unexecuted' => true, 'restored' => $restored, 'diagnostic_closed' => $closed, 'getter_warnings' => $getterWarnings];
    }

    public static function requireOnlyResolutionRows(array $rows): void
    {
        if (! array_is_list($rows) || count($rows) > 32) {
            throw new LogicException('Prepare resolution rows refused.');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || array_keys($row) !== ['level', 'code', 'message_bytes'] || $row['level'] !== 'Note' || $row['code'] !== 1276 || ! is_int($row['message_bytes']) || $row['message_bytes'] < 0 || $row['message_bytes'] > 131072) {
                throw new LogicException('Prepare resolution diagnostic refused.');
            }
        }
    }

    public static function requireOnlyResolutionNotes(array $s): void
    {
        if (array_keys($s) !== ['phase', 'count', 'rows', 'offender_unexecuted', 'restored', 'diagnostic_closed', 'getter_warnings'] || $s['phase'] !== 'prepare' || ! is_int($s['count']) || $s['count'] < 0 || $s['count'] > 32 || ! is_array($s['rows']) || count($s['rows']) !== $s['count'] || $s['offender_unexecuted'] !== true || $s['restored'] !== true || $s['diagnostic_closed'] !== true || $s['getter_warnings'] !== 0) {
            throw new LogicException('Prepare snapshot refused.');
        }
        self::requireOnlyResolutionRows($s['rows']);
    }
}
