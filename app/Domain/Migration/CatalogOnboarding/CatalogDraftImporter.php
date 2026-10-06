<?php

declare(strict_types=1);

namespace App\Domain\Migration\CatalogOnboarding;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Migration\CatalogDryRun;
use App\Domain\Migration\CatalogOnboarding\Models\CatalogImportBatch;
use App\Domain\Migration\CatalogOnboarding\Models\CatalogImportMapping;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Trusted private SQLite staging. This command never updates, publishes or sells an existing track. */
final class CatalogDraftImporter
{
    public const MAX_SEGMENT = 25;

    public function review(array $source, array $release, User $actor, ?Closure $verifyExternal = null): array
    {
        $this->standalone();
        $this->release($release);
        (new PrivateSourceFiles)->unchanged($source);

        return (new PrivateSourceFiles)->leased($source, fn (): array => DB::transaction(function () use ($source, $release, $actor, $verifyExternal): array {
            [$current, $authority] = $this->actor($actor);
            $review = $this->binding($source, $release, (int) $current->id, $authority['sha256']);
            $verifyExternal?->__invoke();
            (new PrivateSourceFiles)->unchanged($source);
            $this->authorityUnchanged($current->id, $authority);

            return $review + ['review_sha256' => CanonicalJson::hash($review)];
        }));
    }

    public function apply(array $source, array $release, array $review, string $expectedReviewSha256, int $limit, User $actor, ?Closure $verifyExternal = null): array
    {
        $this->standalone();
        $this->release($release);
        $this->require($limit >= 1 && $limit <= self::MAX_SEGMENT && $this->digest($expectedReviewSha256));
        $binding = $review;
        unset($binding['review_sha256']);
        $this->require(($review['review_sha256'] ?? null) === $expectedReviewSha256
            && hash_equals($expectedReviewSha256, CanonicalJson::hash($binding))
            && ($review['source_sha256'] ?? null) === $source['source_sha256']
            && ($review['source_identity_sha256'] ?? null) === $this->sourceIdentity($source)
            && CanonicalJson::hash($review['target_release'] ?? null) === CanonicalJson::hash($release) && ($review['total'] ?? 0) > 0
            && ($review['counts']['conflict'] ?? -1) === 0);
        (new PrivateSourceFiles)->unchanged($source);

        return (new PrivateSourceFiles)->leased($source, fn (): array => DB::transaction(function () use ($source, $release, $review, $limit, $actor, $verifyExternal): array {
            [$current, $authority] = $this->actor($actor);
            $this->require($review['actor_id'] === (int) $current->id && $review['actor_sha256'] === $authority['sha256']);
            // Staff is fenced first; batch/mapping locks precede all ordinary track writer locks.
            $batch = CatalogImportBatch::query()->where('review_sha256', $review['review_sha256'])->lockForUpdate()->first();
            CatalogImportMapping::query()->orderBy('id')->lockForUpdate()->get();
            $retained = $batch === null ? null : $this->retained($batch, $review, $source);
            $this->require($this->same((new CatalogDatabaseEvidence)->target(...($retained['exclusions'] ?? [[], [], []])), $review['target_snapshot']));
            if ($batch === null) {
                $fresh = $this->binding($source, $release, (int) $current->id, $authority['sha256']);
                $this->require($this->same($fresh + ['review_sha256' => CanonicalJson::hash($fresh)], $review));
                $acceptedAt = now()->format('Y-m-d H:i:s');
                $batch = CatalogImportBatch::create(['review_sha256' => $review['review_sha256'],
                    'source_sha256' => $source['source_sha256'], 'source_identity_sha256' => $this->sourceIdentity($source),
                    'target_commit' => $release['commit'], 'target_schema_sha256' => $release['schema_hash'],
                    'transform_version' => NormalizedSourceSnapshot::TRANSFORM, 'actor_id' => $current->id,
                    'review_ciphertext' => Crypt::encryptString(CanonicalJson::encode(['review' => $review, 'audit_created_at' => $acceptedAt])),
                    'created_at' => $acceptedAt]);
                $this->audit('migration.catalog_batch.accepted', $batch, $this->batchContext($review), $current->id, $acceptedAt);
                $retained = $this->retained($batch, $review, $source);
            }
            $processed = $this->prefix($review, $retained['record_keys']);
            $planner = new CatalogDryRun;
            $checkpoint = $processed === 0 ? null : $planner->checkpoint($review, null, $processed);
            $next = $planner->checkpoint($review, $checkpoint, $limit);
            $records = [];
            foreach ($source['snapshot']['records'] as $record) {
                $key = (new NormalizedSourceSnapshot)->recordKey($source['snapshot']['source_system'], $record['source_id']);
                $this->require(! isset($records[$key]));
                $records[$key] = $record;
            }
            $created = [];
            foreach (array_slice($review['entries'], $processed, $next['processed'] - $processed) as $entry) {
                if ($entry['result'] === 'skip') {
                    continue;
                }
                $this->require($entry['result'] === 'create_draft' && isset($records[$entry['record_key']]));
                $record = $records[$entry['record_key']];
                $track = app(SaveTrackMetadata::class)->handle(null, $record['metadata'], $current);
                $trackRow = (new CatalogDatabaseEvidence)->rows('tracks')[$track->id] ?? null;
                $this->require($trackRow !== null && $this->draftMatches($trackRow, $record['metadata'])
                    && (new CatalogDatabaseEvidence)->childless($track->id));
                $trackAudits = (new CatalogDatabaseEvidence)->rows('audit_events');
                $trackAuditId = $this->matchAudit($trackAudits, 'catalog.track.created', Track::class, $track->id, $current->id,
                    $this->createdContext($record['metadata']));
                $mappedAt = now()->format('Y-m-d H:i:s');
                $evidence = ['schema_version' => 1, 'review_sha256' => $review['review_sha256'],
                    'source_system' => $source['snapshot']['source_system'], 'record' => $record,
                    'artifact_bindings' => $this->artifacts($source, $record), 'track_sha256' => $trackRow['sha256'],
                    'track_attributes' => $trackRow['attributes'], 'track_types' => $trackRow['types'],
                    'actor_id' => (int) $current->id, 'audit_created_at' => $mappedAt,
                    'track_audit_id' => $trackAuditId, 'track_audit_sha256' => $trackAudits[$trackAuditId]['sha256']];
                $mapping = CatalogImportMapping::create(['batch_id' => $batch->id, 'record_key' => $entry['record_key'],
                    'source_record_sha256' => $record['source_record_sha256'], 'track_id' => $track->id,
                    'track_sha256' => $trackRow['sha256'], 'actor_id' => $current->id,
                    'evidence_ciphertext' => Crypt::encryptString(CanonicalJson::encode($evidence)), 'created_at' => $mappedAt]);
                $this->audit('migration.catalog_draft.mapped', $mapping, $this->mappingContext($review, $mapping->getAttributes()), $current->id, $mappedAt);
                $created[] = (int) $track->id;
            }
            // Every application/model/audit callback is finished. These final filesystem/PDO proofs fire no QueryExecuted callbacks.
            $verifyExternal?->__invoke();
            (new PrivateSourceFiles)->unchanged($source);
            $final = $this->retained($batch, $review, $source);
            $plannedKeys = [...$retained['record_keys'], ...array_column(array_filter(array_slice($review['entries'], $processed,
                $next['processed'] - $processed), static fn (array $entry): bool => $entry['result'] === 'create_draft'), 'record_key')];
            $expectedProcessed = $this->prefix($review, $plannedKeys);
            $this->require($this->prefix($review, $final['record_keys']) === $expectedProcessed
                && $this->same((new CatalogDatabaseEvidence)->target(...$final['exclusions']), $review['target_snapshot']));
            $this->authorityUnchanged($current->id, $authority);

            return ['schema_version' => 1, 'review_sha256' => $review['review_sha256'], 'batch_id' => (int) $batch->id,
                'processed' => $expectedProcessed, 'total' => $review['total'], 'complete' => $expectedProcessed === $review['total'],
                'created_track_ids' => $created, 'retained_mappings' => count($final['record_keys'])];
        }));
    }

    private function plan(array $source): array
    {
        $database = new CatalogDatabaseEvidence;
        $tracks = $database->rows('tracks');
        $mappings = [];
        foreach ($database->rows('catalog_import_mappings') as $row) {
            $this->require(! isset($mappings[$row['attributes']['record_key']]));
            $mappings[$row['attributes']['record_key']] = $row;
        }
        $records = $source['snapshot']['records'];
        usort($records, static fn (array $left, array $right): int => strcmp($left['source_id'], $right['source_id'])
            ?: strcmp($left['source_record_sha256'], $right['source_record_sha256']));
        $ids = array_count_values(array_column($records, 'source_id'));
        $slugs = array_count_values(array_map(static fn (array $record): string => $record['metadata']['slug'], $records));
        $entries = [];
        $counts = ['create_draft' => 0, 'skip' => 0, 'conflict' => 0];
        foreach ($records as $record) {
            $key = (new NormalizedSourceSnapshot)->recordKey($source['snapshot']['source_system'], $record['source_id']);
            $mapping = $mappings[$key] ?? null;
            $reasons = [];
            if ($ids[$record['source_id']] > 1) {
                $reasons[] = 'duplicate_source_identity';
            }
            if ($slugs[$record['metadata']['slug']] > 1) {
                $reasons[] = 'planned_slug_collision';
            }
            if (in_array($record['visibility'], ['sold', 'unknown'], true)) {
                $reasons[] = $record['visibility'] === 'sold' ? 'sold_state_requires_disposition' : 'unknown_visibility';
            }
            $targetId = $mapping['attributes']['track_id'] ?? null;
            foreach ($tracks as $row) {
                if ($row['attributes']['id'] !== $targetId && in_array($record['metadata']['slug'],
                    [$row['attributes']['slug'], $row['attributes']['published_slug']], true)) {
                    $reasons[] = 'target_slug_collision';
                }
            }
            if ($mapping !== null) {
                try {
                    $evidence = $this->decrypt($mapping['attributes']['evidence_ciphertext']);
                    $track = $tracks[$targetId] ?? null;
                    if ($mapping['attributes']['source_record_sha256'] !== $record['source_record_sha256']
                        || ($evidence['source_system'] ?? null) !== $source['snapshot']['source_system']
                        || CanonicalJson::hash($evidence['record'] ?? null) !== CanonicalJson::hash($record)
                        || ($evidence['artifact_bindings'] ?? null) !== $this->artifacts($source, $record)) {
                        $reasons[] = 'source_evidence_changed';
                    }
                    if ($track === null || $track['sha256'] !== $mapping['attributes']['track_sha256']
                        || ! $this->draftMatches($track, $record['metadata']) || ! $database->childless($targetId)) {
                        $reasons[] = 'mapped_target_changed';
                    }
                } catch (Throwable) {
                    $reasons[] = 'mapping_evidence_invalid';
                }
            }
            $reasons = array_values(array_unique($reasons));
            sort($reasons, SORT_STRING);
            $result = $reasons !== [] ? 'conflict' : ($mapping === null ? 'create_draft' : 'skip');
            $counts[$result]++;
            $entries[] = ['source_system' => $source['snapshot']['source_system'], 'source_id' => $record['source_id'],
                'record_key' => $key, 'source_record_sha256' => $record['source_record_sha256'], 'result' => $result,
                'reasons' => $reasons, 'target_id' => $targetId, 'source_visibility' => $record['visibility'],
                'proposed_visibility' => $result === 'create_draft' ? 'draft' : null,
                'proposed_metadata' => $result === 'create_draft' ? $record['metadata'] : null,
                'raw_metadata_sha256' => CanonicalJson::hash($record['raw_metadata']),
                'dependent_work' => $record['assets'] === [] ? [] : ['separate_media_quarantine_intake']];
        }

        return ['entries' => $entries, 'counts' => $counts];
    }

    private function binding(array $source, array $release, int $actorId, string $actorSha256): array
    {
        $plan = $this->plan($source);

        return ['schema_version' => 1, 'mode' => 'private-catalog-dry-run',
            'canonicalization_version' => CanonicalJson::VERSION, 'transform_version' => NormalizedSourceSnapshot::TRANSFORM,
            'actor_id' => $actorId, 'actor_sha256' => $actorSha256,
            'source_sha256' => $source['source_sha256'], 'source_identity_sha256' => $this->sourceIdentity($source),
            'target_release' => $release, 'target_snapshot' => (new CatalogDatabaseEvidence)->target(),
            'total' => count($plan['entries']), 'entries' => $plan['entries'], 'counts' => $plan['counts'], 'database_writes' => 0];
    }

    private function retained(CatalogImportBatch $batch, array $review, array $source): array
    {
        $database = new CatalogDatabaseEvidence;
        $row = $database->rows('catalog_import_batches')[$batch->id]['attributes'] ?? null;
        $this->require($row !== null && $row['review_sha256'] === $review['review_sha256'] && $row['actor_id'] === $review['actor_id']
            && $row['source_sha256'] === $source['source_sha256'] && $row['source_identity_sha256'] === $this->sourceIdentity($source)
            && $row['target_commit'] === $review['target_release']['commit'] && $row['target_schema_sha256'] === $review['target_release']['schema_hash']
            && $row['transform_version'] === NormalizedSourceSnapshot::TRANSFORM);
        $accepted = $this->decrypt($row['review_ciphertext']);
        $this->require(array_keys($accepted) === ['audit_created_at', 'review'] && CanonicalJson::hash($accepted['review']) === CanonicalJson::hash($review) && $row['created_at'] === $accepted['audit_created_at']);
        $audits = $database->rows('audit_events');
        $excludedAudits = [$this->matchAudit($audits, 'migration.catalog_batch.accepted', CatalogImportBatch::class,
            $batch->id, $review['actor_id'], $this->batchContext($review), $accepted['audit_created_at'])];
        $tracks = $database->rows('tracks');
        $keys = [];
        $trackIds = [];
        $mappingIds = [];
        $sourceRecords = [];
        foreach ($source['snapshot']['records'] as $record) {
            $sourceRecords[(new NormalizedSourceSnapshot)->recordKey($source['snapshot']['source_system'], $record['source_id'])] = $record;
        }
        foreach ($database->rows('catalog_import_mappings') as $mapping) {
            $attributes = $mapping['attributes'];
            if ($attributes['batch_id'] !== (int) $batch->id) {
                continue;
            }
            $key = $attributes['record_key'];
            $record = $sourceRecords[$key] ?? null;
            $evidence = $this->decrypt($attributes['evidence_ciphertext']);
            $track = $tracks[$attributes['track_id']] ?? null;
            $this->require($record !== null && ! in_array($key, $keys, true) && $attributes['actor_id'] === $review['actor_id']
                && $attributes['source_record_sha256'] === $record['source_record_sha256']
                && ($evidence['schema_version'] ?? null) === 1 && ($evidence['review_sha256'] ?? null) === $review['review_sha256']
                && ($evidence['source_system'] ?? null) === $source['snapshot']['source_system']
                && ($evidence['actor_id'] ?? null) === $review['actor_id']
                && CanonicalJson::hash($evidence['record'] ?? null) === CanonicalJson::hash($record)
                && ($evidence['artifact_bindings'] ?? null) === $this->artifacts($source, $record)
                && $track !== null && $track['sha256'] === $attributes['track_sha256'] && ($evidence['track_sha256'] ?? null) === $track['sha256']
                && CanonicalJson::hash($evidence['track_attributes'] ?? null) === CanonicalJson::hash($track['attributes'])
                && CanonicalJson::hash($evidence['track_types'] ?? null) === CanonicalJson::hash($track['types'])
                && $this->draftMatches($track, $record['metadata']) && $database->childless($attributes['track_id'])
                && $attributes['created_at'] === ($evidence['audit_created_at'] ?? null));
            $excludedAudits[] = $this->matchAudit($audits, 'migration.catalog_draft.mapped', CatalogImportMapping::class,
                $attributes['id'], $review['actor_id'], $this->mappingContext($review, $attributes), $evidence['audit_created_at']);
            $trackAudit = $this->matchAudit($audits, 'catalog.track.created', Track::class,
                $attributes['track_id'], $review['actor_id'], $this->createdContext($record['metadata']));
            $this->require(($evidence['track_audit_id'] ?? null) === $trackAudit
                && ($evidence['track_audit_sha256'] ?? null) === $audits[$trackAudit]['sha256']);
            $excludedAudits[] = $trackAudit;
            $keys[] = $key;
            $trackIds[] = $attributes['track_id'];
            $mappingIds[] = $attributes['id'];
        }
        $this->prefix($review, $keys);

        return ['record_keys' => $keys, 'exclusions' => [$trackIds, $mappingIds, $excludedAudits]];
    }

    private function prefix(array $review, array $keys): int
    {
        $processed = 0;
        $gap = false;
        $observed = [];
        foreach ($review['entries'] as $entry) {
            $mapped = in_array($entry['record_key'], $keys, true);
            if ($mapped) {
                $this->require($entry['result'] === 'create_draft' && ! $gap);
                $observed[] = $entry['record_key'];
            }
            if (! $gap && ($mapped || $entry['result'] === 'skip')) {
                $processed++;
            } else {
                $gap = true;
            }
        }
        $this->require(count($observed) === count($keys));

        return $processed;
    }

    private function actor(User $actor): array
    {
        $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->id) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        $attributes = $current->getAttributes();
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }
        $row = (new CatalogDatabaseEvidence)->rows('users')[$current->id] ?? null;
        if ($row === null || CanonicalJson::hash($row['attributes']) !== CanonicalJson::hash($attributes)
            || $row['attributes']['is_admin'] !== 1 || $row['attributes']['email_verified_at'] === null) {
            throw new AuthorizationException;
        }

        return [$current, $row + ['mfa_required' => Filament::getPanel('admin')->isMultiFactorAuthenticationRequired()]];
    }

    private function authorityUnchanged(int $id, array $authority): void
    {
        $current = (new CatalogDatabaseEvidence)->rows('users')[$id] ?? null;
        if ($current === null || $current['sha256'] !== $authority['sha256']
            || Filament::getPanel('admin')->isMultiFactorAuthenticationRequired() !== $authority['mfa_required']) {
            throw new AuthorizationException;
        }
    }

    private function draftMatches(array $row, array $metadata): bool
    {
        $attributes = $row['attributes'];
        if ($attributes['status'] !== 'draft' || $attributes['published_at'] !== null || $attributes['published_slug'] !== null
            || $attributes['metadata_version'] !== 1 || $attributes['publication_version'] !== 0
            || $attributes['duration_seconds'] !== null || $attributes['waveform'] !== null) {
            return false;
        }
        $projection = array_intersect_key($attributes, array_flip(NormalizedSourceSnapshot::METADATA));
        $projection['tags'] = json_decode($attributes['tags'], true, 16, JSON_THROW_ON_ERROR);

        return CanonicalJson::hash($projection) === CanonicalJson::hash($metadata);
    }

    private function artifacts(array $source, array $record): array
    {
        $bindings = array_values(array_filter($source['snapshot']['artifacts'], static fn (array $artifact): bool => in_array($artifact['artifact_id'], $record['artifact_ids'], true)));
        usort($bindings, static fn (array $left, array $right): int => strcmp($left['artifact_id'], $right['artifact_id']));

        return $bindings;
    }

    private function sourceIdentity(array $source): string
    {
        return CanonicalJson::hash(array_intersect_key($source, array_flip(['directory', 'directory_identity', 'manifest_name',
            'source_sha256', 'manifest_identity', 'artifacts'])));
    }

    private function batchContext(array $review): array
    {
        return ['schema_version' => 1, 'review_sha256' => $review['review_sha256'], 'source_sha256' => $review['source_sha256'],
            'target_commit' => $review['target_release']['commit'], 'transform_version' => NormalizedSourceSnapshot::TRANSFORM, 'records' => $review['total']];
    }

    private function mappingContext(array $review, array $mapping): array
    {
        return ['schema_version' => 1, 'review_sha256' => $review['review_sha256'], 'record_key' => $mapping['record_key'],
            'source_record_sha256' => $mapping['source_record_sha256'], 'track_sha256' => $mapping['track_sha256']];
    }

    private function audit(string $action, $subject, array $context, int $actorId, string $createdAt): void
    {
        AuditEvent::create(['actor_id' => $actorId, 'action' => $action, 'subject_type' => $subject::class,
            'subject_id' => $subject->id, 'context' => $context, 'created_at' => $createdAt]);
    }

    private function matchAudit(array $audits, string $action, string $subjectType, int $subjectId, int $actorId, array $context, ?string $createdAt = null): int
    {
        $found = [];
        foreach ($audits as $row) {
            $audit = $row['attributes'];
            if ($audit['subject_type'] === $subjectType && $audit['subject_id'] === $subjectId && $audit['action'] === $action) {
                $this->require($audit['actor_id'] === $actorId && ($createdAt === null || $audit['created_at'] === $createdAt)
                    && CanonicalJson::hash(json_decode($audit['context'], true, 16, JSON_THROW_ON_ERROR)) === CanonicalJson::hash($context));
                $found[] = $audit['id'];
            }
        }
        $this->require(count($found) === 1);

        return $found[0];
    }

    private function decrypt(string $ciphertext): array
    {
        $bytes = Crypt::decryptString($ciphertext);
        $decoded = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $this->require(is_array($decoded) && CanonicalJson::encode($decoded) === $bytes);

        return $decoded;
    }

    private function release(array $release): void
    {
        $keys = array_keys($release);
        sort($keys, SORT_STRING);
        $this->require($keys === ['commit', 'schema_hash', 'tree'] && preg_match('/\A[a-f0-9]{40}\z/D', $release['commit']) === 1
            && preg_match('/\A[a-f0-9]{40}\z/D', $release['tree']) === 1 && $this->digest($release['schema_hash']));
    }

    private function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private function same(mixed $left, mixed $right): bool
    {
        return CanonicalJson::hash($left) === CanonicalJson::hash($right);
    }

    private function standalone(): void
    {
        $this->require(DB::transactionLevel() === 0 && DB::getDriverName() === 'sqlite');
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['catalog_import' => 'Private catalog import evidence changed or is invalid. Review the current source and target before applying.']);
        }
    }

    private function createdContext(array $metadata): array
    {
        return ['schema_version' => 1, 'metadata_version' => 1, 'changed_fields' => NormalizedSourceSnapshot::METADATA,
            'canonicalization_version' => CanonicalJson::VERSION, 'before_hash' => null, 'after_hash' => CanonicalJson::hash($metadata)];
    }
}
