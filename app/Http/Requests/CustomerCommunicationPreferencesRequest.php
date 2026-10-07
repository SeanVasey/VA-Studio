<?php

namespace App\Http\Requests;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;

final class CustomerCommunicationPreferencesRequest
{
    public static function body(Request $request): array
    {
        $raw = $request->attributes->get('_customer_body');
        $object = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, false, 3) : null;
        if (! $object instanceof stdClass) {
            self::refuse();
        }
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/s', $raw, $tokens, PREG_OFFSET_CAPTURE);
        $seen = [];
        foreach ($tokens[0] as [$token, $offset]) {
            if (! str_starts_with(ltrim(substr($raw, $offset + strlen($token))), ':')) {
                continue;
            }
            $key = json_decode($token, true);
            if (isset($seen[$key])) {
                self::refuse();
            }
            $seen[$key] = true;
        }
        $body = get_object_vars($object);
        if (count($seen) !== count($body)) {
            self::refuse();
        }
        foreach ($body as $value) {
            if (is_object($value) || is_array($value)) {
                self::refuse();
            }
        }

        return $body;
    }

    private static function refuse(): never
    {
        throw ValidationException::withMessages(['preferences' => 'Choose a valid communication preference action.']);
    }
}
