<?php

namespace App\Domain\SiteBuilder;

use App\Domain\Media\BoundedMediaProcess;
use App\Domain\Media\MediaFailure;

/**
 * Reads a scanned upload's real pixel format and orientation with the bounded prober, and checks sanitized output for transparency.
 *
 * Intake also reads the file headers, so staff learn about a problem when they upload rather than from a failed row that must be kept.
 * Processing repeats every check on the decoded image and is the one that decides.
 */
final class SiteImageInspection
{
    private const OPAQUE = ['yuvj420p', 'yuvj422p', 'yuvj444p', 'yuvj440p', 'yuvj411p', 'yuv420p', 'yuv422p', 'yuv444p', 'yuv440p', 'yuv411p', 'gray', 'monob', 'rgb24', 'bgr24', 'pal8'];

    private const ALPHA = ['rgba', 'bgra', 'argb', 'abgr', 'ya8', 'ya16be', 'ya16le', 'rgba64be', 'rgba64le', 'bgra64be', 'bgra64le'];

    /** @return array{width: int, height: int, pix_fmt: string} */
    public function inspect(string $path, string $mime, string $workspace): array
    {
        $output = app(BoundedMediaProcess::class)->run([
            config('media.ffprobe'), '-v', 'error', '-hide_banner', '-protocol_whitelist', 'file,pipe',
            '-f', $mime === 'image/png' ? 'png_pipe' : 'jpeg_pipe', '-select_streams', 'v:0', '-show_frames', '-read_intervals', '%+#1',
            '-show_entries', 'frame=width,height,pix_fmt:frame_tags=Orientation:frame_side_data=rotation', '-of', 'json', $path,
        ], $workspace);
        $frame = json_decode($output, true)['frames'][0] ?? null;
        if (! is_array($frame) || ! is_int($frame['width'] ?? null) || ! is_int($frame['height'] ?? null) || ! is_string($frame['pix_fmt'] ?? null)) {
            throw new MediaFailure('invalid_image', 'The image could not be read.');
        }
        $orientation = trim((string) ($frame['tags']['Orientation'] ?? '1'));
        $rotations = array_filter(array_map(fn (mixed $side): mixed => is_array($side) ? ($side['rotation'] ?? null) : null, $frame['side_data_list'] ?? []), fn (mixed $rotation): bool => $rotation !== null);
        if ($orientation !== '1' || array_filter($rotations, fn (mixed $rotation): bool => (int) $rotation !== 0) !== []) {
            throw new MediaFailure('rotated_image', 'The image is stored sideways or flipped and relies on a rotation tag. Rotate it in an editor and export it again.');
        }
        $format = $frame['pix_fmt'];
        if (in_array($format, self::ALPHA, true)) {
            throw new MediaFailure('transparent_image', 'Use an image without transparency.');
        }
        if (! in_array($format, self::OPAQUE, true)) {
            // ffmpeg widens 9- to 14-bit samples to 16-bit formats; CMYK JPEGs decode as planar RGB with alpha (gbrap).
            throw new MediaFailure(preg_match('/(9|10|12|14|16|32|48|64)(be|le)\z/', $format) === 1 ? 'unsupported_depth' : 'unsupported_pixel_format',
                'Use an 8-bit RGB or grayscale JPEG or PNG. CMYK, 16-bit and other formats are not supported.');
        }

        return ['width' => $frame['width'], 'height' => $frame['height'], 'pix_fmt' => $format];
    }

    /**
     * The failure code for a problem visible in the file headers, or null. Unreadable or unusual headers return null and are left to processing.
     *
     * @param  array<int|string, mixed>  $dimensions  the getimagesize() result for the same file
     */
    public static function headerProblem(string $path, string $mime, array $dimensions): ?string
    {
        if ($mime === 'image/jpeg') {
            if (! in_array($dimensions['channels'] ?? 3, [1, 3], true)) {
                return 'unsupported_pixel_format';
            }
            if (($dimensions['bits'] ?? 8) !== 8) {
                return 'unsupported_depth';
            }
            $orientation = self::jpegOrientation($path);

            return $orientation !== null && $orientation !== 1 ? 'rotated_image' : null;
        }
        if (($dimensions['bits'] ?? 8) > 8) {
            return 'unsupported_depth';
        }
        $header = self::pngHeader($path);
        if ($header['transparent']) {
            return 'transparent_image';
        }

        return $header['orientation'] !== null && $header['orientation'] !== 1 ? 'rotated_image' : null;
    }

    /** True when a PNG declares an alpha channel or a transparency chunk. Only the chunk headers before the image data are read. */
    public static function pngHasTransparency(string $path): bool
    {
        return self::pngHeader($path)['transparent'];
    }

    /** @return array{transparent: bool, orientation: ?int} */
    private static function pngHeader(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new MediaFailure('invalid_image', 'The image is unavailable.');
        }
        $transparent = false;
        $orientation = null;
        try {
            if (fread($handle, 8) !== "\x89PNG\r\n\x1a\n") {
                throw new MediaFailure('invalid_image', 'The image is not a PNG.');
            }
            while (($header = fread($handle, 8)) !== false && strlen($header) === 8) {
                ['length' => $length] = unpack('Nlength', substr($header, 0, 4));
                $type = substr($header, 4, 4);
                if ($type === 'IDAT' || $type === 'IEND') {
                    return ['transparent' => $transparent, 'orientation' => $orientation];
                }
                if ($type === 'IHDR' && $length !== 13) {
                    throw new MediaFailure('invalid_image', 'The image header is malformed.');
                }
                if ($type === 'IHDR' || ($type === 'eXIf' && $length <= 65536)) {
                    $data = $length > 0 ? fread($handle, $length) : '';
                    if ($data === false || strlen($data) !== $length) {
                        throw new MediaFailure('invalid_image', 'The image header is incomplete.');
                    }
                    if ($type === 'IHDR') {
                        $transparent = $transparent || in_array(ord($data[9]), [4, 6], true);
                    } else {
                        $orientation ??= self::tiffOrientation($data);
                    }
                    fseek($handle, 4, SEEK_CUR);

                    continue;
                }
                $transparent = $transparent || $type === 'tRNS';
                fseek($handle, $length + 4, SEEK_CUR);
            }

            throw new MediaFailure('invalid_image', 'The image ended early.');
        } finally {
            fclose($handle);
        }
    }

    /** The Orientation tag of a JPEG's first EXIF block, or null. Only the markers before the image data are read. */
    private static function jpegOrientation(string $path): ?int
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false || fread($handle, 2) !== "\xFF\xD8") {
            return null;
        }
        try {
            while (true) {
                $marker = fread($handle, 2);
                if ($marker === false || strlen($marker) !== 2 || $marker[0] !== "\xFF") {
                    return null;
                }
                $code = ord($marker[1]);
                if ($code === 0xFF) {
                    // Fill byte before a marker.
                    fseek($handle, -1, SEEK_CUR);

                    continue;
                }
                if ($code === 0x01 || ($code >= 0xD0 && $code <= 0xD7)) {
                    continue;
                }
                if ($code === 0xDA || $code === 0xD9 || $code === 0xD8) {
                    return null;
                }
                $size = fread($handle, 2);
                if ($size === false || strlen($size) !== 2 || ($length = unpack('n', $size)[1]) < 2) {
                    return null;
                }
                if ($code === 0xE1 && $length > 8) {
                    $data = fread($handle, $length - 2);
                    if ($data === false || strlen($data) !== $length - 2) {
                        return null;
                    }
                    if (str_starts_with($data, "Exif\0\0")) {
                        return self::tiffOrientation(substr($data, 6));
                    }

                    continue;
                }
                fseek($handle, $length - 2, SEEK_CUR);
            }
        } finally {
            fclose($handle);
        }
    }

    /** Reads tag 0x0112 from the first directory of a TIFF block, with every offset bounds-checked. */
    private static function tiffOrientation(string $tiff): ?int
    {
        $order = substr($tiff, 0, 2);
        if (strlen($tiff) < 8 || ! in_array($order, ['II', 'MM'], true)) {
            return null;
        }
        [$short, $long] = $order === 'II' ? ['v', 'V'] : ['n', 'N'];
        $directory = unpack($long, $tiff, 4)[1];
        if ($directory < 8 || $directory + 2 > strlen($tiff)) {
            return null;
        }
        $entries = unpack($short, $tiff, $directory)[1];
        for ($index = 0; $index < $entries; $index++) {
            $entry = $directory + 2 + 12 * $index;
            if ($entry + 12 > strlen($tiff)) {
                return null;
            }
            if (unpack($short, $tiff, $entry)[1] === 0x0112) {
                return unpack($short, $tiff, $entry + 8)[1];
            }
        }

        return null;
    }
}
