<?php

namespace App\Domain\SiteBuilder;

use App\Domain\Catalog\Models\Track;
use Illuminate\Validation\ValidationException;

/** Retained editorial IDs describe permanent first-party URLs; public availability is resolved separately on each read. */
final class SiteRelatedTracks
{
    public static function formId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }

        return (int) $value;
    }

    /** Convert only canonical select values; unknown fields and duplicates still reach the strict content validator. */
    public static function fromForm(array $content): array
    {
        foreach (['blog', 'videos'] as $section) {
            foreach (is_array($content[$section]['entries'] ?? null) ? $content[$section]['entries'] : [] as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $path = "content.{$section}.entries.{$index}.related_track_ids";
                $ids = array_key_exists('related_track_ids', $entry) ? $entry['related_track_ids'] : [];
                if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 6) {
                    throw ValidationException::withMessages([$path => 'Choose an ordered list of at most six tracks.']);
                }
                foreach ($ids as $position => $value) {
                    $ids[$position] = self::formId($value);
                    if ($ids[$position] === null) {
                        throw ValidationException::withMessages([$path.'.'.$position => 'Choose a track using its canonical integer identifier.']);
                    }
                }
                $content[$section]['entries'][$index]['related_track_ids'] = $ids;
            }
        }

        return $content;
    }

    public static function used(array $content): bool
    {
        foreach (['blog', 'videos'] as $section) {
            foreach (is_array($content[$section]['entries'] ?? null) ? $content[$section]['entries'] : [] as $entry) {
                if (($entry['related_track_ids'] ?? []) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Called only for a newly validated immutable release, never to revalidate current commercial availability. */
    public function validateIdentities(array $content): void
    {
        if (($content['schema_version'] ?? null) !== 4) {
            return;
        }
        $paths = [];
        foreach (['blog', 'videos'] as $section) {
            foreach (is_array($content[$section]['entries'] ?? null) ? $content[$section]['entries'] : [] as $index => $entry) {
                foreach ($entry['related_track_ids'] as $position => $id) {
                    $paths[$id][] = "content.{$section}.entries.{$index}.related_track_ids.{$position}";
                }
            }
        }
        $tracks = Track::query()->whereIn('id', array_keys($paths))->get(['id', 'slug', 'published_slug'])->keyBy('id');
        $errors = [];
        foreach ($paths as $id => $fields) {
            $track = $tracks->get($id);
            if ($track === null || ! is_string($track->published_slug) || $track->published_slug === '' || $track->published_slug !== $track->slug) {
                foreach ($fields as $field) {
                    $errors[$field] = 'Choose a retained track with a reserved published URL.';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
