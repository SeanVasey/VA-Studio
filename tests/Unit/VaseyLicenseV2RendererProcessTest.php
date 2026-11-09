<?php

namespace Tests\Unit;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\ContractLicenseConstants;
use App\Domain\Contracts\Production\ContractVariableBinder;
use App\Domain\Contracts\Production\VaseyLicenseSignature;
use App\Domain\Contracts\Production\VaseyLicenseV2Implementation;
use App\Domain\Contracts\Production\VaseyLicenseV2PdfRenderer;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use App\Domain\Contracts\Production\VaseyLicenseV2Render;
use App\Domain\Contracts\Production\VaseyLicenseV2RendererProcess;
use App\Domain\Grants\VaseyLicenseIssuance;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\PhpCliWrapperFixture;
use Tests\Support\VaseyLicenseIssuanceFixtures;
use Tests\TestCase;

/**
 * Contract increment 5 activation blocker "Isolation": every `vasey-license-pdf-v2` original renders in a bounded child
 * (`scripts/render-vasey-license-v2.php`) with the paid renderers' limits, and the child re-verifies the profile, the pinned
 * files, the signature and the frozen descriptor itself. SYNTHETIC, NONBINDING fixtures only.
 */
class VaseyLicenseV2RendererProcessTest extends TestCase
{
    use PhpCliWrapperFixture;
    use VaseyLicenseIssuanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->enableV2Issuance();
    }

    private static function fixture(string $method, mixed ...$arguments): mixed
    {
        return Closure::bind(static fn (): mixed => ContractVariableBinderTest::{$method}(...$arguments), null, ContractVariableBinderTest::class)();
    }

    /** A selected line: its frozen input, recorded descriptor, bound values and the configured signature bytes. */
    private static function selected(string $legalName = 'Łukasz Żółć'): array
    {
        $input = self::fixture('taxInput', array_replace(self::fixture('individual'), ['legal_name' => $legalName]), self::fixture('license', self::fixture('terms')));
        [$profile, $branch] = VaseyLicenseIssuance::decide($input);
        self::assertSame('selected', $branch);
        $values = ContractVariableBinder::bind($input, $input['source']['license']['structured_terms'],
            new ContractLicenseConstants($profile['contract']['producer_alias'], $profile['contract']['state_province_country']),
            (new VaseyLicenseV2PdfRenderer)->coverage());
        $signature = VaseyLicenseSignature::fromConfig();

        return [$input, $profile, $values, $signature, $signature->read()['bytes']];
    }

    private function refuses(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected refusal '.$reason);
        } catch (ContractIssuanceException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    /** A factory that edits what reaches the child after every parent-side check has passed. */
    private static function tampering(Closure $edit): Closure
    {
        return static function (array $command, string $cwd, array $environment, string $payload) use ($edit): Process {
            $decoded = json_decode($payload, true, 128, JSON_THROW_ON_ERROR);
            $decoded = $edit($decoded, $environment) ?? $decoded;

            return new Process($command, $cwd, $environment, CanonicalJson::encode($decoded), 60);
        };
    }

    /** A factory that runs other PHP code under the child's exact flags, environment and input. */
    private static function replacing(string $code): Closure
    {
        return static function (array $command, string $cwd, array $environment, string $payload) use ($code): Process {
            $command[count($command) - 1] = '-r';
            $command[] = $code;

            return new Process($command, $cwd, $environment, $payload, 60);
        };
    }

    /** A child that answers with the given JSON (placed in its workspace, inside its open_basedir), then runs `$after`. */
    private static function answering(array $answer, string $after = ''): Closure
    {
        return static function (array $command, string $cwd, array $environment, string $payload) use ($answer, $after): Process {
            $path = $environment['TMPDIR'].'/answer.json';
            file_put_contents($path, json_encode($answer, JSON_THROW_ON_ERROR));
            $command[count($command) - 1] = '-r';
            $command[] = 'readfile('.var_export($path, true).');'.$after;

            return new Process($command, $cwd, $environment, $payload, 60);
        };
    }

    public function test_the_child_renders_the_bytes_the_in_process_renderer_renders(): void
    {
        [$input, $profile, $values, $signature, $bytes] = self::selected();
        $child = (new VaseyLicenseV2RendererProcess)->render($input, $profile, $values, $bytes);
        $renderer = new VaseyLicenseV2PdfRenderer;
        $inProcess = $renderer->render($renderer->document($profile['contract']['template'], $values, $input['buyer']['declarations']),
            $profile['renderer_profile'], $signature);

        $this->assertSame($inProcess->pdfBytes, $child->pdfBytes);
        $this->assertSame([$inProcess->sha256, $inProcess->sizeBytes, $inProcess->pageCount, $inProcess->textDigest, $inProcess->profileHash],
            [$child->sha256, $child->sizeBytes, $child->pageCount, $child->textDigest, $child->profileHash]);
        // The application-free core renders the same bytes too, and the dispatch renders through the child.
        $this->assertSame($child->pdfBytes, VaseyLicenseV2Render::render($input, $profile, $values, $signature)->pdfBytes);
        $this->assertSame($child->pdfBytes, (new VaseyLicenseIssuance)->render($input, $profile)->pdfBytes);
        $this->assertSame(2, substr_count($this->extractPdfText($child->pdfBytes), 'Łukasz Żółć'));
        // The workspace (with its copy of the signature) is gone.
        $this->assertSame([], glob(Storage::disk('local')->path('contracts/render-cache').'/*') ?: []);
    }

    public function test_the_render_path_is_in_the_v2_implementation_pin(): void
    {
        $pinned = VaseyLicenseV2Profile::current()['assets']['implementation'];
        foreach (['app/Domain/Contracts/Production/VaseyLicenseV2Render.php', 'scripts/render-vasey-license-v2.php'] as $path) {
            $this->assertContains($path, VaseyLicenseV2Implementation::ROOTS);
            $this->assertSame(hash_file('sha256', base_path($path)), $pinned[$path] ?? null, $path);
        }
        // The parent-side process is not loaded by the child, so neither it nor its storage classes are pinned.
        foreach (['app/Domain/Contracts/Production/VaseyLicenseV2RendererProcess.php', 'app/Domain/Contracts/ContractFiles.php',
            'app/Domain/Contracts/ContractIo.php', 'app/Domain/Contracts/ContractRenderWorkspace.php'] as $path) {
            $this->assertArrayNotHasKey($path, $pinned);
        }
    }

    public function test_the_child_refuses_a_tampered_descriptor_signature_or_values(): void
    {
        [$input, $profile, $values, , $bytes] = self::selected();
        $rehash = static function (array $profile): array {
            $profile['renderer_profile_sha256'] = CanonicalJson::hash($profile['renderer_profile']);
            $profile['effective_hash'] = CanonicalJson::hash(['profile_sha256' => $profile['renderer_profile_sha256'], 'signature_sha256' => $profile['signature_sha256']]);

            return $profile;
        };
        $cases = [
            // A consistently rehashed profile that is not the manifest's.
            'profile' => [function (array $payload) use ($rehash): array {
                $payload['profile']['renderer_profile']['colors']['band'] = '#000000';
                $payload['profile'] = $rehash($payload['profile']);

                return $payload;
            }, 'profile_changed'],
            // Another template with its own hash: not the frozen document.
            'template' => [function (array $payload): array {
                $payload['profile']['contract']['template'] .= ' Edited.';
                $payload['profile']['contract']['template_sha256'] = hash('sha256', $payload['profile']['contract']['template']);

                return $payload;
            }, 'profile_changed'],
            // A bound value the selection did not freeze.
            'values' => [function (array $payload): array {
                foreach ($payload['values'] as $index => [$name]) {
                    if ($name === 'PRODUCT_TITLE') {
                        $payload['values'][$index][1] = 'Another synthetic title';
                    }
                }

                return $payload;
            }, 'profile_changed'],
            // An inconsistent descriptor.
            'shape' => [function (array $payload): array {
                $payload['profile']['effective_hash'] = str_repeat('b', 64);

                return $payload;
            }, 'profile_changed'],
            // Another signature pin, consistently rehashed: the workspace bytes do not match it.
            'pin' => [function (array $payload) use ($rehash): array {
                $payload['profile']['signature_sha256'] = str_repeat('a', 64);
                $payload['profile'] = $rehash($payload['profile']);

                return $payload;
            }, 'signature_changed'],
            // Other bytes in place of the checked signature.
            'signature' => [function (array $payload, array $environment): void {
                file_put_contents($environment['TMPDIR'].'/'.VaseyLicenseV2RendererProcess::SIGNATURE_FILE, "\x89PNG\r\n\x1a\nnot the pinned signature");
            }, 'signature_changed'],
            'no signature' => [function (array $payload, array $environment): void {
                unlink($environment['TMPDIR'].'/'.VaseyLicenseV2RendererProcess::SIGNATURE_FILE);
            }, 'signature_unavailable'],
            // Values that are not ordered [name, value] pairs.
            'value shape' => [function (array $payload): array {
                $payload['values'][] = ['PRODUCT_TITLE', 'Duplicate'];

                return $payload;
            }, 'unsupported_input'],
            // Another purpose than the descriptor's.
            'purpose' => [function (array $payload): array {
                $payload['input']['provenance'] = 'verified_production';

                return $payload;
            }, 'profile_changed'],
        ];
        foreach ($cases as [$edit, $reason]) {
            $this->refuses($reason, fn () => (new VaseyLicenseV2RendererProcess(self::tampering($edit)))->render($input, $profile, $values, $bytes));
        }
        $this->assertNotEmpty((new VaseyLicenseV2RendererProcess)->render($input, $profile, $values, $bytes)->pdfBytes);
    }

    public function test_the_child_refuses_a_pinned_file_changed_after_the_parent_checked(): void
    {
        [$input, $profile, $values, , $bytes] = self::selected();
        // A font program, the manifest and an implementation file the child would load, each changed after the parent's checks.
        foreach (['resources/contracts/vasey-license-v2/fonts/generated/redditsans.json', 'resources/contracts/vasey-license-v2/profile-assets.json',
            'app/Domain/Contracts/Production/VaseyLicenseLayout.php', 'scripts/render-vasey-license-v2.php'] as $path) {
            $file = base_path($path);
            $original = file_get_contents($file);
            try {
                $factory = static function (array $command, string $cwd, array $environment, string $payload) use ($file, $original): Process {
                    file_put_contents($file, $original."\n");

                    return new Process($command, $cwd, $environment, $payload, 60);
                };
                $this->refuses('profile_changed', fn () => (new VaseyLicenseV2RendererProcess($factory))->render($input, $profile, $values, $bytes));
            } finally {
                file_put_contents($file, $original);
            }
            $this->assertSame($original, file_get_contents($file));
        }

        // Unpinned code never runs: a document class the child loads early, changed to announce itself on stderr (which
        // would fail the render as render_failed), is refused before it is loaded.
        $file = base_path('app/Domain/Contracts/Production/VaseyLicenseDocument.php');
        $original = file_get_contents($file);
        try {
            $factory = static function (array $command, string $cwd, array $environment, string $payload) use ($file, $original): Process {
                file_put_contents($file, $original."\nfwrite(STDERR, 'unpinned code ran');\n");

                return new Process($command, $cwd, $environment, $payload, 60);
            };
            $this->refuses('profile_changed', fn () => (new VaseyLicenseV2RendererProcess($factory))->render($input, $profile, $values, $bytes));
        } finally {
            file_put_contents($file, $original);
        }
        $this->assertSame($original, file_get_contents($file));
    }

    public function test_the_child_runs_without_the_parent_environment_and_under_the_paid_renderer_limits(): void
    {
        [$input, $profile, $values, , $bytes] = self::selected();
        // A child that could write public/ would leave this file; it is removed whatever the outcome.
        $this->beforeApplicationDestroyed(static function (): void {
            if (is_file(public_path('v2-child-probe.txt'))) {
                unlink(public_path('v2-child-probe.txt'));
            }
        });
        putenv('VASEY_V2_CHILD_CANARY=synthetic-secret');
        $_ENV['VASEY_V2_CHILD_CANARY'] = 'synthetic-secret';
        $_SERVER['VASEY_V2_CHILD_CANARY'] = 'synthetic-secret';
        $probe = null;
        try {
            $factory = static function (array $command, string $cwd, array $environment, string $payload) use (&$probe): Process {
                $probeCommand = $command;
                $probeCommand[count($probeCommand) - 1] = '-r';
                $probeCommand[] = 'echo json_encode(["env" => array_keys(getenv()), "memory" => ini_get("memory_limit"), "time" => ini_get("max_execution_time"),'
                    .'"basedir" => ini_get("open_basedir"), "url" => ini_get("allow_url_fopen"), "exec" => function_exists("exec"), "proc_open" => function_exists("proc_open"),'
                    .'"socket" => function_exists("stream_socket_client"), "curl" => function_exists("curl_init"), "outside" => @file_get_contents("/etc/hostname") !== false,'
                    .'"tmp" => sys_get_temp_dir(), "ini" => php_ini_loaded_file(), "input" => strlen(stream_get_contents(STDIN)),'
                    .'"mail" => function_exists("mail"), "putenv" => function_exists("putenv"), "dl" => function_exists("dl"),'
                    .'"env_file" => @file_get_contents('.var_export(base_path('.env'), true).') !== false || @is_file('.var_export(base_path('.env'), true).'),'
                    .'"project_file" => @file_get_contents('.var_export(base_path('composer.json'), true).') !== false,'
                    .'"public_write" => @file_put_contents('.var_export(public_path('v2-child-probe.txt'), true).', "x") !== false,'
                    .'"storage_read" => @scandir('.var_export(storage_path(), true).') !== false,'
                    .'"app_read" => @file_get_contents('.var_export(base_path('app/Support/CanonicalJson.php'), true).') !== false]);';
                $run = new Process($probeCommand, $cwd, $environment, $payload, 60);
                $run->mustRun();
                $probe = json_decode($run->getOutput(), true, 8, JSON_THROW_ON_ERROR);

                return new Process($command, $cwd, $environment, $payload, 60);
            };
            $rendered = (new VaseyLicenseV2RendererProcess($factory))->render($input, $profile, $values, $bytes);
        } finally {
            putenv('VASEY_V2_CHILD_CANARY');
            unset($_ENV['VASEY_V2_CHILD_CANARY'], $_SERVER['VASEY_V2_CHILD_CANARY']);
        }
        $this->assertNotEmpty($rendered->pdfBytes);
        $environment = $probe['env'];
        sort($environment);
        $this->assertSame([], array_diff($environment, ['LANG', 'LC_ALL', 'TZ', 'TMPDIR', 'LD_LIBRARY_PATH']), implode(',', $environment));
        $this->assertNotContains('VASEY_V2_CHILD_CANARY', $environment);
        $this->assertSame(['128M', '60', '0', false, false, false, false, false, false], [$probe['memory'], $probe['time'], $probe['url'], $probe['exec'],
            $probe['proc_open'], $probe['socket'], $probe['curl'], $probe['outside'], $probe['ini']]);
        $this->assertSame([false, false, false, false, false, false, false, true], [$probe['mail'], $probe['putenv'], $probe['dl'], $probe['env_file'],
            $probe['project_file'], $probe['public_write'], $probe['storage_read'], $probe['app_read']]);
        $this->assertFileDoesNotExist(public_path('v2-child-probe.txt'));
        $this->assertNotContains(base_path(), explode(PATH_SEPARATOR, $probe['basedir']));
        $this->assertStringStartsWith(Storage::disk('local')->path('contracts/render-cache'), $probe['tmp']);
        $this->assertSame(strlen(VaseyLicenseV2RendererProcess::payload($input, $profile, $values)), $probe['input']);
    }

    public function test_bounded_input_output_diagnostics_memory_and_time(): void
    {
        [$input, $profile, $values, , $bytes] = self::selected();
        // Output past 24 MiB, any diagnostics, a non-zero exit and memory past 128M each fail the render.
        foreach (['echo str_repeat("a", '.(VaseyLicenseV2RendererProcess::MAX_OUTPUT + 1).');', 'fwrite(STDERR, "x");', 'exit(3);',
            '$a = str_repeat("a", 200 * 1024 * 1024); echo strlen($a);', 'echo "{\"error\":\"anything else\"}";'] as $code) {
            $this->refuses('render_failed', fn () => (new VaseyLicenseV2RendererProcess(self::replacing($code)))->render($input, $profile, $values, $bytes));
        }
        // Well-formed answers that are not the recorded render.
        $result = (new VaseyLicenseV2RendererProcess)->render($input, $profile, $values, $bytes);
        $answer = ['pdf_base64' => base64_encode($result->pdfBytes), 'sha256' => $result->sha256, 'size_bytes' => $result->sizeBytes,
            'page_count' => $result->pageCount, 'text_digest' => $result->textDigest, 'profile_hash' => str_repeat('c', 64)];
        $this->refuses('profile_changed', fn () => (new VaseyLicenseV2RendererProcess(self::answering($answer)))->render($input, $profile, $values, $bytes));
        $answer['profile_hash'] = $result->profileHash;
        $this->assertSame($result->pdfBytes, (new VaseyLicenseV2RendererProcess(self::answering($answer)))->render($input, $profile, $values, $bytes)->pdfBytes);
        // The same correct answer with a single byte of diagnostics fails.
        $this->refuses('render_failed', fn () => (new VaseyLicenseV2RendererProcess(self::answering($answer, 'fwrite(STDERR, "x");')))
            ->render($input, $profile, $values, $bytes));
        foreach ([['text_digest', str_repeat('e', 64)]] as [$field, $value]) {
            $wrong = $answer;
            $wrong[$field] = $value;
            $this->refuses('profile_changed', fn () => (new VaseyLicenseV2RendererProcess(self::answering($wrong)))->render($input, $profile, $values, $bytes));
        }
        foreach ([['page_count', 101], ['size_bytes', $result->sizeBytes + 1], ['sha256', str_repeat('d', 64)], ['pdf_base64', base64_encode('%PDF-1.7 not it')]] as [$field, $value]) {
            $wrong = $answer;
            $wrong[$field] = $value;
            $this->refuses('invalid_pdf', fn () => (new VaseyLicenseV2RendererProcess(self::answering($wrong)))->render($input, $profile, $values, $bytes));
        }

        // A wall clock (test-only below 60 s) stops a child that does not finish.
        $started = hrtime(true);
        $this->refuses('render_failed', fn () => (new VaseyLicenseV2RendererProcess(self::replacing('sleep(20);'), 1))->render($input, $profile, $values, $bytes));
        $this->assertLessThan(10_000_000_000, hrtime(true) - $started);
        $this->app['env'] = 'production';
        try {
            $this->refuses('unsupported_input', fn () => new VaseyLicenseV2RendererProcess(null, 1));
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->refuses('unsupported_input', fn () => new VaseyLicenseV2RendererProcess(null, 61));

        // More than 1 MiB of input is refused before any child starts.
        $large = $profile;
        $large['contract']['template'] = str_repeat('x', VaseyLicenseV2RendererProcess::MAX_INPUT);
        $large['contract']['template_sha256'] = hash('sha256', $large['contract']['template']);
        $spawned = false;
        $factory = static function () use (&$spawned): Process {
            $spawned = true;

            return new Process(['true']);
        };
        $this->refuses('unsupported_input', fn () => (new VaseyLicenseV2RendererProcess($factory))->render($input, $large, $values, $bytes));
        $this->refuses('signature_invalid', fn () => (new VaseyLicenseV2RendererProcess($factory))->render($input, $profile, $values, ''));
        $this->assertFalse($spawned);
    }

    public function test_under_php_fpm_the_child_runs_through_the_validated_cli_binary(): void
    {
        [$input, $profile] = self::selected();
        $wrapper = $this->cliWrapper();
        $this->simulateFpm($wrapper);
        $this->app->forgetInstance(VaseyLicenseV2RendererProcess::class);
        $rendered = (new VaseyLicenseIssuance)->render($input, $profile);
        $this->assertStringStartsWith('%PDF-', $rendered->pdfBytes);
        $runs = array_values(array_filter($this->wrapperRuns($wrapper), static fn (array $run): bool => in_array(base_path('scripts/render-vasey-license-v2.php'), $run, true)));
        $this->assertCount(1, $runs);
        $this->assertSame(['-n'], array_slice($runs[0], 1, 1));
        $this->assertContains('memory_limit=128M', $runs[0]);
    }
}
