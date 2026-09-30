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
 *
 * The file is made anew for every scan, in the scan's workspace on private storage, and it only costs nothing where the filesystem
 * keeps holes. Where it does not, every scan would write 4 GiB of zeros before asking the daemon anything, so the canary is refused
 * there: see create().
 */
final class DaemonLimitCanary
{
    /** 4 GiB and a byte: well beyond the size ClamAV's engine scans. */
    public const BYTES = 4294967297;

    /** Seconds the daemon gets to refuse the file. It only has to look at the size. */
    public const TIMEOUT_SECONDS = 30;

    /** The size the canary is grown to first, to learn cheaply whether the filesystem keeps holes: see create(). */
    private const PROBE_BYTES = 67108864;

    /**
     * What a file of holes may show as allocated and still count as sparse: 1 MiB. Every filesystem measured here keeps none of it:
     * ext4, ext2, tmpfs and ramfs showed 0 bytes allocated for files of 64 MiB and of 4 GiB and a byte. One that keeps holes but
     * counts some metadata against the file shows a block or a few, a few KiB, and 1 MiB is some 250 times one 4 KiB block. One that
     * keeps no holes shows the whole file, 64 MiB at the first step, which is 64 times this. No filesystem without holes was at hand
     * to measure; the refusal is tested with a stand-in.
     */
    private const MAX_ALLOCATED_BYTES = 1048576;

    private const REFUSAL = 'Heuristics.Limits.Exceeded.MaxFileSize FOUND';

    /**
     * @param  ?\Closure(resource): int  $allocated  reads the bytes allocated to an open file; a test gives it a filesystem without holes
     */
    public function __construct(private readonly ?\Closure $allocated = null) {}

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
     * tries, instead of being refused, so the canary is not made for it. Nor is it made when the limit cannot be read, without the
     * posix extension or when the call fails: a worker that cannot be shown to be safe is not risked.
     */
    public static function mayBeCreated(): bool
    {
        if (! function_exists('posix_getrlimit')) {
            return false;
        }
        // A call that fails, or a limit that is not there to read, is null here, which is no.
        $soft = posix_getrlimit()['soft filesize'] ?? null;

        return $soft === 'unlimited' || (is_int($soft) && $soft >= self::BYTES);
    }

    /**
     * Bytes the filesystem has allocated to an open file: its 512-byte blocks, which for a file of holes are next to none. A file that
     * cannot be measured counts as allocated in full, and is not risked.
     *
     * @param  resource  $handle
     */
    public static function allocation($handle): int
    {
        $blocks = (@fstat($handle) ?: [])['blocks'] ?? -1;

        return $blocks < 0 ? PHP_INT_MAX : $blocks * 512;
    }

    /**
     * Makes the canary: a file of holes, 4 GiB and a byte long, which takes no disk where the filesystem keeps holes and all of it
     * where it does not. There every scan would write 4 GiB before it asked the daemon a thing, and on a small disk it would fail for
     * want of room. So the file grows in two steps, 64 MiB and then the whole, and what the filesystem has allocated is read after
     * each: more than MAX_ALLOCATED_BYTES refuses the canary, before the daemon is asked. A filesystem without holes is found at the
     * first step, for a 64th of the cost, and the scan fails as scanner_unavailable.
     */
    private function create(string $directory): string
    {
        if (! self::mayBeCreated()) {
            throw new MediaFailure('storage_failed', 'The scanner daemon check cannot make sure the worker may write a file as large as it needs: its file-size limit is lower, or cannot be read without the PHP posix extension.');
        }
        $path = rtrim($directory, '/').'/.limit-canary-'.bin2hex(random_bytes(8));
        $handle = @fopen($path, 'xb');
        if (! is_resource($handle)) {
            throw new MediaFailure('storage_failed', 'The scanner daemon check could not create its test file in the workspace.');
        }
        try {
            if (! @chmod($path, 0600)) {
                throw new MediaFailure('storage_failed', 'The scanner daemon check could not protect its test file.');
            }
            foreach ([self::PROBE_BYTES, self::BYTES] as $size) {
                // Only the size counts: the file holds no data.
                if (! @ftruncate($handle, $size)) {
                    throw new MediaFailure('storage_failed', 'The scanner daemon check could not size its test file: the filesystem of the workspace does not take a file of 4 GiB.');
                }
                if (($this->allocated ?? self::allocation(...))($handle) > self::MAX_ALLOCATED_BYTES) {
                    throw new MediaFailure('storage_failed', 'The filesystem of the workspace does not keep holes in a file, so the scanner daemon check would write 4 GiB for every scan. Use a filesystem that does, such as ext4, XFS or btrfs.');
                }
            }
        } catch (MediaFailure $failure) {
            fclose($handle);
            @unlink($path);

            throw $failure;
        }
        fclose($handle);

        return $path;
    }
}
