<?php

namespace App\Domain\SiteBuilder;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Bounded plain text only. Identity, artwork, commerce and license claims are not editable here. */
final class SiteContentSchema
{
    public static function defaults(): array
    {
        return [
            'schema_version' => 1,
            'hero' => [
                'eyebrow' => 'INDEPENDENT SOUND. DISTINCT IDENTITY.',
                'title' => 'SOUND', 'line_two' => 'WITH INTENT.',
                'description' => "Beats with character. Sound with depth.\nOriginal music and production by Sean Vasey.",
            ],
            'studio' => [
                'eyebrow' => '03 / BEHIND THE SOUND', 'title' => 'CRAFT FIRST.', 'line_two' => 'ALWAYS.',
                'lead' => 'From the first note to the last detail.',
                'paragraphs' => [
                    'Sean Vasey brings over two decades of composition, music production, and sound design to a practice shaped by hip-hop, classical music, and the space between them.',
                    'Original beats. Bespoke composition. Detailed sonic worlds. Built with intention, for artists with something to say.',
                ],
            ],
            'footer' => ['description' => "Independent sound.\nA studio/VASEY venture."],
            'navigation' => [
                ['label' => 'The catalog', 'href' => '/#catalog'],
                ['label' => 'Licensing', 'href' => '/#licenses'],
                ['label' => 'The studio', 'href' => '/#studio'],
            ],
            'seo' => [
                'title' => 'VASEY.AUDIO — Sound with intent',
                'description' => 'Original music, beats and sound design by Sean Vasey. Explore the VASEY.AUDIO catalog and listen to published previews.',
            ],
        ];
    }

    /** A v3 release starts with every image slot on its built-in image. */
    public const NO_IMAGES = ['hero' => null, 'studio' => null, 'share' => null];

    /** Schema versions carrying the optional editorial pages (D-23). */
    public const EDITORIAL_VERSIONS = [2, 3, 4, 5];

    /** Schema versions carrying site image references (D-25). */
    public const IMAGE_VERSIONS = [3, 4, 5];

    /** Schema versions carrying related-track identities on blog and video entries. */
    public const RELATED_TRACK_VERSIONS = [4, 5];

    /** Version 5 adds the legal pages; a release without legal text keeps an older version. */
    public const LEGAL_VERSION = 5;

    /** Legal page slots and their public paths under /legal/. */
    public const LEGAL_DOCUMENTS = ['privacy', 'terms', 'licenses'];

    public const NO_LEGAL = ['privacy' => null, 'terms' => null, 'licenses' => null];

    /** Characters across one legal document's headings and paragraphs; the 2026-10-24 privacy draft is about 30,000. */
    public const LEGAL_DOCUMENT_MAX_CHARACTERS = 120000;

    public static function validate(array $content): array
    {
        if (in_array($content['schema_version'] ?? null, self::EDITORIAL_VERSIONS, true)) {
            return self::validateEditorial($content);
        }
        $text = fn (int $max): array => ['required', 'string', 'max:'.$max, function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && (trim($value) === '' || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) !== 0)) {
                $fail('Use nonempty plain text without markup or control characters.');
            }
        }];
        Validator::make(['content' => $content], [
            'content' => ['required', 'array:schema_version,hero,studio,footer,navigation,seo'],
            'content.schema_version' => ['required', 'integer', Rule::in([1])],
            'content.hero' => ['required', 'array:eyebrow,title,line_two,description'],
            'content.hero.eyebrow' => $text(120), 'content.hero.title' => $text(80),
            'content.hero.line_two' => $text(80), 'content.hero.description' => $text(600),
            'content.studio' => ['required', 'array:eyebrow,title,line_two,lead,paragraphs'],
            'content.studio.eyebrow' => $text(120), 'content.studio.title' => $text(80),
            'content.studio.line_two' => $text(80), 'content.studio.lead' => $text(240),
            'content.studio.paragraphs' => ['required', 'array', 'list', 'min:1', 'max:4'],
            'content.studio.paragraphs.*' => $text(1500),
            'content.footer' => ['required', 'array:description'], 'content.footer.description' => $text(300),
            'content.navigation' => ['required', 'array', 'list', 'min:1', 'max:4'],
            'content.navigation.*' => ['required', 'array:label,href'],
            'content.navigation.*.label' => $text(48),
            'content.navigation.*.href' => ['required', 'string', 'distinct:strict', Rule::in(['/', '/#catalog', '/#licenses', '/#studio'])],
            'content.seo' => ['required', 'array:title,description'],
            'content.seo.title' => $text(120), 'content.seo.description' => $text(300),
        ])->validate();
        if (($content['schema_version'] ?? null) !== 1) {
            throw ValidationException::withMessages(['content.schema_version' => 'Use the integer content schema version 1.']);
        }

        return $content;
    }

    /**
     * Upgrade an editor copy only; retained releases and their hashes never change. Version 3 adds only image references, so a copy
     * without images stays version 2 and older application code can still read it.
     */
    public static function forEditing(array $content): array
    {
        $content = self::validate($content);

        return $content['schema_version'] !== 1 ? $content : array_replace($content, [
            'schema_version' => 2, 'about' => null, 'contact' => null, 'blog' => null, 'videos' => null,
        ]);
    }

    private static function validateEditorial(array $content): array
    {
        $text = fn (int $max): array => ['required', 'string', 'max:'.$max, function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && (trim($value) === '' || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) !== 0)) {
                $fail('Use nonempty plain text without markup or control characters.');
            }
        }];
        $destinations = ['/', '/#catalog', '/#licenses', '/#studio'];
        foreach (['about', 'contact', 'blog', 'videos'] as $section) {
            if (is_array($content[$section] ?? null)) {
                $destinations[] = '/'.$section;
            }
        }
        $version = $content['schema_version'];
        if ($version === self::LEGAL_VERSION) {
            foreach (self::LEGAL_DOCUMENTS as $document) {
                if (is_array($content['legal'][$document] ?? null)) {
                    $destinations[] = '/legal/'.$document;
                }
            }
        }
        $rules = [
            'content' => ['required', 'array:schema_version,hero,studio,footer,navigation,seo,about,contact,blog,videos'
                .(in_array($version, self::IMAGE_VERSIONS, true) ? ',images' : '').($version === self::LEGAL_VERSION ? ',legal' : '')],
            'content.schema_version' => ['required', 'integer', Rule::in(self::EDITORIAL_VERSIONS)],
            'content.hero' => ['required', 'array:eyebrow,title,line_two,description'],
            'content.hero.eyebrow' => $text(120), 'content.hero.title' => $text(80),
            'content.hero.line_two' => $text(80), 'content.hero.description' => $text(600),
            'content.studio' => ['required', 'array:eyebrow,title,line_two,lead,paragraphs'],
            'content.studio.eyebrow' => $text(120), 'content.studio.title' => $text(80),
            'content.studio.line_two' => $text(80), 'content.studio.lead' => $text(240),
            'content.studio.paragraphs' => ['required', 'array', 'list', 'min:1', 'max:4'],
            'content.studio.paragraphs.*' => $text(1500),
            'content.footer' => ['required', 'array:description'], 'content.footer.description' => $text(300),
            'content.navigation' => ['required', 'array', 'list', 'min:1', 'max:8'],
            'content.navigation.*' => ['required', 'array:label,href'],
            'content.navigation.*.label' => $text(48),
            'content.navigation.*.href' => ['required', 'string', 'distinct:strict', Rule::in($destinations)],
            'content.seo' => ['required', 'array:title,description'],
            'content.seo.title' => $text(120), 'content.seo.description' => $text(300),
        ];
        foreach (['about', 'contact', 'blog', 'videos'] as $section) {
            $prefix = 'content.'.$section;
            $keys = match ($section) {
                'about' => 'title,description,paragraphs',
                'contact' => 'title,description,paragraphs,email',
                default => 'title,description,entries',
            };
            $rules[$prefix] = ['present', 'nullable', 'array:'.$keys];
            if (($content[$section] ?? null) === null) {
                continue;
            }
            $rules[$prefix.'.title'] = $text(120);
            $rules[$prefix.'.description'] = $text(300);
            if (in_array($section, ['about', 'contact'], true)) {
                $rules[$prefix.'.paragraphs'] = ['required', 'array', 'list', 'min:1', 'max:12'];
                $rules[$prefix.'.paragraphs.*'] = $text(1500);
            } else {
                $rules[$prefix.'.entries'] = ['required', 'array', 'list', 'min:1', 'max:30'];
                $rules[$prefix.'.entries.*'] = ['required', 'array:'.($section === 'blog'
                    ? 'slug,title,description,paragraphs' : 'slug,title,description,provider,video_id').(in_array($version, self::RELATED_TRACK_VERSIONS, true) ? ',related_track_ids' : '')];
                $rules[$prefix.'.entries.*.slug'] = ['required', 'string', 'max:80', 'distinct:strict', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'];
                $rules[$prefix.'.entries.*.title'] = $text(120);
                $rules[$prefix.'.entries.*.description'] = $text(300);
                if (in_array($version, self::RELATED_TRACK_VERSIONS, true)) {
                    $rules[$prefix.'.entries.*.related_track_ids'] = ['present', 'array', 'list', 'max:6'];
                    // Distinctness belongs to each entry, not to the entire wildcard expansion: an article and a video
                    // may deliberately link the same track. Empty lists are valid; strings are never retained as IDs.
                    foreach (is_array($content[$section]['entries'] ?? null) ? $content[$section]['entries'] : [] as $index => $entry) {
                        $path = $prefix.'.entries.'.$index.'.related_track_ids';
                        $rules[$path.'.*'] = ['required', 'distinct:strict', function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! is_int($value) || $value < 1) {
                                $fail('Choose a positive integer track identifier.');
                            }
                        }];
                    }
                }
                if ($section === 'blog') {
                    $rules[$prefix.'.entries.*.paragraphs'] = ['required', 'array', 'list', 'min:1', 'max:12'];
                    $rules[$prefix.'.entries.*.paragraphs.*'] = $text(1500);
                } else {
                    $rules[$prefix.'.entries.*.provider'] = ['required', 'string', Rule::in(['youtube', 'vimeo'])];
                    $rules[$prefix.'.entries.*.video_id'] = ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($content): void {
                        $provider = data_get(['content' => $content], str_replace('.video_id', '.provider', $attribute));
                        $pattern = $provider === 'youtube' ? '/^[A-Za-z0-9_-]{11}$/D' : '/^[1-9][0-9]{0,11}$/D';
                        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
                            $fail('Use the exact video identifier for the selected provider, without a URL.');
                        }
                    }];
                }
            }
        }
        if (in_array($version, self::IMAGE_VERSIONS, true)) {
            $rules += self::imageRules($content, $text);
        }
        if ($version === self::LEGAL_VERSION) {
            $rules += self::legalRules($content, $text);
        }
        if (($content['contact'] ?? null) !== null) {
            $rules['content.contact.email'] = ['required', 'string', 'max:254', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || preg_match('/[^\x21-\x7E]/', $value) !== 0 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $fail('Use one valid ASCII email address without spaces or mail header parameters.');
                }
            }];
        }
        Validator::make(['content' => $content], $rules)->validate();
        if ($version === self::LEGAL_VERSION && array_filter($content['legal'], 'is_array') === []) {
            // A release without legal text keeps the older schema, so older application code can still read it.
            throw ValidationException::withMessages(['content.legal' => 'Include at least one legal page, or save the release without legal pages.']);
        }

        return $content;
    }

    /**
     * Legal pages carry staff-pasted approved text as bounded plain text: headings and paragraphs only, no markup or links.
     * The approval reference is retained staff evidence and is never part of the public projection (EditorialContent).
     */
    private static function legalRules(array $content, \Closure $text): array
    {
        $rules = ['content.legal' => ['required', 'array:'.implode(',', self::LEGAL_DOCUMENTS)]];
        $legal = is_array($content['legal'] ?? null) ? $content['legal'] : [];
        foreach (self::LEGAL_DOCUMENTS as $document) {
            $prefix = 'content.legal.'.$document;
            $rules[$prefix] = ['present', 'nullable', 'array:title,description,version,effective_date,approval_reference,sections'];
            if (! is_array($legal[$document] ?? null)) {
                continue;
            }
            $rules += [
                $prefix.'.title' => $text(120),
                $prefix.'.description' => $text(300),
                // A short token such as "1", "2026-10" or "v1.2"; it names the approved text, not a schema.
                $prefix.'.version' => ['required', 'string', 'max:32', 'regex:/\A[0-9A-Za-z](?:[0-9A-Za-z.-]{0,30}[0-9A-Za-z])?\z/D'],
                $prefix.'.effective_date' => ['required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', function (string $attribute, mixed $value, \Closure $fail): void {
                    $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC')) : false;
                    if ($date === false || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 2000 || (int) $date->format('Y') > 2100) {
                        $fail('Use a real effective date as YYYY-MM-DD.');
                    }
                }],
                $prefix.'.approval_reference' => $text(200),
                $prefix.'.sections' => ['required', 'array', 'list', 'min:1', 'max:60', function (string $attribute, mixed $value, \Closure $fail): void {
                    $characters = 0;
                    foreach (is_array($value) ? $value : [] as $section) {
                        $characters += is_string($section['heading'] ?? null) ? mb_strlen($section['heading']) : 0;
                        foreach (is_array($section['paragraphs'] ?? null) ? $section['paragraphs'] : [] as $paragraph) {
                            $characters += is_string($paragraph) ? mb_strlen($paragraph) : 0;
                        }
                    }
                    if ($characters > self::LEGAL_DOCUMENT_MAX_CHARACTERS) {
                        $fail('Keep each legal page within '.number_format(self::LEGAL_DOCUMENT_MAX_CHARACTERS).' characters.');
                    }
                }],
                $prefix.'.sections.*' => ['required', 'array:heading,paragraphs'],
                $prefix.'.sections.*.heading' => $text(160),
                $prefix.'.sections.*.paragraphs' => ['required', 'array', 'list', 'min:1', 'max:40'],
                $prefix.'.sections.*.paragraphs.*' => $text(4000),
            ];
        }

        return $rules;
    }

    /**
     * Each slot holds a reference or null for the built-in image; the two hero images are set together. References name the
     * image and pin its manifest; whether the image exists and is ready is checked by SiteImageReferences, not here.
     */
    private static function imageRules(array $content, \Closure $text): array
    {
        $reference = fn (string $prefix): array => [
            $prefix.'.id' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value) || $value < 1) {
                    $fail('Choose an uploaded site image.');
                }
            }],
            $prefix.'.manifest' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
        ];
        $images = is_array($content['images'] ?? null) ? $content['images'] : [];
        $rules = [
            'content.images' => ['present', 'array:hero,studio,share'],
            'content.images.hero' => ['present', 'nullable', 'array:desktop,mobile,alt'],
            'content.images.studio' => ['present', 'nullable', 'array:id,manifest,alt'],
            'content.images.share' => ['present', 'nullable', 'array:id,manifest,alt'],
        ];
        if (is_array($images['hero'] ?? null)) {
            $rules += [
                'content.images.hero.desktop' => ['required', 'array:id,manifest'],
                'content.images.hero.mobile' => ['required', 'array:id,manifest'],
                'content.images.hero.alt' => $text(200),
            ] + $reference('content.images.hero.desktop') + $reference('content.images.hero.mobile');
        }
        foreach (['studio', 'share'] as $slot) {
            if (is_array($images[$slot] ?? null)) {
                $rules += ["content.images.{$slot}.alt" => $text(200)] + $reference("content.images.{$slot}");
            }
        }

        return $rules;
    }
}
