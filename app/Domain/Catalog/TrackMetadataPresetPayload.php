<?php

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Reusable descriptive metadata only; title, URL, publication and commercial state are excluded. */
class TrackMetadataPresetPayload
{
    public const FIELDS = ['artist', 'bpm', 'musical_key', 'genre', 'mood', 'tags', 'description'];

    public function normalize(array $metadata): array
    {
        if (array_diff(array_keys($metadata), self::FIELDS)) {
            throw ValidationException::withMessages(['name' => 'Only artist, BPM, musical key, genre, mood, tags and description may be stored in a preset.']);
        }
        $metadata += ['artist' => 'VASEY.AUDIO'] + array_fill_keys(self::FIELDS, null);
        foreach (self::FIELDS as $field) {
            if (is_string($metadata[$field])) {
                $metadata[$field] = trim($metadata[$field]);
                if ($metadata[$field] === '') {
                    $metadata[$field] = null;
                }
            }
        }
        // Match SaveTrackMetadata exactly, including literal tag order, whitespace and case.
        $validated = Validator::make($metadata, [
            'artist' => ['required', 'string', 'max:255'],
            'bpm' => ['nullable', 'integer', 'min:20', 'max:400'],
            'musical_key' => ['nullable', 'string', 'max:24'],
            'genre' => ['nullable', 'string', 'max:255'],
            'mood' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array', 'list', 'max:20'],
            'tags.*' => ['required', 'string', 'max:80', 'distinct'],
            'description' => ['nullable', 'string', 'max:10000'],
        ])->validate();
        $validated['bpm'] = $validated['bpm'] === null ? null : (int) $validated['bpm'];
        $validated['tags'] ??= [];

        return $validated;
    }

    public function name(mixed $name): string
    {
        if (is_string($name)) {
            $name = trim($name);
        }

        return Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:255']])->validate()['name'];
    }
}
