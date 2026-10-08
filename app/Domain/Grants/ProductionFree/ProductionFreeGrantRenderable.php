<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Contracts\ContractIssuanceException;
use Throwable;

/**
 * Admission check for every field that later feeds the renderer: an immutable definition or origin whose text the
 * renderer refuses could never produce its original. The check runs the renderer's own code on a probe built by the
 * renderer's own input builder, so there is one source of truth:
 *
 * - `ProductionFreeGrantRenderInput::fromOrigin` decides which fields are rendered,
 * - `ProductionFreeGrantText::build` applies the script/mark/control repertoire (`supportedText`) to every label and value,
 * - the font glyph check is the renderer's test on the finished text (a character the repertoire admits can still
 *   have no glyph in the retained font), over the same retained font files.
 *
 * No normalization is applied: a value that is not renderable exactly as typed is refused, so what is sealed is always
 * what the person entered (decomposed accents are combining marks, which the repertoire excludes).
 */
final class ProductionFreeGrantRenderable
{
    /**
     * The renderer revision (`ProductionFreeGrantRenderProfile::RELEASED` key) whose glyph test this class was proved
     * identical to, over every Unicode scalar value (`hardening/codex-7/glyph-equivalence.txt`). A new revision must
     * repeat that comparison before this constant moves; a test fails until it does.
     */
    public const VERIFIED_REVISION = 'r1';

    /** @var array<string, array{cw:array<int,mixed>,ctgu:array<int,int>,table:string}> */
    private static array $fonts = [];

    /** @var array<int, bool> codepoint => has a glyph in both retained font styles */
    private static array $glyphs = [];

    /** Refuses `unsupported_text` before anything is written. Fields not under test keep a neutral renderable value. */
    public static function require(string $title = 'Title', string $termsReference = 'Reference', string $termsText = 'Terms',
        string $assentText = 'Assent', string $declaredName = 'Buyer Name'): void
    {
        $origin = ['provenance' => 'synthetic_rehearsal', 'origin_id' => '00000000-0000-4000-8000-000000000000',
            'accepted_at' => '2000-01-01T00:00:00Z', 'declared_name' => $declaredName, 'definition_id' => '00000000-0000-4000-8000-000000000001',
            'definition_hash' => str_repeat('a', 64), 'review_id' => '00000000-0000-4000-8000-000000000002', 'display_hash' => str_repeat('b', 64),
            'buyer_binding' => ['account_public_id' => 'probe'],
            'definition' => ['title' => $title, 'terms_reference' => $termsReference, 'terms_text' => $termsText, 'terms_hash' => hash('sha256', $termsText),
                'assent_text' => $assentText, 'assets' => [['role' => 'master_wav', 'source_id' => 'probe', 'sha256' => str_repeat('c', 64),
                    'bytes' => 1, 'mime_type' => 'audio/wav', 'filename' => 'production-free-master_wav.wav']]]];
        try {
            $document = (new ProductionFreeGrantText)->build(ProductionFreeGrantRenderInput::fromOrigin($origin));
        } catch (ContractIssuanceException) {
            throw new ProductionFreeGrantException('unsupported_text');
        }
        foreach (array_unique(mb_str_split($document['text'])) as $character) {
            if ($character !== "\n" && $character !== "\t") {
                ProductionFreeGrantException::require(self::glyph(mb_ord($character)), 'unsupported_text');
            }
        }
    }

    /** The same check over a stored definition payload (approve, open and review re-check what was sealed). */
    public static function requirePayload(array $definition): void
    {
        self::require($definition['title'], $definition['terms_reference'], $definition['terms_text'], $definition['assent_text']);
    }

    /**
     * The renderer's own test (`isCharDefined` and a non-zero glyph id from `getGidForOrd`) in both retained styles, read
     * from the same font definition and CIDToGID files, without loading Tcpdf or defining the process-wide
     * `K_PATH_FONTS` constant (other renderers in the same process define it to their own font folders).
     */
    private static function glyph(int $codepoint): bool
    {
        self::$glyphs[$codepoint] ??= self::covered('dejavusans', $codepoint) && self::covered('dejavusansb', $codepoint);

        return self::$glyphs[$codepoint];
    }

    private static function covered(string $font, int $codepoint): bool
    {
        $metric = self::font($font);
        if (! isset($metric['cw'][$codepoint])) {
            return false;
        }
        if ($codepoint > 0xFFFF) {
            return (int) ($metric['ctgu'][$codepoint] ?? 0) !== 0;
        }

        return ((ord($metric['table'][$codepoint * 2]) << 8) | ord($metric['table'][$codepoint * 2 + 1])) !== 0;
    }

    /** @return array{cw:array<int,mixed>,ctgu:array<int,int>,table:string} */
    private static function font(string $name): array
    {
        if (! isset(self::$fonts[$name])) {
            $directory = dirname(__DIR__, 4).'/resources/contracts/test-v2/fonts/generated/';
            try {
                $definition = json_decode((string) file_get_contents($directory.$name.'.json'), true, 16, JSON_THROW_ON_ERROR);
                $table = gzuncompress((string) file_get_contents($directory.$name.'.ctg.z'), 131072);
                if (! is_array($definition['cw'] ?? null) || ! is_string($table) || strlen($table) !== 131072) {
                    throw new \UnexpectedValueException;
                }
            } catch (Throwable) {
                throw new ProductionFreeGrantException('renderer_unavailable');
            }
            self::$fonts[$name] = ['cw' => $definition['cw'], 'ctgu' => is_array($definition['ctgu'] ?? null) ? $definition['ctgu'] : [], 'table' => $table];
        }

        return self::$fonts[$name];
    }
}
