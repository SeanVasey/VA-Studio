<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveTrackMetadata
{
    private const FIELDS = ['title', 'slug', 'artist', 'bpm', 'musical_key', 'genre', 'mood', 'tags', 'description'];

    public function handle(?Track $track, array $data, User $actor): Track
    {
        Gate::forUser($actor)->authorize('administer-catalog');
        if (array_diff(array_keys($data), [...self::FIELDS, 'metadata_version'])) {
            throw ValidationException::withMessages(['title' => 'Only track metadata may be saved here. Use the publication and media actions for other changes.']);
        }

        try {
            return DB::transaction(function () use ($track, $data, $actor) {
                // Omitted fields and revision checks must use the row acquired after the lock.
                $locked = $track?->exists ? Track::query()->lockForUpdate()->findOrFail($track->id) : new Track;
                if ($locked->exists) {
                    Validator::make($data, ['metadata_version' => ['required', 'integer', 'min:0', 'max:2147483647']])->validate();
                    if ((int) $data['metadata_version'] !== $locked->metadata_version) {
                        throw ValidationException::withMessages(['title' => 'This track changed since you opened it. Close and reopen the editor, then apply your changes.']);
                    }
                } elseif (array_key_exists('metadata_version', $data)) {
                    throw ValidationException::withMessages(['title' => 'A new track cannot supply a metadata revision.']);
                }
                unset($data['metadata_version']);

                $before = $locked->exists ? $this->metadata($locked) : null;
                $after = $this->validate($data + ($before ?? ['artist' => 'VASEY.AUDIO']), $locked);
                if ($locked->published_slug !== null && $after['slug'] !== $locked->published_slug) {
                    throw ValidationException::withMessages(['slug' => 'This URL is reserved because the track has been published. Unpublishing does not release it.']);
                }
                $changed = array_values(array_filter(self::FIELDS, fn (string $field) => $before === null || $before[$field] !== $after[$field]));
                if ($changed === []) {
                    return $locked;
                }

                $locked->fill($after);
                if (! $locked->exists) {
                    $locked->status = 'draft';
                }
                if ($locked->status === 'published') {
                    $blockers = app(PublicationReadiness::class)->blockers($locked);
                    if ($blockers !== []) {
                        // A visible form field keeps command failures actionable in the edit modal.
                        throw ValidationException::withMessages(['title' => 'Unpublish the track before saving incomplete metadata. '.implode(' ', $blockers)]);
                    }
                }
                $locked->metadata_version = ($locked->metadata_version ?? 0) + 1;
                $locked->save();
                AuditEvent::record($before === null ? 'catalog.track.created' : 'catalog.track.metadata_updated', $locked, [
                    'schema_version' => 1,
                    'metadata_version' => $locked->metadata_version,
                    'changed_fields' => $changed,
                    'canonicalization_version' => CanonicalJson::VERSION,
                    'before_hash' => $before === null ? null : CanonicalJson::hash($before),
                    'after_hash' => CanonicalJson::hash($after),
                ], $actor->id);

                return $locked;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The unique index arbitrates simultaneous claims; surface the same error as validation.
            if (str_contains($exception->getMessage(), 'tracks_slug_unique') || str_contains($exception->getMessage(), 'tracks.slug')) {
                throw ValidationException::withMessages(['slug' => 'This track URL is already in use.']);
            }
            throw $exception;
        }
    }

    private function metadata(Track $track): array
    {
        return array_replace($track->only(self::FIELDS), ['tags' => $track->tags ?? []]);
    }

    private function validate(array $data, Track $track): array
    {
        $data += array_fill_keys(self::FIELDS, null);
        foreach (self::FIELDS as $field) {
            if (is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
                if ($data[$field] === '') {
                    $data[$field] = null;
                }
            }
        }
        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', Rule::unique('tracks', 'slug')->ignore($track)],
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
}
