<?php

namespace App\Domain\Collections;

use App\Domain\Catalog\Models\Track;
use App\Domain\Collections\Models\TrackCollection;
use App\Domain\Collections\Models\TrackCollectionItem;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of track collections. Each command runs in one transaction that locks the acting staff user and then the
 * collection, and records one audit event. A collection is only an ordered list of references to tracks: nothing here prices,
 * licenses or sells, and member tracks are never changed. Whether a member is shown publicly is decided later, at read time,
 * by the track's own publication state, so a collection may reference drafts while it is being assembled.
 */
final class TrackCollections
{
    public const MAX_TRACKS = 60;

    private const FIELDS = ['title', 'slug', 'description', 'track_ids'];

    /** @param  array<string, mixed>  $data  exactly the editable fields */
    public function save(?TrackCollection $collection, array $data, User $actor, ?int $expectedRevision = null): TrackCollection
    {
        return DB::transaction(function () use ($collection, $data, $actor, $expectedRevision): TrackCollection {
            $current = $this->lockActor($actor);
            if (array_diff(array_keys($data), self::FIELDS) !== [] || array_diff(self::FIELDS, array_keys($data)) !== []) {
                $this->reject('title', 'The collection form is incomplete. Reload and try again.');
            }
            $locked = $collection?->exists ? TrackCollection::query()->lockForUpdate()->find($collection->getKey()) : new TrackCollection;
            if ($locked === null) {
                $this->reject('title', 'This collection is no longer available. Reload and choose a current collection.');
            }
            if ($locked->exists && $expectedRevision !== null && $expectedRevision !== $locked->revision) {
                $this->reject('title', 'This collection changed after you opened it. Reload it and apply your edits again.');
            }
            $after = $this->normalise($data, $locked);
            $before = $locked->exists ? $this->snapshot($locked) : null;
            $changed = array_values(array_filter(self::FIELDS, fn (string $field): bool => $before === null || $before[$field] !== $after[$field]));
            if ($changed === []) {
                return $locked;
            }
            if ($locked->is_published) {
                $this->requireReady($after);
            }
            $locked->fill(['title' => $after['title'], 'slug' => $after['slug'], 'description' => $after['description'],
                'revision' => $locked->exists ? $locked->revision + 1 : 1])->save();
            $this->replaceItems($locked, $after['track_ids']);
            AuditEvent::record('collections.collection.'.($before === null ? 'created' : 'updated'), $locked, [
                'schema_version' => 1, 'changed_fields' => $changed, 'published' => (bool) $locked->is_published, 'revision' => $locked->revision,
                'before_hash' => $before === null ? null : CanonicalJson::hash($before), 'after_hash' => CanonicalJson::hash($after),
            ], $current->id);

            return $locked->refresh();
        });
    }

    public function publish(TrackCollection $collection, int $expectedRevision, User $actor): TrackCollection
    {
        return DB::transaction(function () use ($collection, $expectedRevision, $actor): TrackCollection {
            $current = $this->lockActor($actor);
            $locked = $this->lockCollection($collection, $expectedRevision);
            if ($locked->is_published) {
                $this->reject('is_published', 'This collection is already published.');
            }
            $after = $this->snapshot($locked);
            $this->requireReady($after);
            $locked->forceFill(['is_published' => true, 'published_at' => now(), 'published_by' => $current->id, 'revision' => $locked->revision + 1])->save();
            AuditEvent::record('collections.collection.published', $locked, [
                'schema_version' => 1, 'revision' => $locked->revision, 'content_hash' => CanonicalJson::hash($after),
            ], $current->id);

            return $locked->refresh();
        });
    }

    public function unpublish(TrackCollection $collection, int $expectedRevision, User $actor): TrackCollection
    {
        return DB::transaction(function () use ($collection, $expectedRevision, $actor): TrackCollection {
            $current = $this->lockActor($actor);
            $locked = $this->lockCollection($collection, $expectedRevision);
            if (! $locked->is_published) {
                $this->reject('is_published', 'This collection is not published.');
            }
            $locked->forceFill(['is_published' => false, 'revision' => $locked->revision + 1])->save();
            AuditEvent::record('collections.collection.unpublished', $locked, [
                'schema_version' => 1, 'revision' => $locked->revision, 'content_hash' => CanonicalJson::hash($this->snapshot($locked)),
            ], $current->id);

            return $locked->refresh();
        });
    }

    /**
     * What stops a collection from being published, keyed by form field. Empty means ready. Reads only; it takes no locks.
     *
     * @return array<string, string>
     */
    public function blockers(TrackCollection $collection): array
    {
        return $this->problems($this->snapshot($collection));
    }

    /** @param  array<string, mixed>  $after */
    private function requireReady(array $after): void
    {
        $problems = $this->problems($after);
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * @param  array<string, mixed>  $collection  a normalised snapshot
     * @return array<string, string>
     */
    private function problems(array $collection): array
    {
        return $collection['track_ids'] === [] ? ['track_ids' => 'Add at least one track before publishing.'] : [];
    }

    private function lockCollection(TrackCollection $collection, int $expectedRevision): TrackCollection
    {
        $locked = $collection->exists ? TrackCollection::query()->lockForUpdate()->find($collection->getKey()) : null;
        if ($locked === null) {
            $this->reject('title', 'This collection is no longer available. Reload and choose a current collection.');
        }
        if ($locked->revision !== $expectedRevision) {
            $this->reject('title', 'This collection changed after you opened it. Reload it and review it again.');
        }

        return $locked;
    }

    /** @return array<string, mixed> */
    private function snapshot(TrackCollection $collection): array
    {
        return [
            'title' => $collection->title, 'slug' => $collection->slug, 'description' => $collection->description,
            'track_ids' => $collection->items()->pluck('track_id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data, TrackCollection $existing): array
    {
        $slug = $data['slug'];
        if (! is_string($slug) || strlen($slug) > 80 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1) {
            $this->reject('slug', 'Use lowercase letters, numbers and single hyphens, up to 80 characters.');
        }
        $taken = TrackCollection::query()->where('slug', $slug)->when($existing->exists, fn ($query) => $query->whereKeyNot($existing->getKey()))->lockForUpdate()->exists();
        if ($taken) {
            $this->reject('slug', 'Another collection already uses this web address.');
        }

        return ['title' => $this->line($data['title'], 'title', 160), 'slug' => $slug, 'description' => $this->block($data['description']),
            'track_ids' => $this->trackIds($data['track_ids'])];
    }

    /** @return list<int> */
    private function trackIds(mixed $ids): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > self::MAX_TRACKS) {
            $this->reject('track_ids', 'Add up to '.self::MAX_TRACKS.' tracks.');
        }
        $clean = [];
        foreach ($ids as $id) {
            $id = is_string($id) && ctype_digit($id) ? (int) $id : $id;
            if (! is_int($id) || $id < 1) {
                $this->reject('track_ids', 'Choose tracks from the list.');
            }
            $clean[] = $id;
        }
        if (count(array_unique($clean)) !== count($clean)) {
            $this->reject('track_ids', 'Use each track once.');
        }
        // Lock the referenced tracks in ascending order so a concurrent edit cannot interleave with the membership check.
        $found = $clean === [] ? [] : Track::query()->whereIn('id', $clean)->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (count($found) !== count($clean)) {
            $this->reject('track_ids', 'A chosen track no longer exists. Reload and choose again.');
        }

        return $clean;
    }

    /** @param  list<int>  $trackIds */
    private function replaceItems(TrackCollection $collection, array $trackIds): void
    {
        $collection->items()->delete();
        foreach ($trackIds as $index => $trackId) {
            TrackCollectionItem::create(['track_collection_id' => $collection->id, 'track_id' => $trackId, 'position' => $index + 1]);
        }
    }

    private function line(mixed $value, string $field, int $max): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->reject($field, 'Enter plain text.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F<>]/u', $value) === 1) {
            $this->reject($field, 'Enter 1 to '.$max.' characters of plain text on one line.');
        }

        return $value;
    }

    private function block(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->reject('description', 'Enter plain text.');
        }
        $value = trim(str_replace("\r\n", "\n", $value));
        if (mb_strlen($value) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u', $value) === 1) {
            $this->reject('description', 'Enter up to 2000 characters of plain text.');
        }

        return $value;
    }

    private function lockActor(User $actor): User
    {
        $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }

        return $current;
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
