<?php

namespace App\Domain\Catalog\SyntheticRehearsal;

/**
 * The fixed, labelled definition of the synthetic staging rehearsal catalog. Every value here is a rehearsal label, not
 * product data: no real recording, artist, price or license term. Each row the command creates carries the shared
 * marker, `synthetic-rehearsal`, as its slug prefix (tracks, license templates), as a track tag, and as the
 * provenance prefix of its rights declaration, so every synthetic row can be found and audited.
 */
final class SyntheticRehearsalCatalog
{
    public const MARKER = 'synthetic-rehearsal';

    public const LABEL = 'Synthetic rehearsal content — not for sale; staging test only';

    public const TITLE_PREFIX = '[SYNTHETIC]';

    public const ARTIST = 'Synthetic Test Content';

    /** Writes are attributed only to staff accounts named for this purpose, never to a real operator's account. */
    public const OPERATOR_NAME_PREFIX = 'Synthetic ';

    public const CURRENCY = 'USD';

    public const TAGS = [self::MARKER, 'not-for-sale', 'staging-test-only'];

    public const PROVENANCE_PREFIX = 'SYNTHETIC-REHEARSAL-';

    public const APPROVAL_REFERENCE = 'SYNTHETIC-REHEARSAL-NOT-FOR-SALE';

    /** @return array<string, array{slug: string, name: string, type: string, price_minor: int, roles: list<string>, features: list<string>, source: string}> */
    public static function licenses(): array
    {
        return [
            'mp3' => self::license('mp3', 'MP3', 111, ['download_mp3'], 'a generated MP3 (download_mp3)'),
            'wav' => self::license('wav', 'WAV', 222, ['download_mp3', 'master_wav'], 'a generated MP3 and WAV (download_mp3, master_wav)'),
        ];
    }

    /** @return list<array{number: string, slug: string, title: string, bpm: int, musical_key: string, duration: int, sources: list<string>, color: array{int, int, int}}> */
    public static function tracks(): array
    {
        $tracks = [
            ['01', 'sine 220 Hz', 60, 'A minor', 30, ['sine=frequency=220:sample_rate=44100:duration=30'], [70, 84, 99]],
            ['02', 'C major chord', 72, 'C major', 36, ['aevalsrc=exprs=0.2*sin(2*PI*261.63*t)+0.2*sin(2*PI*329.63*t)+0.2*sin(2*PI*392*t):sample_rate=44100:duration=36'], [96, 72, 56]],
            ['03', 'pink noise', 84, 'C minor', 42, ['anoisesrc=color=pink:amplitude=0.2:seed=3:sample_rate=44100:duration=42'], [64, 96, 76]],
            ['04', 'tremolo 440 Hz', 96, 'A major', 48, ['aevalsrc=exprs=0.4*sin(2*PI*440*t)*(0.6+0.4*sin(2*PI*2*t)):sample_rate=44100:duration=48'], [88, 64, 100]],
            ['05', 'A minor chord', 108, 'A minor', 54, ['aevalsrc=exprs=0.2*sin(2*PI*220*t)+0.2*sin(2*PI*261.63*t)+0.2*sin(2*PI*329.63*t):sample_rate=44100:duration=54'], [100, 92, 60]],
            ['06', 'brown noise and 110 Hz', 120, 'A minor', 60, ['anoisesrc=color=brown:amplitude=0.15:seed=6:sample_rate=44100:duration=60', 'sine=frequency=110:sample_rate=44100:duration=60'], [60, 60, 60]],
        ];

        return array_map(fn (array $row): array => [
            'number' => $row[0], 'slug' => self::MARKER.'-'.$row[0],
            'title' => self::TITLE_PREFIX.' Rehearsal tone '.$row[0].' — '.$row[1],
            'bpm' => $row[2], 'musical_key' => $row[3], 'duration' => $row[4], 'sources' => $row[5], 'color' => $row[6],
        ], $tracks);
    }

    /** The exact metadata `SaveTrackMetadata` receives for one definition. */
    public static function metadata(array $track): array
    {
        return [
            'title' => $track['title'], 'slug' => $track['slug'], 'artist' => self::ARTIST, 'bpm' => $track['bpm'],
            'musical_key' => $track['musical_key'], 'genre' => self::ARTIST, 'mood' => 'Synthetic rehearsal',
            'tags' => self::TAGS,
            'description' => self::LABEL.'. Generated test audio and artwork for the hosted staging rehearsal; '
                .'no real recording, artist, composition, price or license is represented.',
        ];
    }

    /** The exact pending evidence `SaveRightsDeclaration::create` receives for one definition. */
    public static function rights(array $track, int $trackId): array
    {
        return [
            'track_id' => $trackId,
            'provenance_reference' => self::PROVENANCE_PREFIX.$track['number'].': generated at run time by '
                .'vasey:staging-synthetic-catalog from ffmpeg test sources and GD-drawn artwork; no recording, sample or third-party material.',
            'sample_disclosure' => self::LABEL.'. No samples: generated tones or noise and generated artwork only.',
        ];
    }

    private static function license(string $key, string $format, int $price, array $roles, string $deliverable): array
    {
        return [
            'slug' => self::MARKER.'-'.$key.'-license',
            'name' => self::TITLE_PREFIX.' TEST PRICE '.$format.' license — '.self::LABEL,
            'type' => 'non-exclusive',
            'price_minor' => $price,
            'roles' => $roles,
            'features' => [
                self::LABEL,
                'TEST PRICE: a staging rehearsal value, not a product price',
                'Grants no rights; delivers '.$deliverable.' for download testing only',
            ],
            'source' => implode("\n\n", [
                'SYNTHETIC REHEARSAL LICENSE — NOT A REAL LICENSE',
                self::LABEL.'.',
                'This text exists only so testers can rehearse the staging store end to end in Stripe test mode. It grants no '
                    .'copyright, master, publishing, performance, distribution or other right in anything. The audio is a generated '
                    .'test tone or noise with no artist, composition or recording behind it. Any price shown with it is a TEST PRICE, '
                    .'not a product price, and Stripe test mode collects no real payment.',
                'Rehearsal deliverable: '.$deliverable.'.',
                'Created by vasey:staging-synthetic-catalog. The staging database and private storage are reset before real content is loaded.',
            ]),
        ];
    }
}
