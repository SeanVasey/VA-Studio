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

    /** The schema 6 license comparison frame: a title and an introduction; the comparison is generated (DD-31). */
    public static function comparison(string $marker = 'SYNTHETIC LEGAL'): array
    {
        return ['title' => $marker.' License comparison', 'description' => $marker.' DESCRIPTION'];
    }

    /**
     * Version 4 editorial content, no images, empty related tracks and the requested legal slots: version 6 when the license
     * comparison is requested (its generated frame), otherwise version 5.
     */
    public static function content(string $marker = 'SYNTHETIC LEGAL', array $documents = ['privacy']): array
    {
        $content = self::handTyped($marker, array_values(array_diff($documents, ['licenses'])));
        if (! in_array('licenses', $documents, true)) {
            return $content;
        }
        $content['legal']['licenses'] = self::comparison($marker);

        return ['schema_version' => SiteContentSchema::GENERATED_LICENSES_VERSION] + $content;
    }

    /** Version 5 exactly as it was authored before DD-31, a hand-typed license comparison included when requested. */
    public static function handTyped(string $marker = 'SYNTHETIC LEGAL', array $documents = ['privacy']): array
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
