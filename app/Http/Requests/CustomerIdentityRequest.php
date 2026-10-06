<?php

namespace App\Http\Requests;

use Illuminate\Http\Request;
use stdClass;

final class CustomerIdentityRequest
{
    public static function body(Request $request, array $fields): array
    {
        $raw = $request->attributes->get('_customer_body');
        $object = is_string($raw) ? json_decode($raw, false, 3) : null;
        abort_unless($object instanceof stdClass, 422);
        $body = get_object_vars($object);
        abort_if(count($body) !== count($fields) || array_diff(array_keys($body), $fields)
            || count(array_filter($body, 'is_string')) !== count($fields)
            || preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/s', $raw) !== count($fields) * 2, 422);

        return $body;
    }
}
