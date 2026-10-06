<?php

namespace App\Domain\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/** Server-owned transport files. No client path is accepted or returned. */
class PrivateUploadParts
{
    public function directory(string $session): string
    {
        if (! Str::isUuid($session)) {
            throw new RuntimeException('Invalid upload identity.');
        }
        $directory = app(PrivateMediaFiles::class)->root();
        foreach (['resumable', $session] as $component) {
            $directory .= '/'.$component;
            if (is_link($directory)) {
                throw new RuntimeException('Unsafe private upload directory.');
            }
            if (! is_dir($directory) && ! @mkdir($directory, 0700) && ! is_dir($directory)) {
                throw new RuntimeException('Cannot create private upload directory.');
            }
            if (realpath($directory) !== $directory) {
                throw new RuntimeException('Unsafe private upload directory.');
            }
        }

        return $directory;
    }

    public function preserve(string $session, UploadedFile $upload, int $offset, int $size, string $hash): array
    {
        $part = ['token' => 'part-'.$offset, 'offset' => $offset, 'size' => $size, 'sha256' => $hash];
        $destination = $this->directory($session).'/'.$part['token'].'.part';
        if (file_exists($destination) || is_link($destination)) {
            $this->verify($session, $part);

            return $part;
        }
        $pending = $this->directory($session).'/'.$part['token'].'.pending';
        $this->discardPending($pending);
        $this->copyExact($upload->getPathname(), $pending, $size, $hash);
        if (! rename($pending, $destination)) {
            throw new RuntimeException('Cannot retain private upload bytes.');
        }

        return $part;
    }

    /** The completion caller holds the actor/track/session fences for this identity. */
    public function quarantine(string $session, string $source, int $size, string $hash): void
    {
        if (! Str::isUuid($session)) {
            throw new RuntimeException('Invalid upload identity.');
        }
        $directory = app(PrivateMediaFiles::class)->root();
        foreach (['quarantine', 'resumable', $session] as $component) {
            $directory .= '/'.$component;
            if (is_link($directory) || (! is_dir($directory) && ! @mkdir($directory, 0700) && ! is_dir($directory))
                || realpath($directory) !== $directory) {
                throw new RuntimeException('Unsafe resumable quarantine directory.');
            }
        }
        $destination = $directory.'/source.upload';
        if (file_exists($destination) || is_link($destination)) {
            $this->verifyExactFile($destination, $size, $hash);

            return;
        }
        $pending = $directory.'/source.pending';
        $this->discardPending($pending);
        $this->copyExact($source, $pending, $size, $hash);
        if (! rename($pending, $destination)) {
            throw new RuntimeException('Cannot preserve the resumable quarantine source.');
        }
    }

    private function discardPending(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Unsafe private pending upload.');
        }
        if (file_exists($path)) {
            if (! is_file($path) || ! unlink($path)) {
                throw new RuntimeException('Cannot recover an interrupted upload copy.');
            }
        }
    }

    private function copyExact(string $source, string $destination, int $size, string $hash): void
    {
        $before = ! is_link($source) ? @lstat($source) : false;
        $input = $before ? @fopen($source, 'rb') : false;
        $opened = $input ? fstat($input) : false;
        if (! $opened || ($opened['mode'] & 0170000) !== 0100000 || $before['ino'] !== $opened['ino']
            || $before['dev'] !== $opened['dev'] || $opened['size'] !== $size) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw new RuntimeException('The upload source changed while opening.');
        }
        $output = @fopen($destination, 'xb');
        if (! $output) {
            fclose($input);
            throw new RuntimeException('Cannot preserve private upload bytes.');
        }
        $complete = false;
        try {
            if (! chmod($destination, 0600) || stream_copy_to_stream($input, $output, $size + 1) !== $size
                || ! fflush($output) || ! fsync($output)) {
                throw new RuntimeException('The private upload copy is incomplete.');
            }
            $this->verifyExactFile($destination, $size, $hash);
            $complete = true;
        } finally {
            fclose($input);
            fclose($output);
            // Pending paths are never referenced by database records.
            if (! $complete) {
                @unlink($destination);
            }
        }
    }

    private function verifyExactFile(string $path, int $size, string $hash): void
    {
        $before = ! is_link($path) ? @lstat($path) : false;
        $input = $before ? @fopen($path, 'rb') : false;
        $opened = $input ? fstat($input) : false;
        if (! $opened || ($opened['mode'] & 0170000) !== 0100000 || $before['ino'] !== $opened['ino']
            || $before['dev'] !== $opened['dev'] || $opened['size'] !== $size) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw new RuntimeException('Retained upload bytes are unavailable.');
        }
        try {
            $digest = hash_init('sha256');
            $bytes = hash_update_stream($digest, $input, $size + 1);
            if ($bytes !== $size || ! hash_equals($hash, hash_final($digest))) {
                throw new RuntimeException('The complete upload does not match its declared bytes.');
            }
        } finally {
            fclose($input);
        }
    }

    private function open(string $session, array $part)
    {
        if (! is_string($part['token'] ?? null) || $part['token'] !== 'part-'.($part['offset'] ?? '')
            || ! is_int($part['size'] ?? null) || $part['size'] < 1
            || ! is_string($part['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $part['sha256'])) {
            throw new RuntimeException('Invalid retained upload chunk.');
        }
        $path = $this->directory($session).'/'.$part['token'].'.part';
        $before = ! is_link($path) ? @lstat($path) : false;
        $input = $before ? @fopen($path, 'rb') : false;
        $opened = $input ? fstat($input) : false;
        if (! $opened || ($opened['mode'] & 0170000) !== 0100000 || $before['ino'] !== $opened['ino']
            || $before['dev'] !== $opened['dev'] || $opened['size'] !== $part['size']) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw new RuntimeException('Retained upload bytes are unavailable.');
        }

        return $input;
    }

    public function verify(string $session, array $part): void
    {
        $input = $this->open($session, $part);
        try {
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $input, $part['size'] + 1);
            if ($bytes !== $part['size'] || ! hash_equals($part['sha256'], hash_final($hash))) {
                throw new RuntimeException('Retained upload bytes changed.');
            }
        } finally {
            fclose($input);
        }
    }

    public function assemble(string $session, array $parts, int $size, string $hash): string
    {
        $destination = $this->directory($session).'/complete.assembled';
        if (file_exists($destination) || is_link($destination)) {
            $this->verifyExactFile($destination, $size, $hash);

            return $destination;
        }
        $pending = $this->directory($session).'/assembly.pending';
        $this->discardPending($pending);
        $output = @fopen($pending, 'xb');
        if (! $output) {
            throw new RuntimeException('Cannot assemble private upload.');
        }
        $complete = false;
        try {
            if (! chmod($pending, 0600)) {
                throw new RuntimeException('Cannot protect assembled upload.');
            }
            $offset = 0;
            foreach ($parts as $part) {
                if (($part['offset'] ?? null) !== $offset || ($part['size'] ?? 0) > ResumableMediaUploads::CHUNK_BYTES
                    || $offset + ($part['size'] ?? 0) > $size) {
                    throw new RuntimeException('Retained upload offsets changed.');
                }
                $this->verify($session, $part);
                $input = $this->open($session, $part);
                try {
                    if (stream_copy_to_stream($input, $output, $part['size'] + 1) !== $part['size']) {
                        throw new RuntimeException('Retained upload size changed.');
                    }
                } finally {
                    fclose($input);
                }
                $offset += $part['size'];
            }
            if (! fflush($output) || ! fsync($output) || $offset !== $size || ! hash_equals($hash, hash_file('sha256', $pending))) {
                throw new RuntimeException('The complete upload does not match its declared bytes.');
            }
            $complete = true;
        } finally {
            fclose($output);
            if (! $complete) {
                @unlink($pending);
            }
        }
        if (! rename($pending, $destination)) {
            throw new RuntimeException('Cannot retain the complete upload.');
        }

        return $destination;
    }

    public function remove(string $session): void
    {
        $directory = $this->directory($session);
        // Flat, server-created transport files only. Never descend into subdirectories
        // or follow links, and never touch quarantine/processed media or originals.
        foreach (new \FilesystemIterator($directory) as $file) {
            if ($file->isLink() || $file->isDir() || ! preg_match('/\A(?:part-[0-9]+\.(?:part|pending)|complete\.assembled|assembly\.pending)\z/D', $file->getFilename())) {
                throw new RuntimeException('Unexpected private upload contents; retained for inspection.');
            }
        }
        foreach (new \FilesystemIterator($directory) as $file) {
            if (! unlink($file->getPathname())) {
                throw new RuntimeException('Private upload cleanup is incomplete.');
            }
        }
        if (! rmdir($directory)) {
            throw new RuntimeException('Private upload cleanup is incomplete.');
        }
    }
}
