<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\RenderFit\FaceMetrics;
use App\Domain\Contracts\RenderFit\LineFit;
use App\Domain\Contracts\RenderFit\PdfTextGlyphs;
use App\Domain\Contracts\RenderFit\RenderFitException;
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
 * - the renderer's HTML cell breaks a token wider than its 180 mm column at the column but does not paginate the lines that
 *   makes, so a long enough token is drawn below the page (the PDF holds the glyphs; no reader shows them: BL-72). The
 *   preflight therefore always renders text holding such a token, and every preflight render is checked against the
 *   finished PDF's own text runs: every inked character drawn in order, no run below the page or past the column.
 *
 * No normalization is applied: a value that is not renderable exactly as typed is refused, so what is sealed is always
 * what the person entered (decomposed accents are combining marks, which the repertoire excludes).
 */
final class ProductionFreeGrantRenderable
{
    /**
     * The renderer revision (`ProductionFreeGrantRenderProfile::RELEASED` key) whose glyph test this class was proved
     * identical to, over every Unicode scalar value (`hardening/codex-7/glyph-equivalence.txt`). A new revision must
     * repeat that comparison before this constant moves; a test fails until it does. Repeated for r2 on 2026-11-09
     * (`docs/verification/render-cache-hardening-20261109/glyph-equivalence-r2.txt`: 1,112,032 checked, 0 differences).
     * Repeated for r3 on 2026-11-10 (`docs/verification/tc-lib-update-20261110/glyph-equivalence-r3.txt`, committed script beside it:
     * 1,112,032 checked, 0 differences). Repeated for r4 on 2026-11-14 (`docs/verification/tc-lib-update-20261114/glyph-equivalence-r4.txt`,
     * committed script beside it: 1,112,032 checked, 0 differences).
     */
    public const VERIFIED_REVISION = 'r4';

    /**
     * Characters (newlines included) at or below which the preflight render is skipped. Wrapping never places fewer than
     * one character on a line, so this is a worst-case bound, not an estimate: 2,000 lines plus the fixed document lines
     * stay near a third of the 100-page limit (about 70 lines a page). A bytes-per-line estimate is not a bound; long
     * unbreakable tokens defeated it (A6-1, `review-evidence/addendum6/`).
     */
    private const PREFLIGHT_SKIP_CHARACTERS = 2000;

    /** The renderer's HTML cell: `addHTMLCell(posx: 15, width: 180)` at 10 pt (`ProductionFreeGrantPdfRenderer`). */
    private const COLUMN_LEFT_MM = 15.0;

    private const COLUMN_WIDTH_MM = 180.0;

    private const FONT_SIZE_PT = 10.0;

    /** The renderer sets labels in resource `F1` (bold) and values in `F2` (regular): it inserts `['B', '']` in that order. */
    private const RESOURCE_FONTS = ['F1' => 'dejavusansb', 'F2' => 'dejavusans'];

    /** @var array<string, array{cw:array<int,mixed>,dw:float,ctgu:array<int,int>,table:string}> */
    private static array $fonts = [];

    /** @var array<string, array<int, int|float>> glyph id => advance (1/1000 em), by font */
    private static array $advances = [];

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

    /**
     * Worst-case render of the definition with the real pinned renderer (the container's `ProductionFreeGrantRendererProcess`,
     * as `render()` uses) and the longest allowed declared name, so a definition whose terms exceed the renderer's page or
     * output limits is refused before it can be approved and assented. Only text too short to reach those limits even at
     * one character per line skips the render.
     * Must run outside a database transaction, like the renderer itself.
     *
     * @param  array{title:string,terms_reference:string,terms_text:string,assent_text:string,assets:list<array>}  $definition
     */
    public static function preflight(array $definition, array $profile): void
    {
        $text = $definition['title']."\n".$definition['terms_reference']."\n".$definition['terms_text']."\n".$definition['assent_text'];
        if (mb_strlen($text) <= self::PREFLIGHT_SKIP_CHARACTERS && ! self::hasOverwideToken($text)) {
            return;
        }
        $origin = ['provenance' => $profile['provenance'], 'origin_id' => '00000000-0000-4000-8000-000000000000', 'accepted_at' => '2000-01-01T00:00:00Z',
            'declared_name' => str_repeat('W', 120), 'definition_id' => '00000000-0000-4000-8000-000000000001', 'definition_hash' => str_repeat('a', 64),
            'review_id' => '00000000-0000-4000-8000-000000000002', 'display_hash' => str_repeat('b', 64),
            'buyer_binding' => ['account_public_id' => str_repeat('p', 36)], 'definition' => ['title' => $definition['title'],
                'terms_reference' => $definition['terms_reference'], 'terms_text' => $definition['terms_text'], 'terms_hash' => hash('sha256', $definition['terms_text']),
                'assent_text' => $definition['assent_text'], 'assets' => $definition['assets']]];
        try {
            $input = ProductionFreeGrantRenderInput::fromOrigin($origin);
            $rendered = app(ProductionFreeGrantRendererProcess::class)->render($input, $profile);
            self::requireDrawn($rendered->pdfBytes, (new ProductionFreeGrantText)->build($input)['text']);
        } catch (Throwable) {
            throw new ProductionFreeGrantException('unrenderable_definition');
        }
    }

    /**
     * True when a token (text between spaces and line breaks) is wider than the renderer's column. The HTML cell breaks such
     * a token at the column, and the lines it makes are not paginated: a long enough token is drawn below the page, where
     * nothing shows it (BL-72). Short text is skipped by the render bound above only because wrapping puts at least one
     * character on a line; a token it must break does not honor that bound, so it is always rendered and checked.
     */
    private static function hasOverwideToken(string $text): bool
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
        $limit = self::COLUMN_WIDTH_MM - LineFit::TOLERANCE_MM;

        return LineFit::widest(self::metrics('dejavusans'), self::FONT_SIZE_PT, $text) > $limit;
    }

    /**
     * Checks a finished original against the text it was rendered from: every non-blank character of `$text` is drawn,
     * in order, in the face that sets it, and no text run ends past the column. The check behind the preflight render.
     *
     * @throws RenderFitException
     */
    public static function requireDrawn(string $pdf, string $text): void
    {
        $right = (self::COLUMN_LEFT_MM + self::COLUMN_WIDTH_MM) * 72 / 25.4 + 0.05;
        $space = ['dejavusans' => self::gid('dejavusans', 32), 'dejavusansb' => self::gid('dejavusansb', 32)];
        $expected = array_values(array_filter(mb_str_split($text), static fn (string $character): bool => ! in_array($character, [' ', "\n", "\t"], true)));
        $next = 0;
        foreach (PdfTextGlyphs::runs($pdf) as $run) {
            $font = self::RESOURCE_FONTS[$run['font']] ?? throw new RenderFitException('text run', 'a font the renderer does not set');
            $advance = 0.0;
            $inked = 0.0;
            foreach ($run['glyphs'] as $index => $glyph) {
                $advance += ($run['shifts'][$index] ?? 0.0) + self::advance($font, $glyph);
                if ($glyph === $space[$font]) {
                    continue;
                }
                // A space that ends a line hangs past the column without printing anything.
                $inked = $advance;
                $character = $expected[$next++] ?? null;
                if ($character === null || self::gid($font, mb_ord($character)) !== $glyph) {
                    throw new RenderFitException('text run', 'a drawn glyph is not the next character of the text');
                }
            }
            // The HTML cell does not paginate a token it had to break: the lines fall below the page, drawn but never shown.
            if ($run['y'] < 0) {
                throw new RenderFitException('text run', 'a run is drawn below the page');
            }
            if ($right < $run['x'] + $inked * $run['size'] / 1000) {
                throw new RenderFitException('text run', 'a run ends past the column');
            }
        }
        if ($next !== count($expected)) {
            throw new RenderFitException('text', 'characters of the text are not drawn');
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

    private static function metrics(string $name): FaceMetrics
    {
        $font = self::font($name);

        return FaceMetrics::fromTable($font['cw'], $font['dw']);
    }

    /** The glyph id the retained font gives a codepoint (0 when it has none). */
    private static function gid(string $font, int $codepoint): int
    {
        $metric = self::font($font);
        if ($codepoint > 0xFFFF) {
            return (int) ($metric['ctgu'][$codepoint] ?? 0);
        }

        return (ord($metric['table'][$codepoint * 2]) << 8) | ord($metric['table'][$codepoint * 2 + 1]);
    }

    /** The advance of a glyph id in 1/1000 em, the first codepoint in the width table that maps to it. */
    private static function advance(string $font, int $glyph): int|float
    {
        if (! isset(self::$advances[$font])) {
            $advances = [];
            foreach (self::font($font)['cw'] as $codepoint => $width) {
                $advances += [self::gid($font, (int) $codepoint) => $width];
            }
            self::$advances[$font] = $advances;
        }

        return self::$advances[$font][$glyph] ?? self::font($font)['dw'];
    }

    /** @return array{cw:array<int,mixed>,dw:float,ctgu:array<int,int>,table:string} */
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
            self::$fonts[$name] = ['cw' => $definition['cw'], 'dw' => (float) ($definition['dw'] ?? 0), 'ctgu' => is_array($definition['ctgu'] ?? null) ? $definition['ctgu'] : [], 'table' => $table];
        }

        return self::$fonts[$name];
    }
}
