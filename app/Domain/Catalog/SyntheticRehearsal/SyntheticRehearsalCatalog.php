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

    /**
     * One TEST PRICE license per tier shape of the six license templates (DD-19, DD-20). A shape is only what the
     * repository states about a tier without its terms: the deliverable roles and whether the template is exclusive
     * (`docs/legal/LICENSE-TIERS.md`, "Deliverables" and "Template type"). No tier name, cap, duration, territory, royalty
     * or price is carried over, and the terms stay schema 1 (features and deliverables only), so no typed usage value
     * exists to be mistaken for a term. Two pairs share a shape (tiers 1 and 2, tiers 4 and 5); they differ only in the
     * caps this definition deliberately leaves out.
     *
     * @return array<string, array{slug: string, name: string, type: string, price_minor: int, roles: list<string>, features: list<string>, source: string}>
     */
    public static function licenses(): array
    {
        $mp3 = ['download_mp3'];
        $wav = ['download_mp3', 'master_wav'];
        $stems = ['download_mp3', 'master_wav', 'stems_zip'];

        return [
            'tier-1' => self::license(1, 'non-exclusive', 111, $mp3),
            'tier-2' => self::license(2, 'non-exclusive', 222, $mp3),
            'tier-3' => self::license(3, 'non-exclusive', 333, $wav),
            'tier-4' => self::license(4, 'non-exclusive', 444, $stems),
            'tier-5' => self::license(5, 'non-exclusive', 555, $stems),
            'tier-6' => self::license(6, 'exclusive', 666, $stems),
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

    /** The recording evidence `BindStemsToRecording` receives: the stems are the generated sources of the same master. */
    public static function stemsReference(array $track): string
    {
        return self::PROVENANCE_PREFIX.$track['number'].'-STEMS: generated per test source by vasey:staging-synthetic-catalog';
    }

    private static function license(int $tier, string $type, int $price, array $roles): array
    {
        $labels = ['download_mp3' => 'MP3', 'master_wav' => 'WAV master', 'stems_zip' => 'Stems ZIP'];
        $files = implode(', ', array_map(fn (string $role): string => $labels[$role], $roles));
        $deliverable = $files.' ('.implode(', ', $roles).')';
        $shape = 'tier '.$tier.' shape: '.$files.'; '.$type;
        $features = [
            self::LABEL,
            'TEST PRICE: a staging rehearsal value, not a product price',
            'Grants no rights; delivers '.$deliverable.' for download testing only',
        ];
        if ($type === 'exclusive') {
            $features[] = 'Exclusive template type for rehearsing the exclusive preparation path only; grants no exclusivity in anything';
        }

        return [
            'slug' => self::MARKER.'-tier-'.$tier.'-license',
            'name' => self::TITLE_PREFIX.' TEST PRICE '.$shape.' license — '.self::LABEL,
            'type' => $type,
            'price_minor' => $price,
            'roles' => $roles,
            'features' => $features,
            'source' => implode("\n\n", [
                'SYNTHETIC REHEARSAL LICENSE — NOT A REAL LICENSE',
                self::LABEL.'.',
                'This text exists only so testers can rehearse the staging store end to end in Stripe test mode. It grants no '
                    .'copyright, master, publishing, performance, distribution or other right in anything. The audio is a generated '
                    .'test tone or noise with no artist, composition or recording behind it. Any price shown with it is a TEST PRICE, '
                    .'not a product price, and Stripe test mode collects no real payment.',
                'Rehearsal shape: '.$shape.'. It copies no term, limit or text of a real license.',
                'Rehearsal deliverable: '.$deliverable.'.',
                'Created by vasey:staging-synthetic-catalog. The staging database and private storage are reset before real content is loaded.',
            ]),
        ];
    }
}
