<?php

namespace App\Domain\Albums;

enum ReleaseType: string
{
    case Album = 'album';
    case Ep = 'ep';
    case Single = 'single';

    public function label(): string
    {
        return match ($this) {
            self::Album => 'Album', self::Ep => 'EP', self::Single => 'Single',
        };
    }

    /** schema.org MusicAlbumReleaseType value. */
    public function schema(): string
    {
        return match ($this) {
            self::Album => 'AlbumRelease', self::Ep => 'EPRelease', self::Single => 'SingleRelease',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(array_column(self::cases(), 'value'), array_map(fn (self $case): string => $case->label(), self::cases()));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
