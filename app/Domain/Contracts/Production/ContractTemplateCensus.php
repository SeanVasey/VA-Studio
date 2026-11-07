<?php

namespace App\Domain\Contracts\Production;

/**
 * Increment 4 template gates, shared by the `vasey-license-pdf-v1` renderer and the local placeholder census tool
 * (`scripts/contract-placeholder-census.php`).
 *
 * - Placeholder census (review P3-2): the `{NAME}` placeholders a template actually uses, diffed against
 *   {@see ContractVariableBinder::PLACEHOLDERS}, so a placeholder the binder does not know is caught before activation.
 * - Doubled symbols: a placeholder whose bound value already carries a symbol or unit must not sit next to the same
 *   symbol or unit in the template (`${PRODUCT_PRICE}` would print `$$1,500.00`). Checked on the template and again on
 *   each substitution boundary of the bound values.
 *
 * Pure: it reads only the strings it is given and never reports template text, only names, counts and line numbers.
 */
final class ContractTemplateCensus
{
    /**
     * Symbols and units each formatted placeholder already prints, and the template context that would double them.
     * `before`/`after` patterns are matched against the literal text directly around the placeholder.
     */
    public const FORMATTED = [
        'PRODUCT_PRICE' => ['before' => ['$' => '/\$\s*\z/u']],
        'PRODUCT_PRICE_WORD' => ['after' => ['dollars' => '/\A\s*(?:US\s+)?(?:dollars?|cents?)\b/iu']],
        'PUBLISHING_RIGHTS' => ['after' => ['%' => '/\A\s*(?:%|percent\b|per\s+cent\b)/iu']],
    ];

    /** Symbols a bound value may begin or end with that the template must not repeat at that boundary. */
    private const BOUNDARY_SYMBOLS = ['$', '%'];

    /**
     * The placeholders and stray braces in one template, using the binder's own tokenization.
     *
     * @return array{placeholders: array<string, int>, malformed_lines: list<int>}
     */
    public static function scan(string $template): array
    {
        $placeholders = [];
        $malformed = [];
        foreach (self::segments($template) as $segment) {
            if ($segment['placeholder'] !== null) {
                $placeholders[$segment['placeholder']] = ($placeholders[$segment['placeholder']] ?? 0) + 1;
            } elseif (($brace = strcspn($segment['text'], '{}')) < strlen($segment['text'])) {
                $malformed[] = $segment['line'] + substr_count(substr($segment['text'], 0, $brace), "\n");
            }
        }
        ksort($placeholders, SORT_STRING);

        return ['placeholders' => $placeholders, 'malformed_lines' => array_values(array_unique($malformed))];
    }

    /**
     * Doubled symbols written by the template itself.
     *
     * @return list<array{placeholder: string, symbol: string, line: int}>
     */
    public static function doubledSymbols(string $template): array
    {
        $segments = self::segments($template);
        $found = [];
        foreach ($segments as $index => $segment) {
            $rules = self::FORMATTED[$segment['placeholder'] ?? ''] ?? null;
            if ($rules === null) {
                continue;
            }
            $before = $segments[$index - 1]['text'] ?? '';
            $after = $segments[$index + 1]['text'] ?? '';
            foreach (['before' => $before, 'after' => $after] as $side => $context) {
                foreach ($rules[$side] ?? [] as $symbol => $pattern) {
                    if (preg_match($pattern, $context) === 1) {
                        $found[] = ['placeholder' => $segment['placeholder'], 'symbol' => $symbol, 'line' => $segment['line']];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Doubled symbols at the substitution boundaries of actual bound values: a value that starts with `$` after a
     * literal `$`, or ends with `%` before a literal `%` (spaces between them included). Values are taken as bound;
     * the template is tokenized exactly as {@see ContractVariableBinder::substitute} does.
     *
     * @param  array<string, string>  $values
     * @return list<array{placeholder: string, symbol: string, line: int}>
     */
    public static function doubledAtBoundaries(string $template, array $values): array
    {
        $segments = self::segments($template);
        $found = [];
        foreach ($segments as $index => $segment) {
            $value = $values[$segment['placeholder'] ?? ''] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            $before = rtrim($segments[$index - 1]['text'] ?? '', " \t\n");
            $after = ltrim($segments[$index + 1]['text'] ?? '', " \t\n");
            foreach (self::BOUNDARY_SYMBOLS as $symbol) {
                if ((str_starts_with($value, $symbol) && str_ends_with($before, $symbol))
                    || (str_ends_with($value, $symbol) && str_starts_with($after, $symbol))) {
                    $found[] = ['placeholder' => $segment['placeholder'], 'symbol' => $symbol, 'line' => $segment['line']];
                }
            }
        }

        return $found;
    }

    /**
     * Census of a template set against the binder's closed placeholder set.
     *
     * @param  array<string, string>  $templates  label => template text
     * @return array{files: array<string, array{placeholders: array<string, int>, unknown: list<string>, doubled: list<array{placeholder: string, symbol: string, line: int}>, malformed_lines: list<int>}>, used: array<string, int>, unknown: list<string>, unused: list<string>, ok: bool}
     */
    public static function census(array $templates): array
    {
        $files = [];
        $used = [];
        $ok = $templates !== [];
        foreach ($templates as $label => $template) {
            if (! is_string($template) || ! mb_check_encoding($template, 'UTF-8')) {
                $files[(string) $label] = ['placeholders' => [], 'unknown' => [], 'doubled' => [], 'malformed_lines' => [0]];
                $ok = false;

                continue;
            }
            $scan = self::scan($template);
            $unknown = array_values(array_diff(array_keys($scan['placeholders']), ContractVariableBinder::PLACEHOLDERS));
            $doubled = self::doubledSymbols($template);
            $files[(string) $label] = ['placeholders' => $scan['placeholders'], 'unknown' => $unknown, 'doubled' => $doubled,
                'malformed_lines' => $scan['malformed_lines']];
            foreach ($scan['placeholders'] as $name => $count) {
                $used[$name] = ($used[$name] ?? 0) + $count;
            }
            $ok = $ok && $unknown === [] && $doubled === [] && $scan['malformed_lines'] === [];
        }
        ksort($files, SORT_STRING);
        ksort($used, SORT_STRING);
        $unknown = array_values(array_diff(array_keys($used), ContractVariableBinder::PLACEHOLDERS));

        return ['files' => $files, 'used' => $used, 'unknown' => $unknown,
            'unused' => array_values(array_diff(ContractVariableBinder::PLACEHOLDERS, array_keys($used))), 'ok' => $ok];
    }

    /**
     * Literal text and `{NAME}` tokens in order, split by the binder's own pattern. Literal segments sit at even
     * positions, placeholders at odd ones; each carries the 1-based line it starts on.
     *
     * @return list<array{text: string, placeholder: ?string, line: int}>
     */
    private static function segments(string $template): array
    {
        $parts = preg_split('/\{([^{}]*)\}/u', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($parts)) {
            return [['text' => '{', 'placeholder' => null, 'line' => 1]];
        }
        $segments = [];
        $line = 1;
        foreach ($parts as $index => $part) {
            $placeholder = $index % 2 === 1;
            $segments[] = ['text' => $placeholder ? '' : $part, 'placeholder' => $placeholder ? $part : null, 'line' => $line];
            $line += substr_count($part, "\n");
        }

        return $segments;
    }
}
