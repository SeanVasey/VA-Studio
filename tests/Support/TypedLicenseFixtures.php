<?php

namespace Tests\Support;

use App\Domain\Rights\Models\LicenseVersion;
use App\Models\User;

/** Deliberately synthetic, nonbinding policy values; never production defaults. */
final class TypedLicenseFixtures
{
    public static function terms(): array
    {
        return [
            'schema_version' => 2, 'required_asset_roles' => ['master_wav'],
            'usage' => [
                'audio_releases' => ['mode' => 'limited', 'limit' => 2],
                'copies_downloads' => ['mode' => 'limited', 'limit' => 1500],
                'monetized_streams' => ['mode' => 'limited', 'limit' => 250000],
                'non_monetized_streams' => ['mode' => 'unlimited'],
                'music_videos' => ['mode' => 'limited', 'limit' => 1],
                'live_performances' => ['mode' => 'unlimited'],
                'radio_stations' => ['mode' => 'prohibited'],
            ],
            'permissions' => ['content_id' => 'prohibited', 'paid_advertising' => 'permitted', 'sublicensing' => 'prohibited', 'standalone_resale' => 'prohibited'],
            'credit' => ['mode' => 'required', 'text' => 'SYNTHETIC PRODUCER'],
        ];
    }

    public static function source(): string
    {
        return <<<'SOURCE'
SYNTHETIC NONBINDING REVIEW FIXTURE. Not an actual license.
{{usage.audio_releases}}
{{usage.copies_downloads}}
{{usage.monetized_streams}}
{{usage.non_monetized_streams}}
{{usage.music_videos}}
{{usage.live_performances}}
{{usage.radio_stations}}
{{permissions.content_id}}
{{permissions.paid_advertising}}
{{permissions.sublicensing}}
{{permissions.standalone_resale}}
{{credit}}
{{deliverables}}
SOURCE;
    }

    public static function draft(?User $actor = null): LicenseVersion
    {
        return LicenseFixtures::draft($actor, self::terms(), ['authored_source' => self::source()]);
    }
}
