<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\QueueMediaProcessing;

/** Synthetic recordings and exports only; no inferred association or production seed. */
final class RecordingFixtures
{
    public static function draft(): array
    {
        $actor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Synthetic recording', 'slug' => 'recording-fixture', 'artist' => 'Test only', 'bpm' => 90, 'musical_key' => 'C minor', 'genre' => 'Test']);
        $media = MediaFixtures::readyTrackMedia($track, $actor);
        $source = MediaFixtures::source($track, 'stems_zip', StemsFixtures::zip([['name' => 'Drums.wav']]));
        $run = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $actor)->id);
        $stems = $run->outputs()->sole();
        $data = ['master_asset_id' => $media['master_wav']->id, 'preview_asset_id' => $media['preview_tagged']->id, 'verification_reference' => 'SYNTHETIC SESSION EXPORT ONLY', 'same_recording_confirmed' => true];

        return compact('actor', 'track', 'media', 'stems', 'data');
    }
}
