<?php

namespace App\Domain\Contracts\Production;

use App\Support\Environment\TestEnvironment;
use RuntimeException;

/**
 * The staging specimen for the licensor signature line (DD-27, ledger PCL-06): a plain PNG that reads
 * "STAGING SPECIMEN — NOT A SIGNATURE" in a bundled document font. It draws text only, nothing that resembles a
 * handwritten signature or any mark of the brand, and it is never committed. Generated on the host by
 * `vasey:staging-specimen-signature`, pinned by `VASEY_LICENSE_SIGNATURE_SHA256` and read by
 * {@see VaseyLicenseSignature} like any other signature image.
 *
 * Three things mark it for `vasey:cutover-check` (PCL-06): the file name, the words in the picture and, because
 * pixels are not searchable, the marker inside a `tEXt` chunk. The cutover check looks for the path word and the
 * marker bytes, so a production host fails on it. Removing the marker from a copy is a deliberate act, not
 * something this class claims to stop.
 *
 * Output is deterministic: GD draws one fixed canvas, the only metadata is one fixed `tEXt` chunk, and no clock,
 * random value or host name is involved. The same GD, FreeType and zlib build always yields the same bytes. The
 * command reads the SHA-256 from the file it made and never assumes it.
 */
final class StagingSpecimenSignature
{
    /** Relative to the private local disk, like every `VASEY_LICENSE_SIGNATURE_PATH`. */
    public const PATH = 'contracts/STAGING-specimen-signature.png';

    public const TEXT = 'STAGING SPECIMEN — NOT A SIGNATURE';

    /** Same string the cutover check searches for (CutoverMarkers::SPECIMEN_SIGNATURE_MARKER). */
    public const MARKER = 'STAGING SPECIMEN';

    public const COMMENT = self::MARKER.' - not a signature; staging rehearsal only; not for production';

    public const WIDTH = 1200;

    public const HEIGHT = 160;

    /** The contract documents' own bold face, already in the repository (DejaVu Sans, Bitstream Vera licence). */
    public const FONT = 'resources/contracts/test-v2/fonts/source/DejaVuSans-Bold.ttf';

    /**
     * Where the specimen may be made: local, testing and staging by exact name, and only when the configured and the
     * running environment agree. Production refuses first.
     */
    public static function admitted(): bool
    {
        $configured = config('app.env');

        return is_string($configured) && $configured === app()->environment() && ! app()->isProduction()
            && TestEnvironment::admitsTestCommerce();
    }

    /** The PNG bytes. */
    public static function png(?string $font = null): string
    {
        $font ??= base_path(self::FONT);
        if (! extension_loaded('gd') || ! function_exists('imagettftext') || ! is_file($font) || is_link($font)) {
            throw new RuntimeException('specimen_unavailable');
        }
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($image === false) {
            throw new RuntimeException('specimen_unavailable');
        }
        imagealphablending($image, true);
        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, (int) imagecolorallocate($image, 255, 255, 255));
        $ink = (int) imagecolorallocate($image, 30, 30, 30);
        // The largest whole point size that leaves a 40 px margin on each side.
        $size = 20;
        for ($try = 60; $try >= 10; $try--) {
            $box = imagettfbbox($try, 0, $font, self::TEXT);
            if ($box !== false && ($box[2] - $box[0]) <= self::WIDTH - 80) {
                $size = $try;
                break;
            }
        }
        $box = imagettfbbox($size, 0, $font, self::TEXT);
        if ($box === false) {
            throw new RuntimeException('specimen_unavailable');
        }
        $x = (int) round((self::WIDTH - ($box[2] - $box[0])) / 2) - $box[0];
        $y = (int) round((self::HEIGHT - ($box[1] - $box[7])) / 2) - $box[7];
        if (imagettftext($image, $size, 0, $x, $y, $ink, $font, self::TEXT) === false) {
            throw new RuntimeException('specimen_unavailable');
        }
        imageinterlace($image, false);
        ob_start();
        $written = imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        if (! $written || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('specimen_unavailable');
        }

        return self::withComment($png);
    }

    /** Insert one fixed `tEXt` chunk right after IHDR. */
    private static function withComment(string $png): string
    {
        // 8 byte signature, then IHDR: length(4) type(4) data(13) crc(4).
        $split = 8 + 4 + 4 + 13 + 4;
        if (substr($png, 12, 4) !== 'IHDR') {
            throw new RuntimeException('specimen_unavailable');
        }
        $data = 'Comment'."\0".self::COMMENT;
        $chunk = pack('N', strlen($data)).'tEXt'.$data.pack('N', crc32('tEXt'.$data));

        return substr($png, 0, $split).$chunk.substr($png, $split);
    }
}
