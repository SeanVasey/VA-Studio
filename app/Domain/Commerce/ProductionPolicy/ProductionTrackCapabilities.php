<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyDraft;
use App\Domain\Commerce\ProductionPolicy\Models\ImmutableCapabilityEvidence;
use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityApproval;
use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityCandidate;
use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityClosure;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use PDO;

/** Immutable preparation contract. No provider, order, price, payment or grant is created here. */
final class ProductionTrackCapabilities
{
    public function prepareSave(?ProductionTrackCapabilityCandidate $candidate, ProductionTrackPolicyDraft $source, array $machine, User $actor): array
    {
        return $this->transaction($actor, function (User $current, array $authority, CurrentRows $reader) use ($candidate, $source, $machine): array {
            MachinePolicyV1::require(is_int($source->getKey()) && $source->getKey() > 0);
            $snapshot = CapabilityHistory::load($reader, $source->getKey());
            $latest = end($snapshot['candidates']);
            MachinePolicyV1::require($candidate === null ? $latest === false : $latest !== false && $latest['id'] === $candidate->getKey());
            $commitment = SourceCommitment::current($snapshot['source']);
            $machine = MachinePolicyV1::forSource($machine, $snapshot['source']['authored'][$commitment['version_id']]);
            $capture = $this->capture('save', $current, $authority, $snapshot, $latest === false ? null : $latest['id'],
                $latest === false ? (string) Str::uuid() : $latest['public_id'], $machine);
            $this->finish($current, $authority, $snapshot, $reader);

            return $capture;
        });
    }

    public function applySave(array $review, User $actor): ProductionTrackCapabilityCandidate
    {
        return $this->transaction($actor, function (User $current, array $authority, CurrentRows $reader) use ($review): ProductionTrackCapabilityCandidate {
            $snapshot = $this->verify($review, 'save', $current, $authority, $reader);
            $commitment = SourceCommitment::current($snapshot['source']);
            MachinePolicyV1::require(is_array($review['after']));
            $machine = MachinePolicyV1::forSource($review['after'], $snapshot['source']['authored'][$commitment['version_id']]);
            $latest = end($snapshot['candidates']);
            MachinePolicyV1::require($latest === false ? $review['candidate_id'] === null : $latest['id'] === $review['candidate_id'] && $latest['public_id'] === $review['public_id']);
            if ($latest !== false) {
                $before = $snapshot['bodies'][$latest['id']];
                if (CanonicalJson::encode($before['source']) === CanonicalJson::encode($commitment)
                    && CanonicalJson::encode($before['machine']) === CanonicalJson::encode($machine)) {
                    CapabilityHistory::eligible($snapshot, $latest);
                    $model = $this->model(ProductionTrackCapabilityCandidate::class, $latest);
                    $this->finish($current, $authority, $snapshot, $reader);

                    return $model;
                }
            }
            $versionKey = CapabilityHistory::versionKey($machine['version']);
            MachinePolicyV1::require(count($snapshot['candidates']) < 256 && ! in_array($versionKey, array_column($snapshot['candidates'], 'version_key'), true));
            $at = now()->utc()->format('Y-m-d H:i:s');
            $generation = count($snapshot['candidates']) + 1;
            $publicId = $latest === false ? $review['public_id'] : (string) Str::uuid();
            $body = ['schema_version' => 1, 'purpose' => 'production_track_machine_candidate_record', 'public_id' => $publicId,
                'generation' => $generation, 'created_by' => (int) $current->id, 'created_at' => $at, 'source' => $commitment,
                'machine' => $machine, 'external_facts_verified' => false, 'execution_allowed' => false];
            $row = ['public_id' => $publicId, 'production_track_policy_draft_id' => $review['source_id'],
                'production_track_policy_version_id' => $commitment['version_id'], 'production_track_policy_source_review_id' => $commitment['source_review_id'],
                'generation' => $generation, 'schema_version' => 1, 'version_key' => $versionKey, 'source_graph_hash' => $commitment['source_graph_hash'],
                ...$this->seal($body, 'payload'), 'created_by' => (int) $current->id, 'created_at' => $at];
            $row['id'] = DB::table(CapabilityHistory::CANDIDATES)->insertGetId($row);
            $snapshot['candidates'][] = $row;
            $snapshot['bodies'][$row['id']] = $body;
            $snapshot['audits'][] = $this->audit($review['source_id'], 'candidate_saved', $row['id'], $row['payload_hash'], $current, $at);
            $model = $this->model(ProductionTrackCapabilityCandidate::class, $row);
            $this->finish($current, $authority, $snapshot, $reader);

            return $model;
        });
    }

    public function prepareReview(ProductionTrackCapabilityCandidate $candidate, User $actor): array
    {
        return $this->prepareDecision('approve', $candidate, $actor);
    }

    public function prepareClose(ProductionTrackCapabilityCandidate $candidate, User $actor): array
    {
        return $this->prepareDecision('close', $candidate, $actor);
    }

    private function prepareDecision(string $intent, ProductionTrackCapabilityCandidate $candidate, User $actor): array
    {
        return $this->transaction($actor, function (User $current, array $authority, CurrentRows $reader) use ($intent, $candidate): array {
            $snapshot = $this->forCandidate($reader, $candidate);
            $row = CapabilityHistory::find($snapshot['candidates'], $candidate->getKey());
            $this->decisionEligible($intent, $snapshot, $row, $current);
            $capture = $this->capture($intent, $current, $authority, $snapshot, $row['id'], $row['public_id'], null);
            $this->finish($current, $authority, $snapshot, $reader);

            return $capture;
        });
    }

    public function applyReview(array $review, array $reference, User $actor): ProductionTrackCapabilityApproval
    {
        SourceCommitment::reference($reference, 'software_choices_reviewed');

        return $this->applyDecision('approve', $review, $reference, $actor);
    }

    public function applyClose(array $review, array $reason, User $actor): ProductionTrackCapabilityClosure
    {
        CapabilityHistory::reason($reason);

        return $this->applyDecision('close', $review, $reason, $actor);
    }

    private function applyDecision(string $intent, array $review, array $evidence, User $actor): ImmutableCapabilityEvidence
    {
        return $this->transaction($actor, function (User $current, array $authority, CurrentRows $reader) use ($intent, $review, $evidence): ImmutableCapabilityEvidence {
            $snapshot = $this->verify($review, $intent, $current, $authority, $reader);
            MachinePolicyV1::require($review['after'] === null && is_int($review['candidate_id']) && $review['candidate_id'] > 0);
            $candidate = CapabilityHistory::find($snapshot['candidates'], $review['candidate_id']);
            MachinePolicyV1::require($candidate['public_id'] === $review['public_id']);
            $this->decisionEligible($intent, $snapshot, $candidate, $current);
            $at = now()->utc()->format('Y-m-d H:i:s');
            $body = ['schema_version' => 1, 'purpose' => $intent === 'approve' ? 'production_track_machine_software_approval' : 'production_track_machine_irreversible_closure',
                'candidate_id' => $candidate['id'], 'candidate_hash' => $candidate['payload_hash'], 'created_at' => $at];
            if ($intent === 'approve') {
                $body += ['generation' => $candidate['generation'], 'reviewed_by' => (int) $current->id,
                    'source' => $snapshot['bodies'][$candidate['id']]['source'], 'reference' => $evidence,
                    'external_facts_verified' => false, 'execution_allowed' => false];
                $table = CapabilityHistory::APPROVALS;
                $prefix = 'approval';
                $group = 'approvals';
                $authorField = 'reviewed_by';
                $modelClass = ProductionTrackCapabilityApproval::class;
            } else {
                $body += ['closed_by' => (int) $current->id, 'reason' => $evidence, 'execution_allowed' => false];
                $table = CapabilityHistory::CLOSURES;
                $prefix = 'closure';
                $group = 'closures';
                $authorField = 'closed_by';
                $modelClass = ProductionTrackCapabilityClosure::class;
            }
            $row = ['production_track_capability_candidate_id' => $candidate['id'], 'candidate_hash' => $candidate['payload_hash'],
                $authorField => (int) $current->id, ...$this->seal($body, $prefix), 'created_at' => $at];
            $row['id'] = DB::table($table)->insertGetId($row);
            $snapshot[$group][] = $row;
            $snapshot['audits'][] = $this->audit($review['source_id'], $intent === 'approve' ? 'software_approved' : 'candidate_closed',
                $candidate['id'], $row[$prefix.'_hash'], $current, $at);
            $model = $this->model($modelClass, $row);
            $this->finish($current, $authority, $snapshot, $reader);

            return $model;
        });
    }

    private function decisionEligible(string $intent, array $snapshot, array $candidate, User $actor): void
    {
        MachinePolicyV1::require(! in_array($candidate['id'], array_column($snapshot['closures'], 'production_track_capability_candidate_id'), true));
        if ($intent === 'approve') {
            $source = CapabilityHistory::eligible($snapshot, $candidate);
            CapabilityHistory::independent($snapshot['source'], $snapshot['candidates'], $candidate['generation'], $source['revision'], (int) $actor->id);
            MachinePolicyV1::require(! in_array($candidate['id'], array_column($snapshot['approvals'], 'production_track_capability_candidate_id'), true));
        }
    }

    public function withLockedForAdapter(ProductionTrackCapabilityCandidate $candidate, array $context, User $actor, Closure $prepare, ?Closure $finalPrimaryProof = null): mixed
    {
        return $this->transaction($actor, function (User $current, array $authority, CurrentRows $reader) use ($candidate, $context, $prepare, $finalPrimaryProof): mixed {
            $snapshot = $this->forCandidate($reader, $candidate);
            $row = CapabilityHistory::find($snapshot['candidates'], $candidate->getKey());
            $source = CapabilityHistory::eligible($snapshot, $row);
            $approved = array_values(array_filter($snapshot['approvals'], fn (array $approval): bool => $approval['production_track_capability_candidate_id'] === $row['id']));
            MachinePolicyV1::require(count($approved) === 1);
            $machine = $snapshot['bodies'][$row['id']]['machine'];
            PreparationContextV1::requireMatches($context, $machine);
            $projection = ['schema_version' => 1, 'purpose' => PreparationContextV1::PURPOSE, 'context' => $context,
                'source' => $source, 'candidate_id' => $row['id'], 'candidate_public_id' => $row['public_id'],
                'candidate_hash' => $row['payload_hash'], 'generation' => $row['generation'], 'approval_id' => $approved[0]['id'],
                'approval_hash' => $approved[0]['approval_hash'], 'machine_hash' => CanonicalJson::hash($machine), 'machine' => $machine,
                'external_facts_verified' => false, 'execution_allowed' => false];
            $projection['signature'] = $this->signature('production-preparation-projection-v1', $projection);
            $result = $finalPrimaryProof === null ? $prepare($projection) : $prepare($projection, $reader);
            $this->finish($current, $authority, $snapshot, $reader, $finalPrimaryProof);

            return $result;
        });
    }

    private function forCandidate(CurrentRows $reader, ProductionTrackCapabilityCandidate $candidate): array
    {
        $id = $candidate->getAttribute('production_track_policy_draft_id');
        MachinePolicyV1::require(is_int($id) && $id > 0 && is_int($candidate->getKey()) && $candidate->getKey() > 0);

        return CapabilityHistory::load($reader, $id);
    }

    private function capture(string $intent, User $actor, array $authority, array $snapshot, ?int $candidateId, string $publicId, ?array $after): array
    {
        $capture = ['schema_version' => 1, 'intent' => $intent, 'actor_id' => (int) $actor->id, 'authority_hash' => CanonicalJson::hash($authority),
            'source_id' => $snapshot['source']['rows']['draft']['id'], 'candidate_id' => $candidateId, 'public_id' => $publicId,
            'baseline_hash' => CapabilityHistory::baseline($snapshot), 'after' => $after];
        $capture['signature'] = $this->signature('production-capability-capture-v1', $capture);

        return $capture;
    }

    private function verify(array $capture, string $intent, User $actor, array $authority, CurrentRows $reader): array
    {
        MachinePolicyV1::record($capture, ['schema_version', 'intent', 'actor_id', 'authority_hash', 'source_id', 'candidate_id', 'public_id', 'baseline_hash', 'after', 'signature']);
        MachinePolicyV1::require($capture['schema_version'] === 1 && $capture['intent'] === $intent && $capture['actor_id'] === $actor->id
            && is_int($capture['source_id']) && $capture['source_id'] > 0 && CapabilityHistory::uuid($capture['public_id'])
            && SourceCommitment::hash($capture['authority_hash']) && SourceCommitment::hash($capture['baseline_hash']) && SourceCommitment::hash($capture['signature']));
        $unsigned = $capture;
        unset($unsigned['signature']);
        MachinePolicyV1::require(hash_equals($this->signature('production-capability-capture-v1', $unsigned), $capture['signature']));
        if (CanonicalJson::hash($authority) !== $capture['authority_hash']) {
            throw new AuthorizationException;
        }
        $snapshot = CapabilityHistory::load($reader, $capture['source_id']);
        MachinePolicyV1::require(CapabilityHistory::baseline($snapshot) === $capture['baseline_hash']);

        return $snapshot;
    }

    private function signature(string $purpose, array $value): string
    {
        $key = config('app.key');
        MachinePolicyV1::require(is_string($key) && $key !== '');
        $canonical = CanonicalJson::encode($value);
        MachinePolicyV1::require(strlen($canonical) <= 131072);

        return hash_hmac('sha256', $purpose.':'.$canonical, $key);
    }

    private function seal(array $body, string $prefix): array
    {
        $canonical = CanonicalJson::encode($body);
        MachinePolicyV1::require(strlen($canonical) <= ($prefix === 'payload' ? 65536 : 8192));
        $cipher = Crypt::encryptString($canonical);
        MachinePolicyV1::require(strlen($cipher) <= 131072);

        return [$prefix.'_ciphertext' => $cipher, $prefix.'_hash' => hash('sha256', $cipher), 'canonicalization_version' => CanonicalJson::VERSION];
    }

    private function transaction(User $actor, Closure $command): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0 || ! in_array($connection->getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Production capability preparation requires a supported standalone transaction.');
        }
        $primary = $connection->getPdo();
        $driver = $connection->getDriverName();

        return $connection->transaction(function () use ($actor, $command, $primary, $driver): mixed {
            $reader = new CurrentRows($primary, $driver);
            [$current, $authority] = $this->authority($actor, $reader);

            return $command($current, $authority, $reader);
        });
    }

    private function authority(User $actor, CurrentRows $reader): array
    {
        $current = $actor->exists && is_int($actor->getKey()) && $actor->getKey() > 0 ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException;
        }
        $row = (array) DB::table('users')->where('id', $current->id)->lockForUpdate()->first();
        if (CanonicalJson::encode($row) !== CanonicalJson::encode($current->getAttributes())) {
            throw new AuthorizationException;
        }
        $audits = $reader->audits(User::class, (int) $current->id, 257);
        MachinePolicyV1::require(count($audits) <= 256);

        return [$current, ['row' => $row, 'audits' => $audits]];
    }

    private function finish(User $actor, array $expectedAuthority, array $snapshot, CurrentRows $reader, ?Closure $finalPrimaryProof = null): void
    {
        [, $authority] = $this->authority($actor, $reader);
        if (CanonicalJson::encode($expectedAuthority) !== CanonicalJson::encode($authority)) {
            throw new AuthorizationException;
        }
        // Trusted fixed PDO reads and pure comparisons only, throwing on drift.
        // A false return is a refusal, never a successful verification.
        if ($finalPrimaryProof !== null) {
            MachinePolicyV1::require($finalPrimaryProof($reader) === null);
        }
        // This last proof exposes no QueryExecuted or Eloquent callback. It uses
        // the captured primary PDO after the trusted adapter and all callbacks.
        $actual = CapabilityHistory::load($reader, $snapshot['source']['rows']['draft']['id']);
        MachinePolicyV1::require(CanonicalJson::encode(CapabilityHistory::raw($actual)) === CanonicalJson::encode(CapabilityHistory::raw($snapshot)));
        $actualAuthority = ['row' => $reader->one('users', (int) $actor->id), 'audits' => $reader->audits(User::class, (int) $actor->id, 257)];
        if (CanonicalJson::encode($expectedAuthority) !== CanonicalJson::encode($actualAuthority)) {
            throw new AuthorizationException;
        }
    }

    private function audit(int $sourceId, string $action, int $candidateId, string $hash, User $actor, string $at): array
    {
        $row = ['actor_id' => (int) $actor->id, 'action' => 'commerce.production_capability.'.$action,
            'subject_type' => self::class, 'subject_id' => $sourceId,
            'context' => ['schema_version' => 1, 'candidate_id' => $candidateId, 'evidence_hash' => $hash,
                'external_facts_verified' => false, 'execution_allowed' => false], 'created_at' => $at];
        $model = AuditEvent::create($row);
        $row['id'] = $model->getKey();

        return $row;
    }

    /** Construct returned models before the final proof; no callback runs afterward. */
    private function model(string $class, array $row): ImmutableCapabilityEvidence
    {
        $model = new $class;
        $model->setRawAttributes($row, true);
        $model->exists = true;

        return $model;
    }
}
