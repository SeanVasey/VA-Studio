<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\QueueMediaProcessing;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class MediaFixtures
{
    private static array $cache = [];

    public static function configure(): TestOnlyMediaScanner
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Synthetic media fixtures are test-only.');
        }
        Queue::fake();
        $scanner = new TestOnlyMediaScanner;
        app()->instance(MalwareScanner::class, $scanner);
        Storage::disk('local')->put('approved-tags/test-only.wav', self::wav(0.2, 1800));
        config(['media.tag_path' => 'approved-tags/test-only.wav', 'media.tag_sha256' => hash('sha256', self::wav(0.2, 1800)), 'media.tag_interval_seconds' => 1]);

        return $scanner;
    }

    public static function wav(float $duration = 1.2, int $frequency = 440): string
    {
        $key = $duration.'-'.$frequency;
        if (! isset(self::$cache[$key])) {
            $path = sys_get_temp_dir().'/vasey-test-'.Str::uuid().'.wav';
            try {
                $process = new Process([config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency='.$frequency.':sample_rate=44100:duration='.$duration, '-threads', '1', '-c:a', 'pcm_s16le', '-ac', '2', $path]);
                $process->setTimeout(15)->mustRun();
                self::$cache[$key] = file_get_contents($path);
            } finally {
                @unlink($path);
            }
        }

        return self::$cache[$key];
    }

    public static function png(): string
    {
        // Known tiny PNG fixture. Artwork is decoded/re-encoded by the real worker.
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    }

    public static function source(Track $track, string $role = 'master_wav', ?string $bytes = null): MediaAsset
    {
        $bytes ??= $role === 'artwork' ? self::png() : self::wav();
        $key = 'quarantine/'.Str::uuid().'/source.bin';
        Storage::disk('local')->put($key, $bytes);

        return MediaAsset::create(['track_id' => $track->id, 'role' => $role, 'disk' => 'local', 'storage_path' => $key, 'original_name' => 'test-only-upload', 'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'status' => 'quarantined']);
    }

    /** Returns immutable outputs indexed by role; never creates offers or rights. */
    public static function readyTrackMedia(Track $track, User $actor): array
    {
        self::configure();
        $outputs = [];
        foreach (['master_wav', 'artwork'] as $role) {
            $source = self::source($track, $role);
            $run = app(QueueMediaProcessing::class)->handle($source, $actor);
            app(MediaProcessor::class)->handle($run->id);
            foreach ($run->outputs()->get() as $output) {
                $outputs[$output->role] = $output;
            }
        }
        $track->refresh();

        return $outputs;
    }
}
