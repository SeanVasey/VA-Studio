<?php

namespace App\Domain\Media;

class ArtworkDerivative
{
    public function build(string $source, string $mime, string $workspace): array
    {
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new MediaFailure('unsupported_artwork', 'Only PNG and JPEG raster artwork can be processed.');
        }
        $dimensions = @getimagesize($source);
        $limit = config('media.max_artwork_dimension');
        if (! $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > $limit || $dimensions[1] > $limit || ($dimensions['mime'] ?? null) !== $mime) {
            throw new MediaFailure('invalid_artwork', 'Artwork dimensions or raster format are invalid.');
        }
        $path = $workspace.'/artwork.png';
        app(BoundedMediaProcess::class)->run([
            config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-xerror', '-n', '-threads', '1', '-filter_threads', '1',
            '-protocol_whitelist', 'file,pipe', '-err_detect', 'explode', '-f', $mime === 'image/png' ? 'png_pipe' : 'jpeg_pipe', '-i', $source,
            '-map', '0:v:0', '-map_metadata', '-1', '-frames:v', '1', '-threads', '1', '-c:v', 'png', '-f', 'image2', '-update', '1', $path,
        ], $workspace);
        $actual = @getimagesize($path);
        if (! $actual || $actual[0] !== $dimensions[0] || $actual[1] !== $dimensions[1] || $actual['mime'] !== 'image/png') {
            throw new MediaFailure('invalid_artwork', 'The sanitized artwork failed verification.');
        }

        return [['role' => 'artwork', 'file' => $path, 'name' => 'artwork.png', 'mime_type' => 'image/png', 'technical_metadata' => ['width' => $actual[0], 'height' => $actual[1], 'sanitized' => true]]];
    }
}
