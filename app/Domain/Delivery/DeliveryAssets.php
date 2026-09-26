<?php

namespace App\Domain\Delivery;

use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\RecordingAssociation;
use App\Domain\Media\VerifiedMedia;
use App\Support\CanonicalJson;
use Throwable;

/** Captured historical database evidence and fresh physical checks are deliberately separate. */
class DeliveryAssets
{
    public function inspect(array $original): array
    {
        try {
            if (! is_array($original['lines'] ?? null) || ! array_is_list($original['lines'])
                || count($original['lines']) < 1 || count($original['lines']) > 10) { throw new \UnexpectedValueException; }
            $assets = [];
            foreach ($original['lines'] as $line) {
                $snapshot = $line['selection']['offer_snapshot'];
                $revision = OfferRevision::find($line['offer_revision_id']);
                if (! $revision || CanonicalJson::encode($revision->snapshot) !== CanonicalJson::encode($snapshot)
                    || ! hash_equals($revision->snapshot_hash, CanonicalJson::hash($snapshot))) { throw new \UnexpectedValueException; }
                $manifest = $snapshot['assets'];
                $roles = array_column($manifest, 'role'); sort($roles);
                $required = $snapshot['license']['required_asset_roles']; sort($required);
                if ($manifest === [] || ! array_is_list($manifest) || $roles !== $required
                    || count(array_unique(array_column($manifest, 'id'))) !== count($manifest)) { throw new \UnexpectedValueException; }
                foreach ($manifest as $entry) {
                    $asset = MediaAsset::find($entry['id']);
                    if (! $asset || $asset->track_id !== $snapshot['product']['id']
                        || ! in_array($asset->role, ['master_wav', 'download_mp3', 'stems_zip'], true)) { throw new \UnexpectedValueException; }
                    // OfferSnapshot::asset checks physical files for stems. Reconstruct its same frozen fields using DB-only evidence.
                    $descriptor = $asset->only(['id', 'role', 'sha256', 'mime_type', 'size_bytes', 'original_name', 'parent_asset_id', 'processing_run_id']);
                    $recording = null;
                    if ($asset->role === 'stems_zip') {
                        $binding = app(RecordingAssociation::class)->retained($asset);
                        if (! $binding) { throw new \UnexpectedValueException; }
                        $descriptor['recording_binding'] = $binding->only(['id', 'stems_asset_id', 'master_asset_id', 'preview_asset_id', 'recording_source_id', 'evidence_hash']);
                        $master = app(VerifiedMedia::class)->evidence($binding->master, historical: true);
                        $preview = app(VerifiedMedia::class)->evidence($binding->preview, historical: true);
                        if ($master === null || $preview === null) { throw new \UnexpectedValueException; }
                        $recording = ['binding' => $descriptor['recording_binding'], 'evidence' => $binding->evidence,
                            'master' => $master, 'preview' => $preview];
                    }
                    $provenance = app(VerifiedMedia::class)->evidence($asset, historical: true);
                    if ($provenance === null || CanonicalJson::encode($descriptor) !== CanonicalJson::encode($entry)) { throw new \UnexpectedValueException; }
                    $captured = $asset->only(['id', 'track_id', 'role', 'disk', 'storage_path', 'sha256', 'size_bytes'])
                        + ['descriptor' => $descriptor, 'descriptor_hash' => CanonicalJson::hash($descriptor),
                            'provenance' => $this->retainedValues($provenance), 'recording' => $recording === null ? null : $this->retainedValues($recording),
                            'scan_scope' => $this->hasTestScan([$provenance, $recording]) ? 'test-only' : 'clamav'];
                    if (isset($assets[$asset->id]) && CanonicalJson::encode($assets[$asset->id]) !== CanonicalJson::encode($captured)) { throw new \UnexpectedValueException; }
                    $assets[$asset->id] = $captured;
                }
            }
            ksort($assets, SORT_NUMERIC);

            return array_values($assets);
        } catch (Throwable) {
            throw new DeliveryException('changed');
        }
    }

    private function retainedValues(array $evidence): array
    {
        foreach ($evidence as $key => $value) {
            if ($key === 'technical_metadata') {
                // The validated immutable row remains authoritative. Retain its exact typed digest, not repeated stems manifests/waveforms.
                $evidence[$key] = MediaEvidenceValues::reference($value);
            } elseif (is_array($value)) {
                $evidence[$key] = $this->retainedValues($value);
            }
        }
        return $evidence;
    }

    private function hasTestScan(array $evidence): bool
    {
        if (($evidence['engine'] ?? null) === 'test-only') { return true; }
        foreach ($evidence as $value) {
            if (is_array($value) && $this->hasTestScan($value)) { return true; }
        }

        return false;
    }

    /** No database writes, cache reads, directory creation, replacement or repair. */
    public function verify(array $evidence): void
    {
        ActivationPolicy::outsideTransactions();
        if (! array_is_list($evidence) || $evidence === [] || count($evidence) > 30) { throw new DeliveryException('asset_unavailable'); }
        $deadline = hrtime(true) + 300_000_000_000;
        foreach ($evidence as $entry) {
            if (! is_array($entry) || ! in_array($entry['scan_scope'] ?? null, ['test-only', 'clamav'], true)
                || ($entry['scan_scope'] === 'test-only' && ! app()->environment('testing'))) { throw new DeliveryException('asset_unavailable'); }
            app(DeliveryAssetFiles::class)->verify($entry, $deadline);
        }
    }
}
