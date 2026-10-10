<?php

namespace Tests\Feature\Migration;

use App\Domain\Migration\BeatStars\BeatStarsDryRunFiles;
use App\Domain\Migration\CatalogOnboarding\CatalogDatabaseEvidence;
use App\Domain\Migration\CatalogOnboarding\PrivateSourceFiles;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * End-to-end dry run on the synthetic fixture: the real CLI, byte-identical reports, an admissible
 * snapshot and zero SQL. SQLite only; the dry run never opens a database on any driver.
 */
class BeatStarsDryRunCommandTest extends TestCase
{
    use RefreshDatabase;

    private const OUTPUTS = ['beatstars-dry-run.json', 'beatstars-dry-run.md', 'catalog.json'];

    private string $base;

    private string $repository;

    private string $fixtures;

    private ?string $repositoryOutput = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Absolute, dot-free paths: the CLI refuses `..` components by design.
        $this->repository = dirname(__DIR__, 3);
        $this->fixtures = $this->repository.'/tests/Fixtures/migration/beatstars-synthetic';
        $this->base = sys_get_temp_dir().'/vasey-beatstars-dry-run-'.bin2hex(random_bytes(8));
        mkdir($this->base, 0700);
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
        if ($this->repositoryOutput !== null && is_dir($this->repositoryOutput)) {
            $this->remove($this->repositoryOutput);
        }
        parent::tearDown();
    }

    public function test_real_cli_writes_identical_private_reports_and_an_admissible_snapshot_without_touching_the_database(): void
    {
        $before = $this->evidence();
        $first = $this->command('export.csv', $this->outputDirectory('first'));
        $this->assertSame(0, $first['exit'], $first['stdout'].$first['stderr']);
        $this->assertSame(['code' => 'dry_run_clean', 'rows' => 4, 'normalized' => 4, 'withheld' => 0, 'findings' => 0,
            'snapshot' => 'catalog.json', 'written' => self::OUTPUTS, 'unchanged' => [], 'database_writes' => 0], $first['summary']);
        $this->assertSame('', $first['stderr']);
        foreach (['SYNTHETIC', 'bs-synthetic', 'Producer', $this->base, $this->fixtures] as $private) {
            $this->assertStringNotContainsString($private, $first['stdout']);
        }
        $bytes = $this->bytes($this->outputDirectory('first'));
        $this->assertSame(self::OUTPUTS, array_keys($bytes));
        foreach (self::OUTPUTS as $name) {
            $this->assertSame(0600, fileperms($this->outputDirectory('first').'/'.$name) & 07777);
        }
        $this->assertSame(['.', '..', ...self::OUTPUTS], scandir($this->outputDirectory('first')));
        $this->assertStringContainsString('"applied": false', $bytes['beatstars-dry-run.json']);
        $this->assertStringContainsString('**Nothing was applied.**', $bytes['beatstars-dry-run.md']);
        $this->assertStringNotContainsString($this->base, implode('', $bytes));
        $this->assertStringNotContainsString($this->fixtures, implode('', $bytes));

        // A second run into another directory is byte-identical; a rerun into the first directory rewrites nothing.
        $second = $this->command('export.csv', $this->outputDirectory('second'));
        $this->assertSame(0, $second['exit']);
        $this->assertSame($bytes, $this->bytes($this->outputDirectory('second')));
        $inodes = array_map(fn (string $name): int => fileinode($this->outputDirectory('first').'/'.$name), self::OUTPUTS);
        $rerun = $this->command('export.csv', $this->outputDirectory('first'));
        $this->assertSame(0, $rerun['exit']);
        $this->assertSame(['written' => [], 'unchanged' => self::OUTPUTS], array_intersect_key($rerun['summary'], ['written' => 1, 'unchanged' => 1]));
        clearstatcache();
        $this->assertSame($inodes, array_map(fn (string $name): int => fileinode($this->outputDirectory('first').'/'.$name), self::OUTPUTS));
        $this->assertSame($bytes, $this->bytes($this->outputDirectory('first')));

        // In-process run: the same bytes, zero SQL statements, catalog and draft tables unchanged.
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $outcome = (new BeatStarsDryRunFiles)->run($this->fixtures.'/export.csv', $this->fixtures.'/mapping.json', $this->outputDirectory('third'));
        $this->assertSame([], $queries);
        $this->assertSame(self::OUTPUTS, $outcome['written']);
        $this->assertSame($bytes, $this->bytes($this->outputDirectory('third')));
        $this->assertSame($before, $this->evidence());
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDatabaseCount('catalog_import_batches', 0);
        $this->assertDatabaseCount('catalog_import_mappings', 0);
        $this->assertDatabaseCount('audit_events', 0);

        // The emitted snapshot passes the importer's own private-file admission once the export sits at raw/<name>.
        $stage = $this->base.'/stage';
        mkdir($stage, 0700);
        mkdir($stage.'/raw', 0700);
        copy($this->outputDirectory('first').'/catalog.json', $stage.'/catalog.json');
        copy($this->fixtures.'/export.csv', $stage.'/raw/export.csv');
        chmod($stage.'/catalog.json', 0600);
        chmod($stage.'/raw/export.csv', 0600);
        $source = (new PrivateSourceFiles)->snapshot($stage);
        $this->assertSame($outcome['result']['snapshot_sha256'], $source['source_sha256']);
        $this->assertSame(['bs-synthetic-0001', 'bs-synthetic-0002', 'bs-synthetic-0003', 'bs-synthetic-0004'], array_column($source['snapshot']['records'], 'source_id'));
        $this->assertSame($before, $this->evidence());
    }

    public function test_findings_copy_exits_three_and_writes_the_report_without_a_snapshot(): void
    {
        $result = $this->command('export-findings.csv', $this->outputDirectory('findings'));
        $this->assertSame(3, $result['exit']);
        $this->assertSame(['code' => 'dry_run_findings', 'rows' => 4, 'normalized' => 0, 'withheld' => 4, 'findings' => 2,
            'snapshot' => 'none', 'written' => ['beatstars-dry-run.json', 'beatstars-dry-run.md'], 'unchanged' => [], 'database_writes' => 0], $result['summary']);
        $bytes = $this->bytes($this->outputDirectory('findings'));
        $this->assertSame(['beatstars-dry-run.json', 'beatstars-dry-run.md'], array_keys($bytes));
        $this->assertStringContainsString('| sheet | — | — | `unmapped_column` | Likes | — |', $bytes['beatstars-dry-run.md']);
        $this->assertStringContainsString('| row | 3 | bs-synthetic-0003 | `missing_rights_reference` | Rights Reference | — |', $bytes['beatstars-dry-run.md']);
        $this->assertStringContainsString('No draft snapshot was produced', $bytes['beatstars-dry-run.md']);
        $this->assertSame($bytes, $this->bytes($this->outputDirectory('findings')));
        $this->assertSame(3, $this->command('export-findings.csv', $this->outputDirectory('findings'))['exit']);
        $this->assertSame(3, $this->command('export-findings.csv', $this->outputDirectory('findings-again'))['exit']);
        $this->assertSame($bytes, $this->bytes($this->outputDirectory('findings-again')));
    }

    public function test_a_sheet_whose_snapshot_exceeds_the_limit_exits_three_with_a_report_naming_the_finding(): void
    {
        // A clean synthetic sheet of about 0.6 MB whose drafts snapshot would exceed the 1 MiB decoder cap.
        $handle = fopen($this->base.'/large.csv', 'xb');
        fputcsv($handle, ['Track ID', 'Title', 'BPM', 'Key', 'Genre', 'Mood', 'Tags', 'Description', 'Status', 'Lease Name',
            'Lease Price', 'Exclusive Price', 'Rights Reference', 'Plays'], ',', '"', '');
        for ($index = 1; $index <= 120; $index++) {
            fputcsv($handle, [sprintf('bs-synthetic-large-%04d', $index), sprintf('SYNTHETIC Large %04d', $index), '95', 'C minor',
                'Synthetic', '', 'synthetic', 'SYNTHETIC '.str_repeat('d', 4990), 'Public', 'SYNTHETIC Basic', '1.00', '10.00',
                sprintf('SYNTHETIC-RIGHTS-L%04d', $index), '0'], ',', '"', '');
        }
        fclose($handle);
        chmod($this->base.'/large.csv', 0600);
        $this->assertLessThan(700000, filesize($this->base.'/large.csv'));

        $output = $this->outputDirectory('large');
        $result = $this->invoke(['--export', $this->base.'/large.csv', '--mapping', $this->fixtures.'/mapping.json', '--output', $output]);
        $this->assertSame(3, $result['exit'], $result['stdout'].$result['stderr']);
        $this->assertSame(['code' => 'dry_run_findings', 'rows' => 120, 'normalized' => 0, 'withheld' => 120, 'findings' => 1,
            'snapshot' => 'none', 'written' => ['beatstars-dry-run.json', 'beatstars-dry-run.md'], 'unchanged' => [], 'database_writes' => 0],
            $result['summary']);
        $this->assertSame('', $result['stderr']);
        foreach (['SYNTHETIC', $this->base, $this->fixtures] as $private) {
            $this->assertStringNotContainsString($private, $result['stdout']);
        }
        $this->assertSame(['.', '..', 'beatstars-dry-run.json', 'beatstars-dry-run.md'], scandir($output));
        $bytes = $this->bytes($output);
        $this->assertMatchesRegularExpression('/\| sheet \| — \| — \| `snapshot_too_large` \| — \| [0-9]+ bytes, maximum 1048576 \|/',
            $bytes['beatstars-dry-run.md']);
        $this->assertStringContainsString('No draft snapshot was produced', $bytes['beatstars-dry-run.md']);
        $this->assertSame('snapshot_too_large', json_decode($bytes['beatstars-dry-run.json'], true, 64)['findings'][0]['code']);
    }

    public static function refusals(): array
    {
        return ['missing option' => ['usage'], 'repeated option' => ['usage'], 'missing export' => ['path'], 'empty export' => ['input_file'],
            'symlinked mapping' => ['path'], 'output inside repository' => ['output_inside_repository'],
            'shared output directory' => ['output_directory'], 'foreign file in output' => ['output_directory_not_empty'],
            'different retained report' => ['output_differs'], 'retained snapshot without findings-free run' => ['output_differs'],
            'bad mapping' => ['mapping_json'], 'export inside repository without a synthetic mapping' => ['input_inside_repository'],
            'group-readable export' => ['input_not_private'], 'hard-linked export' => ['input_not_private']];
    }

    #[DataProvider('refusals')]
    public function test_refusals_leave_the_output_directory_untouched(string $reason): void
    {
        $output = $this->outputDirectory('refused');
        $arguments = ['--export', $this->fixtures.'/export.csv', '--mapping', $this->fixtures.'/mapping.json', '--output', $output];
        $expected = ['.', '..'];
        switch ($reason) {
            case 'usage':
                $arguments = $this->dataName() === 'missing option' ? array_slice($arguments, 0, 4)
                    : [...$arguments, '--output', $output];
                break;
            case 'input_file':
                touch($this->base.'/empty.csv');
                $arguments[1] = $this->base.'/empty.csv';
                break;
            case 'path':
                if ($this->dataName() === 'missing export') {
                    $arguments[1] = $this->base.'/absent.csv';
                } else {
                    symlink($this->fixtures.'/mapping.json', $this->base.'/mapping-link.json');
                    $arguments[3] = $this->base.'/mapping-link.json';
                }
                break;
            case 'output_inside_repository':
                $this->repositoryOutput = $this->repository.'/storage/beatstars-dry-run-refused-'.bin2hex(random_bytes(4));
                mkdir($this->repositoryOutput, 0700);
                $arguments[5] = $output = $this->repositoryOutput;
                break;
            case 'output_directory': chmod($output, 0755);
                break;
            case 'output_directory_not_empty':
                file_put_contents($output.'/notes.txt', 'operator note');
                $expected[] = 'notes.txt';
                break;
            case 'output_differs':
                if ($this->dataName() === 'different retained report') {
                    file_put_contents($output.'/beatstars-dry-run.json', "{}\n");
                    $expected[] = 'beatstars-dry-run.json';
                } else {
                    file_put_contents($output.'/catalog.json', "{}\n");
                    $expected[] = 'catalog.json';
                    $arguments[1] = $this->fixtures.'/export-findings.csv';
                }
                chmod($output.'/'.$expected[2], 0600);
                break;
            case 'mapping_json':
                file_put_contents($this->base.'/mapping.json', '{');
                $arguments[3] = $this->base.'/mapping.json';
                break;
            case 'input_inside_repository':
                // The checkout's synthetic export with a mapping that claims a real acquisition.
                $mapping = json_decode((string) file_get_contents($this->fixtures.'/mapping.json'), true, 16);
                $mapping['acquisition_method'] = 'official_export';
                file_put_contents($this->base.'/mapping.json', json_encode($mapping));
                $arguments[3] = $this->base.'/mapping.json';
                break;
            case 'input_not_private':
                copy($this->fixtures.'/export.csv', $this->base.'/export.csv');
                if ($this->dataName() === 'group-readable export') {
                    chmod($this->base.'/export.csv', 0640);
                } else {
                    chmod($this->base.'/export.csv', 0600);
                    link($this->base.'/export.csv', $this->base.'/export-second-link.csv');
                }
                $arguments[1] = $this->base.'/export.csv';
                break;
        }
        $result = $this->invoke($arguments);
        $this->assertSame(1, $result['exit'], $result['stdout']);
        $this->assertSame(['code' => 'dry_run_refused', 'reason' => $reason, 'database_writes' => 0], $result['summary']);
        $this->assertSame($expected, scandir($output));
        foreach (['SYNTHETIC', $this->base, $this->fixtures] as $private) {
            $this->assertStringNotContainsString($private, $result['stdout'].$result['stderr']);
        }
    }

    private function command(string $export, string $output): array
    {
        return $this->invoke(['--export', $this->fixtures.'/'.$export, '--mapping', $this->fixtures.'/mapping.json', '--output', $output]);
    }

    private function invoke(array $arguments): array
    {
        $process = new Process([PHP_BINARY, $this->repository.'/scripts/migration/beatstars-dry-run.php', ...$arguments], null, [], null, 60);
        $process->run();
        $stdout = $process->getOutput();

        return ['exit' => $process->getExitCode(), 'stdout' => $stdout, 'stderr' => $process->getErrorOutput(),
            'summary' => json_decode($stdout, true, 8)];
    }

    private function outputDirectory(string $name): string
    {
        $path = $this->base.'/'.$name;
        if (! is_dir($path)) {
            mkdir($path, 0700);
        }

        return $path;
    }

    private function bytes(string $directory): array
    {
        $bytes = [];
        foreach (self::OUTPUTS as $name) {
            if (file_exists($directory.'/'.$name)) {
                $bytes[$name] = file_get_contents($directory.'/'.$name);
            }
        }

        return $bytes;
    }

    private function evidence(): array
    {
        $database = new CatalogDatabaseEvidence;

        return [$database->target(), $database->rows('catalog_import_batches'), $database->rows('catalog_import_mappings')];
    }

    private function remove(string $path): void
    {
        if (is_link($path) || ! is_dir($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}
