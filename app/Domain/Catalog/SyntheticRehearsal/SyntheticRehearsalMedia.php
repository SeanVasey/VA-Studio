<?php

namespace App\Domain\Catalog\SyntheticRehearsal;

use App\Domain\Media\BoundedMediaProcess;
use RuntimeException;
use ZipArchive;

/**
 * Generates one definition's source files at run time inside a private processing workspace: a PCM WAV master from
 * ffmpeg's built-in test sources (tones, chords, noise), a WAV-only stems ZIP with one mono stem per test source, and a
 * plain PNG with "SYNTHETIC TEST" drawn by GD. Nothing is read from the repository or the network, nothing is
 * committed, and no brand mark is drawn. The files then enter through the ordinary intake service, which copies them
 * to quarantine; the caller removes the workspace.
 */
final class SyntheticRehearsalMedia
{
    private const SECONDS = 120;

    public function master(array $track, string $workspace): string
    {
        $path = $workspace.'/'.$track['slug'].'-master.wav';
        $arguments = [config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-n'];
        foreach ($track['sources'] as $source) {
            array_push($arguments, '-f', 'lavfi', '-i', $source);
        }
        if (count($track['sources']) > 1) {
            $inputs = implode('', array_map(fn (int $index): string => '['.$index.':a]', array_keys($track['sources'])));
            array_push($arguments, '-filter_complex', $inputs.'amix=inputs='.count($track['sources']).':normalize=0[out]', '-map', '[out]');
        }
        // Bit-exact output carries no encoder tag, so the same definition always yields the same master bytes.
        array_push($arguments, '-map_metadata', '-1', '-fflags', '+bitexact', '-flags:a', '+bitexact', '-threads', '1',
            '-ac', '2', '-ar', '44100', '-c:a', 'pcm_s16le', '-t', (string) $track['duration'], '-f', 'wav', $path);
        app(BoundedMediaProcess::class)->run($arguments, $workspace, self::SECONDS);
        if (! is_file($path) || filesize($path) < 44) {
            throw new RuntimeException('Synthetic master generation produced no WAV.');
        }

        return $path;
    }

    /**
     * One bit-exact mono WAV per test source, packed as the media pipeline packs verified stems (stored members, fixed
     * time and attributes, sorted names), so the same definition always yields the same archive bytes.
     */
    public function stems(array $track, string $workspace): string
    {
        $members = [];
        foreach (array_values($track['sources']) as $index => $source) {
            $name = sprintf('stem-%02d.wav', $index + 1);
            $path = $workspace.'/'.$track['slug'].'-'.$name;
            app(BoundedMediaProcess::class)->run([config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-n',
                '-f', 'lavfi', '-i', $source, '-map_metadata', '-1', '-fflags', '+bitexact', '-flags:a', '+bitexact', '-threads', '1',
                '-ac', '1', '-ar', '44100', '-c:a', 'pcm_s16le', '-t', (string) $track['duration'], '-f', 'wav', $path], $workspace, self::SECONDS);
            if (! is_file($path) || filesize($path) < 44) {
                throw new RuntimeException('Synthetic stem generation produced no WAV.');
            }
            $members[$name] = $path;
        }
        $path = $workspace.'/'.$track['slug'].'-stems.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Synthetic stems archive could not be created.');
        }
        try {
            ksort($members, SORT_STRING);
            foreach ($members as $name => $file) {
                if (! $zip->addFile($file, $name) || ! $zip->setCompressionName($name, ZipArchive::CM_STORE)
                    || ! $zip->setMtimeName($name, 315532800) || ! $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100600 << 16)) {
                    throw new RuntimeException('Synthetic stem could not be packaged.');
                }
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('Synthetic stems archive could not be finished.');
            }
        }

        return $path;
    }

    public function artwork(array $track, string $workspace): string
    {
        $path = $workspace.'/'.$track['slug'].'-artwork.png';
        // Draw at 150 px with GD's built-in font, then scale by four without smoothing: no font file is needed.
        $small = imagecreatetruecolor(150, 150);
        $large = imagecreatetruecolor(600, 600);
        if ($small === false || $large === false) {
            throw new RuntimeException('Synthetic artwork allocation failed.');
        }
        try {
            [$red, $green, $blue] = $track['color'];
            $background = imagecolorallocate($small, $red, $green, $blue);
            $stripe = imagecolorallocate($small, min(255, $red + 24), min(255, $green + 24), min(255, $blue + 24));
            $white = imagecolorallocate($small, 255, 255, 255);
            $black = imagecolorallocate($small, 0, 0, 0);
            imagefill($small, 0, 0, $background);
            for ($offset = -150; $offset < 150; $offset += 20) {
                imagefilledpolygon($small, [$offset, 150, $offset + 10, 150, $offset + 160, 0, $offset + 150, 0], $stripe);
            }
            imagerectangle($small, 3, 3, 146, 146, $white);
            foreach ([[38, 'SYNTHETIC TEST'], [62, 'NOT FOR SALE'], [86, 'STAGING ONLY'], [110, 'TRACK '.$track['number']]] as [$y, $text]) {
                $x = intdiv(150 - strlen($text) * imagefontwidth(5), 2);
                imagestring($small, 5, $x + 1, $y + 1, $text, $black);
                imagestring($small, 5, $x, $y, $text, $white);
            }
            imagecopyresized($large, $small, 0, 0, 0, 0, 600, 600, 150, 150);
            if (! imagepng($large, $path) || ! is_file($path)) {
                throw new RuntimeException('Synthetic artwork generation produced no PNG.');
            }
        } finally {
            imagedestroy($small);
            imagedestroy($large);
        }

        return $path;
    }
}
