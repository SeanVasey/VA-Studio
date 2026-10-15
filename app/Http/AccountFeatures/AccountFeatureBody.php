<?php

namespace App\Http\AccountFeatures;

use Illuminate\Http\Request;

/** Bounded JSON syntax pass preserves duplicate keys before PHP object decoding discards them. */
final class AccountFeatureBody
{
    private int $position = 0;

    private int $nodes = 2048;

    private function __construct(private readonly string $raw) {}

    public static function read(Request $request): array
    {
        try {
            $raw = $request->attributes->get('_production_feature_body');
            abort_unless(is_string($raw) && strlen($raw) <= 16384, 422);
            $parser = new self($raw);
            $parser->value(0);
            $parser->space();
            abort_unless($parser->position === strlen($raw), 422);
            $object = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            abort_unless($object instanceof \stdClass, 422);

            return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422);
        }
    }

    private function space(): void
    {
        while (isset($this->raw[$this->position]) && str_contains(" \r\n\t", $this->raw[$this->position])) {
            $this->position++;
        }
    }

    private function string(): string
    {
        $this->space();
        abort_unless(($this->raw[$this->position] ?? '') === '"', 422);
        $start = $this->position++;
        while (isset($this->raw[$this->position])) {
            $character = $this->raw[$this->position++];
            if ($character === '\\') {
                $this->position++;
            } elseif ($character === '"') {
                return json_decode(substr($this->raw, $start, $this->position - $start), true, 2, JSON_THROW_ON_ERROR);
            }
        }
        abort(422);
    }

    private function value(int $depth): void
    {
        abort_if(--$this->nodes < 0 || $depth > 12, 422);
        $this->space();
        $character = $this->raw[$this->position] ?? '';
        if ($character === '"') {
            $this->string();

            return;
        }
        if ($character === '{' || $character === '[') {
            $object = $character === '{';
            $end = $object ? '}' : ']';
            $this->position++;
            $this->space();
            $keys = [];
            if (($this->raw[$this->position] ?? '') === $end) {
                $this->position++;

                return;
            }
            do {
                if ($object) {
                    $key = $this->string();
                    abort_if(array_key_exists($key, $keys), 422);
                    $keys[$key] = true;
                    $this->space();
                    abort_unless(($this->raw[$this->position++] ?? '') === ':', 422);
                }
                $this->value($depth + 1);
                $this->space();
                $delimiter = $this->raw[$this->position++] ?? '';
                if ($delimiter === $end) {
                    return;
                }
                abort_unless($delimiter === ',', 422);
            } while (true);
        }
        abort_unless(preg_match('/\G(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/A', $this->raw, $match, 0, $this->position) === 1, 422);
        $this->position += strlen($match[0]);
    }
}
