<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\VaseyLicenseContractTexts;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/**
 * Contract increment 5, payload growth: `vasey_license_contract_texts` holds each v2 template and renderer profile once,
 * by kind and SHA-256. Rows are immutable on either engine, storing is idempotent, and rollback refuses once anything is
 * stored. SYNTHETIC, NONBINDING text only.
 */
class VaseyLicenseContractTextMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MIGRATION = '2026_11_09_270200_vasey_license_contract_texts.php';

    private const TEXT = "SYNTHETIC NONBINDING TEMPLATE\n\nFor {CUSTOMER_FULLNAME}.";

    public function test_texts_are_stored_once_by_hash_and_are_immutable(): void
    {
        $this->assertTrue(VaseyLicenseContractTexts::retain('template', self::TEXT));
        $this->assertTrue(VaseyLicenseContractTexts::retain('template', self::TEXT));
        $row = (array) DB::table(VaseyLicenseContractTexts::TABLE)->sole();
        $this->assertSame(['template', hash('sha256', self::TEXT)], [$row['kind'], $row['sha256']]);
        $this->assertStringNotContainsString('SYNTHETIC', $row['payload']);
        $this->assertSame(self::TEXT, Crypt::decryptString($row['payload']));
        $this->assertSame(self::TEXT, VaseyLicenseContractTexts::resolve('template', $row['sha256']));
        // The same bytes are a separate address under another kind; unknown kinds and oversized texts are not stored.
        $this->assertTrue(VaseyLicenseContractTexts::retain('renderer_profile', self::TEXT));
        $this->assertFalse(VaseyLicenseContractTexts::retain('signature', self::TEXT));
        $this->assertFalse(VaseyLicenseContractTexts::retain('template', ''));
        $this->assertFalse(VaseyLicenseContractTexts::retain('template', str_repeat('x', VaseyLicenseContractTexts::KINDS['template'] + 1)));
        $this->assertSame(2, DB::table(VaseyLicenseContractTexts::TABLE)->count());

        foreach ([
            fn () => DB::table(VaseyLicenseContractTexts::TABLE)->where('id', $row['id'])->update(['payload' => Crypt::encryptString('Changed.')]),
            fn () => DB::table(VaseyLicenseContractTexts::TABLE)->where('id', $row['id'])->update(['created_at' => '2026-11-10 00:00:00']),
            fn () => DB::table(VaseyLicenseContractTexts::TABLE)->where('id', $row['id'])->delete(),
            fn () => DB::table(VaseyLicenseContractTexts::TABLE)->insert(['kind' => 'signature', 'sha256' => str_repeat('a', 64), 'payload' => 'x', 'created_at' => '2026-11-09 00:00:00']),
            fn () => DB::table(VaseyLicenseContractTexts::TABLE)->insert(['kind' => 'template', 'sha256' => 'short', 'payload' => 'x', 'created_at' => '2026-11-09 00:00:00']),
            fn () => DB::table(VaseyLicenseContractTexts::TABLE)->insert(['kind' => 'template', 'sha256' => str_repeat('b', 64), 'payload' => '', 'created_at' => '2026-11-09 00:00:00']),
        ] as $index => $write) {
            try {
                $write();
                $this->fail('A contract text write was admitted: '.$index);
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame($row, (array) DB::table(VaseyLicenseContractTexts::TABLE)->where('id', $row['id'])->sole());
        $this->assertSame(2, DB::table(VaseyLicenseContractTexts::TABLE)->count());

        // A second insert at a stored address, plain or replacing (with or without the row's id), never changes the row:
        // MySQL refuses it (REPLACE fires the DELETE trigger); SQLite ignores it before conflict resolution, because its
        // `INSERT OR REPLACE` does not fire DELETE triggers.
        $changed = [...$row, 'payload' => Crypt::encryptString('Changed.'), 'created_at' => '2026-11-10 00:00:00'];
        $replace = (DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE').' INTO '.VaseyLicenseContractTexts::TABLE;
        foreach ([array_diff_key($changed, ['id' => true]), $changed] as $values) {
            foreach (['INSERT INTO '.VaseyLicenseContractTexts::TABLE, $replace] as $verb) {
                $refused = false;
                try {
                    DB::statement($verb.' ('.implode(', ', array_keys($values)).') VALUES ('.implode(', ', array_fill(0, count($values), '?')).')', array_values($values));
                } catch (QueryException) {
                    $refused = true;
                }
                $this->assertTrue($refused || DB::getDriverName() === 'sqlite', $verb);
                $this->assertSame($row, (array) DB::table(VaseyLicenseContractTexts::TABLE)->where('kind', 'template')->sole(), $verb);
                $this->assertSame(2, DB::table(VaseyLicenseContractTexts::TABLE)->count(), $verb);
            }
        }

        // A row changed under its address (the triggers dropped inside this test's database) refuses on read.
        foreach (['vasey_license_contract_texts_immutable', 'vasey_license_contract_texts_insert', 'vasey_license_contract_texts_retain'] as $trigger) {
            DB::unprepared('DROP TRIGGER '.$trigger);
        }
        DB::table(VaseyLicenseContractTexts::TABLE)->where('id', $row['id'])->update(['payload' => Crypt::encryptString('Changed.')]);
        try {
            VaseyLicenseContractTexts::resolve('template', $row['sha256']);
            $this->fail('A changed contract text resolved.');
        } catch (ContractIssuanceException $exception) {
            $this->assertSame('profile_changed', $exception->reason);
        }
        $this->assertFalse(VaseyLicenseContractTexts::retain('template', self::TEXT));

        // A missing or malformed address refuses.
        foreach ([['template', str_repeat('c', 64)], ['template', 'not-a-hash'], ['signature', $row['sha256']], ['renderer_profile', str_repeat('d', 64)]] as [$kind, $sha256]) {
            try {
                VaseyLicenseContractTexts::resolve($kind, $sha256);
                $this->fail('An absent contract text resolved.');
            } catch (ContractIssuanceException $exception) {
                $this->assertSame('profile_changed', $exception->reason);
            }
        }
    }

    public function test_rollback_refuses_once_a_text_is_stored_and_otherwise_drops_and_reinstalls_exactly(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->down();
        $this->assertFalse(Schema::hasTable(VaseyLicenseContractTexts::TABLE));
        $this->assertSame([], $this->triggers());
        $migration->up();
        $this->assertTrue(Schema::hasTable(VaseyLicenseContractTexts::TABLE));
        $owned = ['vasey_license_contract_texts_immutable', 'vasey_license_contract_texts_insert', 'vasey_license_contract_texts_retain'];
        if (DB::getDriverName() === 'sqlite') {
            $owned = ['vasey_license_contract_texts_address', ...$owned];
        }
        $this->assertSame($owned, $this->triggers());

        // Rollback drops only this migration's exact trigger set: another trigger on the table refuses it.
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER foreign_contract_text_guard BEFORE UPDATE ON '.VaseyLicenseContractTexts::TABLE.' BEGIN SELECT RAISE(ABORT, \'foreign\'); END'
            : 'CREATE TRIGGER foreign_contract_text_guard AFTER INSERT ON '.VaseyLicenseContractTexts::TABLE.' FOR EACH ROW SET @foreign_contract_text_guard = 1');
        try {
            $migration->down();
            $this->fail('Rollback dropped a trigger set it does not own.');
        } catch (LogicException) {
            $this->assertTrue(Schema::hasTable(VaseyLicenseContractTexts::TABLE));
        }
        DB::unprepared('DROP TRIGGER foreign_contract_text_guard');
        $this->assertSame($owned, $this->triggers());

        $this->assertTrue(VaseyLicenseContractTexts::retain('template', self::TEXT));
        try {
            $migration->down();
            $this->fail('Rollback discarded a stored contract text.');
        } catch (LogicException) {
            $this->assertSame(self::TEXT, VaseyLicenseContractTexts::resolve('template', hash('sha256', self::TEXT)));
            $this->assertSame($owned, $this->triggers());
        }
    }

    /** @return list<string> the table's trigger names, sorted */
    private function triggers(): array
    {
        $names = DB::getDriverName() === 'mysql'
            ? array_column(DB::select('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', [VaseyLicenseContractTexts::TABLE]), 'name')
            : array_column(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?", [VaseyLicenseContractTexts::TABLE]), 'name');
        sort($names);

        return $names;
    }
}
