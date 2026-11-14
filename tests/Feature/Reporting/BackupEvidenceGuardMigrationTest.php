<?php

namespace Tests\Feature\Reporting;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/**
 * The 270360 replacement of the 270350 `backup_evidence` insert guard: kind and status compare byte for byte on MySQL, a
 * successful daily backup needs a positive byte count, and SQLite refuses a non-integer byte count. The migration replaces
 * only the exact 270350 guard and refuses drift before any DDL.
 */
class BackupEvidenceGuardMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const MIGRATION = 'migrations/2026_11_09_270360_backup_evidence_byte_exact_guard.php';

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    /** @param  array<string, mixed>  $values */
    private function insert(array $values): void
    {
        DB::table('backup_evidence')->insert([...['kind' => 'daily', 'status' => 'ok', 'bytes' => 42, 'sha256' => self::SHA,
            'recorded_at' => '2026-11-09 00:00:00'], ...$values]);
    }

    /** @return list<string> */
    private function triggers(): array
    {
        $names = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'backup_evidence')->pluck('name')
            : DB::table('information_schema.TRIGGERS')->whereRaw('CAST(TRIGGER_SCHEMA AS BINARY) = ?', [DB::getDatabaseName()])
                ->where('EVENT_OBJECT_TABLE', 'backup_evidence')->pluck('TRIGGER_NAME');

        return $names->sort()->values()->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function refusedRows(): array
    {
        $rows = [
            'upper-case kind' => ['kind' => 'DAILY'],
            'title-case kind' => ['kind' => 'Daily'],
            'upper-case restore drill' => ['kind' => 'RESTORE-DRILL', 'bytes' => null, 'sha256' => null],
            'accented kind' => ['kind' => 'dâily'],
            'kind with trailing space' => ['kind' => 'daily '],
            'upper-case status' => ['status' => 'OK'],
            'title-case failed status' => ['status' => 'Failed', 'bytes' => null, 'sha256' => null],
            'accented status' => ['status' => 'ók'],
            'status with trailing space' => ['status' => 'ok '],
            'zero bytes for a successful daily backup' => ['bytes' => 0],
            'text bytes' => ['bytes' => 'lots'],
            'text bytes on a failed run' => ['status' => 'failed', 'bytes' => 'lots', 'sha256' => null],
        ];
        if (DB::getDriverName() === 'sqlite') {
            // SQLite keeps a non-integral value as REAL in an INTEGER column; MySQL refuses it as a column value in strict mode.
            $rows['fractional bytes'] = ['bytes' => 1.5];
        }

        return $rows;
    }

    public function test_the_replacement_guard_refuses_case_accent_padding_zero_and_text_bytes_and_admits_valid_evidence(): void
    {
        $this->assertSame(['backup_evidence_immutable', 'backup_evidence_insert_v2', 'backup_evidence_retain'], $this->triggers());
        foreach ($this->refusedRows() as $case => $values) {
            try {
                $this->insert($values);
                $this->fail('Backup evidence insert admitted: '.$case);
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, DB::table('backup_evidence')->count());

        $this->insert([]);
        $this->insert(['bytes' => '7']);
        $this->insert(['status' => 'failed', 'bytes' => 0, 'sha256' => null]);
        $this->insert(['status' => 'failed', 'bytes' => null, 'sha256' => null]);
        $this->insert(['kind' => 'restore-drill', 'bytes' => null, 'sha256' => null]);
        $this->insert(['kind' => 'restore-drill', 'status' => 'failed', 'bytes' => 0, 'sha256' => null]);
        $this->assertSame([['daily', 'ok', 42], ['daily', 'ok', 7], ['daily', 'failed', 0], ['daily', 'failed', null], ['restore-drill', 'ok', null], ['restore-drill', 'failed', 0]],
            DB::table('backup_evidence')->orderBy('id')->get()->map(fn ($r) => [$r->kind, $r->status, $r->bytes === null ? null : (int) $r->bytes])->all());
    }

    public function test_rollback_restores_the_exact_original_guard_and_up_replaces_it_again(): void
    {
        $migration = $this->migration();
        $this->insert([]);
        $migration->down();
        $this->assertSame(['backup_evidence_immutable', 'backup_evidence_insert', 'backup_evidence_retain'], $this->triggers());
        // The 270350 guard admitted a zero-byte successful daily backup.
        $this->insert(['bytes' => 0]);
        $migration->up();
        $this->assertSame(['backup_evidence_immutable', 'backup_evidence_insert_v2', 'backup_evidence_retain'], $this->triggers());
        $this->assertSame(2, DB::table('backup_evidence')->count(), 'Retained evidence is never rewritten.');
        try {
            $this->insert(['bytes' => 0]);
            $this->fail('The replacement guard admitted a zero-byte successful daily backup.');
        } catch (QueryException) {
            $this->assertSame(2, DB::table('backup_evidence')->count());
        }
    }

    public function test_a_drifted_missing_or_already_replaced_guard_is_refused_before_any_ddl(): void
    {
        $migration = $this->migration();
        $sqlite = DB::getDriverName() === 'sqlite';
        $refused = function (string $case, array $expected) use ($migration): void {
            try {
                $migration->up();
                $this->fail('Backup evidence guard migration accepted: '.$case);
            } catch (LogicException) {
                $this->assertSame($expected, $this->triggers(), $case);
            }
        };

        // Already replaced: a second run changes nothing.
        $refused('already replaced', ['backup_evidence_immutable', 'backup_evidence_insert_v2', 'backup_evidence_retain']);

        // A stray replacement beside the exact original is never adopted.
        $migration->down();
        DB::unprepared($sqlite
            ? "CREATE TRIGGER backup_evidence_insert_v2 BEFORE INSERT ON backup_evidence WHEN 0 BEGIN SELECT RAISE(ABORT, 'x'); END"
            : "CREATE TRIGGER backup_evidence_insert_v2 BEFORE INSERT ON backup_evidence FOR EACH ROW BEGIN IF 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'x'; END IF; END");
        $refused('stray replacement', ['backup_evidence_immutable', 'backup_evidence_insert', 'backup_evidence_insert_v2', 'backup_evidence_retain']);
        DB::unprepared('DROP TRIGGER backup_evidence_insert_v2');

        // A missing original is refused.
        DB::unprepared('DROP TRIGGER backup_evidence_insert');
        $refused('missing original', ['backup_evidence_immutable', 'backup_evidence_retain']);

        // A drifted original is refused and left in place.
        DB::unprepared($sqlite
            ? "CREATE TRIGGER backup_evidence_insert BEFORE INSERT ON backup_evidence WHEN NEW.kind = 'weekly' BEGIN SELECT RAISE(ABORT, 'backup evidence shape refused'); END"
            : "CREATE TRIGGER backup_evidence_insert BEFORE INSERT ON backup_evidence FOR EACH ROW BEGIN IF NEW.kind = 'weekly' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'backup evidence shape refused'; END IF; END");
        $refused('drifted original', ['backup_evidence_immutable', 'backup_evidence_insert', 'backup_evidence_retain']);
        // The drifted guard is still the one installed: it admits a zero-byte successful daily backup.
        $this->insert(['bytes' => 0]);
        $this->assertSame(1, DB::table('backup_evidence')->count());
    }
}
