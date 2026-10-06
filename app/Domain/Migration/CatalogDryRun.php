<?php

namespace App\Domain\Migration;

use App\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use stdClass;
use Throwable;

/** Synthetic catalog planning only. This class never boots Laravel or writes a target. */
final class CatalogDryRun
{
    public const TRANSFORM = 'vasey-synthetic-catalog-draft-v1';

    public const FIXTURE = 'vasey-synthetic-migration-v1';

    public function plan(string $manifestJson, string $targetJson): array
    {
        $manifest = $this->object($this->decode($manifestJson), ['schema_version', 'fixture', 'batch_id', 'source_system',
            'acquired_at', 'source_as_of', 'acquisition_method', 'operator', 'records']);
        $target = $this->object($this->decode($targetJson), ['schema_version', 'fixture', 'snapshot_id', 'tracks', 'mappings']);
        foreach ([$manifest, $target] as $envelope) {
            $this->check($envelope['schema_version'] === 1 && $envelope['fixture'] === self::FIXTURE, 'synthetic_schema_required');
        }
        foreach (['batch_id', 'source_system', 'operator'] as $name) {
            $this->identity($manifest[$name]);
        }
        $this->identity($target['snapshot_id']);
        $this->check($manifest['acquisition_method'] === 'synthetic_fixture', 'synthetic_acquisition_required');
        $this->timestamp($manifest['acquired_at']);
        $this->timestamp($manifest['source_as_of']);
        $this->check($manifest['source_as_of'] <= $manifest['acquired_at'], 'source_watermark_after_acquisition');
        $records = $this->list($manifest['records']);
        $tracks = [];
        $slugs = [];
        foreach ($this->list($target['tracks']) as $item) {
            $track = $this->object($item, ['target_id', 'title', 'slug', 'visibility']);
            $this->identity($track['target_id']);
            $this->value($track);
            $this->check(! isset($tracks[$track['target_id']]), 'duplicate_target_identity');
            $tracks[$track['target_id']] = $track;
            $slugs[$track['slug']][] = $track['target_id'];
        }
        $mappings = [];
        $owners = [];
        foreach ($this->list($target['mappings']) as $item) {
            $mapping = $this->object($item, ['source_system', 'source_id', 'source_sha256', 'transform_version', 'target_id', 'target_sha256']);
            foreach (['source_system', 'source_id', 'target_id'] as $name) {
                $this->identity($mapping[$name]);
            }
            $this->digest($mapping['source_sha256']);
            $this->digest($mapping['target_sha256']);
            $this->check(is_string($mapping['transform_version']) && preg_match('/\A[a-z0-9-]{1,80}\z/D', $mapping['transform_version']), 'invalid_transform_identity');
            $key = $mapping['source_system'].'/'.$mapping['source_id'];
            $this->check(! isset($mappings[$key]) && ! isset($owners[$mapping['target_id']]), 'ambiguous_mapping_ownership');
            $mappings[$key] = $mapping;
            $owners[$mapping['target_id']] = $key;
        }
        $sources = [];
        $sourceSlugs = [];
        $rows = [];
        foreach ($records as $item) {
            $row = $this->object($item, ['source_id', 'source_sha256', 'value']);
            $this->identity($row['source_id']);
            $this->digest($row['source_sha256']);
            $row['value'] = $this->object($row['value'], ['title', 'slug', 'visibility']);
            $this->value($row['value']);
            $this->check(hash_equals($row['source_sha256'], CanonicalJson::hash($row['value'])), 'source_digest_mismatch');
            $sources[$row['source_id']] = ($sources[$row['source_id']] ?? 0) + 1;
            $sourceSlugs[$row['value']['slug']] = ($sourceSlugs[$row['value']['slug']] ?? 0) + 1;
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['source_id'], $b['source_id']) ?: strcmp($a['source_sha256'], $b['source_sha256']));
        $entries = [];
        foreach ($rows as $row) {
            $key = $manifest['source_system'].'/'.$row['source_id'];
            $mapping = $mappings[$key] ?? null;
            $reasons = [];
            if ($sources[$row['source_id']] !== 1) {
                $reasons[] = 'duplicate_source_identity';
            }
            if ($sourceSlugs[$row['value']['slug']] !== 1) {
                $reasons[] = 'planned_slug_collision';
            }
            if (in_array($row['value']['visibility'], ['sold', 'unknown'], true)) {
                $reasons[] = $row['value']['visibility'] === 'sold' ? 'sold_state_requires_disposition' : 'unknown_visibility';
            }
            if ($mapping !== null) {
                $existing = $tracks[$mapping['target_id']] ?? null;
                if (! hash_equals($mapping['source_sha256'], $row['source_sha256'])) {
                    $reasons[] = 'source_changed';
                }
                if ($mapping['transform_version'] !== self::TRANSFORM) {
                    $reasons[] = 'transform_changed';
                }
                if ($existing === null) {
                    $reasons[] = 'mapped_target_missing';
                } else {
                    if (! hash_equals($mapping['target_sha256'], CanonicalJson::hash($existing))) {
                        $reasons[] = 'target_changed';
                    }
                    $desired = ['target_id' => $mapping['target_id'], 'title' => $row['value']['title'],
                        'slug' => $row['value']['slug'], 'visibility' => 'draft'];
                    if (CanonicalJson::hash($desired) !== CanonicalJson::hash($existing)) {
                        $reasons[] = 'mapped_projection_conflict';
                    }
                }
                $collisions = array_diff($slugs[$row['value']['slug']] ?? [], [$mapping['target_id']]);
            } else {
                $collisions = $slugs[$row['value']['slug']] ?? [];
            }
            if ($collisions !== []) {
                $reasons[] = 'target_slug_collision';
            }
            sort($reasons, SORT_STRING);
            $result = $reasons !== [] ? 'conflict' : ($mapping !== null ? 'skip' : 'create_draft');
            $entries[] = ['source_system' => $manifest['source_system'], 'source_id' => $row['source_id'],
                'source_sha256' => $row['source_sha256'], 'result' => $result, 'reasons' => $reasons,
                'candidate_reference' => $result === 'create_draft' ? 'candidate:'.CanonicalJson::hash([self::TRANSFORM, $key]) : null,
                'target_id' => $mapping['target_id'] ?? null, 'source_visibility' => $row['value']['visibility'],
                'proposed_visibility' => $result === 'create_draft' ? 'draft' : null,
                'field_changes' => $result === 'create_draft' ? ['title', 'slug', 'visibility'] : [],
                'proposed_value_sha256' => $result === 'create_draft' ? CanonicalJson::hash([
                    'title' => $row['value']['title'], 'slug' => $row['value']['slug'], 'visibility' => 'draft']) : null];
        }
        $binding = ['schema_version' => 1, 'mode' => 'synthetic-dry-run', 'transform_version' => self::TRANSFORM,
            'canonicalization_version' => CanonicalJson::VERSION, 'batch_id' => $manifest['batch_id'],
            'source_system' => $manifest['source_system'], 'input_manifest_sha256' => CanonicalJson::hash($manifest),
            'target_snapshot_sha256' => CanonicalJson::hash($target), 'total' => count($rows), 'production_writes' => 0];

        return $binding + ['plan_sha256' => CanonicalJson::hash($binding + ['entries' => $entries]),
            'entries' => $entries, 'counts' => $this->counts($entries)];
    }

    public function checkpoint(array $plan, ?array $retained, int $limit): array
    {
        $this->check($limit >= 1 && $limit <= 1000, 'invalid_batch_limit');
        $processed = 0;
        if ($retained !== null) {
            $processed = $retained['processed'] ?? null;
            $this->check(is_int($processed) && $processed >= 0 && $processed <= $plan['total'], 'invalid_checkpoint_offset');
            $this->check(CanonicalJson::encode($retained) === CanonicalJson::encode($this->prefix($plan, $processed)), 'checkpoint_changed');
        }

        return $this->prefix($plan, min($plan['total'], $processed + $limit));
    }

    private function prefix(array $plan, int $processed): array
    {
        $plan['entries'] = array_slice($plan['entries'], 0, $processed);
        $plan['counts'] = $this->counts($plan['entries']);
        $plan['processed'] = $processed;
        $plan['complete'] = $processed === $plan['total'];

        return $plan;
    }

    private function counts(array $entries): array
    {
        $counts = ['create_draft' => 0, 'skip' => 0, 'conflict' => 0];
        foreach ($entries as $entry) {
            $counts[$entry['result']]++;
        }

        return $counts;
    }

    private function decode(string $json): mixed
    {
        $this->check(strlen($json) <= 1048576, 'input_too_large');
        try {
            $value = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
            // Exact canonical admission rejects duplicate JSON members, floats,
            // lossy integer decoding and ignored whitespace before trusting hashes.
            $canonical = CanonicalJson::encode($value);
            $this->check($json === $canonical || $json === $canonical."\n", 'canonical_json_required');

            return $value;
        } catch (Throwable) {
            throw new InvalidArgumentException('canonical_json_required');
        }
    }

    private function object(mixed $value, array $keys): array
    {
        $this->check($value instanceof stdClass, 'object_required');
        $fields = get_object_vars($value);
        $actual = array_keys($fields);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        $this->check($actual === $keys, 'unsupported_fields');

        return $fields;
    }

    private function list(mixed $value): array
    {
        $this->check(is_array($value) && array_is_list($value) && count($value) <= 1000, 'bounded_list_required');

        return $value;
    }

    private function identity(mixed $value): void
    {
        $this->check(is_string($value) && preg_match('/\Asynthetic-[a-z0-9][a-z0-9-]{0,78}\z/D', $value), 'synthetic_identity_required');
    }

    private function value(array $value): void
    {
        $this->check(is_string($value['title']) && str_starts_with($value['title'], 'SYNTHETIC ')
            && strlen($value['title']) <= 240 && ! preg_match('/[\x00-\x1F\x7F]/', $value['title']), 'synthetic_title_required');
        $this->identity($value['slug']);
        $this->check(in_array($value['visibility'], ['draft', 'private', 'unlisted', 'public', 'sold', 'unknown'], true), 'invalid_visibility');
    }

    private function digest(mixed $value): void
    {
        $this->check(is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value), 'invalid_digest');
    }

    private function timestamp(mixed $value): void
    {
        $this->check(is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value), 'explicit_utc_timestamp_required');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);
        $this->check($date && $date->format('Y-m-d\TH:i:s\Z') === $value, 'explicit_utc_timestamp_required');
    }

    private function check(mixed $condition, string $code): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($code);
        }
    }
}
