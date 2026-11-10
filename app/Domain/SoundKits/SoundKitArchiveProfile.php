<?php

namespace App\Domain\SoundKits;

use App\Domain\Media\StemsArchive;
use App\Support\CanonicalJson;

/** Technical WAV sample inspection only. This profile grants no sample usage or redistribution rights. */
final class SoundKitArchiveProfile
{
    public const VERSION = 'wav-sample-kit-zip-v1';

    public function current(): array
    {
        // The existing scanner capacity is derived from these shared WAV-archive ceilings.
        // A lower operator setting must constrain kits as well; it is not a musical stems association.
        $policy = app(StemsArchive::class)->policy();

        return array_replace($policy, [
            // Kits keep the 512 MiB expanded bound they had before stems ZIPs were raised to 1 GiB (their upload stays at 200 MiB).
            'archive_max_total_bytes' => min(536870912, $policy['archive_max_total_bytes']),
            'archive_version' => self::VERSION, 'source_max_bytes' => max(1, min(209715200, (int) config('media.max_source_bytes'))),
            'canonicalization' => CanonicalJson::VERSION, 'kind' => 'wav_sample_kit',
            'codecs' => ['pcm_s16le', 'pcm_s24le', 'pcm_s32le', 'pcm_f32le'],
            'channels' => [1, 2], 'sample_rate_min' => 8000, 'sample_rate_max' => 192000,
        ]);
    }

    public function fingerprint(array $profile): string
    {
        return CanonicalJson::hash($profile);
    }
}
