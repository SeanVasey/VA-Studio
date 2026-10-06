<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1 as Check;
use App\Domain\Media\MediaProfile;
use App\Domain\Media\ScanEngines;
use App\Domain\Media\StemsArchive;
use App\Domain\Rights\LicenseContent;
use App\Domain\Rights\LicenseReviewPayload;
use App\Domain\Rights\LicenseTerms;
use App\Domain\Rights\Models\LicenseVersion;
use App\Support\CanonicalJson;
use App\Support\Money\MinorUnits;
use Carbon\CarbonImmutable;

/** Primary row evidence, with the existing offline renderer bound to that evidence. No private file I/O. */
final class PreparationSelection
{
    public static function items(array $items): array
    {
        Check::require(array_is_list($items) && count($items) >= 1 && count($items) <= 10);
        $result = [];
        foreach ($items as $item) {
            Check::require(is_array($item));
            Check::record($item, ['trackId', 'offerId', 'licenseVersionId', 'offerRevisionId']);
            foreach ($item as $id) {
                Check::require(is_int($id) && $id > 0 && $id <= MinorUnits::MAX);
            }
            Check::require(! isset($result[$item['trackId']]));
            $result[$item['trackId']] = $item;
        }
        ksort($result, SORT_NUMERIC);

        return array_values($result);
    }

    /** Complete sets, including inactive offers and historical media, bind all latest selectors. */
    public static function load(CurrentRows $reader, array $items): array
    {
        $ids = array_column($items, 'trackId');
        $graph = ['tracks' => self::set($reader, 'tracks', 'id', $ids),
            'offers' => self::set($reader, 'offers', 'track_id', $ids),
            'rights' => self::set($reader, 'rights_declarations', 'track_id', $ids),
            'media' => self::set($reader, 'media_assets', 'track_id', $ids),
            'bindings' => self::set($reader, 'stems_recordings', 'track_id', $ids)];
        $graph['revisions'] = self::set($reader, 'offer_revisions', 'offer_id', array_column($graph['offers'], 'id'));
        $graph['licenses'] = self::set($reader, 'license_versions', 'id', array_unique(array_column($graph['revisions'], 'license_version_id')));
        $graph['templates'] = self::set($reader, 'license_templates', 'id', array_unique(array_column($graph['licenses'], 'license_template_id')));
        $graph['reviews'] = self::set($reader, 'license_review_evidence', 'license_version_id', array_column($graph['licenses'], 'id'));
        $graph['runs'] = self::set($reader, 'media_processing_runs', 'source_asset_id', array_column($graph['media'], 'id'));
        // Read each full run output set as well, including malformed cross-track outputs.
        $graph['outputs'] = self::set($reader, 'media_assets', 'processing_run_id', array_column($graph['runs'], 'id'));
        $graph['audits'] = [];
        foreach ([Track::class => $ids, Offer::class => array_column($graph['offers'], 'id')] as $type => $subjects) {
            foreach ($subjects as $id) {
                $rows = $reader->audits($type, $id, 257);
                Check::require(count($rows) <= 256);
                $graph['audits'][] = [$type, $id, $rows];
            }
        }
        Check::require(strlen(CanonicalJson::encode($graph)) <= 393216);

        return $graph;
    }

    private static function set(CurrentRows $reader, string $table, string $column, array $ids): array
    {
        $ids = array_values($ids);
        if ($ids === []) {
            return [];
        }
        $rows = $reader->rows($table, $column.' IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids, 257);
        Check::require(count($rows) <= 256);

        return $rows;
    }

    public static function interpret(array $graph, array $items, CarbonImmutable $at): array
    {
        $lines = [];
        foreach ($items as $item) {
            $track = self::one($graph['tracks'], $item['trackId']);
            Check::require($track['status'] === 'published' && $track['published_at'] !== null && $track['publication_version'] >= 1
                && is_int($track['metadata_version']) && $track['metadata_version'] >= 0 && $track['metadata_version'] <= 2147483647
                && $track['published_slug'] === $track['slug'] && $track['bpm'] >= 20 && $track['bpm'] <= 400);
            foreach (['title', 'slug', 'artist', 'genre', 'musical_key'] as $field) {
                Check::require(is_string($track[$field]) && trim($track[$field]) !== '');
            }
            $rights = self::latest($graph['rights'], fn (array $row): bool => $row['track_id'] === $track['id']);
            Check::require($rights['status'] === 'verified' && $rights['verified_by'] > 0 && $rights['verified_at'] !== null);
            $preview = self::latest($graph['media'], fn (array $row): bool => $row['track_id'] === $track['id'] && $row['role'] === 'preview_tagged' && $row['status'] === 'ready');
            $artwork = self::latest($graph['media'], fn (array $row): bool => $row['track_id'] === $track['id'] && $row['role'] === 'artwork' && $row['status'] === 'ready');
            self::media($graph, $preview);
            self::media($graph, $artwork);
            Check::require((self::json($preview['technical_metadata'])['duration_seconds'] ?? 0) >= 1);
            $offers = array_values(array_filter($graph['offers'], fn (array $row): bool => $row['track_id'] === $track['id'] && (int) $row['is_active'] === 1));
            Check::require($offers !== []);
            $selected = null;
            // Preserve complete active-offer publication row semantics. Unsupported
            // active exclusive/free/mixed composition refuses the entire track.
            foreach ($offers as $offer) {
                $revision = self::one($graph['revisions'], $offer['current_revision_id'] ?? 0);
                $snapshot = self::json($revision['snapshot']);
                Check::require($revision['offer_id'] === $offer['id'] && $revision['track_id'] === $track['id']
                    && $revision['canonicalization_version'] === CanonicalJson::VERSION && $revision['published_at'] !== null
                    && $revision['snapshot_hash'] === CanonicalJson::hash($snapshot) && ($snapshot['schema_version'] ?? null) === 1
                    && ($snapshot['product']['id'] ?? null) === $track['id'] && ($snapshot['commercial']['type'] ?? null) === 'non-exclusive'
                    && ($snapshot['commercial']['currency'] ?? null) === 'USD' && $revision['currency'] === 'USD'
                    && is_int($revision['price_minor']) && $revision['price_minor'] >= 1 && $revision['price_minor'] <= 2147483647
                    && ($snapshot['commercial']['price_minor'] ?? null) === $revision['price_minor']);
                $license = self::license($graph, $revision['license_version_id'], $at);
                Check::require(CanonicalJson::hash($snapshot['license'] ?? []) === CanonicalJson::hash($license));
                $rightsIdentity = array_intersect_key($rights, array_flip(['id', 'track_id', 'provenance_reference', 'sample_disclosure', 'status', 'verified_by']))
                    + ['verified_at' => self::iso($rights['verified_at'])];
                Check::require($revision['rights_declaration_id'] === $rights['id'] && ($snapshot['rights']['id'] ?? null) === $rights['id']
                    && ($snapshot['rights']['identity_hash'] ?? null) === CanonicalJson::hash($rightsIdentity)
                    && CanonicalJson::hash($snapshot['preview'] ?? []) === CanonicalJson::hash(array_intersect_key($preview, array_flip(['id', 'parent_asset_id', 'sha256']))));
                $manifest = $snapshot['assets'] ?? [];
                Check::require(is_array($manifest) && array_is_list($manifest) && $manifest !== []
                    && count(array_unique(array_column($manifest, 'id'))) === count($manifest));
                $roles = array_column($manifest, 'role');
                $required = $license['required_asset_roles'];
                sort($roles);
                sort($required);
                Check::require($roles === $required);
                foreach ($manifest as $entry) {
                    Check::require(is_array($entry) && is_int($entry['id'] ?? null));
                    $asset = self::one($graph['media'], $entry['id']);
                    Check::require($asset['track_id'] === $track['id']);
                    self::media($graph, $asset);
                    $assetSnapshot = array_intersect_key($asset, array_flip(['id', 'role', 'sha256', 'mime_type', 'size_bytes', 'original_name', 'parent_asset_id', 'processing_run_id']));
                    if ($asset['role'] === 'stems_zip') {
                        $assetSnapshot['recording_binding'] = self::stems($graph, $asset, $preview);
                    } else {
                        Check::require(in_array($asset['role'], ['master_wav', 'download_mp3'], true) && $asset['parent_asset_id'] === $preview['parent_asset_id']);
                    }
                    Check::require(CanonicalJson::hash($entry) === CanonicalJson::hash($assetSnapshot));
                }
                if ($offer['id'] === $item['offerId']) {
                    Check::require($revision['id'] === $item['offerRevisionId'] && $revision['license_version_id'] === $item['licenseVersionId']);
                    $selected = ['position' => count($lines) + 1, 'track_id' => $track['id'], 'offer_id' => $offer['id'],
                        'offer_revision_id' => $revision['id'], 'license_version_id' => $revision['license_version_id'],
                        'price_minor' => $revision['price_minor'], 'currency' => 'USD', 'offer_snapshot_hash' => $revision['snapshot_hash'], 'offer_snapshot' => $snapshot];
                }
            }
            Check::require($selected !== null);
            $lines[] = $selected;
        }

        return ['currency' => 'USD', 'minor_unit_exponent' => 2, 'advertised_subtotal_minor' => self::subtotal(array_column($lines, 'price_minor')),
            'tax_minor' => null, 'total_minor' => null, 'tax_state' => 'unresolved', 'assent_state' => 'not_collected', 'buyer_state' => 'not_bound',
            'private_bytes_verified' => false, 'payable' => false, 'execution_allowed' => false, 'external_facts_verified' => false, 'lines' => $lines];
    }

    public static function subtotal(array $prices): int
    {
        return MinorUnits::sum($prices);
    }

    /** Pure terminal check: all licenses in the bound active offer footprint remain effective. */
    public static function effective(array $graph, CarbonImmutable $at): void
    {
        foreach ($graph['offers'] as $offer) {
            if ((int) $offer['is_active'] !== 1) {
                continue;
            }
            $revision = self::one($graph['revisions'], $offer['current_revision_id']);
            $license = self::one($graph['licenses'], $revision['license_version_id']);
            Check::require($license['status'] === 'published' && $license['published_at'] !== null
                && ($license['effective_from'] === null || $at->greaterThanOrEqualTo(CarbonImmutable::parse($license['effective_from'], 'UTC')))
                && ($license['effective_until'] === null || $at->lessThan(CarbonImmutable::parse($license['effective_until'], 'UTC'))));
        }
    }

    private static function license(array $graph, int $id, CarbonImmutable $at): array
    {
        $v = self::one($graph['licenses'], $id);
        $t = self::one($graph['templates'], $v['license_template_id']);
        Check::require($t['type'] === 'non-exclusive');
        self::effective(['offers' => [['is_active' => 1, 'current_revision_id' => 1]], 'revisions' => [['id' => 1, 'license_version_id' => $id]], 'licenses' => [$v]], $at);
        $content = app(LicenseContent::class)->validate(['authored_source' => $v['authored_source'], 'structured_terms' => self::json($v['structured_terms']),
            'effective_from' => $v['effective_from'], 'effective_until' => $v['effective_until']]);
        $authors = self::json($v['content_author_ids']);
        Check::require(array_is_list($authors) && $authors !== [] && count(array_unique($authors)) === count($authors)
            && count(array_filter($authors, is_int(...))) === count($authors) && in_array($v['author_id'], $authors, true)
            && ! in_array($v['approved_by'], $authors, true) && $v['submitted_by'] > 0 && $v['submitted_at'] !== null
            && $v['approved_by'] > 0 && $v['approved_at'] !== null && $v['canonicalization_version'] === CanonicalJson::VERSION
            && $v['terms_schema_version'] === $content['structured_terms']['schema_version']);
        $payload = ['canonicalization_version' => CanonicalJson::VERSION, 'license_version_id' => $v['id'], 'version' => $v['version'],
            'template' => array_intersect_key($t, array_flip(['id', 'name', 'slug', 'type'])), 'author_id' => $v['author_id'], 'content_author_ids' => $authors,
            'predecessor_id' => $v['predecessor_id'], 'authored_source' => $content['authored_source'], 'structured_terms' => $content['structured_terms'],
            'source_hash' => hash('sha256', $content['authored_source']), 'model_hash' => CanonicalJson::hash($content['structured_terms']),
            'effective_from' => $content['effective_from']?->format('Y-m-d\TH:i:s\Z'), 'effective_until' => $content['effective_until']?->format('Y-m-d\TH:i:s\Z'),
            'renderer_version' => $v['renderer_version'], 'render_fixture_hash' => $v['render_fixture_hash']];
        // Re-render the existing offline review fixture. The version is hydrated
        // from current primary rows; the helper's ordinary template identity and
        // renderer result must match this reconstructed primary-row payload.
        $currentVersion = new LicenseVersion;
        $currentVersion->setRawAttributes($v, true);
        $currentVersion->exists = true;
        Check::require(CanonicalJson::encode(app(LicenseReviewPayload::class)->build($currentVersion)) === CanonicalJson::encode($payload));
        Check::require($v['source_hash'] === $payload['source_hash'] && $v['model_hash'] === $payload['model_hash']
            && is_string($v['renderer_version']) && $v['renderer_version'] !== '' && preg_match('/\A[a-f0-9]{64}\z/D', $v['render_fixture_hash']) === 1
            && CanonicalJson::hash($payload) === $v['submission_hash'] && CanonicalJson::hash(self::json($v['submission_payload'])) === $v['submission_hash']);
        $reviews = array_values(array_filter($graph['reviews'], fn (array $r): bool => $r['license_version_id'] === $id));
        Check::require(count($reviews) === 1);
        $r = $reviews[0];
        $evidence = ['license_version_id' => $id, 'submission_hash' => $r['submission_hash'], 'reviewer_id' => $r['reviewer_id'],
            'approval_reference' => $r['approval_reference'], 'summary_consistency_confirmed' => (bool) $r['summary_consistency_confirmed'],
            'reviewed_at' => CarbonImmutable::parse($r['reviewed_at'], 'UTC')->format('Y-m-d\TH:i:s\Z'), 'canonicalization_version' => CanonicalJson::VERSION];
        Check::require($r['reviewer_id'] === $v['approved_by'] && $r['submission_hash'] === $v['submission_hash']
            && $r['approval_reference'] === $v['approval_reference'] && (int) $r['summary_consistency_confirmed'] === 1
            && $r['reviewed_at'] === $v['approved_at'] && CanonicalJson::hash($evidence) === $r['evidence_hash']);
        $terms = $content['structured_terms'];
        $features = $terms['schema_version'] === 1 ? $terms['features'] : array_values(app(LicenseTerms::class)->statements($terms));

        return ['id' => $id, 'template_id' => $t['id'], 'name' => $t['name'], 'version' => $v['version'], 'type' => $t['type'],
            'authored_source' => $v['authored_source'], 'source_hash' => $v['source_hash'], 'model_hash' => $v['model_hash'], 'structured_terms' => $terms,
            'features' => $features, 'required_asset_roles' => $terms['required_asset_roles'], 'renderer_version' => $v['renderer_version'], 'render_fixture_hash' => $v['render_fixture_hash'],
            'submission_hash' => $v['submission_hash'], 'review_evidence_id' => $r['id'], 'review_evidence_hash' => $r['evidence_hash'], 'approval_reference' => $v['approval_reference'],
            'approved_by' => $v['approved_by'], 'approved_at' => self::iso($v['approved_at']), 'effective_from' => self::iso($v['effective_from']), 'effective_until' => self::iso($v['effective_until'])];
    }

    private static function media(array $graph, array $asset): void
    {
        Check::require($asset['status'] === 'ready' && $asset['disk'] === 'local' && $asset['verified_at'] !== null && $asset['verified_by'] > 0
            && $asset['parent_asset_id'] > 0 && $asset['processing_run_id'] > 0 && preg_match('~\Amedia/revisions/[a-f0-9-]{36}/(?:master\.wav|delivery\.mp3|preview\.mp3|artwork\.png|stems\.zip)\z~D', $asset['storage_path']) === 1);
        $source = self::one($graph['media'], $asset['parent_asset_id']);
        $run = self::one($graph['runs'], $asset['processing_run_id']);
        $profile = self::json($run['profile']);
        $evidence = self::json($run['evidence']);
        $ids = self::json($run['output_asset_ids']);
        Check::require($source['status'] === 'processed' && $source['track_id'] === $asset['track_id'] && $run['source_asset_id'] === $source['id']
            && $run['status'] === 'completed' && $run['completed_at'] !== null && $run['input_sha256'] === $source['sha256']
            && $run['profile_fingerprint'] === (new MediaProfile)->fingerprint($profile) && in_array($asset['id'], $ids, true));
        self::scan($evidence['source_scan'] ?? [], $run['input_sha256']);
        $expected = match ($source['role']) {
            'master_wav' => ['download_mp3', 'master_wav', 'preview_tagged'], 'artwork' => ['artwork'], 'stems_zip' => ['stems_zip'], default => []
        };
        $outputs = array_values(array_filter($graph['outputs'], fn (array $r): bool => $r['processing_run_id'] === $run['id']));
        $roles = array_column($outputs, 'role');
        sort($roles);
        $actualIds = array_column($outputs, 'id');
        sort($actualIds);
        sort($ids);
        Check::require($expected !== [] && $roles === $expected && $actualIds === $ids);
        foreach ($outputs as $output) {
            Check::require($output['track_id'] === $source['track_id'] && $output['parent_asset_id'] === $source['id'] && $output['status'] === 'ready');
        }
        if ($source['role'] === 'master_wav') {
            self::scan($evidence['tag_scan'] ?? [], $profile['tag_sha256'] ?? '');
        }
        if ($asset['role'] === 'master_wav') {
            Check::require($asset['sha256'] === $run['input_sha256']);
        }
        $metadata = self::json($asset['technical_metadata']);
        if ($source['role'] === 'stems_zip') {
            Check::require((new StemsArchive)->hasEvidence($metadata, $asset['sha256'], $profile));
        }
        if ($asset['role'] === 'preview_tagged') {
            Check::require(! empty($metadata['waveform']) && ($metadata['waveform_sha256'] ?? null) === hash('sha256', json_encode($metadata['waveform'], JSON_THROW_ON_ERROR))
                && ($metadata['tag_sha256'] ?? null) === ($profile['tag_sha256'] ?? null) && ($metadata['full_length'] ?? null) === true);
        }
    }

    private static function scan(array $scan, string $hash): void
    {
        Check::require(($scan['status'] ?? null) === 'clean' && ($scan['sha256'] ?? null) === $hash && ScanEngines::accepted($scan['engine'] ?? null));
    }

    private static function stems(array $graph, array $stems, array $currentPreview): array
    {
        $bindings = array_values(array_filter($graph['bindings'], fn (array $r): bool => $r['stems_asset_id'] === $stems['id']));
        Check::require(count($bindings) === 1);
        $b = $bindings[0];
        $master = self::one($graph['media'], $b['master_asset_id']);
        $preview = self::one($graph['media'], $b['preview_asset_id']);
        self::media($graph, $master);
        self::media($graph, $preview);
        Check::require($master['role'] === 'master_wav' && $preview['role'] === 'preview_tagged' && $b['track_id'] === $stems['track_id']
            && $master['track_id'] === $stems['track_id'] && $preview['track_id'] === $stems['track_id'] && $b['verified_by'] > 0 && $b['verified_at'] !== null
            && trim($b['verification_reference']) !== '' && $b['recording_source_id'] === $master['parent_asset_id']
            && $preview['parent_asset_id'] === $master['parent_asset_id'] && $preview['processing_run_id'] === $master['processing_run_id']
            && $currentPreview['parent_asset_id'] === $b['recording_source_id'] && $b['canonicalization_version'] === CanonicalJson::VERSION);
        $identity = fn (array $r): array => array_intersect_key($r, array_flip(['id', 'track_id', 'role', 'sha256', 'size_bytes', 'parent_asset_id', 'processing_run_id']));
        $evidence = ['schema_version' => 1, 'track_id' => $b['track_id'], 'recording_source_id' => $b['recording_source_id'],
            'stems' => $identity($stems) + ['manifest_sha256' => self::json($stems['technical_metadata'])['manifest_sha256'] ?? null],
            'master' => $identity($master), 'preview' => $identity($preview), 'attestation' => ['same_recording_confirmed' => true,
                'reference' => $b['verification_reference'], 'verified_by' => $b['verified_by'], 'verified_at' => self::iso($b['verified_at'])]];
        Check::require($b['evidence_hash'] === CanonicalJson::hash($evidence) && $b['evidence_hash'] === CanonicalJson::hash(self::json($b['evidence'])));

        return array_intersect_key($b, array_flip(['id', 'stems_asset_id', 'master_asset_id', 'preview_asset_id', 'recording_source_id', 'evidence_hash']));
    }

    public static function one(array $rows, int $id): array
    {
        $matches = array_values(array_filter($rows, fn (array $r): bool => $r['id'] === $id));
        Check::require(count($matches) === 1);

        return $matches[0];
    }

    private static function latest(array $rows, callable $matches): array
    {
        $selected = array_values(array_filter($rows, $matches));
        Check::require($selected !== []);

        return $selected[count($selected) - 1];
    }

    private static function json(?string $value): array
    {
        $value = $value === null ? [] : json_decode($value, true, 24, JSON_THROW_ON_ERROR);
        Check::require(is_array($value));

        return $value;
    }

    private static function iso(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toISOString();
    }
}
