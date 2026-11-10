<?php

namespace App\Domain\Media;

use App\Support\Environment\TestEnvironment;

/**
 * The synthetic STAGING seller tag: a three-tone chime made by ffmpeg's bit-exact `sine` source, generated on the host by
 * `vasey:staging-seller-tag`. It stands in for Sean's approved seller tag on the private staging rehearsal only, so the
 * media pipeline can make tagged previews before the real tag exists (Sean, 2026-11-10; docs/ops/staging-placeholders.md).
 *
 * It is no recording, sample, speech or brand mark, and it is never committed. Three things mark it: the file name, the
 * RIFF INFO comment and title inside the WAV, and the constant below inside that comment. Production refuses it by any of
 * them (MediaProcessor, vasey:doctor, ops/production/validate-runtime.php); removing the marker from a copy is a
 * deliberate act, not something this guard claims to stop.
 */
final class StagingSellerTag
{
    public const MARKER = 'VASEY-STAGING-SYNTHETIC-SELLER-TAG';

    /** Relative to the private disk, like every MEDIA_TAG_PATH. */
    public const PATH = 'branding/STAGING-synthetic-seller-tag.wav';

    public const TITLE = 'STAGING synthetic seller tag';

    public const COMMENT = self::MARKER.' staging rehearsal only; not for production; not the approved seller tag';

    /** Ascending chime: frequency in Hz and length in seconds of each tone, played back to back. */
    private const TONES = [[880, '0.25'], [1320, '0.25'], [1760, '0.5']];

    private const SECONDS = 30;

    /**
     * Where a marked tag may be used: local, testing and staging, by exact name, and only when the configured and the
     * running environment agree. Anything else, production first, refuses it.
     */
    public static function admitted(): bool
    {
        $configured = config('app.env');

        return is_string($configured) && $configured === app()->environment() && ! app()->isProduction()
            && TestEnvironment::admitsTestCommerce();
    }

    /** A tag path that names a staging or synthetic file, in any letter case. Pure: usable before the framework boots. */
    public static function pathCarriesMarker(mixed $path): bool
    {
        return is_string($path) && (stripos($path, 'staging') !== false || stripos($path, 'synthetic') !== false);
    }

    /** True when the file's bytes hold the marker anywhere. An unreadable file reads as marked, so the guard fails closed. */
    public static function fileCarriesMarker(string $file): bool
    {
        $stream = @fopen($file, 'rb');
        if ($stream === false) {
            return true;
        }
        try {
            $tail = '';
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    return true;
                }
                if (str_contains($tail.$chunk, self::MARKER)) {
                    return true;
                }
                // Keep enough of the end to find a marker that straddles two reads.
                $tail = substr($tail.$chunk, -(strlen(self::MARKER) - 1));
            }
        } finally {
            fclose($stream);
        }

        return false;
    }

    /** True when this tag, by its configured path or by its content, may not be used in the current environment. */
    public static function refused(mixed $path, ?string $file = null): bool
    {
        if (self::admitted()) {
            return false;
        }

        return self::pathCarriesMarker($path) || ($file !== null && self::fileCarriesMarker($file));
    }

    /**
     * Writes the tag into the workspace and returns its path. The `sine` source and the fixed-point gain are integer
     * arithmetic and bit-exact muxing writes no encoder tag, so one ffmpeg build always yields the same bytes; the hash is
     * still read from the file, never assumed, because another build may differ.
     */
    public function generate(string $workspace): string
    {
        $path = $workspace.'/staging-seller-tag.wav';
        $arguments = [config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-n'];
        $inputs = '';
        foreach (self::TONES as $index => [$frequency, $duration]) {
            array_push($arguments, '-f', 'lavfi', '-i', "sine=frequency={$frequency}:sample_rate=44100:duration={$duration}");
            $inputs .= "[{$index}:a]";
        }
        array_push($arguments, '-filter_complex', $inputs.'concat=n='.count(self::TONES).':v=0:a=1,volume=volume=3:precision=fixed[out]',
            '-map', '[out]', '-map_metadata', '-1', '-metadata', 'title='.self::TITLE, '-metadata', 'comment='.self::COMMENT,
            '-fflags', '+bitexact', '-flags:a', '+bitexact', '-threads', '1', '-ac', '1', '-ar', '44100', '-c:a', 'pcm_s16le', '-f', 'wav', $path);
        app(BoundedMediaProcess::class)->run($arguments, $workspace, self::SECONDS);
        if (! is_file($path) || is_link($path)) {
            throw new MediaFailure('processor_failed', 'Staging seller tag generation produced no WAV.');
        }

        return $path;
    }

    /**
     * The media pipeline's own tag rules, applied to a candidate before it is installed: a supported PCM WAV, within the
     * duration limit and the repeat interval, under 16 MiB, audible above the pipeline's floor, and carrying the marker.
     */
    public function assertUsable(string $file): void
    {
        $metadata = app(AudioDerivatives::class)->validateWav($file);
        $interval = (int) config('media.tag_interval_seconds');
        if ($metadata['duration_seconds'] > config('media.max_tag_duration_seconds') || $metadata['duration_seconds'] > $interval
            || filesize($file) > 16777216 || $metadata['codec'] !== 'pcm_s16le') {
            throw new MediaFailure('invalid_tag', 'The staging seller tag does not fit the configured tag limits.');
        }
        if ($this->peak($file) < 0.003162) {
            throw new MediaFailure('silent_tag', 'The staging seller tag is silent or too quiet.');
        }
        if (! self::fileCarriesMarker($file) || ! str_contains((string) file_get_contents($file, false, null, 0, 4096), self::MARKER)) {
            throw new MediaFailure('invalid_tag', 'The staging seller tag does not carry its staging marker.');
        }
    }

    /** Largest absolute 16-bit sample of the WAV's data chunk, as a fraction of full scale. */
    private function peak(string $file): float
    {
        $bytes = (string) file_get_contents($file);
        $offset = 12;
        $peak = 0;
        while ($offset + 8 <= strlen($bytes)) {
            $size = unpack('Vsize', substr($bytes, $offset + 4, 4))['size'];
            if (substr($bytes, $offset, 4) === 'data') {
                foreach (unpack('v*', substr($bytes, $offset + 8, $size - $size % 2)) ?: [] as $value) {
                    $peak = max($peak, abs($value >= 32768 ? $value - 65536 : $value));
                }
                break;
            }
            $offset += 8 + $size + $size % 2;
        }

        return $peak / 32768;
    }
}
