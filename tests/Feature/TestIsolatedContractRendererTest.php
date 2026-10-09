<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\IsolatedContractRenderer;
use App\Support\CanonicalJson;
use App\Support\PhpCliBinary;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Subprocess transport and containment tests; direct renderer tests prove the document itself. */
class TestIsolatedContractRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    private function reply(array $profile): array
    {
        $bytes = "%PDF-1.7\nSynthetic transport-only envelope\n%%EOF\n";
        return ['pdf_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes),
            'page_count' => 1, 'text_digest' => hash('sha256', 'synthetic'), 'profile_hash' => CanonicalJson::hash($profile)];
    }

    private function rendererCode(string $code): IsolatedContractRenderer
    {
        return new IsolatedContractRenderer(fn ($command, $root, $environment, $payload) =>
            new Process([PHP_BINARY, '-r', $code], $root, $environment, $payload, 60));
    }

    private function outputCode(array $reply): string
    {
        return 'stream_get_contents(STDIN); fwrite(STDOUT, base64_decode('.var_export(base64_encode(json_encode($reply, JSON_THROW_ON_ERROR)), true).'));';
    }

    private function rejectedRender(string $reason, callable $operation): void
    {
        try { $operation(); $this->fail('Invalid child rendering result was accepted.'); }
        catch (ContractIssuanceException $error) {
            $this->assertSame($reason, $error->reason);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('private-marker', $error->getMessage());
        }
    }

    public function test_protocol_validates_integrity_and_parent_builds_bounded_private_child_command(): void
    {
        $profile = ['test' => 'synthetic-profile']; $reply = $this->reply($profile);
        putenv('CONTRACT_PRIVATE_MARKER=private-marker');
        try {
            $renderer = new IsolatedContractRenderer(function ($command, $root, $environment, $payload) use ($reply) {
                $this->assertContains('memory_limit=128M', $command);
                $this->assertContains('allow_url_fopen=0', $command);
                $this->assertContains('allow_url_include=0', $command);
                $this->assertContains('open_basedir='.$root.PATH_SEPARATOR.$environment['TMPDIR'], $command);
                $this->assertContains('upload_tmp_dir='.$environment['TMPDIR'], $command);
                $this->assertContains('sys_temp_dir='.$environment['TMPDIR'], $command);
                $this->assertSame(0700, fileperms($environment['TMPDIR']) & 0777);
                $this->assertSame(false, $environment['CONTRACT_PRIVATE_MARKER']);
                $this->assertSame('UTC', $environment['TZ']);
                $this->assertSame(['input' => ['test' => 'synthetic-input'], 'profile' => ['test' => 'synthetic-profile']],
                    json_decode($payload, true, 8, JSON_THROW_ON_ERROR));
                $code = 'if (getenv("CONTRACT_PRIVATE_MARKER") !== false) { exit(2); } '.$this->outputCode($reply);
                return new Process([PHP_BINARY, '-r', $code], $root, $environment, $payload, 60);
            });
            $rendered = $renderer->render(['test' => 'synthetic-input'], $profile);
            $this->assertSame(base64_decode($reply['pdf_base64'], true), $rendered->pdfBytes);
            $this->assertSame($reply['sha256'], $rendered->sha256);
            $this->assertSame(1, $rendered->pageCount);
        } finally { putenv('CONTRACT_PRIVATE_MARKER'); }
    }

    public static function malformedReplies(): array
    {
        return array_map(fn ($case) => [$case], ['hash', 'profile', 'size', 'page_zero', 'page_overflow', 'base64', 'header', 'eof', 'extra_key']);
    }

    #[DataProvider('malformedReplies')]
    public function test_malformed_or_mismatched_child_metadata_is_rejected(string $case): void
    {
        $profile = ['test' => 'synthetic-profile']; $reply = $this->reply($profile);
        if ($case === 'hash') { $reply['sha256'] = str_repeat('0', 64); }
        if ($case === 'profile') { $reply['profile_hash'] = str_repeat('0', 64); }
        if ($case === 'size') { $reply['size_bytes']++; }
        if ($case === 'page_zero') { $reply['page_count'] = 0; }
        if ($case === 'page_overflow') { $reply['page_count'] = 101; }
        if ($case === 'base64') { $reply['pdf_base64'] .= "\n"; }
        if ($case === 'extra_key') { $reply['private'] = 'private-marker'; }
        if (in_array($case, ['header', 'eof'], true)) {
            $bytes = $case === 'header' ? 'NOT A PDF '.str_repeat('x', 30).' %%EOF' : "%PDF-1.7\n".str_repeat('x', 32);
            $reply['pdf_base64'] = base64_encode($bytes); $reply['sha256'] = hash('sha256', $bytes); $reply['size_bytes'] = strlen($bytes);
        }
        $this->rejectedRender('invalid_pdf', fn () => $this->rendererCode($this->outputCode($reply))->render([], $profile));
    }

    public static function failedChildren(): array
    {
        return [['exit(1);'], ['fwrite(STDERR, "private-marker");'], ['fwrite(STDOUT, "private-marker");'],
            ['for ($i=0;$i<25;$i++) { fwrite(STDOUT,str_repeat("x",1048576)); }'],
            ['fwrite(STDERR,str_repeat("x",8193));']];
    }

    #[DataProvider('failedChildren')]
    public function test_process_failure_or_unbounded_output_is_a_generic_bounded_failure(string $code): void
    {
        $this->rejectedRender('render_failed', fn () => $this->rendererCode($code)->render([], []));
    }

    public function test_oversized_input_is_rejected_before_a_child_is_started(): void
    {
        $renderer = new IsolatedContractRenderer(function () { $this->fail('Oversized input started a process.'); });
        $this->rejectedRender('unsupported_input', fn () => $renderer->render(['text' => str_repeat('x', 1048576)], []));
    }

    public function test_only_fixed_child_failure_reasons_cross_the_process_boundary(): void
    {
        foreach (['profile_changed', 'unsupported_input', 'invalid_pdf', 'render_failed', 'private-marker'] as $reason) {
            $expected = $reason === 'private-marker' ? 'render_failed' : $reason;
            $this->rejectedRender($expected, fn () => $this->rendererCode($this->outputCode(['error' => $reason]))->render([], []));
        }
    }

    public function test_simulated_fpm_without_a_configured_cli_binary_fails_closed_before_any_child(): void
    {
        $this->app->instance(PhpCliBinary::class, new PhpCliBinary(null, 'fpm-fcgi', '/usr/sbin/php-fpm8.4'));
        $started = 0;
        $renderer = new IsolatedContractRenderer(function () use (&$started) { $started++; });
        $this->rejectedRender('render_failed', fn () => $renderer->render([], []));
        $this->assertSame(0, $started);
    }

    public function test_simulated_fpm_spawns_only_the_validated_cli_binary_with_unchanged_hardening(): void
    {
        $profile = ['test' => 'synthetic-profile']; $reply = $this->reply($profile);
        // A distinct same-version CLI stand-in, so the spawned path cannot coincide with PHP_BINARY.
        $directory = sys_get_temp_dir().'/php-cli-renderer-'.bin2hex(random_bytes(8)); mkdir($directory, 0700);
        $cli = $directory.'/php-cli';
        file_put_contents($cli, "#!/bin/sh\nprintf '%s' ".escapeshellarg('cli '.PHP_VERSION.' '.(PHP_ZTS ? '1' : '0').(PHP_DEBUG ? '1' : '0'))."\n"); chmod($cli, 0700);
        $commands = [];
        $capture = function ($command, $root, $environment, $payload) use (&$commands, $reply) {
            $commands[] = array_map(fn ($argument) => str_replace($environment['TMPDIR'], '{workspace}', $argument), $command);
            return new Process([PHP_BINARY, '-r', $this->outputCode($reply)], $root, $environment, $payload, 60);
        };
        try {
            (new IsolatedContractRenderer($capture))->render([], $profile);
            $this->app->instance(PhpCliBinary::class, new PhpCliBinary($cli, 'fpm-fcgi', '/usr/sbin/php-fpm8.4'));
            (new IsolatedContractRenderer($capture))->render([], $profile);
        } finally { unlink($cli); rmdir($directory); }
        $this->assertCount(2, $commands);
        $this->assertSame(PHP_BINARY, $commands[0][0]);
        $this->assertSame($cli, $commands[1][0]);
        $this->assertSame(array_slice($commands[0], 1), array_slice($commands[1], 1));
    }

    /**
     * Codex P2 (PR #67): a rootless CLI that needs its sibling `lib/<arch>` passes the probe under that loader path, so its
     * render child gets the same path (`PhpCliProcess::libraries()`), as the pinned renderers' children do.
     */
    public function test_simulated_fpm_child_gets_the_validated_binarys_sibling_library_path(): void
    {
        $profile = ['test' => 'synthetic-profile']; $reply = $this->reply($profile);
        $directory = sys_get_temp_dir().'/php-cli-rootless-'.bin2hex(random_bytes(8));
        mkdir($directory.'/bin', 0700, true); mkdir($directory.'/lib/x86_64-linux-gnu', 0700, true);
        $libraries = realpath($directory.'/lib/x86_64-linux-gnu');
        $cli = $directory.'/bin/php';
        file_put_contents($cli, "#!/bin/sh\n[ \"\$LD_LIBRARY_PATH\" = ".escapeshellarg($libraries)." ] || exit 127\nprintf '%s' "
            .escapeshellarg('cli '.PHP_VERSION.' '.(PHP_ZTS ? '1' : '0').(PHP_DEBUG ? '1' : '0'))."\n"); chmod($cli, 0700);
        $seen = [];
        $capture = function ($command, $root, $environment, $payload) use (&$seen, $reply) {
            $seen = [$command[0], $environment['LD_LIBRARY_PATH'] ?? null];
            return new Process([PHP_BINARY, '-r', $this->outputCode($reply)], $root, $environment, $payload, 60);
        };
        try {
            $this->app->instance(PhpCliBinary::class, new PhpCliBinary($cli, 'fpm-fcgi', '/usr/sbin/php-fpm8.4'));
            (new IsolatedContractRenderer($capture))->render([], $profile);
        } finally {
            unlink($cli); rmdir($directory.'/bin'); rmdir($directory.'/lib/x86_64-linux-gnu'); rmdir($directory.'/lib'); rmdir($directory);
        }
        $this->assertSame([$cli, $libraries], $seen);
    }

    public function test_secondary_database_transaction_blocks_rendering_before_process_creation(): void
    {
        config(['database.connections.contract_render_guard' => config('database.connections.'.config('database.default'))]);
        $connection = DB::connection('contract_render_guard'); $connection->beginTransaction();
        try {
            $renderer = new IsolatedContractRenderer(function () { $this->fail('Open transaction started a renderer.'); });
            $this->rejectedRender('unavailable', fn () => $renderer->render([], []));
        } finally { $connection->rollBack(); DB::purge('contract_render_guard'); }
    }

    public function test_failed_child_cleans_only_its_private_cache_and_keeps_the_original_boundary(): void
    {
        $workspace = null;
        $renderer = new IsolatedContractRenderer(function ($command, $root, $environment, $payload) use (&$workspace) {
            $workspace = $environment['TMPDIR'];
            $this->assertFileDoesNotExist($workspace.'/partial-cache');
            file_put_contents($workspace.'/partial-cache', 'Synthetic private partial render');
            return new Process([PHP_BINARY, '-r', 'fwrite(STDERR, "Synthetic child failure");'], $root, $environment, $payload, 60);
        });
        $this->rejectedRender('render_failed', fn () => $renderer->render([], []));
        $this->assertIsString($workspace);
        $this->assertDirectoryDoesNotExist($workspace);
    }

    public function test_pure_child_autoloader_does_not_load_application_or_agent_detection_hooks(): void
    {
        $code = 'require '.var_export(base_path('scripts/contract-renderer-autoload.php'), true).';'
            .'echo json_encode([class_exists("Com\\\\Tecnick\\\\Pdf\\\\Tcpdf"),'
            .'class_exists("App\\\\Domain\\\\Contracts\\\\ContractText"),'
            .'function_exists("app"),class_exists("Laravel\\\\AgentDetector\\\\AgentDetector",false)],JSON_THROW_ON_ERROR);';
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), [], null, 20);
        $process->mustRun();
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame([true, true, false, false], json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR));
    }
}
