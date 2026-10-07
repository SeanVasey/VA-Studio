<?php

namespace App\Domain\Grants\Free;

use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\ContractIo;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\ContractRenderWorkspace;
use App\Domain\Contracts\RenderedContract;
use App\Support\CanonicalJson;
use Closure;
use Composer\InstalledVersions;
use Symfony\Component\Process\Process;
use Throwable;

/** A bounded pure-PHP child, without Laravel bootstrap, database access or inherited secrets. */
final class FreeGrantRendererProcess implements ContractRenderer
{
    private const MAX_INPUT = 1048576;

    private const MAX_OUTPUT = 24117248;

    /** The optional factory supplies a process only for isolated transport tests. */
    public function __construct(private readonly ?Closure $processFactory = null) {}

    public function render(array $input, array $profile): RenderedContract
    {
        ContractIo::outsideTransactions();
        $process = null;
        $workspace = null;
        $collectOutput = true;
        try {
            $payload = CanonicalJson::encode(['input' => $input, 'profile' => $profile]);
            if (strlen($payload) > self::MAX_INPUT) {
                throw new ContractIssuanceException('unsupported_input');
            }
            $projectRoot = dirname(__DIR__, 4);
            FreeGrantRenderProfile::verifyRuntime($profile, $projectRoot);
            $packages = [];
            foreach (array_keys($profile['base']['assets']['packages']) as $name) {
                $path = realpath((string) InstalledVersions::getInstallPath($name));
                if ($path === false || ! is_dir($path)) {
                    throw new ContractIssuanceException('profile_changed');
                }
                $packages[] = $path;
            }
            $workspace = (new ContractFiles)->createRendererWorkspace();
            $environment = [];
            foreach (array_unique([...array_keys((array) getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]) as $name) {
                $environment[$name] = false;
            }
            $environment['LANG'] = 'C';
            $environment['LC_ALL'] = 'C';
            $environment['TZ'] = 'UTC';
            $environment['TMPDIR'] = $workspace->path;
            // Start without application/system ini hooks. Rootless PHP may need its
            // own fixed sibling libraries; never inherit arbitrary loader paths.
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
            $command = [PHP_BINARY, '-n', ...$extensions, '-d', 'memory_limit=128M', '-d', 'max_execution_time=60',
                '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
                '-d', 'open_basedir='.implode(PATH_SEPARATOR, array_unique([$projectRoot, $workspace->path, ...$packages])),
                '-d', 'upload_tmp_dir='.$workspace->path, '-d', 'sys_temp_dir='.$workspace->path,
                '-d', 'disable_functions=curl_init,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,socket_create,exec,passthru,shell_exec,system,popen,proc_open',
                $projectRoot.'/scripts/render-free-grant.php'];
            $process = $this->processFactory === null
                ? new Process($command, $projectRoot, $environment, $payload, 60)
                : ($this->processFactory)($command, $projectRoot, $environment, $payload);
            if (! $process instanceof Process) {
                throw new ContractIssuanceException('render_failed');
            }
            $process->setTimeout(60);
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
                    if ($stderrBytes > 8192) {
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
            if (! $process->isSuccessful() || $stderrBytes !== 0) {
                throw new ContractIssuanceException('render_failed');
            }
            $result = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
            if (is_array($result) && array_keys($result) === ['error']) {
                throw new ContractIssuanceException(in_array($result['error'],
                    ['profile_changed', 'unsupported_input', 'invalid_pdf', 'render_failed'], true) ? $result['error'] : 'render_failed');
            }
            $keys = is_array($result) ? array_keys($result) : [];
            sort($keys);
            if ($keys !== ['page_count', 'pdf_base64', 'profile_hash', 'sha256', 'size_bytes', 'text_digest']
                || ! is_string($result['pdf_base64']) || ! is_int($result['size_bytes'])
                || $result['size_bytes'] < 32 || $result['size_bytes'] > ContractFiles::MAX_BYTES
                || ! is_int($result['page_count']) || $result['page_count'] < 1 || $result['page_count'] > 100) {
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
                || ! hash_equals(CanonicalJson::hash($profile), $result['profile_hash'])
                || ! preg_match('/\A%PDF-1\.[0-7](?:\r\n|\n|\r)/D', $bytes)
                || ! str_ends_with(rtrim($bytes, "\r\n\t "), '%%EOF')) {
                throw new ContractIssuanceException('invalid_pdf');
            }

            return new RenderedContract($bytes, $result['sha256'], $result['size_bytes'],
                $result['page_count'], $result['text_digest'], $result['profile_hash']);
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
            if ($workspace instanceof ContractRenderWorkspace) {
                $workspace->close();
            }
        }
    }
}
