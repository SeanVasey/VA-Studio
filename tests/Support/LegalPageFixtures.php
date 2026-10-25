<?php

namespace Tests\Support;

use App\Domain\SiteBuilder\SiteContentSchema;

/** Synthetic legal text only: never the unapproved drafts in docs/legal. */
final class LegalPageFixtures
{
    public const APPROVAL = 'SYNTHETIC APPROVAL RECORD 0001';

    public static function document(string $marker = 'SYNTHETIC LEGAL', string $title = 'Privacy policy'): array
    {
        return [
            'title' => $marker.' '.$title, 'description' => $marker.' DESCRIPTION',
            'version' => '1', 'effective_date' => '2026-11-01', 'approval_reference' => $marker.' '.self::APPROVAL,
            'sections' => [
                ['heading' => $marker.' FIRST HEADING', 'paragraphs' => [$marker.' FIRST PARAGRAPH', "Second line\nwith a line break."]],
                ['heading' => $marker.' SECOND HEADING', 'paragraphs' => [$marker.' SECOND PARAGRAPH']],
            ],
        ];
    }

    /** Version 5: version 4 editorial content, no images, empty related tracks and the requested legal slots. */
    public static function content(string $marker = 'SYNTHETIC LEGAL', array $documents = ['privacy']): array
    {
        $content = SiteEditorialFixtures::content($marker);
        foreach (['blog', 'videos'] as $section) {
            foreach ($content[$section]['entries'] as $index => $entry) {
                $content[$section]['entries'][$index]['related_track_ids'] = [];
            }
        }
        $titles = ['privacy' => 'Privacy policy', 'terms' => 'Terms', 'licenses' => 'License comparison'];
        $legal = SiteContentSchema::NO_LEGAL;
        foreach ($documents as $document) {
            $legal[$document] = self::document($marker, $titles[$document]);
        }

        return ['schema_version' => 5] + $content + ['images' => SiteContentSchema::NO_IMAGES, 'legal' => $legal];
    }
}
