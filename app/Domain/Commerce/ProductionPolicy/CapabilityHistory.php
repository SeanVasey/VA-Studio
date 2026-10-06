<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;

/** Authenticate every retained immutable artifact, not only the selected current row. */
final class CapabilityHistory
{
    public const CANDIDATES = 'production_track_capability_candidates';

    public const APPROVALS = 'production_track_capability_approvals';

    public const CLOSURES = 'production_track_capability_closures';

    public static function load(CurrentRows $reader, int $sourceId): array
    {
        $source = SourceCommitment::load($reader, $sourceId);
        $candidates = $reader->rows(self::CANDIDATES, 'production_track_policy_draft_id = ?', [$sourceId], 257);
        MachinePolicyV1::require(count($candidates) <= 256);
        $ids = array_column($candidates, 'id');
        $predicate = 'production_track_capability_candidate_id IN ('.implode(', ', array_fill(0, count($ids), '?')).')';
        $approvals = $ids === [] ? [] : $reader->rows(self::APPROVALS, $predicate, $ids, 257);
        $closures = $ids === [] ? [] : $reader->rows(self::CLOSURES, $predicate, $ids, 257);
        $audits = $reader->audits(ProductionTrackCapabilities::class, $sourceId, 769);
        MachinePolicyV1::require(count($approvals) <= 256 && count($closures) <= 256 && count($audits) <= 768);
        $bodies = [];
        foreach ($candidates as $position => $candidate) {
            MachinePolicyV1::require($candidate['generation'] === $position + 1 && $candidate['schema_version'] === 1 && self::uuid($candidate['public_id']));
            $value = SourceCommitment::open($candidate['payload_ciphertext'], $candidate['payload_hash'], $candidate['canonicalization_version'], 65536);
            MachinePolicyV1::record($value, ['schema_version', 'purpose', 'public_id', 'generation', 'created_by', 'created_at', 'source', 'machine', 'external_facts_verified', 'execution_allowed']);
            MachinePolicyV1::require($value['schema_version'] === 1 && $value['purpose'] === 'production_track_machine_candidate_record'
                && $value['public_id'] === $candidate['public_id'] && $value['generation'] === $candidate['generation'] && $value['created_by'] === $candidate['created_by'] && $value['created_at'] === $candidate['created_at']
                && $value['external_facts_verified'] === false && $value['execution_allowed'] === false && is_array($value['source']) && is_array($value['machine']));
            $authored = SourceCommitment::historical($value['source'], $source);
            MachinePolicyV1::forSource($value['machine'], $authored);
            MachinePolicyV1::require($candidate['production_track_policy_version_id'] === $value['source']['version_id']
                && $candidate['production_track_policy_source_review_id'] === $value['source']['source_review_id'] && $candidate['source_graph_hash'] === $value['source']['source_graph_hash']
                && $candidate['version_key'] === self::versionKey($value['machine']['version']));
            $bodies[$candidate['id']] = $value;
        }
        foreach ($approvals as $approval) {
            $candidate = self::find($candidates, $approval['production_track_capability_candidate_id']);
            $value = SourceCommitment::open($approval['approval_ciphertext'], $approval['approval_hash'], $approval['canonicalization_version'], 8192);
            MachinePolicyV1::record($value, ['schema_version', 'purpose', 'candidate_id', 'candidate_hash', 'generation', 'reviewed_by', 'created_at', 'source', 'reference', 'external_facts_verified', 'execution_allowed']);
            MachinePolicyV1::require($value['schema_version'] === 1 && $value['purpose'] === 'production_track_machine_software_approval'
                && $value['candidate_id'] === $candidate['id'] && $value['candidate_hash'] === $candidate['payload_hash'] && $approval['candidate_hash'] === $candidate['payload_hash']
                && $value['generation'] === $candidate['generation'] && $value['reviewed_by'] === $approval['reviewed_by'] && $value['created_at'] === $approval['created_at']
                && $value['source'] === $bodies[$candidate['id']]['source'] && $value['external_facts_verified'] === false && $value['execution_allowed'] === false && is_array($value['reference']));
            SourceCommitment::reference($value['reference'], 'software_choices_reviewed');
            self::independent($source, $candidates, $candidate['generation'], $value['source']['revision'], $approval['reviewed_by']);
        }
        foreach ($closures as $closure) {
            $candidate = self::find($candidates, $closure['production_track_capability_candidate_id']);
            $value = SourceCommitment::open($closure['closure_ciphertext'], $closure['closure_hash'], $closure['canonicalization_version'], 8192);
            MachinePolicyV1::record($value, ['schema_version', 'purpose', 'candidate_id', 'candidate_hash', 'closed_by', 'created_at', 'reason', 'execution_allowed']);
            MachinePolicyV1::require($value['schema_version'] === 1 && $value['purpose'] === 'production_track_machine_irreversible_closure'
                && $value['candidate_id'] === $candidate['id'] && $value['candidate_hash'] === $candidate['payload_hash'] && $closure['candidate_hash'] === $candidate['payload_hash']
                && $value['closed_by'] === $closure['closed_by'] && $value['created_at'] === $closure['created_at'] && $value['execution_allowed'] === false && is_array($value['reason']));
            self::reason($value['reason']);
        }
        $expectedAudits = [];
        foreach ($candidates as $candidate) {
            $expectedAudits[] = self::audit('candidate_saved', $sourceId, $candidate['id'], $candidate['payload_hash'], $candidate['created_by'], $candidate['created_at']);
        }
        foreach ($approvals as $approval) {
            $expectedAudits[] = self::audit('software_approved', $sourceId, $approval['production_track_capability_candidate_id'], $approval['approval_hash'], $approval['reviewed_by'], $approval['created_at']);
        }
        foreach ($closures as $closure) {
            $expectedAudits[] = self::audit('candidate_closed', $sourceId, $closure['production_track_capability_candidate_id'], $closure['closure_hash'], $closure['closed_by'], $closure['created_at']);
        }
        $auditBodies = array_map(function (array $row): string {
            unset($row['id']);

            return CanonicalJson::encode($row);
        }, $audits);
        $expectedBodies = array_map(CanonicalJson::encode(...), $expectedAudits);
        sort($auditBodies);
        sort($expectedBodies);
        MachinePolicyV1::require($auditBodies === $expectedBodies);

        return ['source' => $source, 'candidates' => $candidates, 'approvals' => $approvals, 'closures' => $closures, 'audits' => $audits, 'bodies' => $bodies];
    }

    public static function raw(array $snapshot): array
    {
        return ['source' => $snapshot['source']['rows'], 'candidates' => $snapshot['candidates'], 'approvals' => $snapshot['approvals'], 'closures' => $snapshot['closures'], 'audits' => $snapshot['audits']];
    }

    public static function baseline(array $snapshot): string
    {
        return CanonicalJson::hash(self::raw($snapshot));
    }

    public static function find(array $rows, int $id): array
    {
        $matches = array_values(array_filter($rows, fn (array $row): bool => $row['id'] === $id));
        MachinePolicyV1::require(count($matches) === 1);

        return $matches[0];
    }

    public static function eligible(array $snapshot, array $candidate): array
    {
        $latest = end($snapshot['candidates']);
        $source = SourceCommitment::current($snapshot['source']);
        MachinePolicyV1::require($latest !== false && $latest['id'] === $candidate['id']
            && CanonicalJson::encode($source) === CanonicalJson::encode($snapshot['bodies'][$candidate['id']]['source'])
            && ! in_array($candidate['id'], array_column($snapshot['closures'], 'production_track_capability_candidate_id'), true));

        return $source;
    }

    public static function independent(array $source, array $candidates, int $generation, int $revision, int $actorId): void
    {
        if ($source['rows']['draft']['created_by'] === $actorId) {
            throw new AuthorizationException;
        }
        foreach ($source['rows']['versions'] as $row) {
            if ($row['number'] <= $revision && $row['created_by'] === $actorId) {
                throw new AuthorizationException;
            }
        }
        foreach ($candidates as $row) {
            if ($row['generation'] <= $generation && $row['created_by'] === $actorId) {
                throw new AuthorizationException;
            }
        }
    }

    public static function versionKey(string $version): string
    {
        $key = config('app.key');
        MachinePolicyV1::require(is_string($key) && $key !== '');

        return hash_hmac('sha256', 'production-machine-version-v1:'.$version, $key);
    }

    public static function reason(array $value): void
    {
        MachinePolicyV1::record($value, ['reason_code', 'reference', 'source_sha256', 'closure_requested']);
        MachinePolicyV1::require(in_array($value['reason_code'], ['owner_withdrawal', 'source_changed', 'software_policy_replaced', 'configuration_unavailable', 'security_review'], true));
        $reference = $value;
        unset($reference['reason_code']);
        SourceCommitment::reference($reference, 'closure_requested');
    }

    public static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) === 1;
    }

    private static function audit(string $action, int $sourceId, int $candidateId, string $hash, int $actorId, string $at): array
    {
        return ['actor_id' => $actorId, 'action' => 'commerce.production_capability.'.$action, 'subject_type' => ProductionTrackCapabilities::class,
            'subject_id' => $sourceId, 'context' => ['schema_version' => 1, 'candidate_id' => $candidateId, 'evidence_hash' => $hash,
                'external_facts_verified' => false, 'execution_allowed' => false], 'created_at' => $at];
    }
}
