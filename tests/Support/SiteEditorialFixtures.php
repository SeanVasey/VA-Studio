<?php

namespace Tests\Support;

use App\Domain\SiteBuilder\SiteContentSchema;

/** Synthetic editorial content; legacy bytes deliberately do not derive from current defaults. */
final class SiteEditorialFixtures
{
    public static function legacy(): array
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

    public static function content(string $marker = 'SYNTHETIC EDITORIAL'): array
    {
        $content = SiteContentSchema::forEditing(self::legacy());
        $content['hero']['title'] = $marker.' HOME';
        $content['about'] = [
            'title' => $marker.' ABOUT', 'description' => $marker.' ABOUT DESCRIPTION',
            'paragraphs' => [$marker.' ABOUT BODY'],
        ];
        $content['contact'] = [
            'title' => $marker.' CONTACT', 'description' => $marker.' CONTACT DESCRIPTION',
            'paragraphs' => [$marker.' CONTACT BODY'], 'email' => 'editorial+synthetic@example.test',
        ];
        $content['blog'] = [
            'title' => $marker.' BLOG', 'description' => $marker.' BLOG DESCRIPTION',
            'entries' => [
                ['slug' => 'first-note', 'title' => $marker.' FIRST NOTE', 'description' => $marker.' FIRST DESCRIPTION', 'paragraphs' => [$marker.' FIRST BODY']],
                ['slug' => 'second-note', 'title' => $marker.' SECOND NOTE', 'description' => $marker.' SECOND DESCRIPTION', 'paragraphs' => [$marker.' SECOND BODY']],
            ],
        ];
        $content['videos'] = [
            'title' => $marker.' VIDEOS', 'description' => $marker.' VIDEOS DESCRIPTION',
            'entries' => [
                ['slug' => 'first-film', 'title' => $marker.' FIRST FILM', 'description' => $marker.' FILM DESCRIPTION', 'provider' => 'youtube', 'video_id' => 'AbCdEfGhI_1'],
                ['slug' => 'second-film', 'title' => $marker.' SECOND FILM', 'description' => $marker.' SECOND FILM DESCRIPTION', 'provider' => 'vimeo', 'video_id' => '123456789'],
            ],
        ];
        $content['navigation'] = [
            ['label' => 'Music', 'href' => '/#catalog'], ['label' => 'About', 'href' => '/about'],
            ['label' => 'Contact', 'href' => '/contact'], ['label' => 'Journal', 'href' => '/blog'],
            ['label' => 'Videos', 'href' => '/videos'],
        ];

        return $content;
    }
}
