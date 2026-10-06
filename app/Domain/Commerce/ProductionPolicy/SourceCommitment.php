<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyDraft as SourceDraft;
use App\Domain\Commerce\Policy\ProductionTrackPolicyDraft as AuthoredSchema;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Authenticated historical source v1 reader. Declarations and acknowledgments are not external facts. */
final class SourceCommitment
{
    public const DRAFTS = 'production_track_policy_drafts';

    public const VERSIONS = 'production_track_policy_versions';

    public const REVIEWS = 'production_track_policy_source_reviews';

    public static function load(CurrentRows $reader, int $id): array
    {
        $draft = $reader->one(self::DRAFTS, $id);
        MachinePolicyV1::require($draft !== [] && is_int($draft['revision']) && $draft['revision'] >= 1 && $draft['revision'] <= 256);
        $versions = $reader->rows(self::VERSIONS, 'production_track_policy_draft_id = ?', [$id], 257);
        MachinePolicyV1::require(count($versions) === $draft['revision']);
        $ids = array_column($versions, 'id');
        $reviews = $reader->rows(self::REVIEWS, 'production_track_policy_version_id IN ('.implode(', ', array_fill(0, count($ids), '?')).')', $ids, 257);
        $audits = $reader->audits(SourceDraft::class, $id, 513);
        MachinePolicyV1::require(count($reviews) <= 256 && count($audits) <= 512);
        $authored = [];
        foreach ($versions as $position => $version) {
            MachinePolicyV1::require($version['number'] === $position + 1 && $version['schema_version'] === 1);
            $authored[$version['id']] = AuthoredSchema::validateAuthored(self::open($version['payload_ciphertext'], $version['payload_hash'], $version['canonicalization_version'], 32768));
        }
        foreach ($reviews as $review) {
            self::acknowledgment($review, $versions, $draft);
        }

        return ['rows' => ['draft' => $draft, 'versions' => $versions, 'reviews' => $reviews, 'audits' => $audits], 'authored' => $authored];
    }

    public static function current(array $snapshot): array
    {
        $rows = $snapshot['rows'];
        $version = end($rows['versions']);
        $matches = array_values(array_filter($rows['reviews'], fn (array $review): bool => $review['production_track_policy_version_id'] === $version['id']));
        MachinePolicyV1::require(count($matches) === 1);
        foreach ($snapshot['authored'][$version['id']]['declarations'] as $declaration) {
            MachinePolicyV1::require($declaration['state'] === 'declared');
        }

        return ['draft_id' => $rows['draft']['id'], 'draft_public_id' => $rows['draft']['public_id'], 'revision' => $rows['draft']['revision'],
            'version_id' => $version['id'], 'version_cipher_hash' => $version['payload_hash'],
            'source_review_id' => $matches[0]['id'], 'review_cipher_hash' => $matches[0]['review_hash'], 'source_graph_hash' => CanonicalJson::hash($rows)];
    }

    public static function historical(array $commitment, array $snapshot): array
    {
        MachinePolicyV1::record($commitment, ['draft_id', 'draft_public_id', 'revision', 'version_id', 'version_cipher_hash', 'source_review_id', 'review_cipher_hash', 'source_graph_hash']);
        $rows = $snapshot['rows'];
        $versions = array_values(array_filter($rows['versions'], fn (array $row): bool => $row['id'] === $commitment['version_id']));
        $reviews = array_values(array_filter($rows['reviews'], fn (array $row): bool => $row['id'] === $commitment['source_review_id']));
        MachinePolicyV1::require(count($versions) === 1 && count($reviews) === 1 && $commitment['draft_id'] === $rows['draft']['id']
            && $commitment['draft_public_id'] === $rows['draft']['public_id'] && $commitment['revision'] === $versions[0]['number']
            && $commitment['version_cipher_hash'] === $versions[0]['payload_hash'] && $commitment['review_cipher_hash'] === $reviews[0]['review_hash']
            && $reviews[0]['production_track_policy_version_id'] === $versions[0]['id'] && self::hash($commitment['source_graph_hash']));
        // Reconstruct the retained prefix at creation. Later source versions may
        // append, but may never rewrite the source graph a candidate committed.
        $prefix = $rows;
        $prefix['draft']['revision'] = $commitment['revision'];
        $prefix['draft']['updated_at'] = $versions[0]['created_at'];
        $prefix['versions'] = array_values(array_filter($rows['versions'], fn (array $row): bool => $row['number'] <= $commitment['revision']));
        $ids = array_column($prefix['versions'], 'id');
        $prefix['reviews'] = array_values(array_filter($rows['reviews'], fn (array $row): bool => in_array($row['production_track_policy_version_id'], $ids, true)));
        $prefix['audits'] = array_values(array_filter($rows['audits'], fn (array $row): bool => in_array($row['context']['version_id'] ?? null, $ids, true)));
        MachinePolicyV1::require(CanonicalJson::hash($prefix) === $commitment['source_graph_hash']);

        return $snapshot['authored'][$versions[0]['id']];
    }

    public static function open(mixed $ciphertext, mixed $hash, mixed $canonicalization, int $maximum): array
    {
        try {
            MachinePolicyV1::require(is_string($ciphertext) && strlen($ciphertext) <= 131072 && self::hash($hash)
                && hash_equals($hash, hash('sha256', $ciphertext)) && $canonicalization === CanonicalJson::VERSION);
            $canonical = Crypt::decryptString($ciphertext);
            MachinePolicyV1::require(strlen($canonical) <= $maximum);
            $value = json_decode($canonical, true, 12, JSON_THROW_ON_ERROR);
            MachinePolicyV1::require(is_array($value) && CanonicalJson::encode($value) === $canonical);

            return $value;
        } catch (Throwable) {
            MachinePolicyV1::require(false);
        }
    }

    public static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    public static function reference(array $value, string $affirmation): void
    {
        MachinePolicyV1::record($value, ['reference', 'source_sha256', $affirmation]);
        MachinePolicyV1::require(AuthoredSchema::text($value['reference'], 512) && self::hash($value['source_sha256']) && $value[$affirmation] === true
            && preg_match('/(?:sk|rk)_(?:test|live)_[A-Za-z0-9]|whsec_[A-Za-z0-9]/', $value['reference']) !== 1);
    }

    private static function acknowledgment(array $row, array $versions, array $draft): void
    {
        $matches = array_values(array_filter($versions, fn (array $version): bool => $version['id'] === $row['production_track_policy_version_id']));
        MachinePolicyV1::require(count($matches) === 1 && $row['version_evidence_hash'] === $matches[0]['payload_hash'] && $row['reviewed_by'] !== $draft['created_by']);
        $version = $matches[0];
        foreach ($versions as $retained) {
            MachinePolicyV1::require($retained['number'] > $version['number'] || $retained['created_by'] !== $row['reviewed_by']);
        }
        $value = self::open($row['review_ciphertext'], $row['review_hash'], $row['canonicalization_version'], 8192);
        MachinePolicyV1::record($value, ['schema_version', 'purpose', 'reference', 'version_id', 'version_evidence_hash', 'external_facts_verified', 'activation_allowed']);
        MachinePolicyV1::require($value['schema_version'] === 1 && $value['purpose'] === 'authored_production_policy_source_acknowledgment'
            && $value['version_id'] === $version['id'] && $value['version_evidence_hash'] === $version['payload_hash']
            && $value['external_facts_verified'] === false && $value['activation_allowed'] === false && is_array($value['reference']));
        self::reference($value['reference'], 'authored_source_acknowledged');
    }
}
