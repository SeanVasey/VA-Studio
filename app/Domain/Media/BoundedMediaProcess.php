<?php

namespace App\Domain\Media;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class BoundedMediaProcess
{
    public function run(array $arguments, string $cwd, int $timeout = 0): string
    {
        $limiter = config('media.prlimit');
        if (! is_string($limiter) || ! is_executable($limiter) || ! is_executable($arguments[0])) {
            throw new MediaFailure('tool_unavailable', 'A required media processor or resource limiter is unavailable.');
        }
        $command = [$limiter, '--cpu='.config('media.cpu_seconds'), '--as='.config('media.memory_bytes'), '--fsize='.config('media.max_output_bytes'), '--nofile=64', '--', ...$arguments];
        $process = new Process($command, $cwd, ['TMPDIR' => $cwd, 'OPENBLAS_NUM_THREADS' => '1', 'OMP_NUM_THREADS' => '1']);
        $process->setTimeout($timeout ?: (int) config('media.process_timeout_seconds'));
        $stdout = '';
        $bytes = 0;
        try {
            $process->run(function (string $type, string $buffer) use (&$stdout, &$bytes, $process) {
                $bytes += strlen($buffer);
                if ($bytes > config('media.max_process_log_bytes')) {
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
