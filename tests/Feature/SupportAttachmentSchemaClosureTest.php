<?php

namespace Tests\Feature;

use App\Domain\SupportAttachments\AttachmentSchema;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/** Dedicated synthetic prefixed dictionary probes; no parent or migration history is changed. */
class SupportAttachmentSchemaClosureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection()->setTablePrefix('sc_probe_');
        (new AttachmentSchema)->up();
        $this->beforeApplicationDestroyed(fn () => DB::connection()->getPdo()->exec('DROP TABLE IF EXISTS sc_probe_support_attachments'));
    }

    public function test_reserved_guard_foreign_table_is_refused_in_both_native_and_sqlite_dictionaries(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE sc_probe_support_attachments_update (marker INTEGER)');
        $pdo->exec('INSERT INTO sc_probe_support_attachments_update VALUES (9123)');
        try {
            try {
                (new AttachmentSchema)->assertOwned();
                $this->fail('A foreign table masked the retained owned guard.');
            } catch (LogicException) {
                $this->assertSame(9123, (int) $pdo->query('SELECT marker FROM sc_probe_support_attachments_update')->fetchColumn());
            }
        } finally {
            $pdo->exec('DROP TABLE sc_probe_support_attachments_update');
        }
    }

    public function test_native_case_variant_reserved_name_is_not_exact_owned_namespace(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native case-sensitive table names and case-insensitive dictionary comparisons required.');
        }
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE SC_PROBE_SUPPORT_ATTACHMENTS_UPDATE (marker INTEGER)');
        $pdo->exec('INSERT INTO SC_PROBE_SUPPORT_ATTACHMENTS_UPDATE VALUES (9123)');
        try {
            try {
                (new AttachmentSchema)->up();
                $this->fail('A case variant was treated as an exact owned name.');
            } catch (LogicException) {
                $this->assertSame(9123, (int) $pdo->query('SELECT marker FROM SC_PROBE_SUPPORT_ATTACHMENTS_UPDATE')->fetchColumn());
            }
        } finally {
            $pdo->exec('DROP TABLE SC_PROBE_SUPPORT_ATTACHMENTS_UPDATE');
        }
    }
}
