<?php

namespace App\Support;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Resolves the PHP executable for bounded child processes.
 *
 * Under PHP-FPM, `PHP_BINARY` is the FPM daemon (for example `/usr/sbin/php-fpm8.4`), which rejects
 * `-n`/`-d`/script arguments with its usage text. Outside the `cli` SAPI an explicitly configured path
 * (`app.php_cli_binary`, env `VASEY_PHP_CLI_BINARY`) is required and must prove itself: a regular
 * executable file that, run with `-n`, reports the `cli` SAPI and exactly the running `PHP_VERSION`.
 * There is no fallback to `PHP_BINARY` and no `PATH`/`PhpExecutableFinder` lookup, since `/usr/bin/php`
 * is an alternatives link that can point at another installed version.
 */
final class PhpCliBinary
{
    private const PROBE = 'echo PHP_SAPI, " ", PHP_VERSION;';

    /** @var array<string, true> successful validations in this process, keyed by file identity and version */
    private static array $validated = [];

    /** The SAPI, binary and version parameters are seams for simulating another SAPI in tests. */
    public function __construct(
        private readonly ?string $configured,
        private readonly string $sapi = PHP_SAPI,
        private readonly string $runningBinary = PHP_BINARY,
        private readonly string $runningVersion = PHP_VERSION,
    ) {}

    /**
     * The absolute CLI PHP path to spawn: `PHP_BINARY` under the `cli` SAPI, otherwise the canonical
     * path of the validated configured binary.
     *
     * @throws PhpCliBinaryUnavailable when outside the `cli` SAPI and the configured binary is unset or invalid
     */
    public function path(): string
    {
        if ($this->sapi === 'cli') {
            return $this->runningBinary;
        }
        $configured = $this->configured;
        if ($configured === null || trim($configured) === '') {
            throw new PhpCliBinaryUnavailable('unconfigured');
        }
        if (! str_starts_with($configured, '/') || preg_match('/[\x00-\x1f\x7f]/', $configured) === 1) {
            throw new PhpCliBinaryUnavailable('invalid_path');
        }
        clearstatcache(true);
        $path = realpath($configured);
        if ($path === false || ! is_file($path)) {
            throw new PhpCliBinaryUnavailable('invalid_path');
        }
        if (! is_executable($path)) {
            throw new PhpCliBinaryUnavailable('not_executable');
        }
        $stat = stat($path);
        if ($stat === false) {
            throw new PhpCliBinaryUnavailable('invalid_path');
        }
        // A replaced file (package upgrade under a long-lived FPM worker) has a new identity and is re-probed.
        $key = implode("\0", [$path, $stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime'], $this->runningVersion]);
        if (isset(self::$validated[$key])) {
            return $path;
        }
        $this->probe($path);
        self::$validated[$key] = true;

        return $path;
    }

    private function probe(string $path): void
    {
        $environment = [];
        foreach (array_unique([...array_keys((array) getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]) as $name) {
            if (is_string($name) && $name !== '') {
                $environment[$name] = false;
            }
        }
        $environment['LANG'] = 'C';
        $environment['LC_ALL'] = 'C';
        try {
            $process = new Process([$path, '-n', '-r', self::PROBE], null, $environment, null, 10);
            $process->run();
            $successful = $process->isSuccessful() && $process->getErrorOutput() === '';
            $output = $process->getOutput();
        } catch (Throwable) {
            throw new PhpCliBinaryUnavailable('probe_failed');
        }
        if (! $successful || preg_match('/\A([a-z0-9-]{1,32}) ([0-9A-Za-z.+~-]{1,64})\z/D', $output, $reported) !== 1) {
            throw new PhpCliBinaryUnavailable('probe_failed');
        }
        if ($reported[1] !== 'cli') {
            throw new PhpCliBinaryUnavailable('not_cli');
        }
        if ($reported[2] !== $this->runningVersion) {
            throw new PhpCliBinaryUnavailable('version_mismatch');
        }
    }
}
