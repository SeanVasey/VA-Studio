<?php

namespace Tests\Unit;

use App\Domain\Delivery\MediaEvidenceValues;
use App\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MediaEvidenceValuesTest extends TestCase
{
    public function test_measured_floats_are_lossless_canonical_and_distinct_from_lookalike_json_values(): void
    {
        $first = ['duration' => 1.2, 'waveform' => [0.0, 0.123456789, 1.0], 'sample_rate' => 44100];
        $reordered = ['sample_rate' => 44100, 'waveform' => [0.0, 0.123456789, 1.0], 'duration' => 1.2];
        $this->assertSame(CanonicalJson::encode(MediaEvidenceValues::encode($first)), CanonicalJson::encode(MediaEvidenceValues::encode($reordered)));
        $float = MediaEvidenceValues::encode(1.2);
        $this->assertSame(1.2, unpack('Evalue', hex2bin($float[1]))['value']);
        $values = [1.0, 1, '1', null, false, ['binary64', bin2hex(pack('E', 1.0))]];
        $this->assertCount(count($values), array_unique(array_map(fn ($value) => CanonicalJson::encode(MediaEvidenceValues::encode($value)), $values)));
        $this->assertNotSame(CanonicalJson::hash(MediaEvidenceValues::encode([0.1, 0.2])), CanonicalJson::hash(MediaEvidenceValues::encode([0.2, 0.1])));
    }

    public function test_compact_references_bind_exact_types_and_the_smallest_representable_measurement_changes(): void
    {
        $value = ['duration' => 1.0, 'waveform' => [0.0, 0.5, 1.0]];
        $reference = MediaEvidenceValues::reference($value);
        $this->assertSame(['encoding' => MediaEvidenceValues::VERSION, 'sha256' => CanonicalJson::hash(MediaEvidenceValues::encode($value))], $reference);
        $this->assertSame($reference, MediaEvidenceValues::reference(['waveform' => $value['waveform'], 'duration' => 1.0]));
        $changed = $value; $changed['duration'] = 1.0 + PHP_FLOAT_EPSILON;
        $this->assertNotSame($reference, MediaEvidenceValues::reference($changed));
        $changed = $value; $changed['duration'] = 1;
        $this->assertNotSame($reference, MediaEvidenceValues::reference($changed));
        $changed = $value; $changed['duration'] = ['binary64', bin2hex(pack('E', 1.0))];
        $this->assertNotSame($reference, MediaEvidenceValues::reference($changed));
    }

    public function test_full_supported_stems_manifest_has_a_bounded_reference_without_losing_any_member_binding(): void
    {
        $manifest = [];
        for ($index = 0; $index < 128; $index++) {
            // Full path fits 240 bytes, each component fits 100 bytes, and every name is unique.
            $name = str_repeat('a', 80).'/'.str_repeat('b', 80).'/'.sprintf('%03d-', $index).str_repeat('c', 68).'.wav';
            $this->assertSame(238, strlen($name));
            $hash = hash('sha256', 'synthetic-stem-'.$index);
            $manifest[] = ['name' => $name, 'size_bytes' => 441044, 'sha256' => $hash,
                'audio' => ['sample_rate' => 44100, 'channels' => 2, 'codec' => 'pcm_s16le', 'duration_microseconds' => 2500000],
                'scan' => ['engine' => 'clamav', 'version' => 'Synthetic maximum metadata fixture', 'status' => 'clean', 'sha256' => $hash]];
        }
        $metadata = ['archive_version' => 'wav-stems-zip-v2', 'manifest' => $manifest,
            'manifest_sha256' => CanonicalJson::hash($manifest),
            'archive_scan' => ['engine' => 'clamav', 'version' => 'Synthetic maximum metadata fixture', 'status' => 'clean', 'sha256' => hash('sha256', 'synthetic archive')]];
        $reference = MediaEvidenceValues::reference($metadata);
        $this->assertLessThan(160, strlen(CanonicalJson::encode($reference)));
        // The last member still affects the reference, including metadata outside the promised asset's byte digest.
        $changed = $metadata; $changed['manifest'][127]['audio']['duration_microseconds']++;
        $this->assertNotSame($reference, MediaEvidenceValues::reference($changed));
        $changed = $metadata; $changed['manifest'][0]['name'] = str_replace('aaa', 'aab', $changed['manifest'][0]['name']);
        $this->assertNotSame($reference, MediaEvidenceValues::reference($changed));
        $waveform = ['duration_seconds' => 2.5, 'waveform' => array_fill(0, 1000, 0.123456789)];
        $this->assertLessThan(160, strlen(CanonicalJson::encode(MediaEvidenceValues::reference($waveform))));
    }

    public function test_nonfinite_values_are_not_silently_rewritten(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MediaEvidenceValues::reference(['duration' => INF]);
    }
}
