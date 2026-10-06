<?php

namespace Tests\Unit;

use App\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CatalogDryRunCommandTest extends TestCase
{
    private string $directory;

    private string $output;

    private array $manifest;

    private array $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/vasey-catalog-dry-run-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->output = $this->directory.'/output';
        mkdir($this->output, 0700);
        $records = [];
        foreach (['alpha' => 'public', 'beta' => 'draft', 'gamma' => 'private'] as $name => $visibility) {
            $value = ['title' => 'SYNTHETIC private title '.$name, 'slug' => 'synthetic-'.$name, 'visibility' => $visibility];
            $records[] = ['source_id' => 'synthetic-'.$name, 'source_sha256' => CanonicalJson::hash($value), 'value' => $value];
        }
        $this->manifest = ['schema_version' => 1, 'fixture' => 'vasey-synthetic-migration-v1',
            'batch_id' => 'synthetic-batch-1', 'source_system' => 'synthetic-source',
            'acquired_at' => '2026-10-06T00:00:00Z', 'source_as_of' => '2026-10-06T00:00:00Z',
            'acquisition_method' => 'synthetic_fixture', 'operator' => 'synthetic-operator', 'records' => $records];
        $track = ['target_id' => 'synthetic-target-beta'] + $records[1]['value'];
        $this->target = ['schema_version' => 1, 'fixture' => 'vasey-synthetic-migration-v1',
            'snapshot_id' => 'synthetic-target-1', 'tracks' => [$track], 'mappings' => [[
                'source_system' => 'synthetic-source', 'source_id' => 'synthetic-beta',
                'source_sha256' => $records[1]['source_sha256'], 'transform_version' => 'vasey-synthetic-catalog-draft-v1',
                'target_id' => $track['target_id'], 'target_sha256' => CanonicalJson::hash($track),
            ]]];
        $this->inputs();
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
        parent::tearDown();
    }

    public function test_real_cli_resumes_deterministically_and_completed_replay_does_not_replace_evidence(): void
    {
        $before = [file_get_contents($this->directory.'/manifest.json'), file_get_contents($this->directory.'/target.json')];
        foreach ([1, 2, 3] as $processed) {
            $result = $this->command(['--limit', '1']);
            $this->assertSame($processed === 3 ? 0 : 2, $result['exit']);
            $this->assertSame($processed, $result['summary']['processed']);
            $this->assertSame(3, $result['summary']['total']);
            $this->assertSame($processed, array_sum($result['summary']['counts']));
            $this->assertSame(0, $result['summary']['production_writes']);
            $this->assertSame($processed, count($this->checkpoint()['entries']));
        }
        $this->assertSame(['create_draft' => 2, 'skip' => 1, 'conflict' => 0], $result['summary']['counts']);
        $this->assertSame('dry_run_complete', $result['summary']['code']);
        $retained = file_get_contents($this->output.'/checkpoint.json');
        $inode = fileinode($this->output.'/checkpoint.json');
        $this->assertSame(0, $this->command()['exit']);
        clearstatcache();
        $this->assertSame($inode, fileinode($this->output.'/checkpoint.json'));
        $this->assertSame($retained, file_get_contents($this->output.'/checkpoint.json'));

        $fresh = $this->directory.'/fresh';
        mkdir($fresh, 0700);
        $this->assertSame(0, $this->command([], $fresh)['exit']);
        $this->assertSame($retained, file_get_contents($fresh.'/checkpoint.json'));
        $this->assertSame($before, [file_get_contents($this->directory.'/manifest.json'), file_get_contents($this->directory.'/target.json')]);
        foreach (['checkpoint.json', 'checkpoint.lock'] as $name) {
            $this->assertSame(0600, fileperms($this->output.'/'.$name) & 07777);
        }
        $this->assertFileDoesNotExist($this->output.'/checkpoint.pending');
        $this->assertSame(['code', 'processed', 'total', 'counts', 'production_writes', 'input_manifest_sha256',
            'target_snapshot_sha256', 'plan_sha256'], array_keys($result['summary']));
        foreach (['SYNTHETIC', 'synthetic-alpha', 'synthetic-source', 'synthetic-batch', $this->directory] as $private) {
            $this->assertStringNotContainsString($private, $result['stdout']);
        }
    }

    public function test_complete_conflicts_have_a_distinct_failure_status_and_no_import_effect(): void
    {
        $this->manifest['records'][0]['value']['visibility'] = 'sold';
        $this->manifest['records'][0]['source_sha256'] = CanonicalJson::hash($this->manifest['records'][0]['value']);
        $this->inputs();
        $this->write($this->directory.'/untouched-database.sqlite', 'synthetic sentinel');
        $result = $this->command();
        $this->assertSame(3, $result['exit']);
        $this->assertSame('dry_run_conflicts', $result['summary']['code']);
        $this->assertSame(['create_draft' => 1, 'skip' => 1, 'conflict' => 1], $result['summary']['counts']);
        $this->assertSame('synthetic sentinel', file_get_contents($this->directory.'/untouched-database.sqlite'));
        $this->assertTrue($this->checkpoint()['complete']);
        $this->assertSame(3, $this->command()['exit']);
    }

    public function test_changed_source_target_or_mapping_cannot_resume_the_existing_checkpoint(): void
    {
        $this->assertSame(2, $this->command(['--limit', '1'])['exit']);
        $retained = file_get_contents($this->output.'/checkpoint.json');
        $originalManifest = $this->manifest;
        $originalTarget = $this->target;
        $this->manifest['records'][0]['value']['title'] = 'SYNTHETIC changed title';
        $this->manifest['records'][0]['source_sha256'] = CanonicalJson::hash($this->manifest['records'][0]['value']);
        $this->inputs();
        $this->invalid($this->command());
        $this->assertSame($retained, file_get_contents($this->output.'/checkpoint.json'));
        $this->manifest = $originalManifest;
        $this->target['snapshot_id'] = 'synthetic-changed-target';
        $this->inputs();
        $this->invalid($this->command());
        $this->assertSame($retained, file_get_contents($this->output.'/checkpoint.json'));
        $this->target = $originalTarget;
        $this->target['mappings'][0]['source_sha256'] = str_repeat('a', 64);
        $this->inputs();
        $this->invalid($this->command());
        $this->assertSame($retained, file_get_contents($this->output.'/checkpoint.json'));
    }

    public function test_tampered_noncanonical_and_oversized_checkpoints_are_never_replaced(): void
    {
        $this->assertSame(2, $this->command(['--limit', '2'])['exit']);
        $original = $this->checkpoint();
        $mutations = [];
        foreach (['processed' => 1, 'complete' => true, 'production_writes' => 1, 'plan_sha256' => str_repeat('a', 64), 'extra' => 'PRIVATE unexpected'] as $field => $value) {
            $mutations[] = CanonicalJson::encode(array_replace($original, [$field => $value]));
        }
        $changed = $original;
        $changed['entries'] = array_reverse($changed['entries']);
        $mutations[] = CanonicalJson::encode($changed);
        $changed = $original;
        $changed['counts']['create_draft']++;
        $mutations[] = CanonicalJson::encode($changed);
        $changed = $original;
        $changed['entries'][0]['reasons'] = new \stdClass;
        $mutations[] = CanonicalJson::encode($changed);
        $changed = $original;
        $changed['entries'][1]['field_changes'] = new \stdClass;
        $mutations[] = CanonicalJson::encode($changed);
        $mutations[] = '{"processed":0,'.substr(CanonicalJson::encode($original), 1);
        $mutations[] = ' '.CanonicalJson::encode($original);
        $mutations[] = str_repeat('x', 4194305);
        foreach ($mutations as $bytes) {
            $this->write($this->output.'/checkpoint.json', $bytes);
            $this->invalid($this->command());
            $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $this->output.'/checkpoint.json'));
            $this->assertFileDoesNotExist($this->output.'/checkpoint.pending');
        }
    }

    public function test_invalid_json_and_non_synthetic_values_emit_only_a_safe_code_before_output_writes(): void
    {
        $valid = CanonicalJson::encode($this->manifest);
        $private = $this->manifest;
        $private['records'][0]['value']['title'] = 'PRIVATE-CUSTOMER-CONTENT';
        $private['records'][0]['source_sha256'] = CanonicalJson::hash($private['records'][0]['value']);
        $unknown = $this->manifest;
        $unknown['records'][0]['private_field'] = 'PRIVATE-CUSTOMER-CONTENT';
        foreach (['', '{', ' '.$valid, $valid."\n\n", '{"schema_version":1,'.substr($valid, 1),
            CanonicalJson::encode($private), CanonicalJson::encode($unknown), str_repeat('x', 1048577)] as $bytes) {
            $this->write($this->directory.'/manifest.json', $bytes);
            $this->invalid($this->command());
            $this->assertSame(['.', '..'], scandir($this->output));
        }
        $this->inputs();
        $this->write($this->directory.'/target.json', '{"private":"PRIVATE-CUSTOMER-CONTENT"}');
        $this->invalid($this->command());
        $this->assertSame(['.', '..'], scandir($this->output));
    }

    public function test_unsupported_apply_duplicate_missing_and_invalid_limit_options_are_refused(): void
    {
        foreach ([['--apply'], ['--apply', 'true'], ['--provider', 'PRIVATE-CUSTOMER-CONTENT'],
            ['--limit', '0'], ['--limit', '1001'], ['--limit', '-1'], ['--limit', '1.5'], ['--limit', '01'],
            ['--limit', '1', '--limit', '2'], ['--manifest', $this->directory.'/manifest.json'], ['extra']] as $extra) {
            $this->invalid($this->command($extra));
        }
        foreach ([[], ['--manifest'], ['--manifest', $this->directory.'/manifest.json'],
            ['--manifest='.$this->directory.'/manifest.json']] as $arguments) {
            $this->invalid($this->invoke($arguments));
        }
        $this->assertSame(['.', '..'], scandir($this->output));
    }

    public function test_input_links_and_non_regular_files_are_refused_without_touching_the_original(): void
    {
        $path = $this->directory.'/manifest.json';
        $original = file_get_contents($path);
        rename($path, $this->directory.'/retained.json');
        symlink($this->directory.'/retained.json', $path);
        $this->invalid($this->command());
        unlink($path);
        link($this->directory.'/retained.json', $path);
        $this->invalid($this->command());
        unlink($path);
        mkdir($path, 0700);
        $this->invalid($this->command());
        rmdir($path);
        rename($this->directory.'/retained.json', $path);
        mkdir($this->directory.'/inputs', 0700);
        symlink($this->directory.'/inputs', $this->directory.'/input-link');
        $this->write($this->directory.'/inputs/manifest.json', $original);
        $this->invalid($this->invoke(['--manifest', $this->directory.'/input-link/manifest.json',
            '--target', $this->directory.'/target.json', '--output', $this->output]));
        $this->assertSame($original, file_get_contents($path));
        $this->assertSame(['.', '..'], scandir($this->output));
    }

    public function test_output_requires_a_canonical_private_directory_outside_any_repository(): void
    {
        chmod($this->output, 0755);
        $this->invalid($this->command());
        chmod($this->output, 0700);
        $this->invalid($this->command([], $this->output.'/../output'));
        $this->invalid($this->command([], $this->output.'/'));
        symlink($this->output, $this->directory.'/output-link');
        $this->invalid($this->command([], $this->directory.'/output-link'));
        $this->write($this->directory.'/.git', 'synthetic foreign repository marker');
        $this->invalid($this->command());
        unlink($this->directory.'/.git');
        $inside = dirname(__DIR__, 2).'/storage/catalog-dry-run-'.bin2hex(random_bytes(6));
        mkdir($inside, 0700);
        try {
            $this->invalid($this->command([], $inside));
            $this->assertSame(['.', '..'], scandir($inside));
        } finally {
            rmdir($inside);
        }
        mkdir($this->directory.'/writable-parent', 0700);
        chmod($this->directory.'/writable-parent', 0777);
        mkdir($this->directory.'/writable-parent/output', 0700);
        $this->invalid($this->command([], $this->directory.'/writable-parent/output'));
        $this->assertSame(['.', '..'], scandir($this->directory.'/writable-parent/output'));
        chmod($this->directory.'/writable-parent', 01777);
        $this->assertSame(0, $this->command([], $this->directory.'/writable-parent/output')['exit']);
        $this->assertSame(['.', '..'], scandir($this->output));
    }

    public function test_foreign_output_objects_and_unsafe_recognized_files_are_preserved(): void
    {
        $this->write($this->output.'/foreign.txt', 'PRIVATE retained evidence');
        $this->invalid($this->command());
        $this->assertSame('PRIVATE retained evidence', file_get_contents($this->output.'/foreign.txt'));
        unlink($this->output.'/foreign.txt');
        mkdir($this->output.'/foreign-directory', 0700);
        $this->invalid($this->command());
        rmdir($this->output.'/foreign-directory');
        $this->write($this->directory.'/retained.json', 'PRIVATE retained checkpoint');
        foreach (['checkpoint.json', 'checkpoint.lock', 'checkpoint.pending'] as $name) {
            $path = $this->output.'/'.$name;
            symlink($this->directory.'/retained.json', $path);
            $this->invalid($this->command());
            unlink($path);
            link($this->directory.'/retained.json', $path);
            $this->invalid($this->command());
            unlink($path);
        }
        $this->assertSame('PRIVATE retained checkpoint', file_get_contents($this->directory.'/retained.json'));
        $this->write($this->output.'/checkpoint.lock', 'PRIVATE foreign lock');
        $this->invalid($this->command());
        $this->assertSame('PRIVATE foreign lock', file_get_contents($this->output.'/checkpoint.lock'));
        unlink($this->output.'/checkpoint.lock');
        $this->write($this->output.'/checkpoint.json', 'PRIVATE retained checkpoint');
        chmod($this->output.'/checkpoint.json', 0644);
        $this->invalid($this->command());
        $this->assertSame('PRIVATE retained checkpoint', file_get_contents($this->output.'/checkpoint.json'));
    }

    public function test_a_second_process_cannot_write_while_the_output_lock_is_owned(): void
    {
        $this->assertSame(2, $this->command(['--limit', '1'])['exit']);
        $retained = file_get_contents($this->output.'/checkpoint.json');
        $lock = fopen($this->output.'/checkpoint.lock', 'r+b');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->invalid($this->command());
            $this->assertSame($retained, file_get_contents($this->output.'/checkpoint.json'));
            $this->assertFileDoesNotExist($this->output.'/checkpoint.pending');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertSame(0, $this->command()['exit']);
    }

    public function test_an_interrupted_pending_write_preserves_previous_checkpoint_until_explicit_recovery(): void
    {
        $this->assertSame(2, $this->command(['--limit', '1'])['exit']);
        $retained = file_get_contents($this->output.'/checkpoint.json');
        $fresh = $this->directory.'/complete-output';
        mkdir($fresh, 0700);
        $this->assertSame(0, $this->command([], $fresh)['exit']);
        $pending = file_get_contents($fresh.'/checkpoint.json');
        // Model the durable filesystem state after staging, before the atomic rename.
        $this->write($this->output.'/checkpoint.pending', $pending);
        $this->invalid($this->command());
        $this->assertSame($retained, file_get_contents($this->output.'/checkpoint.json'));
        $this->assertSame($pending, file_get_contents($this->output.'/checkpoint.pending'));
        unlink($this->output.'/checkpoint.pending');
        $this->assertSame(0, $this->command()['exit']);
        $this->assertSame($pending, file_get_contents($this->output.'/checkpoint.json'));
    }

    private function command(array $extra = [], ?string $output = null): array
    {
        return $this->invoke(array_merge(['--manifest', $this->directory.'/manifest.json',
            '--target', $this->directory.'/target.json', '--output', $output ?? $this->output], $extra));
    }

    private function invoke(array $arguments): array
    {
        $root = dirname(__DIR__, 2);
        $process = new Process(array_merge([PHP_BINARY, $root.'/scripts/migration/catalog-dry-run.php'], $arguments), $root, [
            'APP_ENV' => 'production', 'APP_KEY' => '', 'DB_CONNECTION' => 'unavailable-synthetic-driver',
            'DB_DATABASE' => $this->directory.'/untouched-database.sqlite',
        ], null, 20);
        $process->run();
        $this->assertSame('', $process->getErrorOutput());
        $stdout = $process->getOutput();
        $this->assertStringNotContainsString('PRIVATE-CUSTOMER-CONTENT', $stdout);

        return ['exit' => $process->getExitCode(), 'stdout' => $stdout, 'summary' => json_decode($stdout, true, 16, JSON_THROW_ON_ERROR)];
    }

    private function invalid(array $result): void
    {
        $this->assertSame(1, $result['exit']);
        $this->assertSame(['code' => 'dry_run_invalid'], $result['summary']);
    }

    private function checkpoint(): array
    {
        return json_decode(file_get_contents($this->output.'/checkpoint.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    private function inputs(): void
    {
        $this->write($this->directory.'/manifest.json', CanonicalJson::encode($this->manifest)."\n");
        $this->write($this->directory.'/target.json', CanonicalJson::encode($this->target)."\n");
    }

    private function write(string $path, string $bytes): void
    {
        file_put_contents($path, $bytes);
        chmod($path, 0600);
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
