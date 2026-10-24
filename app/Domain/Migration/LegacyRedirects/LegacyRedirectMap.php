<?php

namespace App\Domain\Migration\LegacyRedirects;

use JsonException;

/**
 * The reviewed legacy redirect map, structurally validated. Every entry carries its inventory row ID as provenance.
 * Matching is exact on a lowercase path; the map has no wildcard or pattern syntax because no inventory row needs one.
 */
final class LegacyRedirectMap
{
    public const SCHEMA = 'vasey.legacy-redirects';

    public const MAX_BYTES = 262144;

    public const DISPOSITIONS = ['redirect', 'gone', 'retained', 'unresolved'];

    /** A root slash, or lowercase hyphenated segments with no trailing slash, query, fragment, dot segment or wildcard. */
    public const PATH_PATTERN = '#\A/(?:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*)?\z#D';

    public const SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    public const HOST_PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/D';

    private const TOP_KEYS = ['schema', 'version', 'reviewed_on', 'inventory', 'query_string', 'external_hosts', 'entries'];

    private const ENTRY_KEYS = [
        'redirect' => ['required' => ['status', 'target'], 'optional' => ['note']],
        'gone' => ['required' => ['status'], 'optional' => ['note']],
        'retained' => ['required' => [], 'optional' => ['note']],
        'unresolved' => ['required' => ['reason'], 'optional' => ['note']],
    ];

    /** @param  list<array<string, mixed>>  $entries  @param  list<string>  $externalHosts */
    private function __construct(
        public readonly array $inventory,
        public readonly array $externalHosts,
        public readonly array $entries,
    ) {}

    public static function fromFile(string $path): self
    {
        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            throw new LegacyRedirectMapInvalid(['The map file is missing or unreadable.']);
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new LegacyRedirectMapInvalid(['The map file exceeds '.self::MAX_BYTES.' bytes.']);
        }
        $json = file_get_contents($path);

        return self::fromJson($json === false ? '' : $json);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LegacyRedirectMapInvalid(['The map is not valid JSON.']);
        }

        return self::fromArray(is_array($data) ? $data : []);
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $problems = [];
        if (array_is_list($data) || self::keys($data) !== self::sorted(self::TOP_KEYS)) {
            throw new LegacyRedirectMapInvalid(['The map must have exactly these keys: '.implode(', ', self::TOP_KEYS).'.']);
        }
        if ($data['schema'] !== self::SCHEMA || $data['version'] !== 1) {
            $problems[] = 'Unsupported map schema or version (expected '.self::SCHEMA.' version 1).';
        }
        if (! is_string($data['reviewed_on']) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $data['reviewed_on']) !== 1) {
            $problems[] = 'reviewed_on must be a YYYY-MM-DD date.';
        }
        $inventory = $data['inventory'];
        if (! is_array($inventory) || self::keys($inventory) !== ['path', 'rows', 'sha256']
            || ! is_string($inventory['path']) || $inventory['path'] === ''
            || ! is_string($inventory['sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $inventory['sha256']) !== 1
            || ! is_int($inventory['rows']) || $inventory['rows'] < 1) {
            $problems[] = 'inventory must pin path, sha256 (lowercase hex) and a positive row count.';
            $inventory = ['path' => '', 'sha256' => '', 'rows' => 0];
        }
        // One documented rule: the legacy query string is never forwarded (see docs/migration/legacy-redirects.md).
        if ($data['query_string'] !== 'drop') {
            $problems[] = 'query_string must be "drop"; forwarding legacy query strings is not supported.';
        }
        $hosts = $data['external_hosts'];
        if (! is_array($hosts) || ! array_is_list($hosts)) {
            $problems[] = 'external_hosts must be a list.';
            $hosts = [];
        }
        foreach ($hosts as $host) {
            if (! is_string($host) || preg_match(self::HOST_PATTERN, $host) !== 1) {
                $problems[] = 'external_hosts may hold only lowercase host names.';
            }
        }
        if (count(array_unique($hosts, SORT_REGULAR)) !== count($hosts)) {
            $problems[] = 'external_hosts has duplicates.';
        }
        $entries = $data['entries'];
        if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
            throw new LegacyRedirectMapInvalid([...$problems, 'entries must be a non-empty list.']);
        }
        $ids = [];
        $paths = [];
        foreach ($entries as $index => $entry) {
            $label = 'entries['.$index.']';
            if (! is_array($entry) || array_is_list($entry)) {
                $problems[] = $label.' must be an object.';

                continue;
            }
            $label = is_string($entry['route_id'] ?? null) ? $entry['route_id'] : $label;
            $problems = [...$problems, ...self::entryProblems($entry, $label)];
            if (is_string($entry['route_id'] ?? null)) {
                if (isset($ids[$entry['route_id']])) {
                    $problems[] = $label.' appears more than once.';
                }
                $ids[$entry['route_id']] = true;
            }
            if (is_string($entry['source_host'] ?? null) && is_string($entry['source_path'] ?? null)) {
                $key = $entry['source_host'].$entry['source_path'];
                if (isset($paths[$key])) {
                    $problems[] = $label.' repeats source '.$key.'.';
                }
                $paths[$key] = true;
            }
        }
        if ($problems !== []) {
            throw new LegacyRedirectMapInvalid($problems);
        }

        return new self($inventory, $hosts, $entries);
    }

    /** The single entry whose exact source path matches, ignoring host (the application serves one site). */
    public function entryForPath(string $path): ?array
    {
        foreach ($this->entries as $entry) {
            if ($entry['source_path'] === $path) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function entryProblems(array $entry, string $label): array
    {
        $problems = [];
        foreach (['route_id', 'source_host', 'source_path', 'disposition'] as $key) {
            if (! is_string($entry[$key] ?? null)) {
                return [$label.' needs string route_id, source_host, source_path and disposition.'];
            }
        }
        if (preg_match('/\AURL-\d{3}\z/D', $entry['route_id']) !== 1) {
            $problems[] = $label.' route_id must look like URL-001.';
        }
        if (preg_match(self::HOST_PATTERN, $entry['source_host']) !== 1) {
            $problems[] = $label.' source_host must be a lowercase host name.';
        }
        if (preg_match(self::PATH_PATTERN, $entry['source_path']) !== 1) {
            $problems[] = $label.' source_path must be an exact lowercase path without a trailing slash, query, fragment or wildcard.';
        }
        $shape = self::ENTRY_KEYS[$entry['disposition']] ?? null;
        if ($shape === null) {
            return [...$problems, $label.' disposition must be one of '.implode(', ', self::DISPOSITIONS).'.'];
        }
        $base = ['disposition', 'route_id', 'source_host', 'source_path'];
        $keys = self::keys($entry);
        $missing = array_diff([...$base, ...$shape['required']], $keys);
        $unknown = array_diff($keys, [...$base, ...$shape['required'], ...$shape['optional']]);
        if ($missing !== [] || $unknown !== []) {
            $problems[] = $label.' ('.$entry['disposition'].') has missing ['.implode(', ', $missing).'] or unknown ['.implode(', ', $unknown).'] keys.';

            return $problems;
        }
        if (array_key_exists('note', $entry) && (! is_string($entry['note']) || trim($entry['note']) === '' || mb_strlen($entry['note']) > 500)) {
            $problems[] = $label.' note must be non-empty text of at most 500 characters.';
        }
        if ($entry['disposition'] === 'redirect') {
            if ($entry['status'] !== 301) {
                $problems[] = $label.' redirect status must be 301.';
            }
            $problems = [...$problems, ...self::targetProblems($entry['target'], $label)];
        }
        if ($entry['disposition'] === 'gone' && $entry['status'] !== 410) {
            $problems[] = $label.' gone status must be 410.';
        }
        if ($entry['disposition'] === 'unresolved' && (! is_string($entry['reason']) || trim($entry['reason']) === '' || mb_strlen($entry['reason']) > 500)) {
            $problems[] = $label.' unresolved entries need a reason of at most 500 characters.';
        }

        return $problems;
    }

    /** @return list<string> */
    private static function targetProblems(mixed $target, string $label): array
    {
        if (! is_array($target) || array_is_list($target) || ! is_string($target['type'] ?? null)) {
            return [$label.' target must be an object with a type.'];
        }
        $keys = self::keys($target);

        return match ($target['type']) {
            'route' => $keys === ['name', 'type'] && is_string($target['name']) && preg_match('/\A[a-z0-9][a-z0-9._-]{0,99}\z/D', $target['name']) === 1
                ? [] : [$label.' route target needs exactly a route name.'],
            'editorial' => $keys === ['section', 'slug', 'type'] && in_array($target['section'], ['about', 'contact', 'blog', 'videos'], true)
                && ($target['slug'] === null || (is_string($target['slug']) && in_array($target['section'], ['blog', 'videos'], true) && preg_match(self::SLUG_PATTERN, $target['slug']) === 1))
                ? [] : [$label.' editorial target needs a section and, for blog or videos only, an optional entry slug.'],
            'track' => $keys === ['slug', 'type'] && is_string($target['slug']) && preg_match(self::SLUG_PATTERN, $target['slug']) === 1
                ? [] : [$label.' track target needs exactly a published slug.'],
            'external' => $keys === ['type', 'url'] && is_string($target['url']) && strlen($target['url']) <= 2048
                ? [] : [$label.' external target needs exactly a url.'],
            default => [$label.' target type must be route, editorial, track or external.'],
        };
    }

    /** @return list<string> */
    private static function keys(array $value): array
    {
        return self::sorted(array_map('strval', array_keys($value)));
    }

    /** @param  list<string>  $values @return list<string> */
    private static function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}
