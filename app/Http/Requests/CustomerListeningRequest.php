<?php

namespace App\Http\Requests;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;

final class CustomerListeningRequest
{
    public static function body(Request $request): array
    {
        $raw = $request->attributes->get('_customer_body');
        try {
            $object = is_string($raw) ? json_decode($raw, false, 4, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $object = null;
        }
        if (! $object instanceof stdClass) {
            self::refuse();
        }
        // Tokenize the already-valid bounded JSON, so escaped keys cannot hide duplicates.
        // Listening commands have one object; values are scalar or lists, never nested objects.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/s', $raw, $tokens, PREG_OFFSET_CAPTURE);
        $keys = [];
        foreach ($tokens[0] as [$token, $offset]) {
            if (! str_starts_with(ltrim(substr($raw, $offset + strlen($token))), ':')) {
                continue;
            }
            $key = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
            if (isset($keys[$key])) {
                self::refuse();
            }
            $keys[$key] = true;
        }
        $body = get_object_vars($object);
        if (count($keys) !== count($body)) {
            self::refuse();
        }
        foreach ($body as $value) {
            if ($value instanceof stdClass || (is_array($value) && count(array_filter($value, 'is_string')) !== count($value))) {
                self::refuse();
            }
        }

        return $body;
    }

    private static function refuse(): never
    {
        throw ValidationException::withMessages(['library' => 'Choose a valid saved-track or playlist action.']);
    }
}
