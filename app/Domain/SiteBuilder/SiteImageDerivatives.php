<?php

namespace App\Domain\SiteBuilder;

use App\Domain\Media\BoundedMediaProcess;
use App\Domain\Media\MediaFailure;

/** Scales one sanitized PNG into a slot's sizes. Every output is written fresh and verified before use. */
final class SiteImageDerivatives
{
    /** @return list<array{format: string, width: int, height: int, file: string, name: string}> */
    public function build(string $png, string $slot, int $sourceWidth, int $sourceHeight, string $workspace): array
    {
        $crop = SiteImageSlot::DEFINITIONS[$slot]['crop'];
        $outputs = [];
        foreach (SiteImageSlot::variants($slot, $sourceWidth, $sourceHeight) as $size) {
            ['format' => $format, 'width' => $width, 'height' => $height] = $size;
            $name = $width.($format === 'webp' ? '.webp' : '.jpg');
            $file = $workspace.'/'.$name;
            // Frame side data carries the source's ICC profile into the encoders; drop it with the rest of the metadata.
            $filter = 'sidedata=mode=delete,'.($crop
                ? "scale={$width}:{$height}:force_original_aspect_ratio=increase:flags=lanczos,crop={$width}:{$height}"
                : "scale={$width}:{$height}:flags=lanczos");
            app(BoundedMediaProcess::class)->run([
                config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-xerror', '-n', '-threads', '1', '-filter_threads', '1',
                '-protocol_whitelist', 'file,pipe', '-err_detect', 'explode', '-f', 'png_pipe', '-i', $png,
                // Bitexact output omits the encoder version comment, so the same source and tools give the same bytes.
                '-map', '0:v:0', '-map_metadata', '-1', '-frames:v', '1', '-vf', $filter, ...SiteImageSlot::encoderArguments($format),
                '-flags:v', '+bitexact', '-fflags', '+bitexact',
                '-f', 'image2', '-update', '1', $file,
            ], $workspace);
            $actual = @getimagesize($file);
            $mime = $format === 'webp' ? 'image/webp' : 'image/jpeg';
            if (! $actual || $actual[0] !== $width || $actual[1] !== $height || ($actual['mime'] ?? null) !== $mime) {
                throw new MediaFailure('invalid_image', 'A resized image failed verification.');
            }
            $outputs[] = ['format' => $format, 'width' => $width, 'height' => $height, 'file' => $file, 'name' => $name];
        }

        return $outputs;
    }
}
