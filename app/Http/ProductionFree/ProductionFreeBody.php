<?php

namespace App\Http\ProductionFree;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;

/**
 * Bounded request bodies for the family 256 mount. JSON gets a syntax pass that refuses duplicate object keys before
 * PHP's decoder would silently keep the last one (as `AccountFeatureBody`); the one native form (redemption) admits
 * exactly `token` and `_token`, each once (as `PaidTaxForm`). The bearer token travels only in a body, never a URL.
 */
final class ProductionFreeBody
{
    public const JSON_LIMIT = 4096;

    public const FORM_LIMIT = 4096;

    private int $position = 0;

    private int $nodes = 512;

    private function __construct(private readonly string $raw) {}

    /** @return array<string, mixed> */
    public static function json(string $raw): array
    {
        ProductionFreeGrantException::require(strlen($raw) <= self::JSON_LIMIT, 'invalid_input');
        try {
            $parser = new self($raw);
            $parser->value(0);
            $parser->space();
            ProductionFreeGrantException::require($parser->position === strlen($raw), 'invalid_input');
            $object = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            ProductionFreeGrantException::require($object instanceof \stdClass, 'invalid_input');

            return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProductionFreeGrantException('invalid_input');
        }
    }

    /** @return array{token: string, _token: string} */
    public static function form(string $raw): array
    {
        ProductionFreeGrantException::require(strlen($raw) <= self::FORM_LIMIT && $raw !== '', 'invalid_input');
        $body = [];
        foreach (explode('&', $raw) as $field) {
            ProductionFreeGrantException::require(substr_count($field, '=') === 1 && preg_match('/%(?![0-9a-fA-F]{2})/', $field) !== 1, 'invalid_input');
            [$key, $value] = array_map('urldecode', explode('=', $field, 2));
            ProductionFreeGrantException::require(in_array($key, ['token', '_token'], true) && ! array_key_exists($key, $body), 'invalid_input');
            $body[$key] = $value;
        }
        ProductionFreeGrantException::require(count($body) === 2 && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $body['token'] ?? '') === 1
            && preg_match('/\A[A-Za-z0-9]{40}\z/D', $body['_token'] ?? '') === 1, 'invalid_input');

        return ['token' => $body['token'], '_token' => $body['_token']];
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
        ProductionFreeGrantException::require(($this->raw[$this->position] ?? '') === '"', 'invalid_input');
        $start = $this->position++;
        while (isset($this->raw[$this->position])) {
            $character = $this->raw[$this->position++];
            if ($character === '\\') {
                $this->position++;
            } elseif ($character === '"') {
                return json_decode(substr($this->raw, $start, $this->position - $start), true, 2, JSON_THROW_ON_ERROR);
            }
        }
        throw new ProductionFreeGrantException('invalid_input');
    }

    private function value(int $depth): void
    {
        ProductionFreeGrantException::require(--$this->nodes >= 0 && $depth <= 8, 'invalid_input');
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
                    ProductionFreeGrantException::require(! array_key_exists($key, $keys), 'invalid_input');
                    $keys[$key] = true;
                    $this->space();
                    ProductionFreeGrantException::require(($this->raw[$this->position++] ?? '') === ':', 'invalid_input');
                }
                $this->value($depth + 1);
                $this->space();
                $delimiter = $this->raw[$this->position++] ?? '';
                if ($delimiter === $end) {
                    return;
                }
                ProductionFreeGrantException::require($delimiter === ',', 'invalid_input');
            } while (true);
        }
        ProductionFreeGrantException::require(preg_match('/\G(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/A', $this->raw, $match, 0, $this->position) === 1, 'invalid_input');
        $this->position += strlen($match[0]);
    }
}
