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

    public static function validate(array $content): array
    {
        if (in_array($content['schema_version'] ?? null, [2, 3], true)) {
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
        $rules = [
            'content' => ['required', 'array:schema_version,hero,studio,footer,navigation,seo,about,contact,blog,videos'.($version === 3 ? ',images' : '')],
            'content.schema_version' => ['required', 'integer', Rule::in([2, 3])],
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
                    ? 'slug,title,description,paragraphs' : 'slug,title,description,provider,video_id')];
                $rules[$prefix.'.entries.*.slug'] = ['required', 'string', 'max:80', 'distinct:strict', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'];
                $rules[$prefix.'.entries.*.title'] = $text(120);
                $rules[$prefix.'.entries.*.description'] = $text(300);
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
        if ($version === 3) {
            $rules += self::imageRules($content, $text);
        }
        if (($content['contact'] ?? null) !== null) {
            $rules['content.contact.email'] = ['required', 'string', 'max:254', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || preg_match('/[^\x21-\x7E]/', $value) !== 0 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $fail('Use one valid ASCII email address without spaces or mail header parameters.');
                }
            }];
        }
        Validator::make(['content' => $content], $rules)->validate();

        return $content;
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
