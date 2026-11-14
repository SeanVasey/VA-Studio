<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Contracts\RenderedContract;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalBudget;
use App\Domain\Grants\Member\MemberOriginalFiles;
use App\Domain\Grants\Member\MemberOriginalIoFrame;
use App\Domain\Grants\Member\MemberOriginalSourceValues;
use App\Support\CanonicalJson;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberOriginalRendererFixtures;
use Tests\TestCase;

/** Synthetic envelope bytes test storage only. No PDF validity, source admission or host claim. */
class MemberOriginalFilesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        chmod($this->root(), 0700);
        DB::connection()->getPdo();
    }

    private function root(): string
    {
        return rtrim(config('filesystems.disks.local.root'), '/');
    }

    private function source(): MemberOriginalSourceValues
    {
        return MemberOriginalSourceValues::from(MemberOriginalRendererFixtures::sourceDocument());
    }

    private function document(string $marker = 'NONBINDING synthetic file adapter envelope'): RenderedContract
    {
        $bytes = "%PDF-1.7\n{$marker}\n%%EOF\n";

        return new RenderedContract($bytes, hash('sha256', $bytes), strlen($bytes), 1, hash('sha256', $marker),
            CanonicalJson::hash($this->source()->document()['profile']['document']['render_profile']));
    }

    private function failure(string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid member file operation succeeded.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    public static function wrappedCandidateEntries(): array
    {
        return [['store'], ['verify']];
    }

    #[DataProvider('wrappedCandidateEntries')]
    public function test_user_space_file_stream_cannot_substitute_expected_bytes_for_wrong_native_candidate(string $entry): void
    {
        $files = new MemberOriginalFiles;
        $source = $this->source();
        $expected = $this->document();
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $record = $files->storeOrVerify($source, $expected, $idle, $budget);
        $path = $this->root().'/'.$record->relativePath;
        $wrong = str_replace('synthetic', 'SYNTHETIC', $expected->pdfBytes);
        $this->assertNotSame($expected->pdfBytes, $wrong);
        $this->assertSame(strlen($expected->pdfBytes), strlen($wrong));
        chmod($path, 0600);
        file_put_contents($path, $wrong);
        chmod($path, 0400);
        clearstatcache(true, $path);
        MemberOriginalSyntheticFileWrapper::install('persistent', $expected->pdfBytes, lstat($path));
        $reason = null;
        try {
            try {
                $entry === 'store' ? $files->storeOrVerify($source, $expected, $idle, $budget)
                    : $files->verify($source, $record, $idle, $budget);
            } catch (MemberGrantException $error) {
                $reason = $error->reason;
            }
        } finally {
            MemberOriginalSyntheticFileWrapper::restore();
        }
        $this->assertSame($entry === 'store' ? 'storage_failed' : 'original_unavailable', $reason);
        $this->assertGreaterThan(0, MemberOriginalSyntheticFileWrapper::$openCalls);
        $this->assertSame($wrong, file_get_contents($path));
    }

    public function test_one_shot_wrapper_is_refused_before_a_pre_open_stat_callback_can_restore_native_files(): void
    {
        $files = new MemberOriginalFiles;
        $source = $this->source();
        $expected = $this->document();
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $record = $files->storeOrVerify($source, $expected, $idle, $budget);
        $path = $this->root().'/'.$record->relativePath;
        MemberOriginalSyntheticFileWrapper::install('one_shot', $expected->pdfBytes, lstat($path));
        $reason = null;
        try {
            try {
                $files->verify($source, $record, $idle, $budget);
            } catch (MemberGrantException $error) {
                $reason = $error->reason;
            }
        } finally {
            MemberOriginalSyntheticFileWrapper::restore();
        }
        $this->assertSame('original_unavailable', $reason);
        $this->assertSame(0, MemberOriginalSyntheticFileWrapper::$statCalls);
        $this->assertSame(1, MemberOriginalSyntheticFileWrapper::$openCalls);
        $this->assertSame(0, MemberOriginalSyntheticFileWrapper::$callbacks);
        $this->assertSame($expected->pdfBytes, file_get_contents($path));
    }

    public function test_candidate_is_private_exact_and_recoverable_only_against_trusted_expected_bytes(): void
    {
        $files = new MemberOriginalFiles;
        $source = $this->source();
        $expected = $this->document();
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $before = hrtime(true);
        $first = $files->storeOrVerify($source, $expected, $idle, $budget);
        $after = hrtime(true);
        $this->assertSame('contracts/production-member-v1/synthetic_rehearsal/'.$source->document()['origin_id'].'/'.$source->hash().'/original.pdf', $first->relativePath);
        $path = $this->root().'/'.$first->relativePath;
        $this->assertSame($expected->pdfBytes, file_get_contents($path));
        $this->assertSame(0400, fileperms($path) & 0777);
        $this->assertSame(0700, fileperms(dirname($path)) & 0777);
        $this->assertSame(1, lstat($path)['nlink']);
        $this->assertGreaterThanOrEqual($before, $first->observationStartedNs);
        $this->assertGreaterThanOrEqual($first->observationStartedNs, $first->observedAtNs);
        $this->assertLessThanOrEqual($after, $first->observedAtNs);
        $files->verify($source, $first, $idle, $budget);
        $second = $files->storeOrVerify($source, $expected, $idle, $budget);
        $this->assertSame($first->inode, $second->inode);
        $this->assertSame($first->sha256, $second->sha256);
        $this->failure('storage_failed', fn () => $files->storeOrVerify($source, $this->document('Different expected original must never replace winner'), $idle, $budget));
        $this->assertSame($expected->pdfBytes, file_get_contents($path));
        $this->assertSame([], glob(dirname($path).'/.candidate-*.tmp'));
    }

    public static function damagedFiles(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'truncated', 'changed', 'symlink', 'hardlink', 'writable', 'replacement']);
    }

    public static function invalidRenderedValues(): array
    {
        return array_map(fn ($case) => [$case], ['bytes', 'hash', 'size', 'too_large', 'pages', 'profile']);
    }

    #[DataProvider('invalidRenderedValues')]
    public function test_invalid_expected_rendered_values_cannot_create_a_candidate(string $case): void
    {
        $files = new MemberOriginalFiles;
        $source = $this->source();
        $valid = $this->document();
        $expected = new RenderedContract($case === 'bytes' ? str_repeat('x', $valid->sizeBytes) : $valid->pdfBytes,
            $case === 'hash' ? str_repeat('f', 64) : $valid->sha256,
            match ($case) {
                'size' => $valid->sizeBytes + 1, 'too_large' => MemberOriginalFiles::MAX_BYTES + 1, default => $valid->sizeBytes
            },
            $case === 'pages' ? 0 : $valid->pageCount, $valid->textDigest,
            $case === 'profile' ? str_repeat('e', 64) : $valid->profileHash);
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $this->failure('storage_failed', fn () => $files->storeOrVerify($source, $expected, $idle, $budget));
        $this->assertDirectoryDoesNotExist($this->root().'/contracts/production-member-v1');
    }

    #[DataProvider('damagedFiles')]
    public function test_reobservation_refuses_missing_changed_or_replaced_original(string $case): void
    {
        $files = new MemberOriginalFiles;
        $source = $this->source();
        $expected = $this->document();
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $observation = $files->storeOrVerify($source, $expected, $idle, $budget);
        $path = $this->root().'/'.$observation->relativePath;
        if ($case === 'missing') {
            unlink($path);
        }
        if ($case === 'truncated' || $case === 'changed') {
            chmod($path, 0600);
            file_put_contents($path, $case === 'truncated' ? '%PDF-' : str_replace('synthetic', 'different', $expected->pdfBytes));
            chmod($path, 0400);
        }
        if ($case === 'symlink') {
            rename($path, $path.'.retained');
            symlink($path.'.retained', $path);
        }
        if ($case === 'hardlink') {
            link($path, $path.'.retained');
        }
        if ($case === 'writable') {
            chmod($path, 0600);
        }
        if ($case === 'replacement') {
            rename($path, $path.'.retained');
            file_put_contents($path, $expected->pdfBytes);
            chmod($path, 0400);
        }
        $this->failure('original_unavailable', fn () => $files->verify($source, $observation, $idle, $budget));
    }

    public static function interruptedPublications(): array
    {
        return [['before_temporary_unlink'], ['after_completed_publication']];
    }

    #[DataProvider('interruptedPublications')]
    public function test_actual_worker_death_preserves_partial_link_and_recovers_only_completed_publication(string $point): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Interrupted publication requires pcntl and POSIX process signals.');
        }
        $files = new MemberOriginalFiles;
        $source = $this->source();
        $expected = $this->document();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            if ($point === 'before_temporary_unlink') {
                $GLOBALS['member_original_files_stop_after_link_pid'] = getmypid();
            }
            $files->storeOrVerify($source, $expected, MemberOriginalIoFrame::captureIdle(), MemberOriginalBudget::start());
            posix_kill(getmypid(), SIGKILL);
            exit(28);
        }
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifsignaled($status));
        $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $path = $this->root().'/contracts/production-member-v1/synthetic_rehearsal/'.$source->document()['origin_id'].'/'.$source->hash().'/original.pdf';
        $temporary = glob(dirname($path).'/.candidate-*.tmp');
        if ($point === 'before_temporary_unlink') {
            $this->assertCount(1, $temporary);
            $this->failure('storage_failed', fn () => $files->storeOrVerify($source, $expected, $idle, $budget));
            $this->assertSame($expected->pdfBytes, file_get_contents($temporary[0]));
        } else {
            $this->assertSame([], $temporary);
            $recovered = $files->storeOrVerify($source, $expected, $idle, $budget);
            $this->assertSame($expected->sha256, $recovered->sha256);
        }
        $this->assertSame($expected->pdfBytes, file_get_contents($path));
        clearstatcache(true, $path);
        $this->assertSame($point === 'before_temporary_unlink' ? 2 : 1, lstat($path)['nlink']);
    }

    public static function unsafeRoots(): array
    {
        return array_map(fn ($case) => [$case], ['served', 'public', 'prefix', 'nonlocal', 'public_subtree', 'link_chain', 'dangling_public_alias', 'root_mode', 'directory_symlink']);
    }

    #[DataProvider('unsafeRoots')]
    public function test_unsafe_private_configuration_refuses_before_creating_candidates(string $case): void
    {
        $root = $this->root();
        if ($case === 'served') {
            config(['filesystems.disks.local.serve' => true]);
        }
        if ($case === 'public') {
            config(['filesystems.disks.local.visibility' => 'public']);
        }
        if ($case === 'prefix') {
            config(['filesystems.disks.local.prefix' => 'elsewhere']);
        }
        if ($case === 'nonlocal') {
            config(['filesystems.disks.local.driver' => 's3']);
        }
        if ($case === 'public_subtree') {
            config(['filesystems.disks.exposed' => ['driver' => 'local', 'root' => $root.'/future', 'serve' => true]]);
        }
        if ($case === 'link_chain') {
            config(['filesystems.links' => [storage_path('synthetic-member-chain/child') => $root.'/future',
                public_path('synthetic-member-chain') => storage_path('synthetic-member-chain')]]);
        }
        if ($case === 'dangling_public_alias') {
            $alias = $root.'-alias';
            symlink($root.'/contracts', $alias);
            $this->beforeApplicationDestroyed(fn () => unlink($alias));
            config(['filesystems.disks.exposed' => ['driver' => 'local', 'root' => $alias.'/future', 'serve' => true]]);
        }
        if ($case === 'root_mode') {
            chmod($root, 0755);
        }
        if ($case === 'directory_symlink') {
            mkdir($root.'/untouched', 0700);
            symlink($root.'/untouched', $root.'/contracts');
        }
        $files = new MemberOriginalFiles;
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $this->failure('storage_failed', fn () => $files->storeOrVerify($this->source(), $this->document(), $idle, $budget));
        $this->failure('storage_failed', fn () => $files->createRendererWorkspace($idle, $budget));
        $this->assertDirectoryDoesNotExist($root.'/contracts/production-member-v1');
        if ($case === 'directory_symlink') {
            $this->assertSame(['.', '..'], scandir($root.'/untouched'));
        }
    }

    public function test_read_only_verification_does_not_recreate_missing_root(): void
    {
        $files = new MemberOriginalFiles;
        $source = $this->source();
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $record = $files->storeOrVerify($source, $this->document(), $idle, $budget);
        $root = $this->root();
        (new Filesystem)->deleteDirectory($root);
        $this->failure('original_unavailable', fn () => $files->verify($source, $record, $idle, $budget));
        $this->assertDirectoryDoesNotExist($root);
    }

    public function test_failed_write_cleanup_never_removes_a_replacement_temporary_entry(): void
    {
        $files = new MemberOriginalFiles;
        $source = $this->source();
        $expected = $this->document();
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $replacement = null;
        $GLOBALS['member_original_files_after_write'] = function (string $path) use (&$replacement): void {
            $replacement = $path;
            rename($path, $path.'.owned');
            file_put_contents($path, 'UNRELATED replacement entry');
        };
        try {
            $this->failure('storage_failed', fn () => $files->storeOrVerify($source, $expected, $idle, $budget));
        } finally {
            unset($GLOBALS['member_original_files_after_write']);
        }
        $this->assertIsString($replacement);
        $this->assertSame('UNRELATED replacement entry', file_get_contents($replacement));
        $this->assertSame($expected->pdfBytes, file_get_contents($replacement.'.owned'));
        $this->assertFileDoesNotExist(dirname($replacement).'/original.pdf');
    }

    public function test_native_transaction_started_after_write_stops_publication_and_preserves_foreign_transaction(): void
    {
        $files = new MemberOriginalFiles;
        $source = $this->source();
        $expected = $this->document();
        $pdo = DB::connection()->getPdo();
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $partial = null;
        $GLOBALS['member_original_files_after_write'] = function (string $path) use ($pdo, &$partial): void {
            $partial = $path;
            $pdo->beginTransaction();
        };
        try {
            $this->failure('storage_failed', fn () => $files->storeOrVerify($source, $expected, $idle, $budget));
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertIsString($partial);
            $this->assertFileExists($partial);
            $this->assertFileDoesNotExist(dirname($partial).'/original.pdf');
        } finally {
            unset($GLOBALS['member_original_files_after_write']);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function test_wrapper_installed_after_physical_write_is_refused_before_path_closure_or_publication(): void
    {
        $files = new MemberOriginalFiles;
        $source = $this->source();
        $expected = $this->document();
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $partial = null;
        $GLOBALS['member_original_files_after_write'] = function (string $path) use ($expected, &$partial): void {
            $partial = $path;
            MemberOriginalSyntheticFileWrapper::install('persistent', $expected->pdfBytes, lstat($path));
        };
        $reason = null;
        try {
            try {
                $files->storeOrVerify($source, $expected, $idle, $budget);
            } catch (MemberGrantException $error) {
                $reason = $error->reason;
            }
        } finally {
            unset($GLOBALS['member_original_files_after_write']);
            MemberOriginalSyntheticFileWrapper::restore();
        }
        $this->assertSame('storage_failed', $reason);
        $this->assertIsString($partial);
        $this->assertFileExists($partial);
        $this->assertFileDoesNotExist(dirname($partial).'/original.pdf');
        $this->assertSame(0, MemberOriginalSyntheticFileWrapper::$statCalls);
        $this->assertGreaterThan(0, MemberOriginalSyntheticFileWrapper::$openCalls);
    }

    public function test_workspace_cleanup_removes_only_its_owned_entries_and_never_traverses_links(): void
    {
        $files = new MemberOriginalFiles;
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $first = $files->createRendererWorkspace($idle, $budget);
        $second = $files->createRendererWorkspace($idle, $budget);
        mkdir($first->path.'/font-cache', 0700);
        file_put_contents($first->path.'/font-cache/font', 'temporary');
        file_put_contents($second->path.'/retained', 'unrelated child');
        symlink($second->path, $first->path.'/foreign');
        $first->close();
        $this->assertDirectoryDoesNotExist($first->path);
        $this->assertSame('unrelated child', file_get_contents($second->path.'/retained'));
        $second->close();
        $this->assertDirectoryDoesNotExist($second->path);
    }

    public static function workspaceWrappers(): array
    {
        return [['persistent'], ['one_shot']];
    }

    #[DataProvider('workspaceWrappers')]
    public function test_workspace_cleanup_refuses_substituted_filesystem_without_touching_owned_or_unrelated_contents(string $mode): void
    {
        $files = new MemberOriginalFiles;
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $owned = $files->createRendererWorkspace($idle, $budget);
        $other = $files->createRendererWorkspace($idle, $budget);
        file_put_contents($owned->path.'/partial', 'owned unfinished child output');
        file_put_contents($other->path.'/retained', 'unrelated child output');
        MemberOriginalSyntheticFileWrapper::install($mode, 'synthetic wrapper data', lstat($owned->path));
        $reason = null;
        try {
            try {
                $owned->close();
            } catch (MemberGrantException $error) {
                $reason = $error->reason;
            }
        } finally {
            MemberOriginalSyntheticFileWrapper::restore();
        }
        $this->assertSame('storage_failed', $reason);
        $this->assertSame(0, MemberOriginalSyntheticFileWrapper::$statCalls);
        $this->assertSame(0, MemberOriginalSyntheticFileWrapper::$callbacks);
        $this->assertSame('owned unfinished child output', file_get_contents($owned->path.'/partial'));
        $this->assertSame('unrelated child output', file_get_contents($other->path.'/retained'));
        $owned->close();
        $other->close();
    }

    public function test_workspace_cleanup_refuses_a_replaced_directory_or_expired_original_budget(): void
    {
        $files = new MemberOriginalFiles;
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $workspace = $files->createRendererWorkspace($idle, $budget);
        rename($workspace->path, $workspace->path.'.saved');
        mkdir($workspace->path, 0700);
        file_put_contents($workspace->path.'/retained', 'replacement');
        $this->failure('storage_failed', fn () => $workspace->close());
        $this->assertSame('replacement', file_get_contents($workspace->path.'/retained'));
        $owned = $files->createRendererWorkspace($idle, $budget);
        file_put_contents($owned->path.'/retained', 'budget expired');
        $budget->shortenTo(hrtime(true) - 1);
        $this->failure('storage_failed', fn () => $owned->close());
        $this->assertSame('budget expired', file_get_contents($owned->path.'/retained'));
    }

    public function test_expired_original_budget_prevents_candidate_write(): void
    {
        $files = new MemberOriginalFiles;
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $budget->shortenTo(hrtime(true) - 1);
        $this->failure('storage_failed', fn () => $files->storeOrVerify($this->source(), $this->document(), $idle, $budget));
        $this->assertDirectoryDoesNotExist($this->root().'/contracts/production-member-v1');
    }

    public function test_workspace_cleanup_refuses_excess_depth_and_preserves_unrelated_directory(): void
    {
        $files = new MemberOriginalFiles;
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $owned = $files->createRendererWorkspace($idle, $budget);
        $other = $files->createRendererWorkspace($idle, $budget);
        file_put_contents($other->path.'/retained', 'unrelated workspace');
        $directory = $owned->path;
        for ($depth = 0; $depth < 18; $depth++) {
            $directory .= '/nested';
            mkdir($directory, 0700);
        }
        file_put_contents($directory.'/retained', 'deep child cache');
        $this->failure('storage_failed', fn () => $owned->close());
        $this->assertSame('deep child cache', file_get_contents($directory.'/retained'));
        $this->assertSame('unrelated workspace', file_get_contents($other->path.'/retained'));
        $other->close();
    }

    public function test_native_transaction_at_framework_level_zero_and_configuration_change_prevent_io(): void
    {
        $files = new MemberOriginalFiles;
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $workspace = $files->createRendererWorkspace($idle, $budget);
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->failure('storage_failed', fn () => $files->storeOrVerify($this->source(), $this->document(), $idle, $budget));
            $this->failure('storage_failed', fn () => $workspace->close());
            $this->assertTrue($pdo->inTransaction());
            $this->assertDirectoryDoesNotExist($this->root().'/contracts/production-member-v1');
        } finally {
            $pdo->rollBack();
        }
        config(['filesystems.links' => [public_path('synthetic-member-exposed') => $this->root().'/contracts']]);
        $this->failure('storage_failed', fn () => $files->createRendererWorkspace($idle, $budget));
        $this->assertDirectoryExists($workspace->path);
    }

    public function test_independent_processes_never_replace_winner_and_retry_adopts_only_equal_bytes(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Native process race requires pcntl and Unix socket pairs.');
        }
        $files = new MemberOriginalFiles;
        $source = $this->source();
        $expected = $this->document();
        $children = [];
        $channels = [];
        foreach ([0, 1] as $worker) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertIsArray($pair);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($pair[0]);
                foreach ($channels as $channel) {
                    fclose($channel);
                }
                fread($pair[1], 1);
                try {
                    $record = $files->storeOrVerify($source, $expected, MemberOriginalIoFrame::captureIdle(), MemberOriginalBudget::start());
                    $result = ['outcome' => 'stored', 'inode' => $record->inode, 'pid' => getmypid()];
                } catch (MemberGrantException $error) {
                    $result = ['outcome' => $error->reason, 'pid' => getmypid()];
                }
                fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR));
                fclose($pair[1]);
                exit(0);
            }
            fclose($pair[1]);
            $channels[] = $pair[0];
            $children[] = $pid;
        }
        foreach ($channels as $channel) {
            fwrite($channel, '1');
            stream_set_timeout($channel, 20);
        }
        $results = [];
        foreach ($channels as $index => $channel) {
            $results[] = json_decode(stream_get_contents($channel), true, 8, JSON_THROW_ON_ERROR);
            fclose($channel);
            pcntl_waitpid($children[$index], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        $this->assertContains('stored', array_column($results, 'outcome'));
        foreach ($results as $result) {
            $this->assertContains($result['outcome'], ['stored', 'storage_failed']);
        }
        $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $recovered = $files->storeOrVerify($source, $expected, $idle, $budget);
        foreach ($results as $result) {
            if ($result['outcome'] === 'stored') {
                $this->assertSame($result['inode'], $recovered->inode);
            }
        }
        $this->assertSame($expected->pdfBytes, file_get_contents($this->root().'/'.$recovered->relativePath));
        $this->assertSame([], glob(dirname($this->root().'/'.$recovered->relativePath).'/.candidate-*.tmp'));
    }
}

/** Local synthetic PHP wrapper; does not claim an OS/storage provider boundary. */
class MemberOriginalSyntheticFileWrapper
{
    public mixed $context;

    public static int $openCalls = 0;

    public static int $statCalls = 0;

    public static int $callbacks = 0;

    private static bool $installed = false;

    private static string $mode;

    private static string $bytes;

    private static array $stat;

    private int $offset = 0;

    public static function install(string $mode, string $bytes, array $stat): void
    {
        self::$mode = $mode;
        self::$bytes = $bytes;
        self::$stat = $stat;
        self::$openCalls = self::$statCalls = self::$callbacks = 0;
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
        self::$installed = true;
    }

    public static function restore(): void
    {
        if (self::$installed) {
            stream_wrapper_restore('file');
            self::$installed = false;
        }
    }

    public function url_stat(string $path, int $flags): array|false
    {
        self::$statCalls++;
        self::restore();
        if (self::$mode === 'one_shot') {
            self::$callbacks++;

            return lstat($path);
        }
        try {
            return @lstat($path);
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
            self::$installed = true;
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$openCalls++;
        if (self::$mode === 'one_shot') {
            self::restore();

            return false;
        }
        $openedPath = $path;

        return true;
    }

    public function stream_read(int $count): string
    {
        $bytes = substr(self::$bytes, $this->offset, $count);
        $this->offset += strlen($bytes);

        return $bytes;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= strlen(self::$bytes);
    }

    public function stream_tell(): int
    {
        return $this->offset;
    }

    public function stream_stat(): array
    {
        return self::$stat;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }
}

namespace App\Domain\Grants\Member;

/** Stop only the explicitly marked test child, after the real atomic system operation. */
function link(string $target, string $link): bool
{
    $linked = \link($target, $link);
    if ($linked && ($GLOBALS['member_original_files_stop_after_link_pid'] ?? null) === getmypid()) {
        posix_kill(getmypid(), SIGKILL);
        exit(28);
    }

    return $linked;
}

/** Physical write still occurs; one explicitly installed test callback runs after it. */
function fwrite($stream, string $bytes, ?int $length = null): int|false
{
    $written = $length === null ? \fwrite($stream, $bytes) : \fwrite($stream, $bytes, $length);
    $callback = $GLOBALS['member_original_files_after_write'] ?? null;
    if ($callback !== null) {
        unset($GLOBALS['member_original_files_after_write']);
        $callback(stream_get_meta_data($stream)['uri']);
    }

    return $written;
}
