<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Runs the real script end to end on its own synthetic data in an isolated temporary directory. */
class BackupRestoreProofTest extends TestCase
{
    private string $workdir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workdir = sys_get_temp_dir().'/va-backup-proof-'.bin2hex(random_bytes(8));
        mkdir($this->workdir, 0700);
        chmod($this->workdir, 0700);
        $this->workdir = realpath($this->workdir);
    }

    protected function tearDown(): void
    {
        $this->remove($this->workdir);
        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            chmod($path, 0700);
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $this->remove($path.'/'.$entry);
            }
            rmdir($path);
        }
    }

    private function script(array $arguments): array
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/scripts/ops/backup-restore-proof.php', ...$arguments]);
        $process->setTimeout(60);
        $process->run();
        $this->assertSame('', $process->getErrorOutput());
        $report = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('synthetic_backup_restore_proof', $report['scope']);
        $this->assertTrue($report['synthetic']);
        $this->assertFalse($report['production_data_used']);
        $this->assertSame('documented_not_executed', $report['mysql_procedure']);
        $this->assertStringNotContainsString($this->workdir, $process->getOutput());
        $this->assertSame($report['result'] === 'RESTORE_VERIFIED' ? 0 : 1, $process->getExitCode());

        return $report;
    }

    private function proof(): array
    {
        return $this->script(['--synthetic-proof', '--workdir', $this->workdir]);
    }

    private function verify(): array
    {
        return $this->script(['--verify', '--backup', $this->workdir.'/backup', '--restore', $this->workdir.'/restore']);
    }

    private function statuses(array $report): array
    {
        return array_column($report['checks'], 'status', 'id');
    }

    public function test_synthetic_backup_restores_into_an_isolated_location_with_byte_and_manifest_equality(): void
    {
        $report = $this->proof();
        $this->assertSame('RESTORE_VERIFIED', $report['result']);
        $this->assertSame(['pass'], array_values(array_unique(array_column($report['checks'], 'status'))));
        $this->assertCount(15, $report['checks']);
        $this->assertSame(7, $report['counts']['private_files']);
        $this->assertGreaterThan(0, $report['counts']['database_bytes']);
        $this->assertGreaterThan(262144, $report['counts']['private_bytes']);

        $manifest = json_decode(file_get_contents($this->workdir.'/backup/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame(hash('sha256', file_get_contents($this->workdir.'/backup/manifest.json'))."  manifest.json\n",
            file_get_contents($this->workdir.'/backup/manifest.json.sha256'));
        $this->assertTrue($manifest['synthetic']);
        $this->assertSame(['synthetic_orders' => 24, 'synthetic_receipts' => 25], $manifest['database']['rows']);
        foreach ($manifest['private']['files'] as $relative => $file) {
            $restored = $this->workdir.'/restore/private/'.$relative;
            $this->assertSame($file['sha256'], hash_file('sha256', $restored));
            $this->assertSame(file_get_contents($this->workdir.'/source/private/'.$relative), file_get_contents($restored));
            $this->assertSame(0600, fileperms($restored) & 07777);
        }
        $this->assertSame(hash_file('sha256', $this->workdir.'/backup/database.sqlite'), hash_file('sha256', $this->workdir.'/restore/database.sqlite'));
        $this->assertDirectoryExists($this->workdir.'/restore/private/empty-dir');

        $pdo = new \PDO('sqlite:'.$this->workdir.'/restore/database.sqlite');
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(['SYNTHETIC-ORDER-0001', 'XXX'], $pdo->query('SELECT public_id, currency FROM synthetic_orders ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_NUM));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM synthetic_orders WHERE id = 7')->fetchColumn());
        $pdo = null;

        $this->assertSame('RESTORE_VERIFIED', $this->verify()['result']);
    }

    public static function tampering(): array
    {
        return [
            'restored private byte flipped' => ['flip_private', 'restored_private_files_match_manifest'],
            'restored private file removed' => ['remove_private', 'restored_private_files_match_manifest'],
            'unlisted restored file added' => ['extra_private', 'restored_private_files_match_manifest'],
            'restored symlink planted' => ['symlink_private', 'restored_private_tree_safe'],
            'restored file permissions widened' => ['chmod_private', 'restored_private_tree_safe'],
            'restored database byte changed' => ['flip_database', 'restored_database_bytes_equal_backup'],
            'backup file changed after manifest' => ['flip_backup', 'backup_private_tree_matches_manifest'],
            'manifest edited' => ['edit_manifest', 'manifest_digest_matches'],
        ];
    }

    #[DataProvider('tampering')]
    public function test_any_tampering_after_backup_or_restore_blocks_verification(string $case, string $check): void
    {
        $this->assertSame('RESTORE_VERIFIED', $this->proof()['result']);
        $restore = $this->workdir.'/restore';
        $flip = static function (string $path, int $offset): void {
            $bytes = file_get_contents($path);
            $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);
            file_put_contents($path, $bytes);
        };
        match ($case) {
            'flip_private' => $flip($restore.'/private/masters/synthetic-track-0001.bin', 1000),
            'remove_private' => unlink($restore.'/private/stems/synthetic-track-0001/bass.bin'),
            'extra_private' => file_put_contents($restore.'/private/masters/unlisted.bin', 'SYNTHETIC') && chmod($restore.'/private/masters/unlisted.bin', 0600),
            'symlink_private' => symlink('/etc/hostname', $restore.'/private/contracts/link.bin'),
            'chmod_private' => chmod($restore.'/private/contracts/synthetic-contract-0001.bin', 0644),
            'flip_database' => $flip($restore.'/database.sqlite', 200),
            'flip_backup' => $flip($this->workdir.'/backup/private/masters/synthetic-track-0002.bin', 10),
            'edit_manifest' => file_put_contents($this->workdir.'/backup/manifest.json', str_replace('"synthetic": true', '"synthetic":true', file_get_contents($this->workdir.'/backup/manifest.json'))),
        };
        $report = $this->verify();
        $this->assertSame('BLOCKED', $report['result']);
        $this->assertSame('blocked', $this->statuses($report)[$check]);
    }

    public function test_unsafe_or_ambiguous_invocations_are_refused_before_writing(): void
    {
        file_put_contents($this->workdir.'/occupied', 'SYNTHETIC');
        $report = $this->script(['--synthetic-proof', '--workdir', $this->workdir]);
        $this->assertSame(['workdir_isolated_empty_private' => 'blocked'], $this->statuses($report));
        $this->assertSame(['.', '..', 'occupied'], scandir($this->workdir));
        unlink($this->workdir.'/occupied');

        chmod($this->workdir, 0755);
        $this->assertSame(['workdir_isolated_empty_private' => 'blocked'], $this->statuses($this->proof()));
        chmod($this->workdir, 0700);

        $repository = dirname(__DIR__, 2);
        $this->assertSame(['workdir_isolated_empty_private' => 'blocked'], $this->statuses($this->script(['--synthetic-proof', '--workdir', $repository.'/storage'])));
        $this->assertSame(['workdir_isolated_empty_private' => 'blocked'], $this->statuses($this->script(['--synthetic-proof', '--workdir', 'relative/dir'])));
        $this->assertSame(['workdir_isolated_empty_private' => 'blocked'], $this->statuses($this->script(['--synthetic-proof', '--workdir', $this->workdir.'/../'.basename($this->workdir)])));
        $this->assertSame(['usage_synthetic_proof_or_verify' => 'blocked'], $this->statuses($this->script([])));
        $this->assertSame(['usage_synthetic_proof_or_verify' => 'blocked'], $this->statuses($this->script(['--backup', '/srv/production'])));
        $this->assertSame(['verify_paths_isolated' => 'blocked'], $this->statuses($this->script(['--verify', '--backup', $this->workdir, '--restore', $this->workdir])));
        $this->assertSame(['.', '..'], scandir($this->workdir));
    }
}
