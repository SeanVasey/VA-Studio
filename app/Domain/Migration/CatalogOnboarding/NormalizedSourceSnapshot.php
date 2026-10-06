<?php

declare(strict_types=1);

namespace App\Domain\Migration\CatalogOnboarding;

use App\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use stdClass;
use Throwable;

/** Operator-normalized catalog evidence, never a claim about an uninspected provider export format. */
final class NormalizedSourceSnapshot
{
    public const SCHEMA = 'vasey-private-catalog-drafts-v1';

    public const TRANSFORM = 'vasey-private-catalog-metadata-v1';

    public const METADATA = ['title', 'slug', 'artist', 'bpm', 'musical_key', 'genre', 'mood', 'tags', 'description'];

    public function decode(string $bytes): array
    {
        try {
            $this->require(strlen($bytes) <= 1048576);
            $decoded = json_decode($bytes, false, 16, JSON_THROW_ON_ERROR);
            $canonical = CanonicalJson::encode($decoded);
            $this->require($bytes === $canonical || $bytes === $canonical."\n");
            $snapshot = $this->object($decoded, ['schema_version', 'purpose', 'snapshot_id', 'source_system', 'acquired_at',
                'source_as_of', 'acquisition_method', 'operator_reference', 'artifacts', 'records']);
            $this->require($snapshot['schema_version'] === 1 && $snapshot['purpose'] === self::SCHEMA);
            foreach (['snapshot_id', 'source_system', 'operator_reference'] as $field) {
                $this->identity($snapshot[$field]);
            }
            $this->require(in_array($snapshot['acquisition_method'], ['official_export', 'authorized_read_only_audit',
                'seller_owned_original', 'independently_verified_manual_entry', 'synthetic_fixture'], true));
            $this->timestamp($snapshot['acquired_at']);
            $this->timestamp($snapshot['source_as_of']);
            $this->require($snapshot['source_as_of'] <= $snapshot['acquired_at']);
            $artifacts = [];
            foreach ($this->list($snapshot['artifacts'], 100) as $item) {
                $artifact = $this->object($item, ['artifact_id', 'relative_path', 'sha256', 'bytes']);
                $this->identity($artifact['artifact_id']);
                $this->relative($artifact['relative_path']);
                $this->digest($artifact['sha256']);
                $this->require(is_int($artifact['bytes']) && $artifact['bytes'] > 0 && $artifact['bytes'] <= 1073741824
                    && ! isset($artifacts[$artifact['artifact_id']]));
                $artifacts[$artifact['artifact_id']] = $artifact;
            }
            $this->require($artifacts !== []);
            $records = [];
            foreach ($this->list($snapshot['records'], 1000) as $item) {
                $record = $this->object($item, ['source_id', 'source_record_sha256', 'artifact_ids', 'raw_metadata', 'metadata', 'visibility', 'assets']);
                $this->identity($record['source_id']);
                $this->digest($record['source_record_sha256']);
                $references = $this->list($record['artifact_ids'], 100);
                $this->require($references !== []);
                foreach ($references as $reference) {
                    $this->identity($reference);
                    $this->require(isset($artifacts[$reference]));
                }
                $this->require(count(array_unique($references, SORT_STRING)) === count($references));
                $record['raw_metadata'] = $this->object($record['raw_metadata'], self::METADATA);
                foreach ($record['raw_metadata'] as $value) {
                    $this->require($value === null || is_string($value) || is_int($value) || (is_array($value) && array_is_list($value)
                        && count($value) <= 100 && array_all($value, static fn ($item): bool => is_string($item))));
                }
                $record['metadata'] = $this->metadata($record['metadata']);
                $this->require(in_array($record['visibility'], ['draft', 'private', 'unlisted', 'public', 'sold', 'unknown'], true));
                $assets = [];
                foreach ($this->list($record['assets'], 10) as $item) {
                    $asset = $this->object($item, ['source_asset_id', 'artifact_id', 'role', 'original_name', 'sha256', 'bytes']);
                    foreach (['source_asset_id', 'artifact_id', 'role'] as $field) {
                        $this->identity($asset[$field]);
                    }
                    $this->text($asset['original_name'], 240);
                    $this->digest($asset['sha256']);
                    $this->require(isset($artifacts[$asset['artifact_id']]) && in_array($asset['artifact_id'], $references, true)
                        && $asset['sha256'] === $artifacts[$asset['artifact_id']]['sha256']
                        && $asset['bytes'] === $artifacts[$asset['artifact_id']]['bytes']);
                    $assets[] = $asset;
                }
                $record['assets'] = $assets;
                $this->require(count(array_unique(array_column($assets, 'source_asset_id'), SORT_STRING)) === count($assets));
                $payload = $record;
                unset($payload['source_id'], $payload['source_record_sha256']);
                $this->require(hash_equals($record['source_record_sha256'], CanonicalJson::hash($payload)));
                if ($snapshot['acquisition_method'] === 'synthetic_fixture') {
                    $this->require(str_starts_with($record['metadata']['title'], 'SYNTHETIC '));
                }
                $records[] = $record;
            }
            $snapshot['artifacts'] = array_values($artifacts);
            $snapshot['records'] = $records;

            return $snapshot;
        } catch (Throwable) {
            throw new InvalidArgumentException('catalog_source_invalid');
        }
    }

    public function recordKey(string $sourceSystem, string $sourceId): string
    {
        $this->identity($sourceSystem);
        $this->identity($sourceId);

        return CanonicalJson::hash([$sourceSystem, $sourceId]);
    }

    private function metadata(mixed $value): array
    {
        $metadata = $this->object($value, self::METADATA);
        foreach (['title' => 255, 'slug' => 255, 'artist' => 255] as $field => $maximum) {
            $this->text($metadata[$field], $maximum);
            $this->require(trim($metadata[$field]) === $metadata[$field]);
        }
        $this->require(preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $metadata['slug']) === 1
            && ($metadata['bpm'] === null || (is_int($metadata['bpm']) && $metadata['bpm'] >= 20 && $metadata['bpm'] <= 400)));
        foreach (['musical_key' => 24, 'genre' => 255, 'mood' => 255, 'description' => 10000] as $field => $maximum) {
            if ($metadata[$field] !== null) {
                $this->text($metadata[$field], $maximum, $field === 'description');
                $this->require(trim($metadata[$field]) === $metadata[$field]);
            }
        }
        $metadata['tags'] = $this->list($metadata['tags'], 20);
        foreach ($metadata['tags'] as $tag) {
            $this->text($tag, 80);
            $this->require(trim($tag) === $tag);
        }
        $this->require(count(array_unique($metadata['tags'], SORT_STRING)) === count($metadata['tags']));

        return $metadata;
    }

    private function object(mixed $value, array $keys): array
    {
        $this->require($value instanceof stdClass);
        $actual = array_keys(get_object_vars($value));
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        $this->require($actual === $keys);

        return get_object_vars($value);
    }

    private function list(mixed $value, int $maximum): array
    {
        $this->require(is_array($value) && array_is_list($value) && count($value) <= $maximum);

        return $value;
    }

    private function identity(mixed $value): void
    {
        $this->text($value, 190);
        $this->require(trim($value) === $value);
    }

    private function text(mixed $value, int $maximum, bool $multiline = false): void
    {
        $this->require(is_string($value) && $value !== '' && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= $maximum && preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value) === 0);
    }

    private function relative(mixed $value): void
    {
        $this->text($value, 1024);
        $this->require(! str_starts_with($value, '/') && ! str_contains($value, '\\'));
        foreach (explode('/', $value) as $component) {
            $this->require(! in_array($component, ['', '.', '..'], true));
        }
    }

    private function timestamp(mixed $value): void
    {
        $this->require(is_string($value));
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $this->require($time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value);
    }

    private function digest(mixed $value): void
    {
        $this->require(is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1);
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new InvalidArgumentException('catalog_source_invalid');
        }
    }
}
