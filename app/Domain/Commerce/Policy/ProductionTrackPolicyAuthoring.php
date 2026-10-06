<?php

namespace App\Domain\Commerce\Policy;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyDraft as PolicyDraftModel;
use App\Domain\Commerce\Policy\Models\ProductionTrackPolicySourceReview;
use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyVersion;
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
use Throwable;

/** Staff-only encrypted preparation. No method activates a policy, contacts a provider or grants rights. */
final class ProductionTrackPolicyAuthoring
{
    private const DRAFTS = 'production_track_policy_drafts';

    private const VERSIONS = 'production_track_policy_versions';

    private const REVIEWS = 'production_track_policy_source_reviews';

    public function prepareSave(?PolicyDraftModel $draft, array $authored, User $actor): array
    {
        return $this->transaction($actor, function (User $current, array $actorRow, PDO $primary, string $driver) use ($draft, $authored): array {
            $authored = ProductionTrackPolicyDraft::validateAuthored($authored);
            $snapshot = $draft === null ? null : $this->lockSnapshot($draft->getKey());
            $review = ['schema_version' => 1, 'intent' => 'save_production_track_policy_draft', 'actor_id' => (int) $current->id,
                'public_id' => $snapshot['draft']['public_id'] ?? (string) Str::uuid(), 'draft_id' => $snapshot['draft']['id'] ?? null,
                'baseline' => $snapshot === null ? null : $this->baseline($snapshot),
                'before' => $snapshot === null ? null : $this->authored(end($snapshot['versions'])), 'after' => $authored];
            $this->finish($current, $actorRow, $snapshot, $primary, $driver);

            return $this->sign($review);
        });
    }

    public function applySave(array $review, User $actor): PolicyDraftModel
    {
        return $this->transaction($actor, function (User $current, array $actorRow, PDO $primary, string $driver) use ($review): PolicyDraftModel {
            $this->verifyReview($review, $current, 'save_production_track_policy_draft');
            $after = ProductionTrackPolicyDraft::validateAuthored($review['after']);
            $snapshot = $review['draft_id'] === null ? null : $this->lockSnapshot($review['draft_id']);
            if ($snapshot === null) {
                if ($review['before'] !== null || $review['baseline'] !== null || DB::table(self::DRAFTS)->where('public_id', $review['public_id'])->exists()) {
                    ProductionTrackPolicyDraft::reject();
                }
            } elseif ($snapshot['draft']['public_id'] !== $review['public_id']
                || CanonicalJson::encode($this->baseline($snapshot)) !== CanonicalJson::encode($review['baseline'])
                || CanonicalJson::encode($this->authored(end($snapshot['versions']))) !== CanonicalJson::encode($review['before'])) {
                ProductionTrackPolicyDraft::reject();
            }
            if ($snapshot !== null && CanonicalJson::encode($review['before']) === CanonicalJson::encode($after)) {
                $this->finish($current, $actorRow, $snapshot, $primary, $driver);

                return $this->draftModel($snapshot['draft']);
            }
            if (($snapshot['draft']['revision'] ?? 0) >= 256) {
                ProductionTrackPolicyDraft::reject();
            }
            $at = now()->utc()->format('Y-m-d H:i:s');
            if ($snapshot === null) {
                $draftRow = ['public_id' => $review['public_id'], 'revision' => 0, 'created_by' => (int) $current->id, 'created_at' => $at, 'updated_at' => $at];
                $id = DB::table(self::DRAFTS)->insertGetId($draftRow);
                $draftRow['id'] = $id;
                $snapshot = ['draft' => $draftRow, 'versions' => [], 'reviews' => [], 'audits' => []];
            }
            $number = $snapshot['draft']['revision'] + 1;
            $ciphertext = Crypt::encryptString(CanonicalJson::encode($after));
            $version = ['production_track_policy_draft_id' => $snapshot['draft']['id'], 'number' => $number, 'schema_version' => 1,
                'payload_ciphertext' => $ciphertext, 'payload_hash' => hash('sha256', $ciphertext), 'canonicalization_version' => CanonicalJson::VERSION,
                'created_by' => (int) $current->id, 'created_at' => $at];
            $version['id'] = DB::table(self::VERSIONS)->insertGetId($version);
            DB::table(self::DRAFTS)->where('id', $snapshot['draft']['id'])->update(['revision' => $number, 'updated_at' => $at]);
            $snapshot['draft']['revision'] = $number;
            $snapshot['draft']['updated_at'] = $at;
            $beforeHash = $snapshot['versions'] === [] ? null : end($snapshot['versions'])['payload_hash'];
            $snapshot['versions'][] = $version;
            $snapshot['audits'][] = $this->audit($snapshot['draft'], 'commerce.production_policy.source_saved', [
                'schema_version' => 1, 'revision' => $number, 'version_id' => $version['id'], 'before_evidence_hash' => $beforeHash,
                'after_evidence_hash' => $version['payload_hash'], 'activation_allowed' => false, 'external_facts_verified' => false,
            ], $current, $at);
            $this->finish($current, $actorRow, $snapshot, $primary, $driver);

            return $this->draftModel($snapshot['draft']);
        });
    }

    public function prepareSourceReview(ProductionTrackPolicyVersion $version, User $actor): array
    {
        return $this->transaction($actor, function (User $current, array $actorRow, PDO $primary, string $driver) use ($version): array {
            // An immutable parent hint locates the fence; it is not trusted evidence.
            // Avoid establishing a repeatable-read snapshot before waiting on the parent.
            $parentId = $version->getAttribute('production_track_policy_draft_id');
            if (! is_int($parentId) || $parentId < 1) {
                ProductionTrackPolicyDraft::reject();
            }
            $snapshot = $this->lockSnapshot($parentId);
            $this->independent($snapshot, $current);
            $latest = end($snapshot['versions']);
            if ($latest['id'] !== $version->getKey() || $snapshot['reviews'] !== []) {
                // Earlier acknowledgments remain retained; the current version may receive its own.
                if ($latest['id'] !== $version->getKey() || array_filter($snapshot['reviews'], fn (array $review): bool => $review['production_track_policy_version_id'] === $latest['id']) !== []) {
                    ProductionTrackPolicyDraft::reject();
                }
            }
            $review = ['schema_version' => 1, 'intent' => 'review_production_track_policy_source', 'actor_id' => (int) $current->id,
                'public_id' => $snapshot['draft']['public_id'], 'draft_id' => $snapshot['draft']['id'], 'baseline' => $this->baseline($snapshot),
                'before' => $this->authored($latest), 'after' => null];
            $this->finish($current, $actorRow, $snapshot, $primary, $driver);

            return $this->sign($review);
        });
    }

    public function applySourceReview(array $review, array $reference, User $actor): ProductionTrackPolicySourceReview
    {
        return $this->transaction($actor, function (User $current, array $actorRow, PDO $primary, string $driver) use ($review, $reference): ProductionTrackPolicySourceReview {
            $this->verifyReview($review, $current, 'review_production_track_policy_source');
            $this->validateReference($reference);
            if ($review['draft_id'] === null || $review['after'] !== null) {
                ProductionTrackPolicyDraft::reject();
            }
            $snapshot = $this->lockSnapshot($review['draft_id']);
            $this->independent($snapshot, $current);
            $latest = end($snapshot['versions']);
            if ($snapshot['draft']['public_id'] !== $review['public_id'] || CanonicalJson::encode($this->baseline($snapshot)) !== CanonicalJson::encode($review['baseline'])
                || CanonicalJson::encode($this->authored($latest)) !== CanonicalJson::encode($review['before'])
                || array_filter($snapshot['reviews'], fn (array $ack): bool => $ack['production_track_policy_version_id'] === $latest['id']) !== []) {
                ProductionTrackPolicyDraft::reject();
            }
            $at = now()->utc()->format('Y-m-d H:i:s');
            $ciphertext = Crypt::encryptString(CanonicalJson::encode(['schema_version' => 1, 'purpose' => 'authored_production_policy_source_acknowledgment',
                'reference' => $reference, 'version_id' => $latest['id'], 'version_evidence_hash' => $latest['payload_hash'],
                'external_facts_verified' => false, 'activation_allowed' => false]));
            $ack = ['production_track_policy_version_id' => $latest['id'], 'reviewed_by' => (int) $current->id,
                'version_evidence_hash' => $latest['payload_hash'], 'review_ciphertext' => $ciphertext,
                'review_hash' => hash('sha256', $ciphertext), 'canonicalization_version' => CanonicalJson::VERSION, 'created_at' => $at];
            $ack['id'] = DB::table(self::REVIEWS)->insertGetId($ack);
            $snapshot['reviews'][] = $ack;
            $snapshot['audits'][] = $this->audit($snapshot['draft'], 'commerce.production_policy.source_acknowledged', [
                'schema_version' => 1, 'revision' => $latest['number'], 'version_id' => $latest['id'], 'source_review_id' => $ack['id'],
                'version_evidence_hash' => $latest['payload_hash'], 'review_evidence_hash' => $ack['review_hash'],
                'activation_allowed' => false, 'external_facts_verified' => false,
            ], $current, $at);
            $this->finish($current, $actorRow, $snapshot, $primary, $driver);
            $model = new ProductionTrackPolicySourceReview;
            $model->setRawAttributes($ack, true);
            $model->exists = true;

            return $model;
        });
    }

    private function transaction(User $actor, Closure $command): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Production policy preparation requires a standalone transaction.');
        }
        $primary = $connection->getPdo();
        $driver = $connection->getDriverName();

        return $connection->transaction(function () use ($actor, $command, $primary, $driver): mixed {
            [$current, $row] = $this->authority($actor);

            return $command($current, $row, $primary, $driver);
        });
    }

    private function authority(User $actor): array
    {
        $current = $actor->exists && is_int($actor->getKey()) && $actor->getKey() > 0 ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException;
        }
        // Keep every authority read current and locking until resource fences are held.
        $raw = (array) DB::table('users')->where('id', $current->id)->lockForUpdate()->first();
        if (CanonicalJson::encode($raw) !== CanonicalJson::encode($current->getAttributes())) {
            throw new AuthorizationException;
        }

        return [$current, $raw];
    }

    private function lockSnapshot(mixed $id): array
    {
        if (! is_int($id) || $id < 1 || DB::table(self::DRAFTS)->where('id', $id)->lockForUpdate()->first() === null) {
            ProductionTrackPolicyDraft::reject();
        }
        DB::table(self::VERSIONS)->where('production_track_policy_draft_id', $id)->orderBy('id')->limit(257)->lockForUpdate()->get();

        return $this->snapshot($id);
    }

    private function snapshot(int $id): array
    {
        // A retrieval callback may already have opened a consistent snapshot before
        // the parent fence. Every semantic read must therefore be a current read.
        $draft = (array) DB::table(self::DRAFTS)->where('id', $id)->lockForUpdate()->first();
        $versions = DB::table(self::VERSIONS)->where('production_track_policy_draft_id', $id)->orderBy('id')->limit(257)->lockForUpdate()->get()->map(fn ($row): array => (array) $row)->all();
        $reviews = DB::table(self::REVIEWS)->whereIn('production_track_policy_version_id', array_column($versions, 'id'))->orderBy('id')->limit(257)->lockForUpdate()->get()->map(fn ($row): array => (array) $row)->all();
        $audits = AuditEvent::query()->where('subject_type', PolicyDraftModel::class)->where('subject_id', $id)->orderBy('id')->limit(513)->lockForUpdate()->get()
            ->map(fn (AuditEvent $row): array => $this->auditRow($row->getAttributes()))->all();
        if ($draft === [] || ! is_int($draft['revision']) || $draft['revision'] < 1 || count($versions) !== $draft['revision']
            || count($versions) > 256 || count($reviews) > 256 || count($audits) > 512) {
            ProductionTrackPolicyDraft::reject();
        }
        foreach ($versions as $position => $version) {
            if ($version['number'] !== $position + 1) {
                ProductionTrackPolicyDraft::reject();
            }
            $this->authored($version);
        }
        foreach ($reviews as $review) {
            $this->validateAcknowledgment($review, $versions, $draft);
        }

        return ['draft' => $draft, 'versions' => $versions, 'reviews' => $reviews, 'audits' => $audits];
    }

    private function baseline(array $snapshot): array
    {
        return ['revision' => $snapshot['draft']['revision'], 'draft_hash' => CanonicalJson::hash($snapshot['draft']),
            'versions_hash' => CanonicalJson::hash($snapshot['versions']), 'reviews_hash' => CanonicalJson::hash($snapshot['reviews']),
            'audits_hash' => CanonicalJson::hash($snapshot['audits'])];
    }

    private function authored(array $row): array
    {
        try {
            if ($row['schema_version'] !== 1 || $row['canonicalization_version'] !== CanonicalJson::VERSION
                || strlen($row['payload_ciphertext']) > 131072 || ! hash_equals($row['payload_hash'], hash('sha256', $row['payload_ciphertext']))) {
                ProductionTrackPolicyDraft::reject();
            }
            $canonical = Crypt::decryptString($row['payload_ciphertext']);
            if (strlen($canonical) > 32768) {
                ProductionTrackPolicyDraft::reject();
            }
            $value = json_decode($canonical, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($value) || CanonicalJson::encode($value) !== $canonical) {
                ProductionTrackPolicyDraft::reject();
            }

            return ProductionTrackPolicyDraft::validateAuthored($value);
        } catch (Throwable) {
            ProductionTrackPolicyDraft::reject();
        }
    }

    private function independent(array $snapshot, User $actor): void
    {
        if ($snapshot['draft']['created_by'] === $actor->id || in_array($actor->id, array_column($snapshot['versions'], 'created_by'), true)) {
            throw new AuthorizationException;
        }
    }

    private function validateReference(array $reference): void
    {
        if (! ProductionTrackPolicyDraft::keys($reference, ['reference', 'source_sha256', 'authored_source_acknowledged'])
            || ! ProductionTrackPolicyDraft::text($reference['reference'], 512) || ! is_string($reference['source_sha256'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $reference['source_sha256']) !== 1 || $reference['authored_source_acknowledged'] !== true
            || preg_match('/(?:sk|rk)_(?:test|live)_[A-Za-z0-9]|whsec_[A-Za-z0-9]/', $reference['reference'])) {
            ProductionTrackPolicyDraft::reject();
        }
    }

    private function validateAcknowledgment(array $review, array $versions, array $draft): void
    {
        try {
            $matches = array_values(array_filter($versions, fn (array $version): bool => $version['id'] === $review['production_track_policy_version_id']));
            if (count($matches) !== 1 || $review['canonicalization_version'] !== CanonicalJson::VERSION
                || strlen($review['review_ciphertext']) > 131072 || ! hash_equals($review['review_hash'], hash('sha256', $review['review_ciphertext']))
                || $review['version_evidence_hash'] !== $matches[0]['payload_hash'] || $review['reviewed_by'] === $draft['created_by']) {
                ProductionTrackPolicyDraft::reject();
            }
            $version = $matches[0];
            foreach ($versions as $authored) {
                if ($authored['number'] <= $version['number'] && $authored['created_by'] === $review['reviewed_by']) {
                    ProductionTrackPolicyDraft::reject();
                }
            }
            $canonical = Crypt::decryptString($review['review_ciphertext']);
            $value = strlen($canonical) <= 8192 ? json_decode($canonical, true, 8, JSON_THROW_ON_ERROR) : null;
            if (! is_array($value) || CanonicalJson::encode($value) !== $canonical
                || ! ProductionTrackPolicyDraft::keys($value, ['schema_version', 'purpose', 'reference', 'version_id', 'version_evidence_hash', 'external_facts_verified', 'activation_allowed'])
                || $value['schema_version'] !== 1 || $value['purpose'] !== 'authored_production_policy_source_acknowledgment'
                || $value['version_id'] !== $version['id'] || $value['version_evidence_hash'] !== $version['payload_hash']
                || $value['external_facts_verified'] !== false || $value['activation_allowed'] !== false || ! is_array($value['reference'])) {
                ProductionTrackPolicyDraft::reject();
            }
            $this->validateReference($value['reference']);
        } catch (Throwable) {
            ProductionTrackPolicyDraft::reject();
        }
    }

    private function sign(array $review): array
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            ProductionTrackPolicyDraft::reject();
        }
        $review['signature'] = hash_hmac('sha256', 'production-policy-review-v1'.CanonicalJson::encode($review), $key);

        return $review;
    }

    private function verifyReview(array $review, User $actor, string $intent): void
    {
        if (! ProductionTrackPolicyDraft::keys($review, ['schema_version', 'intent', 'actor_id', 'public_id', 'draft_id', 'baseline', 'before', 'after', 'signature'])
            || $review['schema_version'] !== 1 || $review['intent'] !== $intent || ! is_int($review['actor_id']) || $review['actor_id'] < 1
            || ! is_string($review['public_id']) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $review['public_id']) !== 1
            || ($review['draft_id'] !== null && (! is_int($review['draft_id']) || $review['draft_id'] < 1))
            || ! is_string($review['signature']) || preg_match('/\A[a-f0-9]{64}\z/D', $review['signature']) !== 1) {
            ProductionTrackPolicyDraft::reject();
        }
        if ($review['actor_id'] !== $actor->id) {
            throw new AuthorizationException;
        }
        $unsigned = $review;
        unset($unsigned['signature']);
        try {
            if (strlen(CanonicalJson::encode($unsigned)) > 131072 || ! hash_equals($this->sign($unsigned)['signature'], $review['signature'])) {
                ProductionTrackPolicyDraft::reject();
            }
        } catch (Throwable) {
            ProductionTrackPolicyDraft::reject();
        }
    }

    private function finish(User $actor, array $actorRow, ?array $expected, PDO $primary, string $driver): void
    {
        [, $currentActor] = $this->authority($actor);
        if (CanonicalJson::encode($actorRow) !== CanonicalJson::encode($currentActor)) {
            throw new AuthorizationException;
        }
        // Laravel query listeners run after fetching, too. Once all authority/model
        // callbacks finish, use the captured transaction's primary PDO exclusively.
        if ($expected !== null) {
            $id = $expected['draft']['id'];
            $versionIds = array_column($expected['versions'], 'id');
            $actual = [
                'draft' => $this->primaryRows($primary, $driver, self::DRAFTS, 'id = ?', [$id])[0] ?? [],
                'versions' => $this->primaryRows($primary, $driver, self::VERSIONS, 'production_track_policy_draft_id = ?', [$id]),
                'reviews' => $versionIds === [] ? [] : $this->primaryRows($primary, $driver, self::REVIEWS,
                    'production_track_policy_version_id IN ('.implode(', ', array_fill(0, count($versionIds), '?')).')', $versionIds),
                'audits' => array_map(fn (array $row): array => $this->auditRow($row),
                    $this->primaryRows($primary, $driver, 'audit_events', 'subject_type = ? AND subject_id = ?', [PolicyDraftModel::class, $id])),
            ];
            if (CanonicalJson::encode($actual) !== CanonicalJson::encode($expected)) {
                ProductionTrackPolicyDraft::reject();
            }
        }
        if (CanonicalJson::encode($this->primaryRows($primary, $driver, 'users', 'id = ?', [$actor->id])[0] ?? []) !== CanonicalJson::encode($actorRow)) {
            throw new AuthorizationException;
        }
    }

    private function primaryRows(PDO $primary, string $driver, string $table, string $where, array $bindings): array
    {
        $statement = $primary->prepare('SELECT * FROM '.$table.' WHERE '.$where.' ORDER BY id'.($driver === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function audit(array $draft, string $action, array $context, User $actor, string $at): array
    {
        $attributes = ['actor_id' => (int) $actor->id, 'action' => $action, 'subject_type' => PolicyDraftModel::class,
            'subject_id' => $draft['id'], 'context' => $context, 'created_at' => $at];
        $model = AuditEvent::create($attributes);
        $attributes['id'] = $model->getKey();

        return $attributes;
    }

    private function auditRow(array $row): array
    {
        if (! is_string($row['context'] ?? null) || strlen($row['context']) > 8192) {
            ProductionTrackPolicyDraft::reject();
        }
        try {
            $row['context'] = json_decode($row['context'], true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            ProductionTrackPolicyDraft::reject();
        }

        return $row;
    }

    private function draftModel(array $row): PolicyDraftModel
    {
        $model = new PolicyDraftModel;
        $model->setRawAttributes($row, true);
        $model->exists = true;

        return $model;
    }
}
