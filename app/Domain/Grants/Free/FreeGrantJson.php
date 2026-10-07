<?php

namespace App\Domain\Grants\Free;

use JsonException;
use stdClass;

/** Bounded valid-JSON token scan: every decoded object key must occur exactly once. */
final class FreeGrantJson
{
    public static function body(mixed $raw): array
    {
        if (! is_string($raw) || strlen($raw) > 65536) {
            throw new FreeGrantException(422);
        }
        try {
            $decoded = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof stdClass) {
                throw new FreeGrantException(422);
            }
            $stack = [];
            $offset = 0;
            while ($offset < strlen($raw)) {
                $character = $raw[$offset];
                if ($character === '{' || $character === '[') {
                    $stack[] = ['object' => $character === '{', 'key' => true, 'seen' => []];
                    $offset++;
                } elseif ($character === '}' || $character === ']') {
                    array_pop($stack);
                    $offset++;
                } elseif ($character === ',') {
                    if ($stack !== []) {
                        $stack[array_key_last($stack)]['key'] = true;
                    }
                    $offset++;
                } elseif ($character === '"') {
                    $start = $offset++;
                    while ($offset < strlen($raw)) {
                        $next = $raw[$offset++];
                        if ($next === '\\') {
                            $offset++;
                        } elseif ($next === '"') {
                            break;
                        }
                    }
                    $last = array_key_last($stack);
                    if ($last !== null && $stack[$last]['object'] && $stack[$last]['key']) {
                        $key = json_decode(substr($raw, $start, $offset - $start), true, 2, JSON_THROW_ON_ERROR);
                        if (isset($stack[$last]['seen'][$key])) {
                            throw new FreeGrantException(422);
                        }
                        $stack[$last]['seen'][$key] = true;
                        $stack[$last]['key'] = false;
                    }
                } else {
                    $offset++;
                }
            }

            return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FreeGrantException(422);
        }
    }
}
