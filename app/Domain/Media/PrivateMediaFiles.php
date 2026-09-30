<?php

namespace App\Domain\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrivateMediaFiles
{
    public function root(): string
    {
        $disk = config('filesystems.disks.local');
        if (($disk['driver'] ?? null) !== 'local' || ($disk['serve'] ?? false) || ($disk['visibility'] ?? 'private') === 'public') {
            throw new MediaFailure('unsafe_storage', 'Media processing requires an unserved private local disk.');
        }
        // Resolve the actual disk root, including Laravel test adapters.
        $root = rtrim(Storage::disk('local')->path(''), '/');
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        $real = realpath($root);
        $public = realpath(public_path());
        if (! $real || is_link($root) || ($public && ($real === $public || str_starts_with($real, $public.'/')))) {
            throw new MediaFailure('unsafe_storage', 'The media root cannot be public or a symbolic link.');
        }

        return $real;
    }

    public function resolve(string $relative): string
    {
        if (! preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9_./-]*\z~D', $relative) || str_contains($relative, '..') || str_contains($relative, '//')) {
            throw new MediaFailure('unsafe_path', 'Media paths must be safe relative private object keys.');
        }
        $path = $this->root();
        foreach (explode('/', $relative) as $component) {
            $path .= '/'.$component;
            if (is_link($path)) {
                throw new MediaFailure('unsafe_path', 'Symbolic links are not accepted for media.');
            }
        }
        $real = realpath($path);
        if (! $real || ! str_starts_with($real, $this->root().'/') || ! is_file($real)) {
            throw new MediaFailure('missing_source', 'The private media file is unavailable.');
        }

        return $real;
    }

    public function snapshot(string $relative, string $destination, int $maxBytes): array
    {
        $path = $this->resolve($relative);
        $before = lstat($path);
        $input = fopen($path, 'rb');
        $opened = $input ? fstat($input) : false;
        if (! $opened || $before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev'] || ($opened['mode'] & 0170000) !== 0100000) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw new MediaFailure('unsafe_path', 'Media changed while it was being opened.');
        }
        if ($opened['size'] < 12 || $opened['size'] > $maxBytes) {
            fclose($input);
            throw new MediaFailure('invalid_size', 'Media is empty or exceeds the allowed size.');
        }
        $output = @fopen($destination, 'xb');
        if (! $output) {
            fclose($input);
            throw new MediaFailure('storage_failed', 'Unable to create a private processing snapshot.');
        }
        $complete = false;
        try {
            $copied = stream_copy_to_stream($input, $output, $maxBytes + 1);
            if ($copied !== $opened['size'] || $copied > $maxBytes) {
                throw new MediaFailure('source_changed', 'Media size changed during processing.');
            }
            if (! chmod($destination, 0600)) {
                throw new MediaFailure('storage_failed', 'Unable to protect the processing snapshot.');
            }
            $complete = true;
        } finally {
            fclose($input);
            fclose($output);
            if (! $complete) {
                @unlink($destination);
            }
        }

        return ['sha256' => hash_file('sha256', $destination), 'size_bytes' => filesize($destination), 'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->file($destination)];
    }

    public function workspace(): string
    {
        $base = $this->root().'/processing';
        if (is_link($base)) {
            throw new MediaFailure('unsafe_path', 'The processing directory cannot be a symbolic link.');
        }
        if (! is_dir($base)) {
            mkdir($base, 0700, true);
        }
        $path = $base.'/'.Str::uuid();
        mkdir($path, 0700);

        return $path;
    }

    public function promote(string $source, string $directory, string $name): string
    {
        return $this->promoteUnder('media/revisions', $source, $directory, $name);
    }

    /** Copies a verified output into one of the fixed immutable revision roots; no other location can be written this way. */
    public function promoteUnder(string $revisions, string $source, string $directory, string $name): string
    {
        if (! in_array($revisions, ['media/revisions', 'site-images/revisions'], true)
            || ! preg_match('~\A[a-zA-Z0-9-]+\z~D', $directory) || ! preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9_-]*\.[a-z0-9]+\z~D', $name)) {
            throw new MediaFailure('unsafe_path', 'Revisions are written only under a fixed root with a safe name.');
        }
        $relative = $revisions.'/'.$directory.'/'.$name;
        $root = $this->root();
        $current = $root;
        foreach (explode('/', dirname($relative)) as $component) {
            $current .= '/'.$component;
            if (is_link($current)) {
                throw new MediaFailure('unsafe_path', 'The revision directory cannot be a symbolic link.');
            }
            if (! is_dir($current)) {
                mkdir($current, 0700);
            }
        }
        $destination = $root.'/'.$relative;
        $input = @fopen($source, 'rb');
        if (! $input) {
            throw new MediaFailure('storage_failed', 'Unable to read the verified processing output.');
        }
        $output = @fopen($destination, 'xb'); // Never replace an existing revision.
        if (! $output) {
            fclose($input);
            throw new MediaFailure('storage_failed', 'Unable to create an immutable media revision.');
        }
        $complete = false;
        try {
            if (stream_copy_to_stream($input, $output) !== filesize($source)) {
                throw new MediaFailure('storage_failed', 'The immutable media copy is incomplete.');
            }
            if (! chmod($destination, 0400)) {
                throw new MediaFailure('storage_failed', 'Unable to protect the immutable media revision.');
            }
            $complete = true;
        } finally {
            fclose($input);
            fclose($output);
            if (! $complete) {
                // This call exclusively created the file. Never unlink a pre-existing revision.
                @unlink($destination);
            }
        }

        return $relative;
    }

    public function cleanup(string $directory): void
    {
        if (! str_starts_with($directory, $this->root().'/processing/') || is_link($directory)) {
            return;
        }
        if (! is_dir($directory)) {
            return;
        }
        // Include hidden/tool-created scratch entries; never follow directory symlinks.
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || ! $entry->isDir()) {
                unlink($path);
            } else {
                rmdir($path);
            }
        }
        if (! @rmdir($directory)) {
            Log::warning('Media workspace cleanup incomplete.', ['workspace' => basename($directory)]);
        }
    }
}
