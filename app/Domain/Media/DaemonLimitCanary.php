<?php

namespace App\Domain\Media;

/**
 * Shows, before a resident clamd's answers are trusted, that it refuses a file it cannot scan instead of skipping it.
 *
 * clamd's packaged configuration (MaxFileSize 25M, MaxScanSize 100M, no AlertExceedsMax) answers OK for a file over a limit, so a 30 MiB
 * ZIP with an EICAR member came back clean, and so did an archive whose scan outlasted MaxScanTime. Only AlertExceedsMax turns those
 * into detections, and nothing in a daemon's answer to a clean file says whether it is set.
 *
 * The canary is a sparse file of 4 GiB and a byte. ClamAV 1.5.4 refuses a file that large whatever limits clamd.conf gives it: with
 * MaxFileSize and MaxScanSize at 25M, 1280M, 4000M, 4096M, 8192M and 0 (no limit) it answered `Heuristics.Limits.Exceeded.MaxFileSize
 * FOUND`, exit status 1, in 0.01 seconds, because the engine's own ceiling is below 2 GiB. With AlertExceedsMax off, at every one of
 * those limits, it answered OK. So a daemon that alerts passes whatever its limits are, and one that skips does not. A canary of one
 * byte more than the application's own limit would instead be scanned, for minutes, by a daemon whose limit is higher.
 *
 * This shows that the daemon alerts, not that its limits are as large as the application needs. A daemon that alerts with
 * MaxFileSize 25M refuses every large upload as a detection, which the acceptance list in docs/media-processing.md finds.
 */
final class DaemonLimitCanary
{
    /** 4 GiB and a byte: well beyond the size ClamAV's engine scans. */
    public const BYTES = 4294967297;

    /** Seconds the daemon gets to refuse the file. It only has to look at the size. */
    public const TIMEOUT_SECONDS = 30;

    private const REFUSAL = 'Heuristics.Limits.Exceeded.MaxFileSize FOUND';

    /**
     * Returns when the daemon behind $binary refused the canary; otherwise throws what it did instead: an answer, an error, no answer
     * in time, or a canary that could not be made. Runs $binary under $runner in $directory, which takes a sparse file.
     *
     * Nothing is remembered. A scan asks every time: the question costs about what the version call that precedes it does (a median
     * of 7 milliseconds against a real clamd), and a daemon reconfigured to fail open is caught by the next scan, not some minutes
     * later.
     *
     * @throws MediaFailure
     */
    public function confirm(string $binary, BoundedMediaProcess $runner, string $directory, int $timeoutSeconds = self::TIMEOUT_SECONDS): void
    {
        $canary = $this->create($directory);
        try {
            $answer = $runner->run([$binary, '--no-summary', '--stdout', '--fdpass', '--', $canary], $directory, min($timeoutSeconds, self::TIMEOUT_SECONDS), ignoreErrorOutput: true);
        } catch (MediaFailure $failure) {
            if ($failure->failureCode === 'processor_failed' && $failure->exitCode === 1 && $failure->outputLine === $canary.': '.self::REFUSAL) {
                return;
            }
            throw $failure;
        } finally {
            @unlink($canary);
        }
        // Exit status 0: the daemon scanned or skipped the file and said it was fine.
        throw new MediaFailure('daemon_answered', 'The scanner daemon answered a file over every size limit instead of refusing it.', 0, $runner->lastErrorLine(), BoundedMediaProcess::firstLine($answer));
    }

    /**
     * Whether this process may extend a file to the canary's size. A worker with a lower file-size limit is ended by SIGXFSZ when it
     * tries, instead of being refused, so the canary is not made for it.
     */
    public static function mayBeCreated(): bool
    {
        if (! function_exists('posix_getrlimit')) {
            return true;
        }
        $soft = posix_getrlimit()['soft filesize'] ?? 'unlimited';

        return $soft === 'unlimited' || $soft >= self::BYTES;
    }

    private function create(string $directory): string
    {
        if (! self::mayBeCreated()) {
            throw new MediaFailure('storage_failed', 'The worker may not write a file as large as the scanner daemon check needs.');
        }
        $path = rtrim($directory, '/').'/.limit-canary-'.bin2hex(random_bytes(8));
        $handle = @fopen($path, 'xb');
        if (! is_resource($handle)) {
            throw new MediaFailure('storage_failed', 'The scanner daemon check could not create its test file.');
        }
        // Only the size counts: the file holds no data and takes no disk.
        $made = @ftruncate($handle, self::BYTES) && @chmod($path, 0600);
        fclose($handle);
        if (! $made) {
            @unlink($path);
            throw new MediaFailure('storage_failed', 'The scanner daemon check could not size its test file.');
        }

        return $path;
    }
}
