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

    public static function validate(array $content): array
    {
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
}
