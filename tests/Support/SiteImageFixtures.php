<?php

namespace Tests\Support;

use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\SiteImageProcessor;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Synthetic site images for tests. Baseline JPEGs use an explicit FFmpeg sampling format; progressive JPEGs use GD.
 * Everything GD cannot write
 * (CMYK, 12-bit and grayscale JPEGs, every PNG) is assembled byte by byte so each case is exact.
 */
final class SiteImageFixtures
{
    /** Planted in metadata so tests can prove none of it reaches a prepared file. */
    public const MARKERS = ['SYNTHETIC-GPS-MARKER', 'SYNTHETIC-XMP-MARKER', 'SYNTHETIC-IPTC-MARKER', 'SYNTHETIC-ICC-MARKER', 'SYNTHETIC-COMMENT-MARKER', 'SYNTHETIC-POLYGLOT-MARKER'];

    /** The test pattern: a light block over the top-left third of a blue field. */
    public const FIELD = [20, 120, 200];

    public const BLOCK = [250, 250, 250];

    /**
     * A JPEG of the RGB test pattern, optionally carrying metadata segments.
     *
     * exif is the Orientation value, or one value per EXIF block; GPS data is always added with it. stray moves the metadata after
     * the encoder's first segment behind a stray byte, which decoders and getimagesize() skip. hidden_exif places a sideways EXIF block where
     * FFmpeg reads it but intake's header reader does not: after the scan data, inside a DNL segment, or behind "Exif\0\x01".
     * solid fills the whole image with one colour.
     *
     * @param  array{exif?: int|list<int>, xmp?: bool, iptc?: bool, icc?: bool, comment?: bool, progressive?: bool, stray?: bool, hidden_exif?: 'after_scan'|'dnl'|'id', solid?: array{int, int, int}}  $options
     */
    public static function jpeg(int $width, int $height, array $options = []): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, ...($options['solid'] ?? self::FIELD)));
        if (! isset($options['solid'])) {
            imagefilledrectangle($image, 0, 0, intdiv($width, 3) - 1, intdiv($height, 3) - 1, imagecolorallocate($image, ...self::BLOCK));
        }
        try {
            if ($options['progressive'] ?? false) {
                // Preserve actual SOF2/multiple-scan coverage; no sampling assumption is made for this branch.
                imageinterlace($image, true);
                ob_start();
                imagejpeg($image, null, 92);
                $jpeg = (string) ob_get_clean();
            } else {
                $jpeg = self::baselineJpeg($image);
            }
        } finally {
            imagedestroy($image);
        }
        $segments = '';
        foreach ((array) ($options['exif'] ?? []) as $orientation) {
            $segments .= self::segment(0xE1, "Exif\0\0".self::tiff($orientation));
        }
        if ($options['xmp'] ?? false) {
            $segments .= self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0".self::xmp());
        }
        if ($options['iptc'] ?? false) {
            // Photoshop image resource 0x0404 holds IPTC-IIM records; 2:80 is the byline.
            $iptc = "\x1C\x02\x50".pack('n', 21).'SYNTHETIC-IPTC-MARKER';
            $segments .= self::segment(0xED, "Photoshop 3.0\0".'8BIM'.pack('n', 0x0404)."\0\0".pack('N', strlen($iptc)).$iptc.(strlen($iptc) % 2 ? "\0" : ''));
        }
        if ($options['icc'] ?? false) {
            $segments .= self::segment(0xE2, "ICC_PROFILE\0\x01\x01".self::iccProfile());
        }
        if ($options['comment'] ?? false) {
            $segments .= self::segment(0xFE, 'SYNTHETIC-COMMENT-MARKER');
        }
        $hidden = $options['hidden_exif'] ?? null;
        if ($hidden === 'after_scan') {
            // Between the scan data and the end-of-image marker.
            $end = (int) strrpos($jpeg, "\xFF\xD9");
            $jpeg = substr($jpeg, 0, $end).self::segment(0xE1, "Exif\0\0".self::tiff(6)).substr($jpeg, $end);
        } elseif ($hidden === 'dnl') {
            // FFmpeg looks for the next marker inside a segment it does not parse; a reader that skips by length does not.
            $segments .= self::segment(0xDC, "\0\0".self::segment(0xE1, "Exif\0\0".self::tiff(6)));
        } elseif ($hidden === 'id') {
            // FFmpeg checks only "Exif" and skips the next two bytes unread.
            $segments .= self::segment(0xE1, "Exif\0\x01".self::tiff(6));
        }
        if ($options['stray'] ?? false) {
            // After a complete first segment, so the file still starts like a JPEG; its length field counts itself.
            $first = 4 + unpack('n', $jpeg, 4)[1];

            return substr($jpeg, 0, $first)."\x00".$segments.substr($jpeg, $first);
        }

        // Cameras and editors put metadata straight after the start-of-image marker.
        return substr($jpeg, 0, 2).$segments.substr($jpeg, 2);
    }

    /** Encode the pattern with equal component sampling (4:4:4), independent of the GD/libjpeg build. */
    private static function baselineJpeg(\GdImage $image): string
    {
        $directory = sys_get_temp_dir().'/site-image-fixture-'.Str::uuid();
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create the private site image fixture directory.');
        }
        try {
            $input = $directory.'/pattern.png';
            $output = $directory.'/pattern.jpg';
            touch($input);
            chmod($input, 0600);
            imagepng($image, $input);
            (new Process([
                config('media.prlimit'), '--cpu=30', '--as=536870912', '--fsize=33554432', '--nofile=64', '--',
                config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-xerror', '-n',
                '-threads', '1', '-filter_threads', '1', '-protocol_whitelist', 'file,pipe', '-f', 'png_pipe', '-i', $input,
                '-map', '0:v:0', '-map_metadata', '-1', '-frames:v', '1', '-c:v', 'mjpeg', '-threads:v', '1', '-q:v', '3', '-pix_fmt', 'yuvj444p',
                '-flags:v', '+bitexact', '-fflags', '+bitexact', '-f', 'image2', '-update', '1', $output,
            ], $directory, ['TMPDIR' => $directory, 'FFREPORT' => false, 'OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1']))
                ->setTimeout(30)->mustRun();

            return (string) file_get_contents($output);
        } finally {
            @unlink($directory.'/pattern.png');
            @unlink($directory.'/pattern.jpg');
            rmdir($directory);
        }
    }

    /**
     * Genuine baseline RGB: three unsampled components whose zero DC/AC coefficients each decode to 128.
     * Adobe transform 0 truthfully declares those equal midgrey samples RGB, without relabelling coloured YCbCr data.
     */
    public static function rgbJpeg(int $width, int $height): string
    {
        $jpeg = self::flatJpeg($width, $height, 3);

        return substr($jpeg, 0, 2).self::segment(0xEE, 'Adobe'.pack('nnn', 100, 0, 0)."\0").substr($jpeg, 2);
    }

    /**
     * A flat mid-grey JPEG in any component count and precision, which GD cannot write: 4 components with an
     * Adobe marker is CMYK, 1 component is grayscale, and precision 12 is a 12-bit JPEG.
     */
    public static function flatJpeg(int $width, int $height, int $components, int $precision = 8): string
    {
        $ids = range(1, $components);
        $sof = pack('Cnn', $precision, $height, $width).chr($components);
        $sos = chr($components);
        foreach ($ids as $id) {
            $sof .= chr($id)."\x11\x00";
            $sos .= chr($id)."\x00";
        }
        // One-symbol Huffman tables: every block is "DC unchanged" then "end of block", one bit each.
        $table = chr(1).str_repeat("\0", 15)."\0";
        $blocks = (int) ceil($width / 8) * (int) ceil($height / 8) * $components;
        $bits = str_repeat('0', 2 * $blocks);
        $bits .= str_repeat('1', (8 - strlen($bits) % 8) % 8);
        $scan = '';
        foreach (str_split($bits, 8) as $byte) {
            $scan .= chr(bindec($byte));
        }

        return "\xFF\xD8"
            .($components === 4 ? self::segment(0xEE, 'Adobe'.pack('nnn', 100, 0, 0)."\0") : '')
            .self::segment(0xDB, "\0".str_repeat("\x01", 64))
            .self::segment($precision === 8 ? 0xC0 : 0xC1, $sof)
            .self::segment(0xC4, "\x00".$table."\x10".$table)
            .self::segment(0xDA, $sos."\x00\x3F\x00")
            .$scan."\xFF\xD9";
    }

    /**
     * A PNG of the test pattern in the requested form.
     *
     * @param  'rgb'|'rgba'|'gray'|'gray_alpha'|'palette'|'palette_alpha'|'rgb_key'|'gray_key'|'rgb16'|'gray16'  $kind
     * @param  list<string>  $chunks  extra chunks from {@see pngChunk()}, written before the image data
     * @param  list<string>  $trailing  extra chunks written after the image data, before IEND
     */
    public static function png(int $width, int $height, string $kind = 'rgb', array $chunks = [], array $trailing = []): string
    {
        $third = intdiv($width, 3);
        [$type, $depth, $field, $block, $extra] = match ($kind) {
            'rgb' => [2, 8, self::rgb(self::FIELD), self::rgb(self::BLOCK), []],
            'rgba' => [6, 8, self::rgb(self::FIELD)."\xFF", self::rgb(self::BLOCK)."\x80", []],
            'gray' => [0, 8, "\x60", "\xF0", []],
            'gray_alpha' => [4, 8, "\x60\xFF", "\xF0\x80", []],
            'palette' => [3, 8, "\x00", "\x01", [self::pngChunk('PLTE', self::rgb(self::FIELD).self::rgb(self::BLOCK))]],
            'palette_alpha' => [3, 8, "\x00", "\x01", [self::pngChunk('PLTE', self::rgb(self::FIELD).self::rgb(self::BLOCK)), self::pngChunk('tRNS', "\xFF\x00")]],
            'rgb_key' => [2, 8, self::rgb(self::FIELD), self::rgb(self::BLOCK), [self::pngChunk('tRNS', pack('nnn', ...self::BLOCK))]],
            'gray_key' => [0, 8, "\x60", "\xF0", [self::pngChunk('tRNS', pack('n', 0xF0))]],
            'rgb16' => [2, 16, self::rgb16(self::FIELD), self::rgb16(self::BLOCK), []],
            'gray16' => [0, 16, "\x60\x60", "\xF0\xF0", []],
        };
        $plain = "\0".str_repeat($field, $width);
        $marked = "\0".str_repeat($block, $third).str_repeat($field, $width - $third);
        $rows = str_repeat($marked, intdiv($height, 3)).str_repeat($plain, $height - intdiv($height, 3));

        return "\x89PNG\r\n\x1a\n".self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, $depth, $type, 0, 0, 0))
            .implode('', $extra).implode('', $chunks).self::pngChunk('IDAT', (string) gzcompress($rows, 6)).implode('', $trailing).self::pngChunk('IEND', '');
    }

    /** A PNG whose header claims a size far beyond the limit, with no real image data behind it. */
    public static function oversizedPngHeader(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n".self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .self::pngChunk('IDAT', (string) gzcompress("\0\0\0\0")).self::pngChunk('IEND', '');
    }

    /** @return list<string> text, XMP, EXIF (with GPS) and ICC chunks, each carrying a marker */
    public static function pngMetadataChunks(): array
    {
        return [
            self::pngChunk('tEXt', "Comment\0SYNTHETIC-COMMENT-MARKER"),
            self::pngChunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0".self::xmp()),
            self::pngExif(1),
            self::pngChunk('iCCP', "Synthetic\0\0".gzcompress(self::iccProfile())),
        ];
    }

    /** A PNG eXIf chunk holding an Orientation tag (and GPS data), zero-padded to at least $bytes. */
    public static function pngExif(int $orientation, int $bytes = 0): string
    {
        return self::pngChunk('eXIf', str_pad(self::tiff($orientation), $bytes, "\0"));
    }

    public static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /** The size each slot's fixtures use: the slot's own reference size, so every check passes. */
    public const SIZES = ['hero_desktop' => [2400, 890], 'hero_mobile' => [960, 890], 'studio' => [1440, 630], 'share' => [1200, 630]];

    /** A processed, ready image for a slot. The caller has bound the testing-only scanner (MediaFixtures::configure()). */
    public static function ready(string $slot, User $uploader, ?string $bytes = null, string $name = 'synthetic-fixture'): SiteImage
    {
        [$width, $height] = self::SIZES[$slot];
        $image = app(SiteImageProcessor::class)->handle(self::quarantined($slot, $bytes ?? self::jpeg($width, $height), $width, $height, $uploader, name: $name)->id);
        if ($image->status !== 'ready') {
            throw new \LogicException('The synthetic site image did not become ready: '.$image->failure_code);
        }

        return $image;
    }

    /** Stores bytes as a quarantined upload and records it directly, as a bypassed or older intake would. */
    public static function quarantined(string $slot, string $bytes, int $width, int $height, User $uploader, ?string $mime = null, string $name = 'synthetic-fixture'): SiteImage
    {
        $path = 'site-images/quarantine/'.Str::uuid().'/source.upload';
        Storage::disk('local')->put($path, $bytes);

        return SiteImage::create([
            'slot' => $slot, 'original_name' => $name, 'source_path' => $path, 'source_sha256' => hash('sha256', $bytes),
            'size_bytes' => strlen($bytes), 'mime_type' => $mime ?? (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), 'width' => $width, 'height' => $height,
            'credit' => 'Synthetic test fixture', 'rights_confirmed_at' => now(), 'uploaded_by' => $uploader->id, 'status' => 'quarantined', 'attempts' => 0,
        ]);
    }

    private static function segment(int $marker, string $payload): string
    {
        return "\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload;
    }

    /** A little-endian TIFF block with an Orientation tag and a GPS directory holding a marker. */
    private static function tiff(int $orientation): string
    {
        $gpsOffset = 8 + 2 + 2 * 12 + 4;
        $ifd0 = pack('v', 2).pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('vvVV', 0x8825, 4, 1, $gpsOffset).pack('V', 0);
        $markerOffset = $gpsOffset + 2 + 2 * 12 + 4;
        $gps = pack('v', 2).pack('vvV', 0x0001, 2, 2)."N\0\0\0".pack('vvVV', 0x001B, 7, 20, $markerOffset).pack('V', 0);

        return "II*\0".pack('V', 8).$ifd0.$gps.'SYNTHETIC-GPS-MARKER';
    }

    private static function xmp(): string
    {
        return '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" dc:creator="SYNTHETIC-XMP-MARKER"/></rdf:RDF></x:xmpmeta>';
    }

    /** A structurally plausible, empty RGB display profile with a marker in its reserved header bytes. */
    private static function iccProfile(): string
    {
        $header = pack('N', 132).'none'.pack('N', 0x02100000).'mntrRGB XYZ '.str_repeat("\0", 12).'acsp'.str_repeat("\0", 24)
            .pack('N', 0).pack('NNN', 0x0000F6D6, 0x00010000, 0x0000D32D).str_repeat("\0", 4).str_repeat("\0", 16)
            .str_pad('SYNTHETIC-ICC-MARKER', 28, "\0");

        return $header.pack('N', 0);
    }

    private static function rgb(array $colour): string
    {
        return pack('CCC', ...$colour);
    }

    private static function rgb16(array $colour): string
    {
        return pack('nnn', ...array_map(fn (int $channel): int => $channel * 257, $colour));
    }
}
