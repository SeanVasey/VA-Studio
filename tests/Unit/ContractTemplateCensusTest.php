<?php

namespace Tests\Unit;

use App\Domain\Contracts\Production\ContractTemplateCensus;
use App\Domain\Contracts\Production\ContractVariableBinder;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Increment 4 gate (review P3-2): the placeholder census of license templates against the binder's closed set, and
 * the doubled-symbol rule. The committed templates are SYNTHETIC, NONBINDING fixtures; Sean's real templates are
 * private and are only ever read from outside the repository.
 */
class ContractTemplateCensusTest extends TestCase
{
    private const FIXTURES = 'tests/fixtures/contracts/vasey-license-templates';

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().'/contract-census-test-'.bin2hex(random_bytes(6));
        File::copyDirectory(base_path(self::FIXTURES), $this->scratch);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    private function census(string ...$paths): Process
    {
        $process = new Process([PHP_BINARY, base_path('scripts/contract-placeholder-census.php'), ...$paths], base_path(), timeout: 60);
        $process->run();

        return $process;
    }

    public function test_the_synthetic_set_uses_exactly_the_binders_closed_set(): void
    {
        $report = ContractTemplateCensus::census([
            'synthetic-basic.txt' => file_get_contents($this->scratch.'/synthetic-basic.txt'),
            'synthetic-exclusive.txt' => file_get_contents($this->scratch.'/synthetic-exclusive.txt'),
        ]);

        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['unknown']);
        $this->assertSame([], $report['unused']);
        $this->assertEqualsCanonicalizing(ContractVariableBinder::PLACEHOLDERS, array_keys($report['used']));
        $this->assertSame(['CONTRACT_DATE' => 1, 'CUSTOMER_ALIAS' => 1, 'CUSTOMER_FULLNAME' => 1, 'LICENSE_NAME' => 1, 'PRODUCT_PRICE' => 1, 'PRODUCT_TITLE' => 1],
            $report['files']['synthetic-exclusive.txt']['placeholders']);
    }

    public function test_unknown_placeholders_doubled_symbols_and_stray_braces_are_reported_by_line(): void
    {
        $report = ContractTemplateCensus::census(['synthetic-flawed.txt' => file_get_contents($this->scratch.'/synthetic-flawed.txt')]);

        $this->assertFalse($report['ok']);
        $this->assertSame(['CUSTOMER_EMAIL'], $report['unknown']);
        $this->assertSame(['CUSTOMER_EMAIL'], $report['files']['synthetic-flawed.txt']['unknown']);
        $this->assertSame([
            ['placeholder' => 'PRODUCT_PRICE', 'symbol' => '$', 'line' => 3],
            ['placeholder' => 'PRODUCT_PRICE_WORD', 'symbol' => 'dollars', 'line' => 3],
            ['placeholder' => 'PUBLISHING_RIGHTS', 'symbol' => '%', 'line' => 4],
        ], $report['files']['synthetic-flawed.txt']['doubled']);
        $this->assertSame([5], $report['files']['synthetic-flawed.txt']['malformed_lines']);
        $this->assertContains('CUSTOMER_ADDRESS', $report['unused']);
    }

    public function test_leftover_license_source_variables_and_invalid_text_fail_the_census(): void
    {
        $this->assertSame([1], ContractTemplateCensus::scan('Leftover {{usage.audio_streams}} variable.')['malformed_lines']);
        $this->assertFalse(ContractTemplateCensus::census(['bad.txt' => "\xff"])['ok']);
        $this->assertFalse(ContractTemplateCensus::census([])['ok']);
        // Lower-case or spaced names are not the binder's and are reported as unknown, never normalized.
        $this->assertSame(['CONTRACT DATE', 'contract_date'], ContractTemplateCensus::census(['x.txt' => '{contract_date} {CONTRACT DATE}'])['unknown']);
    }

    public function test_the_tool_reads_private_templates_from_outside_the_repository_only(): void
    {
        $clean = $this->census($this->scratch.'/synthetic-basic.txt', $this->scratch.'/synthetic-exclusive.txt');
        $this->assertSame(0, $clean->getExitCode(), $clean->getErrorOutput());
        $report = json_decode($clean->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['unused']);
        // The report carries names, counts and line numbers only, never template text.
        $this->assertStringNotContainsString('synthetic fixture', $clean->getOutput());

        $flawed = $this->census($this->scratch.'/synthetic-flawed.txt');
        $this->assertSame(1, $flawed->getExitCode());
        $this->assertSame(['CUSTOMER_EMAIL'], json_decode($flawed->getOutput(), true, 32, JSON_THROW_ON_ERROR)['unknown']);

        foreach ([base_path(self::FIXTURES.'/synthetic-basic.txt'), self::FIXTURES.'/synthetic-basic.txt', base_path('composer.json')] as $inside) {
            $refused = $this->census($inside);
            $this->assertSame(2, $refused->getExitCode(), $inside);
            $this->assertStringContainsString('inside the repository', $refused->getErrorOutput());
            $this->assertSame('', $refused->getOutput());
        }
        symlink(base_path(self::FIXTURES.'/synthetic-basic.txt'), $this->scratch.'/linked.txt');
        $this->assertSame(2, $this->census($this->scratch.'/linked.txt')->getExitCode());
        $this->assertSame(2, $this->census($this->scratch.'/missing.txt')->getExitCode());
        $this->assertSame(2, $this->census()->getExitCode());
    }
}
