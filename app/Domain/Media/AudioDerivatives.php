<?php

namespace App\Domain\Media;

class AudioDerivatives
{
    /** @param  int  $timeoutSeconds  the wall-clock seconds ffprobe may take; 0 is the limit every tool has */
    public function probe(string $path, string $demuxer, ?int $maxDurationSeconds = null, int $timeoutSeconds = 0): array
    {
        $json = app(BoundedMediaProcess::class)->run([
            config('media.ffprobe'), '-v', 'error', '-threads', '1', '-protocol_whitelist', 'file,pipe',
            '-f', $demuxer, '-show_entries', 'format=duration:stream=codec_name,codec_type,sample_rate,channels,duration', '-of', 'json', $path,
        ], dirname($path), $timeoutSeconds);
        $probe = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $streams = $probe['streams'] ?? [];
        $stream = $streams[0] ?? [];
        $duration = (float) ($probe['format']['duration'] ?? $stream['duration'] ?? 0);
        if (count($streams) !== 1 || ($stream['codec_type'] ?? null) !== 'audio' || ! is_finite($duration) || $duration <= 0 || $duration > ($maxDurationSeconds ?? config('media.max_duration_seconds'))) {
            throw new MediaFailure('invalid_audio', 'Audio must contain one valid, bounded audio stream.');
        }
        $sampleRate = (int) ($stream['sample_rate'] ?? 0);
        $channels = (int) ($stream['channels'] ?? 0);
        if (! in_array($channels, [1, 2], true) || $sampleRate < 8000 || $sampleRate > 192000) {
            throw new MediaFailure('invalid_audio', 'Audio requires one or two channels and a supported sample rate.');
        }
        if ($demuxer === 'wav' && ! in_array($stream['codec_name'] ?? '', ['pcm_s16le', 'pcm_s24le', 'pcm_s32le', 'pcm_f32le'], true)) {
            throw new MediaFailure('unsupported_wav', 'Master WAV requires 16/24/32-bit PCM or 32-bit float audio.');
        }
        if ($demuxer === 'mp3' && ($stream['codec_name'] ?? null) !== 'mp3') {
            throw new MediaFailure('invalid_audio', 'The generated MP3 failed format verification.');
        }

        return ['duration_seconds' => $duration, 'sample_rate' => $sampleRate, 'channels' => $channels, 'codec' => $stream['codec_name']];
    }

    /** @param  int  $timeoutSeconds  the wall-clock seconds ffprobe may take; 0 is the limit every tool has */
    public function validateWav(string $path, ?string $mime = null, ?int $maxDurationSeconds = null, int $timeoutSeconds = 0): array
    {
        $mime ??= (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $header = file_get_contents($path, false, null, 0, 12);
        if (! in_array($mime, ['audio/x-wav', 'audio/wav', 'audio/vnd.wave'], true) || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE' || unpack('Vsize', substr($header, 4, 4))['size'] + 8 !== filesize($path)) {
            throw new MediaFailure('invalid_wav', 'The upload is not a complete supported RIFF/WAVE file.');
        }

        return $this->probe($path, 'wav', $maxDurationSeconds, $timeoutSeconds);
    }

    public function build(string $master, string $tag, array $profile, string $workspace): array
    {
        $metadata = $this->validateWav($master);
        $tagMetadata = $this->validateWav($tag);
        $interval = $profile['tag_interval_seconds'];
        if ($tagMetadata['duration_seconds'] > config('media.max_tag_duration_seconds') || $interval < 1 || $interval > 120 || $tagMetadata['duration_seconds'] > $interval) {
            throw new MediaFailure('invalid_tag', 'The approved tag must fit its configured repeat interval and duration limit.');
        }
        $duration = number_format($metadata['duration_seconds'], 6, '.', '');
        $base = [config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-xerror', '-n', '-threads', '1', '-filter_threads', '1', '-filter_complex_threads', '1'];
        $input = ['-protocol_whitelist', 'file,pipe', '-err_detect', 'explode', '-f', 'wav', '-i', $master];
        $mp3 = ['-map_metadata', '-1', '-vn', '-sn', '-dn', '-threads', '1', '-c:a', 'libmp3lame', '-ar', (string) $profile['audio_sample_rate'], '-ac', '2'];
        $runner = app(BoundedMediaProcess::class);
        $tagPcm = $workspace.'/tag.pcm';
        $runner->run([...$base, '-protocol_whitelist', 'file,pipe', '-err_detect', 'explode', '-f', 'wav', '-i', $tag, '-map', '0:a:0', '-ac', '1', '-ar', '44100', '-c:a', 'pcm_s16le', '-f', 's16le', $tagPcm], $workspace);
        if (max($this->peaks($tagPcm, 100)) < 0.003162) {
            throw new MediaFailure('silent_tag', 'The approved seller tag is silent or too quiet to protect the preview.');
        }
        $delivery = $workspace.'/delivery.mp3';
        $preview = $workspace.'/preview.mp3';
        $runner->run([...$base, ...$input, '-map', '0:a:0', ...$mp3, '-b:a', $profile['delivery_bitrate'], '-f', 'mp3', $delivery], $workspace);
        $loopSamples = $interval * 48000;
        // Pad the approved tag to the interval, then repeat. The song remains full length.
        $filter = "[1:a]aresample=48000,apad=whole_dur={$interval},atrim=duration={$interval},aloop=loop=-1:size={$loopSamples},volume=0.8[tag];[0:a]volume=0.8[music];[music][tag]amix=inputs=2:duration=first:dropout_transition=0:normalize=0,alimiter=limit=0.95:latency=1[out]";
        $runner->run([...$base, ...$input, '-protocol_whitelist', 'file,pipe', '-err_detect', 'explode', '-f', 'wav', '-i', $tag, '-filter_complex', $filter, '-map', '[out]', '-t', $duration, ...$mp3, '-b:a', $profile['preview_bitrate'], '-f', 'mp3', $preview], $workspace);
        $deliveryMetadata = $this->probe($delivery, 'mp3');
        $previewMetadata = $this->probe($preview, 'mp3');
        foreach ([$deliveryMetadata, $previewMetadata] as $output) {
            if (abs($output['duration_seconds'] - $metadata['duration_seconds']) > 0.15) {
                throw new MediaFailure('duration_mismatch', 'The generated audio does not match the full source duration.');
            }
        }
        $pcm = $workspace.'/waveform.pcm';
        $runner->run([...$base, '-protocol_whitelist', 'file,pipe', '-err_detect', 'explode', '-f', 'mp3', '-i', $preview, '-map', '0:a:0', '-ac', '1', '-ar', (string) $profile['waveform_sample_rate'], '-c:a', 'pcm_s16le', '-f', 's16le', $pcm], $workspace);
        $peaks = $this->peaks($pcm, $profile['waveform_points']);
        $previewMetadata += ['waveform' => $peaks, 'waveform_sha256' => hash('sha256', json_encode($peaks, JSON_THROW_ON_ERROR)), 'waveform_algorithm' => $profile['waveform_algorithm'], 'tag_sha256' => hash_file('sha256', $tag), 'tag_interval_seconds' => $interval, 'full_length' => true];

        return [
            ['role' => 'master_wav', 'file' => $master, 'name' => 'master.wav', 'mime_type' => 'audio/wav', 'technical_metadata' => $metadata],
            ['role' => 'download_mp3', 'file' => $delivery, 'name' => 'delivery.mp3', 'mime_type' => 'audio/mpeg', 'technical_metadata' => $deliveryMetadata],
            ['role' => 'preview_tagged', 'file' => $preview, 'name' => 'preview.mp3', 'mime_type' => 'audio/mpeg', 'technical_metadata' => $previewMetadata],
        ];
    }

    private function peaks(string $path, int $count): array
    {
        $size = filesize($path);
        if ($size < 2 || $size % 2 || $count < 10 || $count > 1000) {
            throw new MediaFailure('invalid_waveform', 'The preview waveform could not be measured.');
        }
        $samples = intdiv($size, 2);
        $peaks = array_fill(0, $count, 0.0);
        $stream = fopen($path, 'rb');
        $index = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                foreach (unpack('v*', $chunk) ?: [] as $value) {
                    $signed = $value >= 32768 ? $value - 65536 : $value;
                    $bin = min($count - 1, (int) floor($index++ * $count / $samples));
                    $peaks[$bin] = max($peaks[$bin], abs($signed) / 32768);
                }
            }
        } finally {
            fclose($stream);
        }

        return array_map(fn ($peak) => round($peak, 6), $peaks);
    }
}
