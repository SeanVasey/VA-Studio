<?php

namespace App\Domain\Media;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class BoundedMediaProcess
{
    /** How much standard error a caller that ignores it tolerates before the tool counts as runaway. */
    private const IGNORED_ERROR_OUTPUT_BYTES = 4 * 1024 * 1024;

    /** How much of a tool's output is read for its first line, to say why it failed. */
    private const FIRST_LINE_BYTES = 1024;

    // Limits of this runner's own, in place of the shared media.* ones; null keeps the shared value.
    private ?int $cpuSeconds = null;

    private ?int $memoryBytes = null;

    private ?int $timeoutSeconds = null;

    private string $lastErrorLine = '';

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
        $limited->lastErrorLine = '';

        return $limited;
    }

    /**
     * The first line the latest run that returned wrote to standard error, empty when it wrote none. A caller reports with it on a
     * run that succeeded, such as a version call that printed no date, which returns only standard output. A run that fails
     * carries the line on its MediaFailure instead.
     */
    public function lastErrorLine(): string
    {
        return $this->lastErrorLine;
    }

    /**
     * Runs a media tool under the resource limiter and returns its standard output. Standard error counts toward the output
     * limit unless the caller's verdict rests on standard output and the exit status alone; its warnings then have their own,
     * larger bound, so they cannot cut off a result that finished. A failure carries the first line of each stream for the
     * caller's log, and a run that returns leaves the first line of standard error in lastErrorLine().
     */
    public function run(array $arguments, string $cwd, int $timeout = 0, bool $ignoreErrorOutput = false): string
    {
        $this->lastErrorLine = '';
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
        $errorHead = '';
        $bytes = 0;
        $errorBytes = 0;
        try {
            $process->run(function (string $type, string $buffer) use (&$stdout, &$errorHead, &$bytes, &$errorBytes, $process, $ignoreErrorOutput) {
                if ($type === Process::ERR && strlen($errorHead) < self::FIRST_LINE_BYTES) {
                    $errorHead .= substr($buffer, 0, self::FIRST_LINE_BYTES - strlen($errorHead));
                }
                if ($ignoreErrorOutput && $type === Process::ERR) {
                    $errorBytes += strlen($buffer);
                } else {
                    $bytes += strlen($buffer);
                }
                if ($bytes > config('media.max_process_log_bytes') || $errorBytes > self::IGNORED_ERROR_OUTPUT_BYTES) {
                    $process->stop(0);
                    throw new MediaFailure('processor_output_limit', 'Media processing exceeded its output limit.', null, self::firstLine($errorHead), self::firstLine($stdout));
                }
                if ($type === Process::OUT) {
                    $stdout .= $buffer;
                }
            });
        } catch (ProcessTimedOutException) {
            throw new MediaFailure('processor_timeout', 'Media processing exceeded its time limit.', null, self::firstLine($errorHead), self::firstLine($stdout));
        }
        if (! $process->isSuccessful()) {
            throw new MediaFailure('processor_failed', 'Media processing or validation failed. Verify the file and installed tools before retrying.', $process->getExitCode(), self::firstLine($errorHead), self::firstLine($stdout));
        }
        $this->lastErrorLine = self::firstLine($errorHead);

        return $stdout;
    }

    /** The first line of what a tool wrote to a stream, as far as it is kept; empty when it wrote none. */
    public static function firstLine(string $output): string
    {
        return (string) strtok(substr($output, 0, self::FIRST_LINE_BYTES), "\r\n");
    }
}
