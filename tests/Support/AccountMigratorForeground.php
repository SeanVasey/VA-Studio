<?php

namespace Tests\Support;

use LogicException;

/** Closed native foreground classification for the exclusively owned ledger fixture. */
final class AccountMigratorForeground
{
    public static function classify(array $rows): array
    {
        if (! array_is_list($rows)) {
            throw new LogicException('Native foreground list required.');
        }
        $clients = [];
        $internal = [];
        $threads = [];
        $sessions = [];
        foreach ($rows as $row) {
            if (! is_array($row) || array_keys($row) !== ['THREAD_ID', 'NAME', 'TYPE', 'PROCESSLIST_ID',
                'PROCESSLIST_COMMAND', 'PROCESSLIST_DB', 'USER_IS_NULL', 'INSTRUMENTED', 'EVENT_ID', 'END_EVENT_ID']) {
                throw new LogicException('Unrecognized native foreground row shape.');
            }
            $thread = self::positive($row['THREAD_ID']);
            $session = self::positive($row['PROCESSLIST_ID']);
            if (isset($threads[$thread]) || isset($sessions[$session]) || $row['TYPE'] !== 'FOREGROUND'
                || ! in_array($row['USER_IS_NULL'], [0, 1, '0', '1'], true)
                || ! in_array($row['INSTRUMENTED'], ['YES', 'NO'], true)
                || ! is_string($row['PROCESSLIST_COMMAND']) || $row['PROCESSLIST_COMMAND'] === ''
                || ($row['PROCESSLIST_DB'] !== null && ! is_string($row['PROCESSLIST_DB']))) {
                throw new LogicException('Ambiguous native foreground identity.');
            }
            foreach (['EVENT_ID', 'END_EVENT_ID'] as $field) {
                if ($row[$field] !== null) {
                    self::positive($row[$field]);
                }
            }
            $threads[$thread] = true;
            $sessions[$session] = true;
            if ($row['NAME'] === 'thread/sql/compress_gtid_table' && $row['PROCESSLIST_COMMAND'] === 'Daemon'
                && $row['PROCESSLIST_DB'] === null && (int) $row['USER_IS_NULL'] === 1 && $internal === []) {
                // Actual owned Oracle8.4.11 baseline proves this exact nonclient identity, including positive ID.
                $internal[] = $row;
            } elseif ($row['NAME'] === 'thread/sql/one_connection' && (int) $row['USER_IS_NULL'] === 0) {
                $clients[] = $row;
            } else {
                throw new LogicException('Unknown native foreground owner.');
            }
        }

        return ['clients' => $clients, 'internal' => $internal];
    }

    private static function positive(mixed $value): int
    {
        if ((! is_int($value) && (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1))
            || (int) $value < 1 || (string) (int) $value !== (string) $value) {
            throw new LogicException('Native foreground positive identity required.');
        }

        return (int) $value;
    }
}
