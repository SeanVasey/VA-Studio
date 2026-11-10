<?php

namespace Tests\Unit;

use App\Support\Diagnostics\CutoverLedgerChecks;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * BL-49: every entry of docs/production-cleanup-ledger.md is either checked by `vasey:cutover-check` or listed by it as
 * not checkable with the ledger's own verification as the note. Adding a ledger entry without deciding which, or
 * leaving a check behind for an entry the ledger no longer has, fails here.
 */
class CutoverCheckLedgerCoverageTest extends TestCase
{
    private const LEDGER = __DIR__.'/../../docs/production-cleanup-ledger.md';

    /** @return list<string> ids in the first column of the ledger's tables, in document order */
    private function ledgerIds(): array
    {
        $ids = [];
        foreach (file(self::LEDGER, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/\A\|\s*(PCL-\d+)\s*\|/', $line, $match) === 1) {
                $ids[] = $match[1];
            }
        }

        return $ids;
    }

    public function test_every_ledger_id_has_a_check_or_a_not_checkable_entry_and_nothing_else_is_mapped(): void
    {
        $ids = $this->ledgerIds();
        $this->assertGreaterThanOrEqual(30, count($ids), 'The ledger tables were not parsed.');
        $this->assertSame($ids, array_values(array_unique($ids)), 'A ledger id heads two rows.');
        $mapped = array_keys(CutoverLedgerChecks::LEDGER);
        $this->assertSame([], array_values(array_diff($ids, $mapped)), 'Ledger ids with no check and no NOT_CHECKABLE entry in CutoverLedgerChecks::LEDGER.');
        $this->assertSame([], array_values(array_diff($mapped, $ids)), 'CutoverLedgerChecks::LEDGER maps ids the ledger does not have.');
        $this->assertSame($ids, $mapped, 'CutoverLedgerChecks::LEDGER is not in ledger order.');
    }

    public function test_every_id_the_ledger_mentions_anywhere_is_a_row_of_its_tables(): void
    {
        preg_match_all('/PCL-\d+/', (string) file_get_contents(self::LEDGER), $matches);
        $this->assertSame([], array_values(array_diff(array_unique($matches[0]), $this->ledgerIds())), 'The ledger refers to an id that heads no row.');
    }

    public function test_each_mapping_names_a_real_check_or_carries_a_note_for_the_person_who_verifies_it(): void
    {
        $class = new ReflectionClass(CutoverLedgerChecks::class);
        $checks = [];
        foreach (CutoverLedgerChecks::LEDGER as $id => $entry) {
            $this->assertSame(['subject', 'check', 'manual'], array_keys($entry), $id);
            $this->assertNotSame('', trim($entry['subject']), $id);
            if ($entry['check'] === null) {
                $this->assertIsString($entry['manual'], $id.' is not checkable and has no verification note.');
                $this->assertGreaterThan(20, strlen($entry['manual']), $id);

                continue;
            }
            $this->assertTrue($class->hasMethod($entry['check']), $id.' maps to a check that does not exist.');
            $this->assertTrue($class->getMethod($entry['check'])->isPrivate(), $id);
            $checks[] = $entry['check'];
        }
        $this->assertSame($checks, array_values(array_unique($checks)), 'Two ledger ids share one check.');
    }

    public function test_the_ledger_and_the_cutover_runbook_name_the_command(): void
    {
        $this->assertStringContainsString('vasey:cutover-check --expect=production', (string) file_get_contents(self::LEDGER));
        $this->assertStringContainsString('vasey:cutover-check --expect=production', (string) file_get_contents(__DIR__.'/../../docs/migration/cutover_runbook.md'));
    }
}
