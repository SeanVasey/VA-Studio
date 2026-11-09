<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\ContractIo;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderWorkspace;
use App\Domain\Contracts\RenderedContract;
use App\Support\CanonicalJson;
use Closure;
use Composer\InstalledVersions;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs one `vasey-license-pdf-v2` original render ({@see VaseyLicenseV2Render}) in a bounded pure-PHP child
 * (`scripts/render-vasey-license-v2.php`), without Laravel bootstrap, database access or inherited secrets, under the same
 * limits as the pinned paid renderers' child (`PaidGrantRendererProcess`): `php -n` with only the fixed extensions,
 * `memory_limit=128M`, `max_execution_time=60` and a 60 s wall clock, network, process, `mail`, `putenv` and `dl` disabled,
 * `open_basedir` limited to what the child reads (app/, its script, the v2 profile directory, Composer's loader files and the
 * tc-lib packages; never .env, storage or public/) and a private per-render workspace, an environment with every
 * inherited name removed, at most 1 MiB of input and 24 MiB of output (a 16 MiB PDF in base64), and at most 8 KiB of
 * diagnostics, which also fail the render.
 *
 * The parent passes the line, its recorded descriptor and its bound values on standard input and the checked signature
 * bytes as `signature.png` in the workspace (deleted as soon as the child exits); the child re-verifies all of them itself.
 * The parent's re-check of the answer catches accidental drift (the child echoes descriptor values), not a malicious child.
 *
 * Not in the v2 implementation pin: the child never loads this class, so it stays outside that profile's closure. Under PHP-FPM the container gives this
 * class `App\Support\PhpCliProcess::factory()`, which swaps only the executable for the validated CLI binary.
 */
final class VaseyLicenseV2RendererProcess
{
    public const MAX_INPUT = 1048576;

    public const MAX_OUTPUT = 24117248;

    public const MAX_DIAGNOSTICS = 8192;

    public const TIMEOUT_SECONDS = 60;

    public const SIGNATURE_FILE = 'signature.png';

    public const SCRIPT = 'render-vasey-license-v2.php';

    /** Refusals the child may report; anything else it says is `render_failed`. */
    private const REASONS = ['profile_changed', 'unsupported_input', 'invalid_pdf', 'render_failed', 'signature_changed',
        'signature_unavailable', 'signature_invalid'];

    /**
     * @param  Closure|null  $processFactory  Supplies the process (the container's CLI-binary factory under PHP-FPM, or a transport test's).
     * @param  int  $timeoutSeconds  Test-only below 60: a shorter wall clock, refused outside `testing`.
     */
    public function __construct(private readonly ?Closure $processFactory = null, private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS)
    {
        if ($timeoutSeconds !== self::TIMEOUT_SECONDS
            && ($timeoutSeconds < 1 || $timeoutSeconds > self::TIMEOUT_SECONDS || ! function_exists('app') || ! app()->environment('testing'))) {
            throw new ContractIssuanceException('unsupported_input');
        }
    }

    /**
     * The exact standard input the child reads, so a selection can tell whether a line would fit. Canonical JSON sorts
     * object keys, so the bound values travel as ordered `[name, value]` pairs: the document requires the binder's order.
     */
    public static function payload(array $input, array $profile, array $values): string
    {
        return CanonicalJson::encode(['input' => $input, 'profile' => $profile,
            'values' => array_map(static fn (string|int $name, mixed $value): array => [$name, $value], array_keys($values), array_values($values))]);
    }

    /**
     * @param  array<string, string>  $values  The line's bound values.
     * @param  string  $signaturePng  Signature bytes the caller read and checked against the configured pin.
     */
    public function render(array $input, array $profile, array $values, string $signaturePng): RenderedContract
    {
        ContractIo::outsideTransactions();
        $process = null;
        $workspace = null;
        $collectOutput = true;
        try {
            VaseyLicenseV2Render::shape($profile);
            $payload = self::payload($input, $profile, $values);
            if (strlen($payload) > self::MAX_INPUT) {
                throw new ContractIssuanceException('unsupported_input');
            }
            if ($signaturePng === '' || strlen($signaturePng) > VaseyLicenseSignature::MAX_BYTES) {
                throw new ContractIssuanceException('signature_invalid');
            }
            $projectRoot = dirname(__DIR__, 4);
            // The package paths for open_basedir come from the recorded profile once it equals the current manifest's.
            $renderer = VaseyLicenseV2Profile::validate($profile['renderer_profile'], $projectRoot);
            // What the child reads: the application classes, its own script, the v2 profile directory, Composer's loader
            // files and the tc-lib packages it autoloads (the profile's pinned packages and every Com\Tecnick PSR-4 path).
            // Not the project root: .env, storage and public/ stay outside it.
            $readable = [$projectRoot.'/app', $projectRoot.'/scripts/'.self::SCRIPT, $projectRoot.'/'.VaseyLicenseV2Profile::ASSETS_DIRECTORY,
                $projectRoot.'/vendor/composer'];
            foreach (array_keys($renderer['assets']['packages']) as $name) {
                $readable[] = (string) InstalledVersions::getInstallPath($name);
            }
            foreach ((require $projectRoot.'/vendor/composer/autoload_psr4.php') as $prefix => $paths) {
                if (str_starts_with($prefix, 'Com\\Tecnick\\')) {
                    array_push($readable, ...$paths);
                }
            }
            $packages = [];
            foreach ($readable as $path) {
                $resolved = realpath($path);
                if ($resolved === false) {
                    throw new ContractIssuanceException('profile_changed');
                }
                $packages[] = $resolved;
            }
            $workspace = (new ContractFiles)->createRendererWorkspace();
            $signaturePath = $workspace->path.'/'.self::SIGNATURE_FILE;
            if (file_put_contents($signaturePath, $signaturePng, LOCK_EX) !== strlen($signaturePng) || ! chmod($signaturePath, 0600)) {
                throw new ContractIssuanceException('render_failed');
            }
            $environment = [];
            foreach (array_unique([...array_keys((array) getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]) as $name) {
                if (is_string($name) && $name !== '') {
                    $environment[$name] = false;
                }
            }
            $environment['LANG'] = 'C';
            $environment['LC_ALL'] = 'C';
            $environment['TZ'] = 'UTC';
            $environment['TMPDIR'] = $workspace->path;
            // Rootless PHP may need its own fixed sibling libraries; never inherit arbitrary loader paths.
            $libraries = [];
            foreach (['x86_64-linux-gnu', 'aarch64-linux-gnu'] as $architecture) {
                $directory = realpath(dirname(PHP_BINARY, 2).'/lib/'.$architecture);
                if ($directory !== false) {
                    $libraries[] = $directory;
                }
            }
            if ($libraries !== []) {
                $environment['LD_LIBRARY_PATH'] = implode(PATH_SEPARATOR, $libraries);
            }
            $extensions = [];
            $extensionDirectory = realpath((string) ini_get('extension_dir'));
            if ($extensionDirectory === false) {
                throw new ContractIssuanceException('profile_changed');
            }
            foreach (['ctype', 'mbstring', 'xml', 'gd', 'iconv', 'bcmath', 'intl', 'fileinfo', 'curl'] as $name) {
                $file = $extensionDirectory.'/'.$name.'.so';
                if (is_file($file) && ! is_link($file)) {
                    $extensions[] = '-d';
                    $extensions[] = 'extension='.$file;
                }
            }
            $command = [PHP_BINARY, '-n', ...$extensions, '-d', 'memory_limit=128M', '-d', 'max_execution_time='.self::TIMEOUT_SECONDS,
                '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
                '-d', 'open_basedir='.implode(PATH_SEPARATOR, array_unique([...$packages, $workspace->path])),
                '-d', 'upload_tmp_dir='.$workspace->path, '-d', 'sys_temp_dir='.$workspace->path,
                '-d', 'disable_functions=curl_init,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,socket_create,exec,passthru,shell_exec,system,popen,proc_open,mail,putenv,dl',
                $projectRoot.'/scripts/'.self::SCRIPT];
            $process = $this->processFactory === null
                ? new Process($command, $projectRoot, $environment, $payload, $this->timeoutSeconds)
                : ($this->processFactory)($command, $projectRoot, $environment, $payload);
            if (! $process instanceof Process) {
                throw new ContractIssuanceException('render_failed');
            }
            $process->setTimeout($this->timeoutSeconds);
            $stdout = '';
            $stderrBytes = 0;
            $process->run(function (string $type, string $chunk) use ($process, &$stdout, &$stderrBytes, &$collectOutput): void {
                if (! $collectOutput) {
                    $process->clearOutput();
                    $process->clearErrorOutput();

                    return;
                }
                if ($type === Process::ERR) {
                    $stderrBytes += strlen($chunk);
                    $process->clearErrorOutput();
                    if ($stderrBytes > self::MAX_DIAGNOSTICS) {
                        $collectOutput = false;
                        throw new ContractIssuanceException('render_failed');
                    }

                    return;
                }
                if (strlen($stdout) + strlen($chunk) > self::MAX_OUTPUT) {
                    $collectOutput = false;
                    throw new ContractIssuanceException('render_failed');
                }
                $stdout .= $chunk;
                $process->clearOutput();
            });
            // The child has exited: its signature copy is no longer needed.
            @unlink($signaturePath);
            if (! $process->isSuccessful() || $stderrBytes !== 0) {
                throw new ContractIssuanceException('render_failed');
            }

            return self::result($stdout, $profile);
        } catch (ContractIssuanceException $error) {
            throw $error;
        } catch (Throwable) {
            throw new ContractIssuanceException('render_failed');
        } finally {
            // Process shutdown drains pipes too; discard further chunks without throwing another exception.
            $collectOutput = false;
            if ($process instanceof Process && $process->isRunning()) {
                $process->stop(0);
            }
            // The signature copy goes first, before the workspace itself, so it outlives the child by as little as possible.
            if (isset($signaturePath) && is_file($signaturePath)) {
                @unlink($signaturePath);
            }
            if ($workspace instanceof ContractRenderWorkspace) {
                $workspace->close();
            }
        }
    }

    /** The child's answer, checked field by field against its own bytes and the recorded descriptor. */
    private static function result(string $stdout, array $profile): RenderedContract
    {
        $result = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        if (is_array($result) && array_keys($result) === ['error']) {
            throw new ContractIssuanceException(in_array($result['error'], self::REASONS, true) ? $result['error'] : 'render_failed');
        }
        $keys = is_array($result) ? array_keys($result) : [];
        sort($keys);
        $limits = $profile['renderer_profile']['limits'];
        if ($keys !== ['page_count', 'pdf_base64', 'profile_hash', 'sha256', 'size_bytes', 'text_digest']
            || ! is_string($result['pdf_base64']) || ! is_int($result['size_bytes'])
            || $result['size_bytes'] < 32 || $result['size_bytes'] > $limits['output_bytes'] || $result['size_bytes'] > ContractFiles::MAX_BYTES
            || ! is_int($result['page_count']) || $result['page_count'] < 1 || $result['page_count'] > $limits['pages']) {
            throw new ContractIssuanceException('invalid_pdf');
        }
        foreach (['sha256', 'text_digest', 'profile_hash'] as $field) {
            if (! is_string($result[$field]) || ! preg_match('/\A[a-f0-9]{64}\z/D', $result[$field])) {
                throw new ContractIssuanceException('invalid_pdf');
            }
        }
        $bytes = base64_decode($result['pdf_base64'], true);
        if (! is_string($bytes) || base64_encode($bytes) !== $result['pdf_base64']
            || strlen($bytes) !== $result['size_bytes'] || ! hash_equals(hash('sha256', $bytes), $result['sha256'])
            || ! preg_match('/\A%PDF-1\.[0-7](?:\r\n|\n|\r)/D', $bytes)
            || ! str_ends_with(rtrim($bytes, "\r\n\t "), '%%EOF')) {
            throw new ContractIssuanceException('invalid_pdf');
        }
        // The recorded identity and text, not the child's say-so.
        if (! hash_equals($profile['effective_hash'], $result['profile_hash']) || ! hash_equals($profile['expected']['text_digest'], $result['text_digest'])) {
            throw new ContractIssuanceException('profile_changed');
        }

        return new RenderedContract($bytes, $result['sha256'], $result['size_bytes'], $result['page_count'], $result['text_digest'], $result['profile_hash']);
    }
}
