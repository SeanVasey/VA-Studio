<?php

namespace Tests\Support;

use App\Support\PhpCliBinary;

/**
 * A configured "CLI PHP" that records each argument vector and then runs this process's own CLI PHP, so a test can
 * prove a renderer child was spawned through the configured binary. The SAPI is simulated through PhpCliBinary's seam;
 * the child is a real PHP CLI process.
 */
trait PhpCliWrapperFixture
{
    private ?string $wrapperDirectory = null;

    /** Makes this test's container behave as a non-CLI SAPI whose configured CLI binary is $configured. */
    protected function simulateFpm(?string $configured): void
    {
        $this->app->instance(PhpCliBinary::class, new PhpCliBinary($configured, 'fpm-fcgi', '/usr/sbin/php-fpm8.4'));
    }

    protected function cliWrapper(): string
    {
        $this->wrapperDirectory = sys_get_temp_dir().'/php-cli-wrapper-'.bin2hex(random_bytes(8));
        mkdir($this->wrapperDirectory, 0700);
        $wrapper = $this->wrapperDirectory.'/php-cli';
        // One NUL-separated, newline-terminated record per run; the environment is already scrubbed by the caller.
        file_put_contents($wrapper, "#!/bin/sh\nfor argument in \"\$0\" \"\$@\"; do printf '%s\\0' \"\$argument\"; done >> "
            .escapeshellarg($wrapper.'.runs')."\nprintf '\\n' >> ".escapeshellarg($wrapper.'.runs')."\nexec "
            .escapeshellarg(PHP_BINARY)." \"\$@\"\n");
        chmod($wrapper, 0700);
        $this->beforeApplicationDestroyed(function (): void {
            if ($this->wrapperDirectory !== null && is_dir($this->wrapperDirectory)) {
                array_map('unlink', glob($this->wrapperDirectory.'/*') ?: []);
                rmdir($this->wrapperDirectory);
            }
        });

        return $wrapper;
    }

    /** @return list<list<string>> each recorded argument vector, $0 first */
    protected function wrapperRuns(string $wrapper): array
    {
        if (! is_file($wrapper.'.runs')) {
            return [];
        }
        $runs = [];
        foreach (explode("\n", rtrim((string) file_get_contents($wrapper.'.runs'), "\n")) as $record) {
            $runs[] = explode("\0", rtrim($record, "\0"));
        }

        return $runs;
    }
}
