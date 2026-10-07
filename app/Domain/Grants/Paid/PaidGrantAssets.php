<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\MediaEvidenceValues;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\RecordingAssociation;
use App\Domain\Media\VerifiedMedia;
use App\Support\CanonicalJson;

/** Frozen producer descriptors only; no current offer, catalog, price or license substitution. */
final class PaidGrantAssets
{
    public function capture(array $line, PaidGrantRows $rows): array
    {
        $descriptors = $line['asset_revisions'];
        PaidGrantException::require(array_is_list($descriptors) && count($descriptors) >= 1 && count($descriptors) <= 3, 409);
        $roles = array_column($descriptors, 'role');
        $required = $line['license']['required_asset_roles'];
        sort($roles);
        sort($required);
        PaidGrantException::require($roles === $required && count(array_unique($roles)) === count($roles)
            && count(array_unique(array_column($descriptors, 'id'))) === count($descriptors), 409);
        $selectors = $this->selectors($line, $rows);
        $raw = $this->raw($selectors, $rows);
        $files = [];
        foreach ($descriptors as $descriptor) {
            $record = $rows->one('media_assets', 'id = ?', [$descriptor['id']]);
            PaidGrantException::require($record !== [] && (int) $record['track_id'] === $line['product']['id'], 409);
            $asset = new MediaAsset;
            $asset->setRawAttributes($record, true);
            $asset->exists = true;
            PaidGrantException::require(in_array($asset->role, ['master_wav', 'download_mp3', 'stems_zip'], true), 409);
            $actual = $asset->only(['id', 'role', 'sha256', 'mime_type', 'size_bytes', 'original_name', 'parent_asset_id', 'processing_run_id']);
            $recording = null;
            if ($asset->role === 'stems_zip') {
                $binding = app(RecordingAssociation::class)->retained($asset);
                PaidGrantException::require($binding !== null, 409);
                $actual['recording_binding'] = $binding->only(['id', 'stems_asset_id', 'master_asset_id', 'preview_asset_id', 'recording_source_id', 'evidence_hash']);
                $master = app(VerifiedMedia::class)->evidence($binding->master, historical: true);
                $preview = app(VerifiedMedia::class)->evidence($binding->preview, historical: true);
                PaidGrantException::require($master !== null && $preview !== null, 409);
                $recording = [$actual['recording_binding'], $binding->evidence, $master, $preview];
            }
            $provenance = app(VerifiedMedia::class)->evidence($asset, historical: true);
            PaidGrantException::require($provenance !== null && CanonicalJson::encode($actual) === CanonicalJson::encode($descriptor), 409);
            $test = $this->hasTestScan([$provenance, $recording]);
            PaidGrantException::require(! $test || $line['provenance'] === 'synthetic_rehearsal' && app()->environment('testing'), 409);
            $file = $asset->only(['id', 'track_id', 'role', 'disk', 'storage_path', 'sha256', 'size_bytes'])
                + ['descriptor' => $descriptor, 'descriptor_hash' => CanonicalJson::hash($descriptor),
                    'provenance' => MediaEvidenceValues::reference([$provenance, $recording]), 'scan_scope' => $test ? 'test-only' : 'clamav'];
            $files[(int) $asset->id] = $file;
        }
        PaidGrantException::require($this->raw($selectors, $rows) === $raw, 409);
        ksort($files, SORT_NUMERIC);

        return ['schema_version' => 'paid-asset-manifest-v1', 'asset_revisions_hash' => $line['asset_revisions_hash'],
            'files' => array_values($files), 'selectors' => $selectors, 'raw_hash' => CanonicalJson::hash($raw)];
    }

    private function selectors(array $line, PaidGrantRows $rows): array
    {
        $assets = $parents = $runs = [];
        $bindings = [];
        $pending = array_column($line['asset_revisions'], 'id');
        foreach ($line['asset_revisions'] as $descriptor) {
            if (($descriptor['role'] ?? null) === 'stems_zip') {
                $binding = $rows->one('stems_recordings', 'id = ? AND stems_asset_id = ?', [$descriptor['recording_binding']['id'], $descriptor['id']]);
                PaidGrantException::require($binding !== [], 409);
                $bindings[] = (int) $binding['id'];
                $pending[] = (int) $binding['master_asset_id'];
                $pending[] = (int) $binding['preview_asset_id'];
            }
        }
        foreach (array_unique($pending) as $id) {
            PaidGrantException::require(is_int($id) && $id >= 1, 409);
            $asset = $rows->one('media_assets', 'id = ?', [$id]);
            PaidGrantException::require($asset !== [] && (int) $asset['track_id'] === $line['product']['id']
                && $asset['parent_asset_id'] !== null && $asset['processing_run_id'] !== null, 409);
            $assets[] = $id;
            $parents[] = (int) $asset['parent_asset_id'];
            $runs[] = (int) $asset['processing_run_id'];
        }
        $selectors = ['assets' => array_values(array_unique([...$assets, ...$parents])), 'runs' => array_values(array_unique($runs)), 'bindings' => array_values(array_unique($bindings))];
        foreach ($selectors as &$ids) {
            sort($ids, SORT_NUMERIC);
        }
        unset($ids);
        PaidGrantException::require(count($selectors['assets']) <= 10 && count($selectors['runs']) <= 5 && count($selectors['bindings']) <= 1, 409);

        return $selectors;
    }

    private function raw(array $selectors, PaidGrantRows $rows, bool $closed = false): array
    {
        $result = ['assets' => [], 'runs' => [], 'bindings' => [], 'outputs' => []];
        foreach (['assets' => 'media_assets', 'runs' => 'media_processing_runs', 'bindings' => 'stems_recordings'] as $kind => $table) {
            foreach ($selectors[$kind] as $id) {
                $record = $rows->one($table, 'id = ?', [$id], $closed);
                PaidGrantException::require($record !== [], 409);
                $result[$kind][] = $record;
            }
        }
        foreach ($selectors['runs'] as $id) {
            $outputs = $rows->rows('media_assets', 'processing_run_id = ?', [$id], 5, $closed);
            PaidGrantException::require($outputs !== [] && count($outputs) <= 4, 409);
            $result['outputs'][] = $outputs;
        }

        return $result;
    }

    public function proveRetained(array $manifest, PaidGrantRows $rows, bool $closed = false): void
    {
        PaidGrantException::require($manifest['schema_version'] === 'paid-asset-manifest-v1'
            && hash_equals($manifest['raw_hash'], CanonicalJson::hash($this->raw($manifest['selectors'], $rows, $closed))), 409);
    }

    public function snapshots(array $manifest, PaidGrantRows $rows): array
    {
        $this->proveRetained($manifest, $rows);
        $result = [];
        foreach (['assets' => 'media_assets', 'runs' => 'media_processing_runs', 'bindings' => 'stems_recordings'] as $kind => $table) {
            foreach ($manifest['selectors'][$kind] as $id) {
                $result[] = [$table, 'id = ?', [$id], 2, [$rows->one($table, 'id = ?', [$id])]];
            }
        }
        foreach ($manifest['selectors']['runs'] as $id) {
            $result[] = ['media_assets', 'processing_run_id = ?', [$id], 5, $rows->rows('media_assets', 'processing_run_id = ?', [$id], 5)];
        }

        return $result;
    }

    /** Physical observations are bounded and performed outside all application transactions. */
    public function verify(array $manifest, ?int $deadline = null): void
    {
        $deadline ??= hrtime(true) + 300_000_000_000;
        PaidGrantException::require($manifest['schema_version'] === 'paid-asset-manifest-v1'
            && array_is_list($manifest['files']) && count($manifest['files']) >= 1 && count($manifest['files']) <= 3);
        foreach ($manifest['files'] as $file) {
            PaidGrantException::require(in_array($file['scan_scope'], ['test-only', 'clamav'], true)
                && ($file['scan_scope'] !== 'test-only' || app()->environment('testing')));
            app(DeliveryAssetFiles::class)->verify($file, $deadline);
        }
    }

    private function hasTestScan(array $value): bool
    {
        if (($value['engine'] ?? null) === 'test-only') {
            return true;
        }
        foreach ($value as $item) {
            if (is_array($item) && $this->hasTestScan($item)) {
                return true;
            }
        }

        return false;
    }
}
