<?php

namespace App\Domain\Media;

use App\Support\CanonicalJson;

/** Untrusted member names are metadata only; extraction always uses generated paths. */
class StemsArchive
{
    public const VERSION = 'wav-stems-zip-v2';

    public function policy(): array
    {
        $policy = ['archive_version' => self::VERSION, 'archive_max_duration_seconds' => max(1, min(1200, (int) config('media.max_duration_seconds')))];
        foreach (['entries' => 128, 'member_bytes' => 134217728, 'total_bytes' => 536870912, 'ratio' => 100, 'seconds' => 360] as $key => $ceiling) {
            $policy['archive_max_'.$key] = max(1, min($ceiling, (int) config('media.stems.max_'.$key, $ceiling)));
        }

        return $policy;
    }

    public function hasEvidence(array $metadata, string $hash, array $profile, bool $historical = false): bool
    {
        $manifest = $metadata['manifest'] ?? [];
        $version = $profile['archive_version'] ?? null;
        // Historical validators must not depend on the version emitted by a new worker.
        if (! in_array($version, ['wav-stems-zip-v1', 'wav-stems-zip-v2'], true) || ($metadata['archive_version'] ?? null) !== $version
            || ! is_array($manifest) || ! array_is_list($manifest) || $manifest === [] || count($manifest) > ($profile['archive_max_entries'] ?? 0)
            || ! hash_equals($metadata['manifest_sha256'] ?? '', CanonicalJson::hash($manifest)) || ! $this->cleanScan($metadata['archive_scan'] ?? [], $hash, $historical)) {
            return false;
        }
        if ($version === 'wav-stems-zip-v2' && (! is_int($profile['archive_max_duration_seconds'] ?? null) || $profile['archive_max_duration_seconds'] < 1 || $profile['archive_max_duration_seconds'] > 1200)) {
            return false;
        }
        foreach ($manifest as $member) {
            if (! $this->cleanScan($member['scan'] ?? [], $member['sha256'] ?? '', $historical)
                || ($version === 'wav-stems-zip-v2' && (! is_int($member['audio']['duration_microseconds'] ?? null) || $member['audio']['duration_microseconds'] < 1 || $member['audio']['duration_microseconds'] > $profile['archive_max_duration_seconds'] * 1000000))) {
                return false;
            }
        }

        return true;
    }

    private function cleanScan(array $scan, string $hash, bool $historical = false): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $hash) && ($scan['status'] ?? null) === 'clean' && hash_equals($hash, $scan['sha256'] ?? '')
            && ScanEngines::accepted($scan['engine'] ?? null, $historical);
    }

    /** The stems adapter preserves its existing role, filenames, profile and historical evidence shape. */
    public function inspectUpload(string $input, string $mime, array $profile): void
    {
        (new WavZipArchive($this->clock(...)))->inspectUpload($input, $mime, $profile);
    }

    public function build(string $input, string $mime, array $profile, string $workspace, callable $scan): array
    {
        $result = (new WavZipArchive($this->clock(...)))->build($input, $mime, $profile, $workspace, $scan);

        return [[
            'role' => 'stems_zip', 'file' => $result['file'], 'name' => 'stems.zip', 'mime_type' => 'application/zip',
            'technical_metadata' => ['archive_version' => $profile['archive_version'], 'manifest' => $result['manifest'],
                'manifest_sha256' => CanonicalJson::hash($result['manifest']), 'archive_scan' => $result['archive_scan']],
        ]];
    }

    /** Retain the existing overridable budget clock for callers and deterministic deadline tests. */
    protected function clock(): int
    {
        return hrtime(true);
    }
}
