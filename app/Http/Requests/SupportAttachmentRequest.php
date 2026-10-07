<?php

namespace App\Http\Requests;

use App\Domain\SupportAttachments\AttachmentException;
use Illuminate\Http\Request;

final class SupportAttachmentRequest
{
    public static function command(Request $request, bool $process): array
    {
        $raw = $request->attributes->get('_support_attachment_body');
        AttachmentException::require(is_string($raw) && strlen($raw) <= 512, 422);
        try {
            $object = json_decode($raw, false, 3, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new AttachmentException(422);
        }
        AttachmentException::require($object instanceof \stdClass, 422);
        $body = get_object_vars($object);
        $wanted = $process ? ['attempt', 'sourceVersion'] : [];
        $keys = array_keys($body);
        sort($keys);
        AttachmentException::require($keys === $wanted && count(array_filter($body, fn ($value) => is_int($value) && $value >= 0 && $value <= 2147483647)) === count($wanted), 422);
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"\s*:/s', $raw, $matches);
        $seen = [];
        foreach ($matches[0] as $key) {
            $decoded = json_decode(rtrim(trim($key), ':'), true, 2, JSON_THROW_ON_ERROR);
            AttachmentException::require(! isset($seen[$decoded]), 422);
            $seen[$decoded] = true;
        }
        AttachmentException::require(count($seen) === count($wanted), 422);

        return $body;
    }

    public static function upload(Request $request): array
    {
        $version = $request->header('X-Source-Version');
        $key = $request->header('X-Request-Key');
        $encoded = $request->header('X-Attachment-Name');
        AttachmentException::require(is_string($version) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $version) === 1 && (float) $version <= 2147483647
            && is_string($key) && is_string($encoded) && strlen($encoded) <= 640 && preg_match('/\A[A-Za-z0-9+\/=]+\z/D', $encoded) === 1, 422);
        $name = base64_decode($encoded, true);
        AttachmentException::require(is_string($name) && base64_encode($name) === $encoded, 422);

        return ['sourceVersion' => (int) $version, 'requestKey' => $key, 'name' => $name];
    }
}
