<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\ProductDraftVersion;
use App\Support\CanonicalJson;
use Illuminate\Validation\ValidationException;

/** Descriptive composition only; this format contains no license, price or purchased media. */
class ProductDraftManifest
{
    public const SCHEMA = 'collection-album-draft-v1';

    public function make(string $kind, string $title, string $description, array $members): array
    {
        return ['schema' => self::SCHEMA, 'canonicalization' => CanonicalJson::VERSION,
            'kind' => $kind, 'title' => $title, 'description' => $description, 'members' => $members];
    }

    /** Cross-check the complete retained membership, not just a self-reported JSON hash. */
    public function verified(ProductDraftVersion $version, string $kind): array
    {
        $manifest = $version->manifest;
        if (! is_array($manifest) || ! $this->keys($manifest, ['schema', 'canonicalization', 'kind', 'title', 'description', 'members'])
            || ($manifest['schema'] ?? null) !== self::SCHEMA || ($manifest['canonicalization'] ?? null) !== CanonicalJson::VERSION
            || ($manifest['kind'] ?? null) !== $kind || ! in_array($kind, ['collection', 'album'], true)
            || ! $this->text($manifest['title'] ?? null, 180, true) || ! $this->text($manifest['description'] ?? null, 4000, false)
            || ! is_array($manifest['members'] ?? null) || ! array_is_list($manifest['members'])
            || count($manifest['members']) < 1 || count($manifest['members']) > 100) {
            $this->invalid();
        }
        $ids = [];
        foreach ($manifest['members'] as $member) {
            if (! is_array($member) || ! $this->keys($member, ['track_id', 'title', 'metadata_version', 'publication_version'])
                || ! is_int($member['track_id']) || $member['track_id'] < 1 || in_array($member['track_id'], $ids, true)
                || ! $this->text($member['title'], 255, true)
                || ! is_int($member['metadata_version']) || $member['metadata_version'] < 0
                || ! is_int($member['publication_version']) || $member['publication_version'] < 0) {
                $this->invalid();
            }
            $ids[] = $member['track_id'];
        }
        if (! is_string($version->manifest_sha256) || ! hash_equals(CanonicalJson::hash($manifest), $version->manifest_sha256)) {
            $this->invalid();
        }
        $rows = $version->members()->orderBy('position')->lockForUpdate()->get();
        if ($rows->count() !== count($manifest['members'])) {
            $this->invalid();
        }
        foreach ($rows as $position => $row) {
            $member = ['track_id' => $row->track_id, 'title' => $row->title,
                'metadata_version' => $row->metadata_version, 'publication_version' => $row->publication_version];
            if ($row->position !== $position + 1 || CanonicalJson::hash($member) !== CanonicalJson::hash($manifest['members'][$position])) {
                $this->invalid();
            }
        }

        return $manifest;
    }

    public function text(mixed $text, int $limit, bool $required): bool
    {
        return is_string($text) && mb_check_encoding($text, 'UTF-8') && mb_strlen($text) <= $limit
            && (! $required || trim($text) !== '') && trim($text) === $text
            && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text);
    }

    private function keys(array $data, array $keys): bool
    {
        return count($data) === count($keys) && array_diff(array_keys($data), $keys) === [];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['title' => 'This retained product version has inconsistent evidence. Preserve it for investigation.']);
    }
}
