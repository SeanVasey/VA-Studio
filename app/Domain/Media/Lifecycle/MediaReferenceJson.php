<?php

namespace App\Domain\Media\Lifecycle;

/**
 * Reads media and track ids out of reviewed plaintext JSON for {@see MediaReferenceProof}. Every parser returns null when
 * the value is not the shape it knows, which the proof reports as unparsed (coverage incomplete), never as "no reference".
 *
 * Key vocabulary of the `ids` and `assets` parsers (case-insensitive):
 *  - assets: any key ending in asset_id or asset_ids; and under `assets`, `asset`, `preview` or `files`, a scalar, a list of
 *    scalars, or the `id` of each object. Objects nested inside an asset entry (a recording binding, say) contribute only
 *    their own asset keys, so their ids are not mistaken for assets.
 *  - runs: any key ending in processing_run_id.
 *  - tracks (`ids` only): any key that is or ends in track_id or track_ids; and under `track`, `tracks` or `product`
 *    (an offer snapshot's product is the track), a scalar, a list of scalars, or the `id` of each object.
 */
final class MediaReferenceJson
{
    private const OUTBOX_KEYS = ['evidence_hash', 'finalization_id', 'grant_id', 'schema_version'];

    /**
     * @param  'asset_list'|'assets'|'ids'|'outbox'|'job'  $parser
     * @param  array<string, array{0:string,1:string}|null>  $jobs  reviewed job classes
     * @return array{media_assets:list<int>, media_processing_runs:list<int>, tracks:list<int>}|null
     */
    public static function read(string $parser, mixed $value, array $jobs = []): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if ($parser === 'asset_list' && is_string($decoded)) {
            // A JSON column written through the query builder with an already encoded string.
            $decoded = json_decode($decoded, true);
        }
        if (! is_array($decoded)) {
            return null;
        }

        return match ($parser) {
            'asset_list' => self::walk($decoded, true, false),
            'assets' => self::walk($decoded, false, false),
            'ids' => self::walk($decoded, false, true),
            'outbox' => self::outbox($decoded),
            'job' => self::job($decoded, $jobs),
        };
    }

    private static function outbox(array $payload): ?array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys === self::OUTBOX_KEYS && $payload['schema_version'] === 1 ? self::none() : null;
    }

    private static function job(array $payload, array $jobs): ?array
    {
        $class = $payload['data']['commandName'] ?? null;
        $command = $payload['data']['command'] ?? null;
        if (! is_string($class) || ! is_string($command) || ! array_key_exists($class, $jobs)) {
            return null;
        }
        $result = self::none();
        if ($jobs[$class] === null) {
            // Still require a readable command of that class: an encrypted command names nothing we can review.
            return str_starts_with($command, 'O:'.strlen($class).':"'.$class.'"') ? $result : null;
        }
        [$property, $target] = $jobs[$class];
        if (! str_starts_with($command, 'O:'.strlen($class).':"'.$class.'"')
            || preg_match('/s:'.strlen($property).':"'.preg_quote($property, '/').'";i:([1-9][0-9]{0,17});/', $command, $match) !== 1) {
            return null;
        }
        $result[$target][] = (int) $match[1];

        return $result;
    }

    private static function none(): array
    {
        return ['media_assets' => [], 'media_processing_runs' => [], 'tracks' => []];
    }

    private static function walk(array $decoded, bool $assetList, bool $tracks): array
    {
        $ids = self::none();
        $add = function (string $kind, mixed $scalar) use (&$ids): void {
            if (is_int($scalar) && $scalar > 0) {
                $ids[$kind][] = $scalar;
            } elseif (is_string($scalar) && preg_match('/\A[1-9][0-9]{0,17}\z/D', $scalar) === 1) {
                $ids[$kind][] = (int) $scalar;
            }
        };
        $walk = function (mixed $node, ?string $kind) use (&$walk, $add): void {
            if (! is_array($node)) {
                return;
            }
            if (array_is_list($node)) {
                foreach ($node as $child) {
                    is_array($child) ? $walk($child, $kind) : ($kind !== null ? $add($kind, $child) : null);
                }

                return;
            }
            foreach ($node as $key => $child) {
                $name = strtolower((string) $key);
                $named = match (true) {
                    preg_match('/asset_ids?\z/', $name) === 1, preg_match('/\A(?:assets?|preview|files)\z/', $name) === 1 => 'media_assets',
                    preg_match('/(?:\A|_)processing_run_id\z/', $name) === 1 => 'media_processing_runs',
                    preg_match('/(?:\A|_)track_ids?\z/', $name) === 1, preg_match('/\A(?:tracks?|product)\z/', $name) === 1 => 'tracks',
                    default => null,
                };
                if ($named !== null) {
                    is_array($child) ? $walk($child, $named) : $add($named, $child);
                } elseif ($kind !== null && $name === 'id') {
                    $add($kind, $child);
                } else {
                    $walk($child, null);
                }
            }
        };
        $walk($decoded, null);
        if ($assetList) {
            $walk($decoded, 'media_assets');
        }
        if (! $tracks) {
            $ids['tracks'] = [];
        }

        return array_map(fn (array $list): array => array_values(array_unique($list)), $ids);
    }
}
