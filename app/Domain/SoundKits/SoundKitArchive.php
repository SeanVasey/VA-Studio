<?php

namespace App\Domain\SoundKits;

use App\Domain\Media\MediaFailure;
use App\Domain\Media\WavZipArchive;

/** Samples have their own adapter, artifact name and profile; there is no Track or MediaAsset record. */
class SoundKitArchive
{
    public function inspect(string $input, string $mime, array $profile): void
    {
        $this->profile($profile);
        (new WavZipArchive($this->clock(...), 'samples'))->inspectUpload($input, $mime, $profile);
    }

    public function build(string $input, string $mime, array $profile, string $workspace, callable $scan): array
    {
        $this->profile($profile);

        return (new WavZipArchive($this->clock(...), 'samples'))->build($input, $mime, $profile, $workspace, $scan);
    }

    private function profile(array $profile): void
    {
        if (($profile['archive_version'] ?? null) !== SoundKitArchiveProfile::VERSION || ($profile['kind'] ?? null) !== 'wav_sample_kit') {
            throw new MediaFailure('profile_changed', 'This archive requires the supported WAV sample-kit profile.');
        }
    }

    protected function clock(): int
    {
        return hrtime(true);
    }
}
