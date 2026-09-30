<?php

namespace App\Domain\SiteBuilder;

/** Plain-language explanations of site image failure codes, shared by upload errors and the image list. */
final class SiteImageProblem
{
    public const MESSAGES = [
        'scanner_unavailable' => 'The malware scanner was unavailable. Retry once it is running.',
        'scanner_signatures_stale' => 'The malware scanner’s signatures are out of date. Update them, then retry.',
        'tool_unavailable' => 'An image tool is missing on the server. Retry once it is installed.',
        'processor_timeout' => 'Processing took too long. Retry.',
        'storage_failed' => 'Private storage was unavailable. Retry.',
        'unsafe_storage' => 'Private storage is misconfigured. Fix it, then retry.',
        'missing_source' => 'The stored upload could not be read. Retry, or upload it again.',
        'processing_interrupted' => 'Processing was interrupted. Retry.',
        'scan_not_clean' => 'The malware scan did not return a clean result. This file cannot be used.',
        'source_changed' => 'The upload changed after it was received. Upload it again.',
        'invalid_image' => 'The image could not be read, or it no longer matched its size check. Export it again and upload a new copy.',
        'invalid_artwork' => 'The image could not be re-encoded. Export it again and upload a new copy.',
        'rotated_image' => 'The image relies on a rotation tag. Rotate it in an editor, export it again and upload the new copy.',
        'oversized_metadata' => 'The image carries more embedded camera data than can be checked. Export it again without metadata and upload the new copy.',
        'transparent_image' => 'The image has transparency or an alpha channel. Export it without transparency and upload it again.',
        'unsupported_depth' => 'The image is more than 8 bits per channel. Export an 8-bit JPEG or PNG.',
        'unsupported_pixel_format' => 'The image uses an unsupported colour format, such as CMYK. Export an 8-bit RGB or grayscale JPEG or PNG.',
        'processor_failed' => 'The image could not be processed. Export it again and upload a new copy.',
        'processor_output_limit' => 'The image could not be processed. Export it again and upload a new copy.',
        'unsafe_path' => 'The stored upload failed a storage safety check. Upload it again.',
        'invalid_size' => 'The stored upload is empty or too large. Upload it again.',
    ];

    public static function describe(?string $code): string
    {
        return $code === null ? '' : (self::MESSAGES[$code] ?? 'Processing failed.');
    }
}
