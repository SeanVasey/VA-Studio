<?php

namespace App\Domain\SoundKits;

use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;

/** Fixed identities bound orphan storage across failed commits. Existing bytes are never replaced. */
final class SoundKitFiles
{
    public function preserve(string $source, string $directory, string $name, string $hash, int $size): string
    {
        if (! preg_match('/\A[a-f0-9-]{36,101}\z/D', $directory) || ! in_array($name, ['source.zip', 'samples.zip'], true)) {
            throw new MediaFailure('unsafe_path', 'Invalid internal kit identity.');
        }
        $files = app(PrivateMediaFiles::class);
        $relative = 'sound-kits/revisions/'.$directory.'/'.$name;
        $path = $files->root();
        foreach (explode('/', dirname($relative)) as $part) {
            $path .= '/'.$part;
            if (is_link($path) || (! is_dir($path) && ! @mkdir($path, 0700) && ! is_dir($path))) {
                throw new MediaFailure('unsafe_storage', 'The private kit directory is unavailable.');
            }
        }
        $lockPath = $path.'/.write-lock';
        $before = @lstat($lockPath);
        if (is_link($lockPath) || ($before !== false && ($before['mode'] & 0170000) !== 0100000)) {
            throw new MediaFailure('unsafe_storage', 'The private kit lock is unsafe.');
        }
        $lock = @fopen($lockPath, 'c+b');
        $opened = $lock ? fstat($lock) : false;
        if (! $opened || ($opened['mode'] & 0170000) !== 0100000
            || ($before !== false && ($before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev']))) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new MediaFailure('storage_failed', 'Unable to lock private kit storage.');
        }
        $deadline = microtime(true) + 5;
        while (! flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($lock);
                throw new MediaFailure('storage_failed', 'The private kit copy is busy. Inspect its status before retrying.');
            }
            usleep(10000);
        }
        $pending = $path.'/.'.$name.'.pending';
        try {
            if (file_exists($path.'/'.$name) || is_link($path.'/'.$name)) {
                $this->verify($relative, $hash, $size);

                return $relative;
            }
            if (is_link($pending)) {
                throw new MediaFailure('unsafe_storage', 'The private kit pending path is unsafe.');
            }
            // Only this deterministic unreferenced pending file, under its exclusive lock, may be discarded.
            if (file_exists($pending) && ! @unlink($pending)) {
                throw new MediaFailure('storage_failed', 'Unable to recover a pending private copy.');
            }
            $input = @fopen($source, 'rb');
            $output = @fopen($pending, 'xb');
            if (! $input || ! $output) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new MediaFailure('storage_failed', 'Unable to preserve the private kit file.');
            }
            try {
                if (stream_copy_to_stream($input, $output, $size + 1) !== $size || ! fflush($output) || ! fsync($output)
                    || ! chmod($pending, 0400) || ! hash_equals($hash, (string) hash_file('sha256', $pending))) {
                    throw new MediaFailure('source_changed', 'Kit bytes changed while being preserved.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            // link() atomically refuses an existing destination; rename() could replace it.
            // Both paths are inside this same private local directory/filesystem.
            if (! @link($pending, $path.'/'.$name)) {
                throw new MediaFailure('storage_failed', 'Unable to finish the private kit copy.');
            }

            return $relative;
        } finally {
            // A final canonical file always survives exceptions and unknown database outcomes.
            if (is_file($pending) && ! is_link($pending)) {
                @unlink($pending);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function verify(string $relative, string $hash, int $size): string
    {
        $path = app(PrivateMediaFiles::class)->resolve($relative);
        if (filesize($path) !== $size || ! hash_equals($hash, (string) hash_file('sha256', $path))) {
            throw new MediaFailure('source_changed', 'Retained kit bytes do not match their evidence.');
        }

        return $path;
    }
}
