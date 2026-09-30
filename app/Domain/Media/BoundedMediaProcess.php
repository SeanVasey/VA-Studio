<?php

namespace App\Domain\Media;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class BoundedMediaProcess
{
    /** How much standard error a caller that ignores it tolerates before the tool counts as runaway. */
    private const IGNORED_ERROR_OUTPUT_BYTES = 4 * 1024 * 1024;

    // Limits of this runner's own, in place of the shared media.* ones; null keeps the shared value.
    private ?int $cpuSeconds = null;

    private ?int $memoryBytes = null;

    private ?int $timeoutSeconds = null;

    /**
     * A copy of this runner whose calls run under these limits instead of the shared media.* ones; an argument left null keeps the
     * shared value. They are not arguments of run(): a subclass that overrides run() must accept every argument of it, so each new
     * one would break every such override.
     */
    public function withLimits(?int $cpuSeconds = null, ?int $memoryBytes = null, ?int $timeoutSeconds = null): static
    {
        $limited = clone $this;
        $limited->cpuSeconds = $cpuSeconds ?? $this->cpuSeconds;
        $limited->memoryBytes = $memoryBytes ?? $this->memoryBytes;
        $limited->timeoutSeconds = $timeoutSeconds ?? $this->timeoutSeconds;

        return $limited;
    }

    /**
     * Runs a media tool under the resource limiter and returns its standard output. Standard error counts toward the output
     * limit unless the caller's verdict rests on standard output and the exit status alone; its warnings then have their own,
     * larger bound, so they cannot cut off a result that finished.
     */
    public function run(array $arguments, string $cwd, int $timeout = 0, bool $ignoreErrorOutput = false): string
    {
        $limiter = config('media.prlimit');
        if (! is_string($limiter) || ! is_executable($limiter) || ! is_executable($arguments[0])) {
            throw new MediaFailure('tool_unavailable', 'A required media processor or resource limiter is unavailable.');
        }
        $command = [$limiter, '--cpu='.($this->cpuSeconds ?? config('media.cpu_seconds')), '--as='.($this->memoryBytes ?? config('media.memory_bytes')), '--fsize='.config('media.max_output_bytes'), '--nofile=64', '--', ...$arguments];
        // A tool prints a local time in the zone of its process (clamscan --version does), and callers read it as UTC, so the worker's
        // own zone, from its host or its container, must not reach the tool.
        $process = new Process($command, $cwd, ['TMPDIR' => $cwd, 'TZ' => 'UTC', 'OPENBLAS_NUM_THREADS' => '1', 'OMP_NUM_THREADS' => '1']);
        $process->setTimeout($timeout ?: $this->timeoutSeconds ?: (int) config('media.process_timeout_seconds'));
        $stdout = '';
        $bytes = 0;
        $errorBytes = 0;
        try {
            $process->run(function (string $type, string $buffer) use (&$stdout, &$bytes, &$errorBytes, $process, $ignoreErrorOutput) {
                if ($ignoreErrorOutput && $type === Process::ERR) {
                    $errorBytes += strlen($buffer);
                } else {
                    $bytes += strlen($buffer);
                }
                if ($bytes > config('media.max_process_log_bytes') || $errorBytes > self::IGNORED_ERROR_OUTPUT_BYTES) {
                    $process->stop(0);
                    throw new MediaFailure('processor_output_limit', 'Media processing exceeded its output limit.');
                }
                if ($type === Process::OUT) {
                    $stdout .= $buffer;
                }
            });
        } catch (ProcessTimedOutException) {
            throw new MediaFailure('processor_timeout', 'Media processing exceeded its time limit.');
        }
        if (! $process->isSuccessful()) {
            throw new MediaFailure('processor_failed', 'Media processing or validation failed. Verify the file and installed tools before retrying.', $process->getExitCode());
        }

        return $stdout;
    }
}
