<?php

namespace App\Http\Requests;

use App\Domain\Commerce\Orders\OrderRequest;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Small exact transport contracts. Do not use input merging, validation redirects or flashed input for secrets. */
final class TestDeliveryRequest
{
    public static function issuance(Request $request): array
    {
        self::mediaType($request, 'application/json');
        $raw = self::body($request);
        try { $value = json_decode($raw, false, 4, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new HttpException(422); }
        if (! $value instanceof stdClass) { throw new HttpException(422); }
        $body = get_object_vars($value);
        if (count($body) !== 2 || array_diff(array_keys($body), ['grantId', 'kind']) !== []
            || ! is_string($body['grantId'] ?? null) || ! OrderRequest::uuid($body['grantId'])
            || ! is_string($body['kind'] ?? null) || ! in_array($body['kind'], ['contract', 'master_wav', 'download_mp3', 'stems_zip'], true)
            // Both allowed string values are colon-free. A third colon is another member (including a duplicate), never valid data.
            || substr_count($raw, ':') !== 2) { throw new HttpException(422); }
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! OrderRequest::uuid($key)) { throw new HttpException(422); }
        return $body + ['idempotencyKey' => $key];
    }

    public static function download(Request $request): array
    {
        self::mediaType($request, 'application/x-www-form-urlencoded');
        $body = [];
        foreach (explode('&', self::body($request)) as $field) {
            if (substr_count($field, '=') !== 1 || preg_match('/%(?![0-9a-fA-F]{2})/', $field)) { throw new HttpException(422); }
            [$key, $value] = array_map('urldecode', explode('=', $field, 2));
            if (! in_array($key, ['authorizationId', 'token', '_token'], true) || array_key_exists($key, $body)) { throw new HttpException(422); }
            $body[$key] = $value;
        }
        if (count($body) !== 3 || ! OrderRequest::uuid($body['authorizationId'] ?? '')
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $body['token'] ?? '') !== 1
            || preg_match('/\A[A-Za-z0-9]{40}\z/D', $body['_token'] ?? '') !== 1) { throw new HttpException(422); }
        return $body;
    }

    private static function mediaType(Request $request, string $type): void
    {
        $value = $request->header('Content-Type', '');
        if (preg_match('~\A'.preg_quote($type, '~').'(?:\s*;\s*charset=(?:utf-8|"utf-8"))?\z~iD', $value) !== 1) {
            throw new HttpException(415);
        }
    }

    private static function body(Request $request): string
    {
        $body = $request->attributes->get('_test_delivery_body');
        if (! is_string($body) || strlen($body) > 4096) { throw new HttpException(503); }
        return $body;
    }
}
