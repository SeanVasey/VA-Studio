<?php

namespace App\Domain\SiteBuilder;

use InvalidArgumentException;

/**
 * Turns the owner-approved drafts in docs/legal (PRIVACY.md, TERMS.md) into the legal page content of a STAGING draft
 * release (DD-33, ledger PCL-07). It reads the drafts and never writes them.
 *
 * Every square-bracketed span in the drafts is either resolved by an entry of REPLACEMENTS, which holds only the values
 * DD-33 (and the decisions it points to) fixed for staging, or the build refuses. The builder never composes an address, a
 * legal name, a county, a provider or a date of its own: where the owner has not decided, the page reads "set at production".
 * Output is plain text in the shape SiteContentSchema accepts for a legal page.
 */
final class StagingLegalRelease
{
    public const BANNER = 'STAGING PLACEHOLDER — not in effect';

    public const UNSET = 'set at production';

    public const VERSION = 'staging-draft';

    /** Shown in the page description, where the schema wants a short summary. */
    private const DESCRIPTIONS = [
        'privacy' => self::BANNER.'. Staging rehearsal copy of the privacy policy draft.',
        'terms' => self::BANNER.'. Staging rehearsal copy of the terms of sale and use draft.',
    ];

    public const SOURCES = ['privacy' => 'docs/legal/PRIVACY.md', 'terms' => 'docs/legal/TERMS.md'];

    /**
     * The draft's exact bracketed spans, and the draft's production provider and region wording, with what staging shows
     * instead, applied in this order. Values: DD-33 for the effective date, postal address, backup and email providers
     * ("set at production"), storage (the server's private disk) and region (the United States); hosting (DigitalOcean), the
     * minimum age (DD-34) and the venue county (SQ-07, Polk County, Iowa) are filled in the drafts themselves since 2026-11-14;
     * DD-35 for the DMCA agent sentence (removed); the legal-review
     * tags are dropped from the headings because DD-36 keeps production publication behind counsel anyway.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        'Postal address: [registered postal address — inserted at publication]' => 'Postal address: '.self::UNSET,
        '[registered postal address — inserted at publication]' => 'Postal address: '.self::UNSET,
        '[set on publication]' => self::UNSET,
        // The drafts carry the production dates since 2026-11-14 (Sean: effective November 15, 2026); staging pages are not in effect.
        '**Effective date:** November 15, 2026' => '**Effective date:** '.self::UNSET,
        '**Last updated:** November 15, 2026' => '**Last updated:** '.self::UNSET,
        // The drafts now carry the production values (SQ-07, SQ-08, SQ-09, SQ-01, DD-34); staging shows its own DD-33 facts.
        '**File storage provider**: **Cloudflare R2**' => '**File storage provider**: our own server\'s private disk',
        '**Backup storage**: **Backblaze B2**' => '**Backup storage**: '.self::UNSET,
        '**Email delivery provider**: **Postmark**' => '**Email delivery provider**: '.self::UNSET,
        'located in the United States (New York)' => 'located in the United States',
        ' [Sean to decide whether to register a DMCA designated agent with the U.S. Copyright Office.]' => '',
        ' [For legal review]' => '',
    ];

    /** Where staging placeholder text may be built: the same admission as the guard's staging_placeholder class. */
    public static function admitted(): bool
    {
        return LegalPublicationGuard::stagingPlaceholderAdmitted();
    }

    /**
     * @return array{title: string, description: string, version: string, effective_date: string, approval_reference: string, sections: list<array{heading: string, paragraphs: list<string>}>}
     */
    public static function page(string $document, string $markdown, string $effectiveDate): array
    {
        if (! isset(self::DESCRIPTIONS[$document])) {
            throw new InvalidArgumentException('Unknown legal document.');
        }
        $parsed = self::parse($markdown);

        return [
            'title' => $parsed['title'],
            'description' => self::DESCRIPTIONS[$document],
            'version' => self::VERSION,
            // The schema requires a real date. It is the build date, not an effective date: the page text reads "set at production".
            'effective_date' => $effectiveDate,
            'approval_reference' => self::BANNER.'; no approval; built from '.self::SOURCES[$document].' sha256:'.hash('sha256', $markdown),
            'sections' => $parsed['sections'],
        ];
    }

    /** @return array{title: string, sections: list<array{heading: string, paragraphs: list<string>}>} */
    private static function parse(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        // The reviewer appendix and everything after it (the change log) are for Sean and the reviewer.
        $cut = preg_match('/^## Reviewer appendix/m', $markdown, $match, PREG_OFFSET_CAPTURE);
        if ($cut !== 1) {
            throw new InvalidArgumentException('The draft has no reviewer appendix to remove; refusing to guess where the public text ends.');
        }
        $body = substr($markdown, 0, $match[0][1]);
        // The status blockquote at the top is draft framing.
        $body = (string) preg_replace('/^>.*\n?/m', '', $body);
        $body = (string) preg_replace('/\[([^\]\n]+)\]\(([^)\n]+)\)/', '$1', $body);
        $body = strtr($body, self::REPLACEMENTS);
        if (preg_match('/[\[\]]/u', $body, $left) === 1) {
            preg_match('/.{0,40}[\[\]].{0,40}/u', $body, $near);
            throw new InvalidArgumentException('A square bracket is left after substitution ('.trim($near[0] ?? '').'); the builder writes no value DD-33 did not fix.');
        }

        $title = null;
        $sections = [['heading' => self::BANNER, 'paragraphs' => [
            self::BANNER.'. This page is a staging rehearsal. The effective date, postal address, backup provider and email provider read "'.self::UNSET.'".',
        ]]];
        $pending = null;
        $paragraph = [];
        $table = null;
        $flush = function () use (&$paragraph, &$sections): void {
            if ($paragraph !== []) {
                $sections[array_key_last($sections)]['paragraphs'][] = self::inline(implode("\n", $paragraph));
                $paragraph = [];
            }
        };
        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^(#{1,3}) +(.+?) *$/', $line, $heading) === 1) {
                $flush();
                $table = null;
                $text = self::inline($heading[2]);
                if (strlen($heading[1]) === 1) {
                    $title = $text;

                    continue;
                }
                if ($sections[array_key_last($sections)]['paragraphs'] === [] && $pending === null && array_key_last($sections) > 0) {
                    // A heading with no text of its own (for example "3. What we collect" over "3.1 ...") joins the next one.
                    $pending = array_pop($sections)['heading'];
                }
                $sections[] = ['heading' => ($pending !== null ? $pending.' — ' : '').$text, 'paragraphs' => []];
                $pending = null;

                continue;
            }
            if (trim($line) === '' || preg_match('/^-{3,}\s*$/', $line) === 1) {
                $flush();
                $table = null;

                continue;
            }
            if (preg_match('/^\s*\|(.*)\|\s*$/', $line, $row) === 1) {
                $flush();
                $cells = array_map(fn (string $cell): string => self::inline(trim($cell)), explode('|', $row[1]));
                if (preg_match('/^\s*\|[\s:|-]+\|\s*$/', $line) === 1) {
                    continue;
                }
                if ($table === null) {
                    $table = $cells;

                    continue;
                }
                $pairs = [];
                foreach ($cells as $position => $cell) {
                    if ($cell !== '') {
                        $pairs[] = ($table[$position] ?? '').': '.$cell;
                    }
                }
                $sections[array_key_last($sections)]['paragraphs'][] = implode('; ', $pairs);

                continue;
            }
            if (preg_match('/^\s*[-*] +(.+)$/', $line, $item) === 1) {
                $flush();
                $sections[array_key_last($sections)]['paragraphs'][] = self::inline($item[1]);

                continue;
            }
            $paragraph[] = trim($line);
        }
        $flush();
        // A heading left without text at the end of the draft has nothing to show.
        if ($sections[array_key_last($sections)]['paragraphs'] === []) {
            array_pop($sections);
        }
        if ($title === null) {
            throw new InvalidArgumentException('The draft has no title heading.');
        }

        return ['title' => $title, 'sections' => $sections];
    }

    /** Plain text only: emphasis marks and code ticks are removed, and nothing else is rewritten. */
    private static function inline(string $text): string
    {
        $text = (string) preg_replace('/\*\*(.+?)\*\*/su', '$1', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/su', '$1', $text);

        return trim(str_replace('`', '', $text));
    }
}
