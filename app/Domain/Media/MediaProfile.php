<?php

namespace App\Domain\Media;

class MediaProfile
{
    public function current(string $role): array
    {
        return [
            'version' => (string) config('media.profile_version'),
            'role' => $role,
            'tag_path' => $role === 'master_wav' ? config('media.tag_path') : null,
            'tag_sha256' => $role === 'master_wav' ? config('media.tag_sha256') : null,
            'tag_interval_seconds' => (int) config('media.tag_interval_seconds'),
            'preview_bitrate' => '192k', 'delivery_bitrate' => '320k',
            'audio_sample_rate' => 44100, 'waveform_sample_rate' => 44100,
            'waveform_points' => (int) config('media.waveform_points'),
            'waveform_algorithm' => 'absolute-peak-s16le-v1',
            'artwork' => 'sanitized-png-v1',
        ];
    }

    public function fingerprint(array $profile): string
    {
        ksort($profile); // MySQL normalizes JSON object key order.

        return hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
