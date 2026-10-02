<?php

namespace Tests\Support;

use App\Domain\SiteBuilder\SiteContentSchema;

final class SiteRelatedTrackFixtures
{
    public static function content(array $blog = [], array $video = [], string $marker = 'SYNTHETIC RELATED'): array
    {
        $content = SiteEditorialFixtures::content($marker);
        $content['schema_version'] = 4;
        $content['images'] = SiteContentSchema::NO_IMAGES;
        foreach (['blog', 'videos'] as $section) {
            foreach ($content[$section]['entries'] as &$entry) {
                $entry['related_track_ids'] = [];
            }
            unset($entry);
        }
        $content['blog']['entries'][0]['related_track_ids'] = $blog;
        $content['videos']['entries'][0]['related_track_ids'] = $video;

        return $content;
    }
}
