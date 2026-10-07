<?php

namespace App\Domain\Grants\Free;

use App\Domain\Catalog\OfferSnapshot;
use App\Domain\Delivery\MediaEvidenceValues;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\LicenseDisclosure;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\VerifiedLicense;
use App\Support\CanonicalJson;

/** Full reviewed rights/source graph; physical durability remains a separate outside-transaction check. */
final class FreeGrantSources
{
    public function capture(int $licenseId, int $trackId, int $scopeId, array $assetIds, FreeGrantRows $rows): array
    {
        $raw = $this->raw($licenseId, $trackId, $scopeId, $assetIds, $rows);
        $version = new LicenseVersion;
        $version->setRawAttributes($raw['license'], true);
        $version->exists = true;
        FreeGrantException::require(app(VerifiedLicense::class)->availableForPublication($version), 409);
        $license = app(OfferSnapshot::class)->license($version);
        FreeGrantException::require($license['type'] === 'non-exclusive', 422);
        $required = $license['required_asset_roles'];
        sort($required);
        $assets = [];
        foreach ($raw['assets'] as $item) {
            $asset = new MediaAsset;
            $asset->setRawAttributes($item, true);
            $asset->exists = true;
            FreeGrantException::require((int) $asset->track_id === $trackId
                && in_array($asset->role, ['master_wav', 'download_mp3'], true), 422);
            $evidence = app(VerifiedMedia::class)->evidence($asset, historical: true);
            FreeGrantException::require($evidence !== null, 409);
            $testScan = $this->testScan($evidence);
            FreeGrantException::require(! $testScan || app()->environment('testing'), 409);
            $assets[] = $asset->only(['id', 'track_id', 'role', 'disk', 'storage_path', 'sha256', 'mime_type', 'size_bytes', 'original_name'])
                + ['provenance' => MediaEvidenceValues::reference($evidence), 'scan_scope' => $testScan ? 'test-only' : 'clamav'];
        }
        $roles = array_column($assets, 'role');
        sort($roles);
        FreeGrantException::require($roles !== [] && $roles === $required && count(array_unique($roles)) === count($roles), 422);
        $this->requireAdmission($scopeId, $rows);
        FreeGrantException::require($this->raw($licenseId, $trackId, $scopeId, $assetIds, $rows) === $raw, 409);
        $product = $rows->one('tracks', 'id = ?', [$trackId]);

        return ['license' => $license, 'disclosure' => app(LicenseDisclosure::class)->fromSnapshot($license),
            'product' => array_intersect_key($product, array_flip(['id', 'slug', 'title', 'artist'])),
            'assets' => $assets, 'raw' => $raw, 'hash' => CanonicalJson::hash($raw)];
    }

    public function proveCurrent(array $snapshot, FreeGrantRows $rows, bool $newAdmission): void
    {
        $raw = $snapshot['raw'];
        $current = $this->raw((int) $raw['license']['id'], (int) $raw['track']['id'], (int) $raw['scope']['id'],
            array_column($raw['assets'], 'id'), $rows, $newAdmission ? null : (int) $raw['clearance']['id']);
        FreeGrantException::require(CanonicalJson::encode($current) === CanonicalJson::encode($raw)
            && hash_equals($snapshot['hash'], CanonicalJson::hash($current)), 409);
        if ($newAdmission) {
            $license = $raw['license'];
            FreeGrantException::require($license['status'] === 'published' && $license['published_at'] !== null
                && ($license['effective_from'] === null || now()->greaterThanOrEqualTo($license['effective_from']))
                && ($license['effective_until'] === null || now()->lessThan($license['effective_until'])), 409);
            $this->requireAdmission((int) $raw['scope']['id'], $rows);
        }
    }

    private function raw(int $licenseId, int $trackId, int $scopeId, array $assetIds, FreeGrantRows $rows, ?int $clearanceId = null): array
    {
        FreeGrantException::require(array_is_list($assetIds) && count($assetIds) >= 1 && count($assetIds) <= 2
            && count(array_unique($assetIds)) === count($assetIds), 422);
        sort($assetIds, SORT_NUMERIC);
        $license = $rows->one('license_versions', 'id = ?', [$licenseId]);
        FreeGrantException::require($license !== [], 404);
        $template = $rows->one('license_templates', 'id = ?', [(int) $license['license_template_id']]);
        $review = $rows->one('license_review_evidence', 'license_version_id = ?', [$licenseId]);
        $trackRow = $rows->one('tracks', 'id = ?', [$trackId]);
        $scopeRow = $rows->one('rights_scopes', 'id = ?', [$scopeId]);
        FreeGrantException::require($template !== [] && $review !== [] && $trackRow !== [] && $scopeRow !== [], 404);
        // Current titles/control can change; retained source identity/rights stay frozen separately.
        $track = ['id' => $trackRow['id']];
        $scope = array_diff_key($scopeRow, array_flip(['blocked', 'control_version']));
        $assets = $parents = $runs = $outputs = [];
        foreach ($assetIds as $id) {
            $asset = $rows->one('media_assets', 'id = ?', [$id]);
            FreeGrantException::require($asset !== [] && (int) $asset['track_id'] === $trackId && $asset['parent_asset_id'] !== null && $asset['processing_run_id'] !== null, 409);
            $assets[] = $asset;
            $parent = $rows->one('media_assets', 'id = ?', [(int) $asset['parent_asset_id']]);
            $run = $rows->one('media_processing_runs', 'id = ?', [(int) $asset['processing_run_id']]);
            FreeGrantException::require($parent !== [] && $run !== [], 409);
            $parents[(int) $parent['id']] = $parent;
            $runs[(int) $run['id']] = $run;
            $outputs[(int) $run['id']] = $rows->rows('media_assets', 'processing_run_id = ?', [(int) $run['id']], 5);
            FreeGrantException::require(count($outputs[(int) $run['id']]) <= 4, 409);
        }
        ksort($parents, SORT_NUMERIC);
        ksort($runs, SORT_NUMERIC);
        ksort($outputs, SORT_NUMERIC);
        // Source clearance is explicitly bound by the operator and separate reviewer.
        $rights = $clearanceId === null ? $rows->rows('rights_declarations', 'track_id = ?', [$trackId])
            : $rows->rows('rights_declarations', 'id = ? AND track_id = ?', [$clearanceId, $trackId]);
        FreeGrantException::require($rights !== [] && count($rights) <= 1000, 409);
        $clearance = $rights[array_key_last($rights)];
        FreeGrantException::require($clearance['status'] === 'verified' && $clearance['verified_by'] !== null && $clearance['verified_at'] !== null, 409);

        return compact('license', 'template', 'review', 'track', 'scope', 'assets', 'parents', 'runs', 'outputs', 'clearance');
    }

    private function requireAdmission(int $scopeId, FreeGrantRows $rows): void
    {
        $scope = $rows->one('rights_scopes', 'id = ?', [$scopeId]);
        FreeGrantException::require($scope !== [] && ! $scope['blocked'], 409);
        $claims = $rows->rows('inventory_claims', 'rights_scope_id = ?', [$scopeId]);
        FreeGrantException::require(count($claims) <= 1000, 503);
        foreach ($claims as $claim) {
            $reservation = $rows->one('inventory_reservations', 'id = ?', [(int) $claim['inventory_reservation_id']]);
            FreeGrantException::require($reservation !== [] && ($reservation['state'] === 'expired'
                || $reservation['state'] === 'held' && now()->greaterThanOrEqualTo($reservation['expires_at'])), 409);
        }
    }

    private function testScan(array $value): bool
    {
        if (($value['engine'] ?? null) === 'test-only') {
            return true;
        }
        foreach ($value as $item) {
            if (is_array($item) && $this->testScan($item)) {
                return true;
            }
        }

        return false;
    }
}
