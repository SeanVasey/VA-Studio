<?php

namespace Tests\Unit\Migration;

use App\Domain\Migration\CatalogOnboarding\PrivateSourceFiles;
use App\Domain\Migration\History\HistorySourceFiles;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyHistoryFixtures;

class HistoryPrivateSourceFilesTest extends TestCase
{
    protected function tearDown(): void
    {
        LegacyHistoryFixtures::cleanup();
        parent::tearDown();
    }

    public function test_empty_private_file_requires_an_explicit_opt_in_and_retains_exact_identity(): void
    {
        $path = LegacyHistoryFixtures::privateFile('');
        $files = new PrivateSourceFiles;
        $this->refused(fn () => $files->protectedFile($path));
        $proof = $files->protectedFile($path, 16, true);
        $this->assertSame('', $proof['bytes']);
        $this->assertSame(hash('sha256', ''), $proof['sha256']);
        $this->assertSame(0, $proof['identity']['size']);
        $this->assertSame(1, $proof['identity']['nlink']);
        $this->assertSame(0600, $proof['identity']['mode'] & 07777);
    }

    public function test_empty_opt_in_does_not_admit_unsafe_permissions_or_links_or_exceed_bounds(): void
    {
        $path = LegacyHistoryFixtures::privateFile('');
        $files = new PrivateSourceFiles;
        chmod($path, 0640);
        $this->refused(fn () => $files->protectedFile($path, 16, true));
        chmod($path, 0600);
        link($path, $path.'.hard');
        $this->refused(fn () => $files->protectedFile($path, 16, true));
        unlink($path.'.hard');
        symlink($path, $path.'.symbolic');
        $this->refused(fn () => $files->protectedFile($path.'.symbolic', 16, true));
        unlink($path.'.symbolic');
        chmod(dirname($path), 0750);
        $this->refused(fn () => $files->protectedFile($path, 16, true));
        chmod(dirname($path), 0700);
        file_put_contents($path, 'oversize');
        $this->refused(fn () => $files->protectedFile($path, 1, true));
    }

    public function test_only_history_contract_originals_admit_empty_bytes_while_sheets_and_manifests_refuse(): void
    {
        $reader = new HistorySourceFiles;
        $source = LegacyHistoryFixtures::privateCopy('baseline', function (array $parts): array {
            $parts['files']['synthetic-contract-1001-1.txt'] = '';

            return $parts;
        });
        $this->assertSame('', $reader->read($source)['contracts']['synthetic-contract-1001-1.txt']);
        foreach (['mapping', 'customers', 'orders', 'contracts'] as $sheet) {
            $source = LegacyHistoryFixtures::privateCopy('baseline', function (array $parts) use ($sheet): array {
                $parts[$sheet] = '';

                return $parts;
            });
            $this->refused(fn () => $reader->read($source));
        }
        $manifest = LegacyHistoryFixtures::privateFile('');
        rename($manifest, dirname($manifest).'/catalog.json');
        $this->refused(fn () => (new PrivateSourceFiles)->snapshot(dirname($manifest)));
        $sideFile = LegacyHistoryFixtures::privateFile('');
        $this->refused(fn () => $reader->sideFile($sideFile));
    }

    public function test_synthetic_repository_fixture_also_distinguishes_empty_original_from_empty_sheet(): void
    {
        $source = LegacyHistoryFixtures::privateCopy('baseline', function (array $parts): array {
            $parts['files']['synthetic-contract-1001-1.txt'] = '';

            return $parts;
        });
        $fixture = dirname(__DIR__, 2).'/Fixtures/migration/history-empty-runtime-'.bin2hex(random_bytes(8));
        mkdir($fixture, 0700);
        mkdir($fixture.'/contracts', 0700);
        try {
            foreach (['mapping.json', 'customers.csv', 'orders.csv', 'contracts.csv'] as $name) {
                copy($source.'/'.$name, $fixture.'/'.$name);
            }
            foreach (glob($source.'/contracts/*') as $path) {
                copy($path, $fixture.'/contracts/'.basename($path));
            }
            $reader = new HistorySourceFiles;
            $this->assertSame('', $reader->read($fixture)['contracts']['synthetic-contract-1001-1.txt']);
            file_put_contents($fixture.'/orders.csv', '');
            try {
                $reader->read($fixture);
                $this->fail('Empty repository fixture sheet admitted');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('source_file', $exception->getMessage());
            }
        } finally {
            foreach (glob($fixture.'/contracts/*') as $path) {
                unlink($path);
            }
            rmdir($fixture.'/contracts');
            foreach (glob($fixture.'/*') as $path) {
                unlink($path);
            }
            rmdir($fixture);
        }
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unsafe or non-opted-in source admitted');
        } catch (InvalidArgumentException $exception) {
            $this->assertContains($exception->getMessage(), ['catalog_source_files_invalid', 'source_not_private']);
        }
    }
}
