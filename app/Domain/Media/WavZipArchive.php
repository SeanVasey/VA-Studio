<?php

namespace App\Domain\Media;

use Closure;
use InvalidArgumentException;
use ZipArchive;

/** Shared bounded WAV ZIP mechanics; callers retain distinct product roles and versioned manifests. */
class WavZipArchive
{
    public function __construct(private readonly ?Closure $clockSource = null, private readonly string $kind = 'stems')
    {
        if (! in_array($kind, ['stems', 'samples'], true)) {
            throw new InvalidArgumentException('Unsupported internal WAV archive kind.');
        }
    }

    private function memberPrefix(): string
    {
        return $this->kind === 'stems' ? 'stem' : 'sample';
    }

    private function archiveName(): string
    {
        return $this->kind === 'stems' ? 'stems.zip' : 'samples.zip';
    }

    /** Check the complete directory without extracting a member or invoking a scanner. */
    public function inspectUpload(string $input, string $mime, array $profile): void
    {
        $zip = $this->open($input, $mime);
        try {
            $this->inspect($zip, $profile);
        } finally {
            $zip->close();
        }
    }

    private function open(string $input, string $mime): ZipArchive
    {
        if (! class_exists(ZipArchive::class)) {
            throw new MediaFailure('zip_unavailable', 'Install the PHP ZIP extension before processing stems.');
        }
        if (! in_array($mime, ['application/zip', 'application/x-zip'], true) || file_get_contents($input, false, null, 0, 4) !== "PK\x03\x04") {
            throw new MediaFailure('invalid_archive', 'Stems require a complete ZIP containing only WAV audio.');
        }
        $zip = new ZipArchive;
        if ($zip->open($input, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new MediaFailure('invalid_archive', 'The ZIP structure is corrupt or unsupported.');
        }

        return $zip;
    }

    public function build(string $input, string $mime, array $profile, string $workspace, callable $scan): array
    {
        $deadline = $this->clock() + $profile['archive_max_seconds'] * 1000000000;
        $zip = $this->open($input, $mime);
        $manifest = [];
        $members = [];
        $scanLimit = (int) config('media.scanner.timeout_seconds');
        $toolLimit = (int) config('media.process_timeout_seconds');
        try {
            // Validate the entire directory before reading or scanning any expanded member.
            $entries = $this->inspect($zip, $profile);
            foreach ($entries as $index => $entry) {
                $this->withinDeadline($deadline);
                $path = $workspace.'/'.$this->memberPrefix().'-'.$index.'.wav';
                $hash = $this->copyMember($zip, $index, $entry, $path, $deadline);
                $memberScan = $this->withinBudget(fn (int $seconds) => $scan($path, $seconds), $deadline, $scanLimit);
                $audio = $this->withinBudget(fn (int $seconds) => app(AudioDerivatives::class)->validateWav($path, maxDurationSeconds: $profile['archive_max_duration_seconds'], timeoutSeconds: min($toolLimit, $seconds)), $deadline, $toolLimit);
                $this->withinBudget(fn (int $seconds) => app(BoundedMediaProcess::class)->run([
                    config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-xerror',
                    '-threads', '1', '-protocol_whitelist', 'file,pipe', '-err_detect', 'explode',
                    '-f', 'wav', '-i', $path, '-map', '0:a:0', '-threads', '1', '-f', 'null', '-',
                ], $workspace, min($toolLimit, $seconds)), $deadline, $toolLimit);
                // Integer duration keeps the manifest stable through MySQL JSON normalization.
                $audio['duration_microseconds'] = (int) round($audio['duration_seconds'] * 1000000);
                unset($audio['duration_seconds']);
                $manifest[] = ['name' => $entry['name'], 'size_bytes' => $entry['size'], 'sha256' => $hash, 'audio' => $audio, 'scan' => $memberScan];
                $members[$entry['name']] = $path;
            }
        } finally {
            $zip->close();
        }
        usort($manifest, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        $output = $workspace.'/'.$this->archiveName();
        $this->package($output, $members, $deadline);
        $archiveScan = $this->withinBudget(fn (int $seconds) => $scan($output, $seconds), $deadline, $scanLimit);
        $this->withinDeadline($deadline);

        return ['file' => $output, 'manifest' => $manifest, 'archive_scan' => $archiveScan];
    }

    private function inspect(ZipArchive $zip, array $profile): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > $profile['archive_max_entries']) {
            throw new MediaFailure('archive_limit', 'The archive is empty or contains too many entries.');
        }
        $names = $entries = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            if (! $entry || ! $zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                throw new MediaFailure('invalid_archive', 'Archive member metadata could not be read.');
            }
            $name = $entry['name'];
            $directory = str_ends_with($name, '/');
            $key = strtolower(rtrim($name, '/'));
            $this->validateName($name, $directory);
            if (array_key_exists($key, $names)) {
                throw new MediaFailure('unsafe_archive', 'Archive names must be unique, including case-insensitive names.');
            }
            $names[$key] = $directory;
            $type = ($attributes >> 16) & 0170000;
            if (! in_array($opsys, [ZipArchive::OPSYS_UNIX, ZipArchive::OPSYS_DOS], true)
                || ! in_array($type, [0, $directory ? 0040000 : 0100000], true)
                || (! $directory && ($attributes & 0x10)) || ($attributes & 0x08)) {
                throw new MediaFailure('unsafe_archive', 'Links, special files and unsupported archive attributes are not accepted.');
            }
            if (($entry['encryption_method'] ?? -1) !== ZipArchive::EM_NONE || ! in_array($entry['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw new MediaFailure('unsupported_archive', 'Use an unencrypted ZIP with stored or deflated WAV members.');
            }
            $size = $entry['size'];
            $compressed = $entry['comp_size'];
            if (! is_int($size) || ! is_int($compressed) || $size < 0 || $compressed < 0 || ($directory && $size !== 0)
                || (! $directory && ($size < 44 || $size > $profile['archive_max_member_bytes'] || $compressed < 1 || $size / $compressed > $profile['archive_max_ratio']))
                || $size > $profile['archive_max_total_bytes'] - $total) {
                throw new MediaFailure('archive_limit', 'Archive entry size, expansion ratio or total expanded size exceeds the supported limits.');
            }
            $total += $size;
            if (! $directory) {
                $entries[$index] = $entry;
            }
        }
        foreach ($names as $name => $directory) {
            $parts = explode('/', $name);
            array_pop($parts);
            while ($parts !== []) {
                if (($names[implode('/', $parts)] ?? true) === false) {
                    throw new MediaFailure('unsafe_archive', 'An archive file cannot also be a parent directory.');
                }
                array_pop($parts);
            }
        }
        if ($entries === []) {
            throw new MediaFailure('invalid_archive', 'The archive contains no WAV stems.');
        }

        return $entries;
    }

    private function validateName(string $name, bool $directory): void
    {
        $parts = explode('/', $directory ? substr($name, 0, -1) : $name);
        if (strlen($name) > 240 || count($parts) > 5) {
            throw new MediaFailure('unsafe_archive', 'Archive names or folder depth exceed the supported limits.');
        }
        foreach ($parts as $part) {
            if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9 _.\-]{0,99}\z/D', $part) || preg_match('/[. ]\z/D', $part)
                || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|\z)/iD', $part)) {
                throw new MediaFailure('unsafe_archive', 'Use portable relative names: letters, numbers, spaces, underscores, hyphens and dots; no hidden or reserved names.');
            }
        }
        if (! $directory && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'wav') {
            throw new MediaFailure('unsupported_archive', 'Every archive file must be a WAV stem; nested archives and auxiliary files are not supported.');
        }
    }

    private function copyMember(ZipArchive $zip, int $index, array $entry, string $path, int $deadline): string
    {
        $input = @$zip->getStreamIndex($index);
        $output = @fopen($path, 'xb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new MediaFailure('invalid_archive', 'An archive member could not be opened safely.');
        }
        $bytes = 0;
        $hash = hash_init('sha256');
        $crc = hash_init('crc32b');
        try {
            if (! chmod($path, 0600)) {
                throw new MediaFailure('storage_failed', 'Unable to protect the expanded stem.');
            }
            while (! feof($input)) {
                $this->withinDeadline($deadline);
                $chunk = @fread($input, min(65536, $entry['size'] - $bytes + 1));
                if ($chunk === false || ($chunk === '' && ! feof($input))) {
                    throw new MediaFailure('invalid_archive', 'An archive member is truncated or unreadable.');
                }
                $length = strlen($chunk);
                $bytes += $length;
                if ($bytes > $entry['size']) {
                    throw new MediaFailure('archive_limit', 'An expanded member exceeds its declared size.');
                }
                if ($length && fwrite($output, $chunk) !== $length) {
                    throw new MediaFailure('storage_failed', 'Unable to write the complete expanded stem.');
                }
                hash_update($hash, $chunk);
                hash_update($crc, $chunk);
            }
            if ($bytes !== $entry['size'] || hash_final($crc) !== sprintf('%08x', $entry['crc'])) {
                throw new MediaFailure('invalid_archive', 'An archive member failed length or checksum verification.');
            }

            return hash_final($hash);
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function package(string $path, array $members, int $deadline): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new MediaFailure('storage_failed', 'Unable to create the verified stems archive.');
        }
        try {
            ksort($members, SORT_STRING);
            foreach ($members as $name => $file) {
                $this->withinDeadline($deadline);
                if (! $zip->addFile($file, $name) || ! $zip->setCompressionName($name, ZipArchive::CM_STORE)
                    || ! $zip->setMtimeName($name, 315532800) || ! $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100400 << 16)) {
                    throw new MediaFailure('storage_failed', 'Unable to package the verified stem.');
                }
            }
        } finally {
            if (! $zip->close()) {
                throw new MediaFailure('storage_failed', 'Unable to finish the verified stems archive.');
            }
        }
        if (! chmod($path, 0600)) {
            throw new MediaFailure('storage_failed', 'Unable to protect the verified stems archive.');
        }
    }

    /**
     * One process inside what is left of the budget: none starts after the deadline, and none may take longer than the seconds that
     * remain, which $run is given. The malware scans and the FFprobe and FFmpeg calls of a member all run through here. Without it
     * a call that started an instant before the deadline could run for its own limit, 300 seconds for a scan and 120 for a tool, and
     * FFprobe and FFmpeg run one after the other; the job, whose 900 seconds also hold the scan of the upload, would be cut off with
     * the media still claimed. A call the budget cut off is the budget's failure, not the tool's: a timeout ends a call for the
     * budget's sake when the seconds it was given do not exceed $limit, its own. (The deadline is no test of that: the scanner
     * counts whole seconds against what is left and stops a little before it.)
     *
     * @param  callable(int): mixed  $run
     */
    private function withinBudget(callable $run, int $deadline, int $limit): mixed
    {
        $this->withinDeadline($deadline);
        $seconds = (int) floor(($deadline - $this->clock()) / 1000000000);
        if ($seconds < 1) {
            throw new MediaFailure('archive_timeout', 'Archive processing exceeded its bounded time budget.');
        }
        try {
            $result = $run($seconds);
        } catch (MediaFailure $failure) {
            if ($failure->failureCode === 'processor_timeout' && $seconds <= $limit) {
                throw new MediaFailure('archive_timeout', 'Archive processing exceeded its bounded time budget.');
            }
            throw $failure;
        }
        $this->withinDeadline($deadline);

        return $result;
    }

    private function withinDeadline(int $deadline): void
    {
        if ($this->clock() >= $deadline) {
            throw new MediaFailure('archive_timeout', 'Archive processing exceeded its bounded time budget.');
        }
    }

    /** The budget's clock, in nanoseconds. Apart so that a test can move it. */
    protected function clock(): int
    {
        return $this->clockSource === null ? hrtime(true) : ($this->clockSource)();
    }
}
