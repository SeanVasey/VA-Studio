<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Rights\SoundKitLicenseKind;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The `vasey-kit-license-text-v1` document for one kit purchase: the sound-kit license version's frozen contract text with
 * its six placeholders substituted in one pass from {@see SoundKitLicenseBinder}'s output, split into the layout blocks of
 * `vasey-license-text-v1` (same grammar: the first block is the title, a one-line all-uppercase block is a heading, every
 * other block a paragraph that keeps its line breaks), plus the acceptance block built outside the text.
 *
 * The text must declare exactly {@see SoundKitLicenseKind::PLACEHOLDERS}; any other, missing or malformed placeholder
 * refuses. Blocks are classified on the text before substitution, so a purchase value can never turn a paragraph into a
 * heading, and substituted values are never scanned again.
 *
 * Refusals are {@see ContractBindingException} reasons: `malformed_template`, `unknown_placeholder`, `missing_value`,
 * `doubled_symbol`, `unsupported_input` and `unsupported_characters`.
 */
final readonly class SoundKitLicenseDocument
{
    public const TEMPLATE_BYTES = 1048576;

    private const HEADING_CHARACTERS = 120;

    /** What `{LICENSE_FEE}` already prints (`$25.00`): a currency symbol, code or word before it, or a dollar word after it, doubles it. */
    private const FEE_BEFORE = ['/\p{Sc}\s*\z/u', '/\b(?:USD|US|EUR|GBP|CAD|AUD)\s*\z/u', '/\b(?:dollars?|cents?)\s*\z/iu'];

    private const FEE_AFTER = '/\A\s*(?:US\s+)?(?:dollars?|cents?)\b/iu';

    /**
     * @param  list<array{type: string, text: string}>  $blocks
     * @param  array{licensor: string, licensee: string, representative_line: null, date: string}  $acceptance
     */
    private function __construct(
        public array $blocks,
        public array $acceptance,
        public int $timestamp,
        public string $title,
        public string $inputHash,
        public string $text,
    ) {}

    /**
     * @param  string  $template  The kit license version's frozen contract text (`{NAME}` placeholders).
     * @param  array<string, string>  $values  {@see SoundKitLicenseBinder::bind} output for the purchase.
     * @param  ContractGlyphCoverage  $coverage  The profile's own faces.
     */
    public static function build(string $template, array $values, ContractGlyphCoverage $coverage): self
    {
        ContractBindingException::require($template !== '' && strlen($template) <= self::TEMPLATE_BYTES && mb_check_encoding($template, 'UTF-8')
            && preg_match('/[\x00-\x09\x0b-\x1f\x7f]/', $template) !== 1, 'malformed_template');
        ContractBindingException::require(array_keys($values) === SoundKitLicenseKind::PLACEHOLDERS, 'unsupported_input');
        foreach ($values as $name => $value) {
            ContractBindingException::require(is_string($value) && $value !== '' && mb_check_encoding($value, 'UTF-8'), 'missing_value', $name);
            ContractBindingException::require(preg_match('/[\x00-\x1f\x7f{}]/', $value) !== 1, 'unsupported_input', $name);
            ContractBindingException::require($coverage->covers($value), 'unsupported_characters', $name);
        }

        $segments = preg_split('/\{([^{}]*)\}/u', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        ContractBindingException::require(is_array($segments), 'malformed_template');
        $declared = [];
        foreach ($segments as $index => $segment) {
            if ($index % 2 === 0) {
                ContractBindingException::require(! str_contains($segment, '{') && ! str_contains($segment, '}'), 'malformed_template');
            } else {
                ContractBindingException::require(in_array($segment, SoundKitLicenseKind::PLACEHOLDERS, true), 'unknown_placeholder',
                    preg_match('/\A[A-Z0-9_]{1,64}\z/', $segment) === 1 ? $segment : null);
                $declared[$segment] = true;
            }
        }
        foreach (SoundKitLicenseKind::PLACEHOLDERS as $name) {
            ContractBindingException::require(isset($declared[$name]), 'missing_value', $name);
        }
        self::refuseDoubled($segments, $values);

        $date = DateTimeImmutable::createFromFormat('!F j, Y', $values['PURCHASE_DATE'], new DateTimeZone('UTC'));
        ContractBindingException::require($date !== false && $date->format('F j, Y') === $values['PURCHASE_DATE'], 'unsupported_input', 'PURCHASE_DATE');

        $blocks = self::blocks($template, $values);
        ContractBindingException::require($blocks !== [], 'malformed_template');
        $acceptance = ['licensor' => $values['LICENSOR_NAME'], 'licensee' => $values['LICENSEE_NAME'], 'representative_line' => null, 'date' => $values['PURCHASE_DATE']];
        $text = implode("\n", array_column($blocks, 'text'))."\n".implode("\n", array_filter($acceptance, is_string(...)));

        return new self($blocks, $acceptance, $date->getTimestamp(), 'Sound kit license: '.$values['KIT_NAME'],
            CanonicalJson::hash(['template_sha256' => hash('sha256', $template), 'values' => $values]), $text);
    }

    /**
     * A currency symbol, code or word directly before `{LICENSE_FEE}` or a dollar word directly after it, and a `$` repeated
     * at a substitution boundary, would print the symbol twice.
     *
     * @param  list<string>  $segments  literal text at even positions, placeholder names at odd ones
     * @param  array<string, string>  $values
     */
    private static function refuseDoubled(array $segments, array $values): void
    {
        foreach ($segments as $index => $name) {
            if ($index % 2 === 0) {
                continue;
            }
            $before = $segments[$index - 1] ?? '';
            $after = $segments[$index + 1] ?? '';
            if ($name === 'LICENSE_FEE') {
                foreach (self::FEE_BEFORE as $pattern) {
                    ContractBindingException::require(preg_match($pattern, $before) !== 1, 'doubled_symbol', $name);
                }
                ContractBindingException::require(preg_match(self::FEE_AFTER, $after) !== 1, 'doubled_symbol', $name);
            }
            $value = $values[$name];
            ContractBindingException::require(! (str_starts_with($value, '$') && str_ends_with(rtrim($before, " \t\n"), '$')), 'doubled_symbol', $name);
        }
    }

    /**
     * @param  array<string, string>  $values
     * @return list<array{type: string, text: string}>
     */
    private static function blocks(string $template, array $values): array
    {
        $blocks = [];
        foreach (preg_split('/\n[ ]*\n+/', trim($template, "\n ")) ?: [] as $raw) {
            $lines = array_map(static fn (string $line): string => trim($line, ' '), explode("\n", trim($raw, "\n")));
            $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
            if ($lines === []) {
                continue;
            }
            $source = implode("\n", $lines);
            $literal = (string) preg_replace('/\{[^{}]*\}/u', '', $source);
            $type = match (true) {
                $blocks === [] => 'title',
                count($lines) === 1 && mb_strlen($source) <= self::HEADING_CHARACTERS
                    && preg_match('/\p{L}/u', $literal) === 1 && mb_strtoupper($literal) === $literal => 'heading',
                default => 'paragraph',
            };
            $blocks[] = ['type' => $type, 'text' => self::substitute($source, $values)];
        }

        return $blocks;
    }

    /** @param  array<string, string>  $values */
    private static function substitute(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{([^{}]*)\}/u', static fn (array $match): string => $values[$match[1]], $text);
    }
}
