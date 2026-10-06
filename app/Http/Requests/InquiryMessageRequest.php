<?php

namespace App\Http\Requests;

use App\Domain\Inquiries\InquiryConversation;
use App\Domain\Inquiries\InquiryException;
use Illuminate\Http\Request;
use JsonException;
use stdClass;

final class InquiryMessageRequest
{
    public static function body(Request $request): array
    {
        $raw = $request->attributes->get('_inquiry_body');
        if (! is_string($raw)) {
            throw new InquiryException(503);
        }
        try {
            $object = json_decode($raw, false, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InquiryException(422);
        }
        if (! $object instanceof stdClass) {
            throw new InquiryException(422);
        }
        $body = get_object_vars($object);
        if (count($body) !== 2 || array_diff(array_keys($body), ['message', 'requestKey']) !== [] || count(array_filter($body, 'is_string')) !== 2) {
            throw new InquiryException(422);
        }
        // Scan the bounded, already-valid string-only object without regex recursion on long messages.
        $keys = [];
        $offset = 0;
        self::whitespace($raw, $offset);
        $offset++; // Opening brace.
        while ($offset < strlen($raw)) {
            self::whitespace($raw, $offset);
            if ($raw[$offset] === '}') {
                break;
            }
            $key = json_decode(self::stringToken($raw, $offset), true, 2, JSON_THROW_ON_ERROR);
            if (isset($keys[$key])) {
                throw new InquiryException(422);
            }
            $keys[$key] = true;
            self::whitespace($raw, $offset);
            $offset++; // Colon; json_decode already validated the grammar and string-only values.
            self::whitespace($raw, $offset);
            self::stringToken($raw, $offset);
            self::whitespace($raw, $offset);
            if ($raw[$offset] === '}') {
                break;
            }
            $offset++; // Comma.
        }
        if (count($keys) !== 2) {
            throw new InquiryException(422);
        }

        return InquiryConversation::validate($body);
    }

    private static function whitespace(string $raw, int &$offset): void
    {
        while ($offset < strlen($raw) && str_contains(" \t\r\n", $raw[$offset])) {
            $offset++;
        }
    }

    private static function stringToken(string $raw, int &$offset): string
    {
        $start = $offset++;
        while ($offset < strlen($raw)) {
            $character = $raw[$offset++];
            if ($character === '\\') {
                $offset++;
            } elseif ($character === '"') {
                return substr($raw, $start, $offset - $start);
            }
        }
        throw new InquiryException(422);
    }
}
