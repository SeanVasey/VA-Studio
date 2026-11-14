<?php

namespace Tests\Support;

use App\Domain\Rights\Models\LicenseVersion;
use App\Models\User;

/** NONBINDING, synthetic tier limits only. Never the seller's production tiers or defaults. */
final class TieredLicenseFixtures
{
    public const USAGE_VARIABLES = [
        'usage.audio_releases', 'usage.copies_downloads', 'usage.audio_streams', 'usage.monetized_video_streams',
        'usage.non_monetized_video_streams', 'usage.music_videos', 'usage.radio_stations', 'usage.free_downloads',
        'usage.for_profit_performances', 'usage.not_for_profit_performances',
    ];

    public static function terms(): array
    {
        return array_replace(EconomicLicenseFixtures::terms(), [
            'schema_version' => 5,
            'usage' => [
                'audio_releases' => ['mode' => 'limited', 'limit' => 2],
                'copies_downloads' => ['mode' => 'limited', 'limit' => 1500],
                'audio_streams' => ['mode' => 'limited', 'limit' => 250000],
                'monetized_video_streams' => ['mode' => 'limited', 'limit' => 50000],
                'non_monetized_video_streams' => ['mode' => 'unlimited'],
                'music_videos' => ['mode' => 'limited', 'limit' => 1],
                'radio_stations' => ['mode' => 'prohibited'],
                'free_downloads' => ['mode' => 'limited', 'limit' => 3000],
                'for_profit_performances' => ['mode' => 'prohibited'],
                'not_for_profit_performances' => ['mode' => 'unlimited'],
            ],
        ]);
    }

    public static function source(): string
    {
        $usage = implode("\n", array_map(fn (string $key) => '{{'.$key.'}}', self::USAGE_VARIABLES));
        $rest = substr(EconomicLicenseFixtures::source(), strpos(EconomicLicenseFixtures::source(), '{{permissions.content_id}}'));

        return "SYNTHETIC NONBINDING REVIEW FIXTURE. Not an actual license.\n".$usage."\n".$rest;
    }

    public static function draft(?User $actor = null): LicenseVersion
    {
        return LicenseFixtures::draft($actor, self::terms(), ['authored_source' => self::source()]);
    }
}
