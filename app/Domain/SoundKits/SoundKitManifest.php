<?php

namespace App\Domain\SoundKits;

use App\Domain\Media\MediaFailure;
use App\Domain\Media\ScanEngines;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Support\CanonicalJson;

final class SoundKitManifest
{
    public const VERSION = 'wav-sample-kit-manifest-v1';

    public function make(SoundKitRevision $revision, array $members, string $hash, int $size): array
    {
        return ['schema' => self::VERSION, 'kind' => 'wav_sample_kit', 'revision' => $revision->public_id, 'number' => $revision->number,
            'description' => $revision->description_snapshot, 'profile' => $revision->profile, 'profile_sha256' => $revision->profile_sha256,
            'source' => ['sha256' => $revision->source_sha256, 'size_bytes' => $revision->source_size_bytes, 'mime_type' => $revision->mime_type],
            'archive' => ['sha256' => $hash, 'size_bytes' => $size, 'mime_type' => 'application/zip'], 'members' => $members];
    }

    public function verified(SoundKitRevision $revision): array
    {
        $manifest = $revision->manifest;
        if ($revision->status !== 'ready' || ! is_array($manifest) || ! is_array($manifest['members'] ?? null) || $manifest['members'] === []
            || ! hash_equals((string) $revision->manifest_sha256, CanonicalJson::hash($manifest))
            || CanonicalJson::hash($manifest) !== CanonicalJson::hash($this->make($revision, $manifest['members'], $revision->archive_sha256, $revision->archive_size_bytes))
            || ! hash_equals($revision->profile_sha256, CanonicalJson::hash($revision->profile))) {
            throw new MediaFailure('invalid_evidence', 'The retained kit manifest could not be verified.');
        }
        self::scan($revision->evidence['source_scan'] ?? null, $revision->source_sha256);
        self::scan($revision->evidence['archive_scan'] ?? null, $revision->archive_sha256);
        foreach ($manifest['members'] as $member) {
            if (! is_array($member) || ! is_string($member['name'] ?? null) || ! is_int($member['size_bytes'] ?? null)
                || ! is_array($member['audio'] ?? null) || ! is_string($member['sha256'] ?? null)) {
                throw new MediaFailure('invalid_evidence', 'The retained kit member evidence is incomplete.');
            }
            self::scan($member['scan'] ?? null, $member['sha256']);
        }
        app(SoundKitFiles::class)->verify($revision->archive_path, $revision->archive_sha256, $revision->archive_size_bytes);

        return $manifest;
    }

    public static function scan(mixed $scan, string $hash): void
    {
        if (! is_array($scan) || ! ScanEngines::accepted($scan['engine'] ?? null) || ($scan['status'] ?? null) !== 'clean'
            || ! is_string($scan['sha256'] ?? null) || ! hash_equals($hash, $scan['sha256'])) {
            throw new MediaFailure('scan_not_clean', 'The scanner did not verify these exact kit bytes.');
        }
    }
}
