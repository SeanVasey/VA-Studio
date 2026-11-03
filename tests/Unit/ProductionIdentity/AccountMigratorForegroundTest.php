<?php

namespace Tests\Unit\ProductionIdentity;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AccountMigratorForeground;

final class AccountMigratorForegroundTest extends TestCase
{
    private static function client(): array
    {
        return ['THREAD_ID' => 51, 'NAME' => 'thread/sql/one_connection', 'TYPE' => 'FOREGROUND', 'PROCESSLIST_ID' => 17,
            'PROCESSLIST_COMMAND' => 'Sleep', 'PROCESSLIST_DB' => 'vaseyaudio_production_identity', 'USER_IS_NULL' => 0,
            'INSTRUMENTED' => 'YES', 'EVENT_ID' => 3, 'END_EVENT_ID' => 3];
    }

    private static function internal(): array
    {
        return array_replace(self::client(), ['THREAD_ID' => 31, 'NAME' => 'thread/sql/compress_gtid_table',
            'PROCESSLIST_ID' => 5, 'PROCESSLIST_COMMAND' => 'Daemon', 'PROCESSLIST_DB' => null, 'USER_IS_NULL' => 1,
            'EVENT_ID' => null, 'END_EVENT_ID' => null]);
    }

    public function test_only_the_exact_proven_internal_daemon_is_separated_and_retained_as_evidence(): void
    {
        $client = self::client();
        $internal = self::internal();
        self::assertSame(['clients' => [$client], 'internal' => [$internal]], AccountMigratorForeground::classify([$internal, $client]));
        self::assertSame(['clients' => [$client], 'internal' => []], AccountMigratorForeground::classify([$client]));
        self::assertSame(['clients' => [], 'internal' => []], AccountMigratorForeground::classify([]));
    }

    public function test_other_real_clients_are_accounted_even_outside_fixture_schema_or_when_active(): void
    {
        $other = array_replace(self::client(), ['THREAD_ID' => '52', 'PROCESSLIST_ID' => '18',
            'PROCESSLIST_DB' => 'other_schema', 'PROCESSLIST_COMMAND' => 'Query', 'END_EVENT_ID' => null]);
        self::assertSame(['clients' => [self::client(), $other], 'internal' => []], AccountMigratorForeground::classify([self::client(), $other]));
        $daemonCommandClient = array_replace(self::client(), ['PROCESSLIST_COMMAND' => 'Daemon']);
        self::assertSame([$daemonCommandClient], AccountMigratorForeground::classify([$daemonCommandClient])['clients']);
    }

    public static function invalidRows(): array
    {
        $cases = [];
        foreach (['NAME' => ['thread/sql/event_scheduler', 'thread/sql/unknown', null],
            'TYPE' => ['BACKGROUND', null], 'PROCESSLIST_COMMAND' => ['Sleep', 'Query', null],
            'PROCESSLIST_DB' => ['', 'vaseyaudio_production_identity'], 'USER_IS_NULL' => [0, '0', null],
            'PROCESSLIST_ID' => [0, '0', -1, '01', '999999999999999999999999'],
            'THREAD_ID' => [0, null], 'INSTRUMENTED' => ['unknown'], 'EVENT_ID' => [0, false]] as $field => $values) {
            foreach ($values as $index => $value) {
                $cases['internal '.$field.' '.$index] = [[array_replace(self::internal(), [$field => $value])]];
            }
        }
        $missing = self::internal(); unset($missing['USER_IS_NULL']);
        $cases['missing field'] = [[$missing]];
        $cases['extra field'] = [[self::internal() + ['UNRECOGNIZED' => true]]];
        $cases['non row'] = [[null]];
        $cases['non list'] = [['named' => self::client()]];
        $cases['duplicate internal'] = [[self::internal(), self::internal()]];
        $cases['two distinct internal threads'] = [[self::internal(), array_replace(self::internal(), ['THREAD_ID' => 32, 'PROCESSLIST_ID' => 6])]];
        $cases['duplicate client'] = [[self::client(), self::client()]];
        $cases['duplicate thread across owners'] = [[self::client(), array_replace(self::internal(), ['THREAD_ID' => 51])]];
        $cases['duplicate session across owners'] = [[self::client(), array_replace(self::internal(), ['PROCESSLIST_ID' => 17])]];
        $cases['client unknown name'] = [[array_replace(self::client(), ['NAME' => 'thread/sql/unknown'])]];
        $cases['client null user'] = [[array_replace(self::client(), ['USER_IS_NULL' => 1])]];

        return $cases;
    }

    #[DataProvider('invalidRows')]
    public function test_unproven_ambiguous_and_malformed_foreground_rows_refuse(array $rows): void
    {
        $this->expectException(LogicException::class);
        AccountMigratorForeground::classify($rows);
    }
}
