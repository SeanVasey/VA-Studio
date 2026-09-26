<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\IsolatedContractRenderer;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Subprocess transport and containment tests; direct renderer tests prove the document itself. */
class TestIsolatedContractRendererTest extends TestCase
{
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
                $this->assertContains('open_basedir='.$root, $command);
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

    public function test_secondary_database_transaction_blocks_rendering_before_process_creation(): void
    {
        config(['database.connections.contract_render_guard' => config('database.connections.'.config('database.default'))]);
        $connection = DB::connection('contract_render_guard'); $connection->beginTransaction();
        try {
            $renderer = new IsolatedContractRenderer(function () { $this->fail('Open transaction started a renderer.'); });
            $this->rejectedRender('unavailable', fn () => $renderer->render([], []));
        } finally { $connection->rollBack(); DB::purge('contract_render_guard'); }
    }
}
