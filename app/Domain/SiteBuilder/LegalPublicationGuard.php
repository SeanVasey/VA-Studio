<?php

namespace App\Domain\SiteBuilder;

use App\Support\Environment\TestEnvironment;

/**
 * A release may not become public while a legal page still shows drafting material. The marker classes are the
 * conventions of the drafts in docs/legal ("Text still in square brackets is a placeholder or a choice that must be filled
 * before publication"); this guard only detects them and never fills, removes or rewrites anything.
 */
final class LegalPublicationGuard
{
    /** Reason code of every refusal, in the staff message, the scheduler result and the schedule audit event. */
    public const REASON = 'legal_unresolved_markers';

    /** Counsel has not cleared the wording: "[For legal review]" (TERMS sections 15 to 17, LICENSE-TIERS 4.14). */
    public const LEGAL_REVIEW = 'legal_review_marker';

    /** A choice still with the owner: "[county — Sean to confirm]", "Sean to choose", "Sean to set". */
    public const OWNER_DECISION = 'owner_decision';

    /** A value entered when the page is published: "[set on publication]", "[… — inserted at publication]". */
    public const PUBLICATION_VALUE = 'publication_value';

    /** Draft framing that is removed before publication: the status banner and the reviewer appendix. */
    public const DRAFT_NOTICE = 'draft_notice';

    /** An unsubstituted license source variable such as "{{deliverables}}" (LICENSE-TIERS 6.3). */
    public const TEMPLATE_VARIABLE = 'template_variable';

    /** Any other square-bracketed text, such as "[Tier name]", and any unpaired square bracket. */
    public const BRACKETED_PLACEHOLDER = 'bracketed_placeholder';

    /**
     * A license comparison typed as sections (schema 5). DD-31: the comparison is generated from the published license
     * versions (schema 6), so typed comparison text, which can drift from what is sold, is never made public.
     */
    public const HAND_TYPED_COMPARISON = 'hand_typed_license_comparison';

    /**
     * DD-33 staging stand-in text: the "STAGING PLACEHOLDER — not in effect" banner and "set at production" values. It is
     * admitted only where the application is local, testing or staging (stagingPlaceholderAdmitted()) and refuses publication
     * everywhere else, production included, under the same reason code as every other marker.
     */
    public const STAGING_PLACEHOLDER = 'staging_placeholder';

    public const LABELS = [
        self::STAGING_PLACEHOLDER => 'staging placeholder',
        self::LEGAL_REVIEW => 'legal-review marker',
        self::OWNER_DECISION => 'undecided owner choice',
        self::PUBLICATION_VALUE => 'value still to be entered at publication',
        self::DRAFT_NOTICE => 'draft notice',
        self::TEMPLATE_VARIABLE => 'unsubstituted template variable',
        self::BRACKETED_PLACEHOLDER => 'square-bracket placeholder',
        self::HAND_TYPED_COMPARISON => 'hand-typed license comparison; the comparison is now generated from the published license versions',
    ];

    /** Phrase classes in precedence order; they apply inside and outside square brackets. */
    private const PHRASES = [
        self::STAGING_PLACEHOLDER => '/\bSTAGING\s+PLACEHOLDER\b|\bset\s+at\s+production\b/iu',
        self::LEGAL_REVIEW => '/\bfor\s+legal\s+review\b/iu',
        self::OWNER_DECISION => '/\bSean\s+to\s+(?:confirm|choose|decide|set)\b/iu',
        self::PUBLICATION_VALUE => '/\b(?:set|inserted|entered)\s+(?:on|at)\s+publication\b/iu',
        self::DRAFT_NOTICE => '/\bnot\s+yet\s+published\b|\bremoved?\s+before\s+publication\b|\breviewer\s+appendix\b|\bdraft\s+version\s+label\b|\bdraft\s+for\b[^.\n]{0,80}\b(?:review|approval)\b/iu',
        self::TEMPLATE_VARIABLE => '/\{\{[A-Za-z][A-Za-z0-9_.]*\}\}/u',
    ];

    private const MESSAGE_FINDINGS = 8;

    /**
     * Every unresolved marker in the public text of the legal pages: title, description, headings and paragraphs. The
     * version and effective date cannot hold one (SiteContentSchema), and the approval reference is never public. A license
     * comparison that still holds typed sections is one finding of its own, reported before the markers in its text.
     *
     * @return list<array{document: string, field: string, class: string, excerpt: string}>
     */
    public static function findings(array $content): array
    {
        $stagingAdmitted = self::stagingPlaceholderAdmitted();
        $legal = is_array($content['legal'] ?? null) ? $content['legal'] : [];
        $findings = [];
        foreach (SiteContentSchema::LEGAL_DOCUMENTS as $document) {
            $page = $legal[$document] ?? null;
            if (! is_array($page)) {
                continue;
            }
            if ($document === 'licenses' && array_key_exists('sections', $page)) {
                $count = is_array($page['sections']) ? count($page['sections']) : 0;
                $findings[] = ['document' => $document, 'field' => 'sections', 'class' => self::HAND_TYPED_COMPARISON,
                    'excerpt' => $count.' typed '.($count === 1 ? 'section' : 'sections')];
            }
            $fields = ['title' => $page['title'] ?? null, 'description' => $page['description'] ?? null];
            foreach (is_array($page['sections'] ?? null) ? $page['sections'] : [] as $index => $section) {
                $fields["sections.{$index}.heading"] = $section['heading'] ?? null;
                foreach (is_array($section['paragraphs'] ?? null) ? $section['paragraphs'] : [] as $position => $paragraph) {
                    $fields["sections.{$index}.paragraphs.{$position}"] = $paragraph;
                }
            }
            foreach ($fields as $field => $text) {
                foreach (is_string($text) ? self::scan($text) : [] as [$class, $excerpt]) {
                    if ($class === self::STAGING_PLACEHOLDER && $stagingAdmitted) {
                        continue;
                    }
                    $findings[] = ['document' => $document, 'field' => $field, 'class' => $class, 'excerpt' => $excerpt];
                }
            }
        }

        return $findings;
    }

    /**
     * Whether staging placeholder text may stand: local, testing or staging by exact name, and only when the configured and
     * the running environment agree. Production, an unknown name and a disagreement all refuse it (the pattern of
     * StagingSellerTag::admitted()).
     */
    public static function stagingPlaceholderAdmitted(): bool
    {
        $configured = config('app.env');

        return is_string($configured) && $configured === app()->environment() && ! app()->isProduction()
            && TestEnvironment::admitsTestCommerce();
    }

    public static function assertPublishable(array $content): void
    {
        $findings = self::findings($content);
        if ($findings !== []) {
            throw LegalPublicationRefused::because($findings);
        }
    }

    /** One staff sentence naming the reason code, the count and the first markers with their place. */
    public static function describe(array $findings): string
    {
        $count = count($findings);
        $listed = array_map(fn (array $finding): string => self::place($finding).': '.self::LABELS[$finding['class']]
            .' “'.$finding['excerpt'].'” ('.$finding['class'].')', array_slice($findings, 0, self::MESSAGE_FINDINGS));

        return 'The legal pages still hold '.$count.' unresolved '.($count === 1 ? 'marker' : 'markers').' ('.self::REASON.'). '
            .implode('; ', $listed).($count > self::MESSAGE_FINDINGS ? '; and '.($count - self::MESSAGE_FINDINGS).' more' : '')
            .'. Save a new draft with the approved final text; nothing was published.';
    }

    /**
     * Compact evidence for the audit trail: counts by page and class, never the page text.
     *
     * @return array<string, int>
     */
    public static function summary(array $findings): array
    {
        $summary = [];
        foreach ($findings as $finding) {
            $key = $finding['document'].'.'.$finding['class'];
            $summary[$key] = ($summary[$key] ?? 0) + 1;
        }
        ksort($summary, SORT_STRING);

        return $summary;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function scan(string $text): array
    {
        $found = [];
        // Bracketed spans first, each counted once under its most specific class.
        $rest = preg_replace_callback('/\[[^\[\]]*\]/u', function (array $match) use (&$found): string {
            $found[] = [self::phraseClass($match[0]) ?? self::BRACKETED_PLACEHOLDER, self::excerpt($match[0])];

            return ' ';
        }, $text) ?? $text;
        foreach (self::PHRASES as $class => $pattern) {
            if (preg_match_all($pattern, $rest, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $found[] = [$class, self::excerpt($match)];
                }
            }
        }
        // A bracket left over after the paired spans has lost its partner, for example across two paragraphs.
        if (preg_match_all('/[\[\]]/u', $rest, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $found[] = [self::BRACKETED_PLACEHOLDER, $match];
            }
        }

        return $found;
    }

    private static function phraseClass(string $text): ?string
    {
        foreach (self::PHRASES as $class => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $class;
            }
        }

        return null;
    }

    private static function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 79).'…' : $text;
    }

    private static function place(array $finding): string
    {
        $page = ['privacy' => 'Privacy policy', 'terms' => 'Terms', 'licenses' => 'License comparison'][$finding['document']];
        if (preg_match('/\Asections\.(\d+)\.(heading|paragraphs\.(\d+))\z/', $finding['field'], $match) === 1) {
            return $page.', section '.((int) $match[1] + 1).(isset($match[3]) ? ' paragraph '.((int) $match[3] + 1) : ' heading');
        }
        if ($finding['field'] === 'sections') {
            return $page.' sections';
        }

        return $page.' '.$finding['field'];
    }
}
