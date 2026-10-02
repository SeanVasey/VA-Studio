<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit Keep/Set/Clear metadata patches, reviewed and applied as one optimistic batch. */
class BulkUpdateTrackMetadata
{
    public const MAX_TRACKS = 25;

    public const FIELDS = ['artist', 'bpm', 'musical_key', 'genre', 'mood'];

    private const MAX_VERSION = 2147483647;

    public function review(array $ids, array $changes, User $actor): array
    {
        return DB::transaction(function () use ($ids, $changes, $actor) {
            $currentActor = $this->lockActor($actor);
            $this->validateIds($ids);
            $changes = $this->normalizeChanges($changes);
            $rows = [];
            foreach ($this->lockTracks($ids) as $track) {
                $before = $this->metadata($track);
                $after = array_replace($before, $this->patch($changes));
                $this->checkVersionCapacity($track->metadata_version, $before, $after);
                $rows[] = [
                    'id' => (int) $track->id,
                    'metadata_version' => $track->metadata_version,
                    'title' => $track->title,
                    'before_metadata' => $before,
                    'after_metadata' => $after,
                    // Bind kept metadata and every protected attribute without exposing their raw contents.
                    'row_hash' => CanonicalJson::hash($track->getAttributes()),
                ];
            }

            return ['schema_version' => 1, 'actor_id' => (int) $currentActor->id, 'changes' => $changes, 'tracks' => $rows];
        });
    }

    public function apply(array $review, User $actor): array
    {
        return DB::transaction(function () use ($review, $actor) {
            $currentActor = $this->lockActor($actor);
            $this->validateReview($review);
            if ($review['actor_id'] !== (int) $currentActor->id) {
                throw new AuthorizationException('This review belongs to a different operator.');
            }
            $patch = $this->patch($review['changes']);
            $tracks = $this->lockTracks(array_column($review['tracks'], 'id'))->keyBy('id');
            // Inspect every target, including no-ops and version exhaustion, before any ordinary save.
            foreach ($review['tracks'] as $row) {
                $track = $tracks[$row['id']];
                $before = $this->metadata($track);
                if ($track->metadata_version !== $row['metadata_version'] || $track->title !== $row['title']
                    || ! $this->same($before, $row['before_metadata']) || ! hash_equals($row['row_hash'], CanonicalJson::hash($track->getAttributes()))) {
                    $this->reject('A selected track changed after review. Review the current tracks before trying again.');
                }
                $after = array_replace($before, $patch);
                if (! $this->same($after, $row['after_metadata'])) {
                    $this->reject('The reviewed changes do not match the selected tracks. Review them again.');
                }
                $this->checkVersionCapacity($track->metadata_version, $before, $after);
            }
            $changed = [];
            $unchanged = [];
            foreach ($review['tracks'] as $row) {
                $track = $tracks[$row['id']];
                $retainedBefore = $track->getAttributes();
                // The ordinary command owns validation, readiness, reserved URLs, revision and audit rules.
                app(SaveTrackMetadata::class)->handle($track, ['metadata_version' => $row['metadata_version'], ...$patch], $currentActor);
                // Compare stored values, not the ordinary command's in-memory JSON re-encoding.
                // A locking reload also avoids an older outer MySQL Repeatable Read snapshot.
                $saved = Track::query()->lockForUpdate()->findOrFail($track->id);
                $retainedAfter = $saved->getAttributes();
                foreach ([...array_keys($patch), 'metadata_version', 'updated_at'] as $field) {
                    unset($retainedBefore[$field], $retainedAfter[$field]);
                }
                if (! $this->same($retainedBefore, $retainedAfter) || ! $this->same($this->metadata($saved), $row['after_metadata'])) {
                    $this->reject('A selected track needs a metadata review in its editor before these fields can be changed in bulk.');
                }
                $didChange = ! $this->same($row['before_metadata'], $row['after_metadata']);
                $expectedVersion = $row['metadata_version'] + ($didChange ? 1 : 0);
                if ($saved->metadata_version !== $expectedVersion || $saved->metadata_version > self::MAX_VERSION) {
                    $this->reject('A selected track cannot accept this metadata revision. Review it in its editor before trying again.');
                }
                if ($didChange) {
                    $changed[] = (int) $saved->id;
                } else {
                    if (! $this->same($track->getAttributes(), $saved->getAttributes())) {
                        $this->reject('An unchanged track did not retain its original metadata evidence. Review it in its editor before trying again.');
                    }
                    $unchanged[] = (int) $saved->id;
                }
            }

            return ['changed_ids' => $changed, 'unchanged_ids' => $unchanged];
        });
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

    private function lockTracks(array $ids): Collection
    {
        sort($ids, SORT_NUMERIC);
        $tracks = Track::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        if ($tracks->count() !== count($ids)) {
            $this->reject('A selected track is no longer available. Select and review the current tracks again.');
        }

        return $tracks;
    }

    private function validateIds(array $ids): void
    {
        if (! array_is_list($ids) || count($ids) < 1 || count($ids) > self::MAX_TRACKS || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            $this->reject('Select between 1 and 25 distinct tracks.');
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                $this->reject('Track IDs must be positive integers.');
            }
        }
    }

    private function normalizeChanges(array $changes): array
    {
        $this->requireKeys($changes, self::FIELDS);
        $normalized = [];
        $hasChange = false;
        foreach (self::FIELDS as $field) {
            $change = $changes[$field];
            if (! is_array($change) || ! in_array($change['mode'] ?? null, ['keep', 'set', 'clear'], true)) {
                $this->reject('Choose Keep, Set or Clear for each allowed metadata field.');
            }
            $mode = $change['mode'];
            $this->requireKeys($change, $mode === 'set' ? ['mode', 'value'] : ['mode']);
            if ($mode === 'keep') {
                $normalized[$field] = ['mode' => 'keep'];

                continue;
            }
            $hasChange = true;
            if ($mode === 'clear') {
                if ($field === 'artist') {
                    $this->reject('Artist is required and cannot be cleared.', 'changes.artist.mode');
                }
                $normalized[$field] = ['mode' => 'clear'];

                continue;
            }
            $value = is_string($change['value']) ? trim($change['value']) : $change['value'];
            $rules = $field === 'bpm' ? ['required', 'integer', 'min:20', 'max:400']
                : ['required', 'string', 'max:'.($field === 'musical_key' ? 24 : 255)];
            Validator::make(['changes' => [$field => ['value' => $value]]], ['changes.'.$field.'.value' => $rules])->validate();
            $normalized[$field] = ['mode' => 'set', 'value' => $field === 'bpm' ? (int) $value : $value];
        }
        if (! $hasChange) {
            $this->reject('Choose Set or Clear for at least one metadata field before review.');
        }

        return $normalized;
    }

    private function patch(array $changes): array
    {
        $patch = [];
        foreach (self::FIELDS as $field) {
            if ($changes[$field]['mode'] !== 'keep') {
                $patch[$field] = $changes[$field]['mode'] === 'clear' ? null : $changes[$field]['value'];
            }
        }

        return $patch;
    }

    private function metadata(Track $track): array
    {
        return $track->only(self::FIELDS);
    }

    private function validateReview(array $review): void
    {
        $this->requireKeys($review, ['schema_version', 'actor_id', 'changes', 'tracks']);
        if ($review['schema_version'] !== 1 || ! is_int($review['actor_id']) || $review['actor_id'] < 1
            || ! is_array($review['changes']) || ! is_array($review['tracks']) || ! array_is_list($review['tracks'])) {
            $this->reject('Review the selected tracks before changing metadata.');
        }
        $normalizedChanges = $this->normalizeChanges($review['changes']);
        foreach (self::FIELDS as $field) {
            if ($review['changes'][$field]['mode'] === 'set' && $review['changes'][$field]['value'] !== $normalizedChanges[$field]['value']) {
                $this->reject('The reviewed field values are not canonical. Review the selected tracks again.');
            }
        }
        $ids = [];
        foreach ($review['tracks'] as $row) {
            if (! is_array($row)) {
                $this->reject('The review is incomplete. Review the selected tracks again.');
            }
            $this->requireKeys($row, ['id', 'metadata_version', 'title', 'before_metadata', 'after_metadata', 'row_hash']);
            if (! is_int($row['id']) || ! is_int($row['metadata_version']) || $row['metadata_version'] < 0 || $row['metadata_version'] > self::MAX_VERSION
                || ! is_string($row['title']) || ! is_string($row['row_hash']) || preg_match('/\A[a-f0-9]{64}\z/D', $row['row_hash']) !== 1) {
                $this->reject('The review is incomplete. Review the selected tracks again.');
            }
            foreach (['before_metadata', 'after_metadata'] as $snapshot) {
                if (! is_array($row[$snapshot])) {
                    $this->reject('The review is incomplete. Review the selected tracks again.');
                }
                $this->requireKeys($row[$snapshot], self::FIELDS);
                foreach (self::FIELDS as $field) {
                    $value = $row[$snapshot][$field];
                    if ($value !== null && ($field === 'bpm' ? ! is_int($value) : ! is_string($value))) {
                        $this->reject('The reviewed metadata is invalid. Review the selected tracks again.');
                    }
                }
            }
            $ids[] = $row['id'];
        }
        $this->validateIds($ids);
        $ordered = $ids;
        sort($ordered, SORT_NUMERIC);
        if ($ids !== $ordered) {
            $this->reject('The reviewed tracks are not in their original order. Review the selected tracks again.');
        }
    }

    private function checkVersionCapacity(int $version, array $before, array $after): void
    {
        if ($version < 0 || $version > self::MAX_VERSION || ($version === self::MAX_VERSION && ! $this->same($before, $after))) {
            $this->reject('A selected track cannot accept another metadata revision. Review it in its editor before trying again.');
        }
    }

    private function requireKeys(array $data, array $keys): void
    {
        if (array_diff(array_keys($data), $keys) !== [] || array_diff($keys, array_keys($data)) !== []) {
            $this->reject('Only the complete reviewed metadata fields and their explicit modes may be supplied.');
        }
    }

    private function same(array $left, array $right): bool
    {
        return CanonicalJson::hash($left) === CanonicalJson::hash($right);
    }

    private function reject(string $message, string $field = 'changes'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
