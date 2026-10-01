<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Literal tag additions only. A reviewed batch is one optimistic, atomic metadata command. */
class BulkAddTrackTags
{
    public const MAX_TRACKS = 25;

    public function review(array $ids, array $additions, User $actor): array
    {
        $this->validateIds($ids);
        $this->validateTags($additions, true);

        return DB::transaction(function () use ($ids, $additions, $actor) {
            $currentActor = $this->lockActor($actor);
            $rows = [];
            foreach ($this->lockTracks($ids) as $track) {
                $before = $track->tags ?? [];
                $after = $this->union($before, $additions);
                $this->validateTags($after);
                $rows[] = ['id' => $track->id, 'metadata_version' => $track->metadata_version,
                    'title' => $track->title, 'before_tags' => $before, 'after_tags' => $after];
            }

            return ['schema_version' => 1, 'actor_id' => $currentActor->id, 'additions' => $additions, 'tracks' => $rows];
        });
    }

    public function apply(array $review, User $actor): array
    {
        $this->validateReview($review);
        $ids = array_column($review['tracks'], 'id');

        return DB::transaction(function () use ($review, $actor, $ids) {
            $currentActor = $this->lockActor($actor);
            if ($review['actor_id'] !== $currentActor->id) {
                throw new AuthorizationException('This review belongs to a different operator.');
            }
            $tracks = $this->lockTracks($ids)->keyBy('id');
            // Check every reviewed row before writing any of them, even rows that would be no-ops.
            foreach ($review['tracks'] as $row) {
                $track = $tracks[$row['id']];
                $before = $track->tags ?? [];
                if ($track->metadata_version !== $row['metadata_version'] || $before !== $row['before_tags'] || $track->title !== $row['title']) {
                    throw ValidationException::withMessages(['additions' => 'A selected track changed after review. Review the current tracks before trying again.']);
                }
                if ($this->union($before, $review['additions']) !== $row['after_tags']) {
                    throw ValidationException::withMessages(['additions' => 'The reviewed additions do not match the selected tracks. Review them again.']);
                }
            }
            $changed = [];
            $unchanged = [];
            foreach ($review['tracks'] as $row) {
                $track = $tracks[$row['id']];
                // The ordinary metadata command owns all validation, readiness, URL, revision and audit rules.
                $saved = app(SaveTrackMetadata::class)->handle($track, [
                    'metadata_version' => $row['metadata_version'], 'tags' => $row['after_tags'],
                ], $currentActor);
                $retainedBefore = $track->getAttributes();
                $retainedAfter = $saved->getAttributes();
                foreach (['tags', 'metadata_version', 'updated_at'] as $field) {
                    unset($retainedBefore[$field], $retainedAfter[$field]);
                }
                if ($retainedBefore !== $retainedAfter) {
                    // Legacy metadata that needs normalization must first use the ordinary editor.
                    throw ValidationException::withMessages(['additions' => 'A selected track needs a metadata review in its editor before tags can be added in bulk.']);
                }
                if ($saved->metadata_version === $row['metadata_version']) {
                    $unchanged[] = $track->id;
                } else {
                    $changed[] = $track->id;
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
        Gate::forUser($current)->authorize('administer-catalog');
        if (! AdminMultiFactor::satisfiedBy($current)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }

        return $current;
    }

    private function lockTracks(array $ids): Collection
    {
        sort($ids, SORT_NUMERIC);
        $tracks = Track::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        if ($tracks->count() !== count($ids)) {
            throw ValidationException::withMessages(['additions' => 'A selected track is no longer available. Select and review the current tracks again.']);
        }

        return $tracks;
    }

    private function validateIds(array $ids): void
    {
        if (! array_is_list($ids) || count($ids) < 1 || count($ids) > self::MAX_TRACKS || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            throw ValidationException::withMessages(['additions' => 'Select between 1 and 25 distinct tracks.']);
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw ValidationException::withMessages(['additions' => 'Track IDs must be positive integers.']);
            }
        }
    }

    private function validateTags(array $tags, bool $required = false): void
    {
        if (! array_is_list($tags) || count($tags) > 20 || ($required && $tags === [])) {
            throw ValidationException::withMessages(['additions' => 'Use between 1 and 20 additions, keeping each track within 20 tags.']);
        }
        Validator::make(['additions' => $tags], [
            'additions' => ['array', 'list', $required ? 'min:1' : 'min:0', 'max:20'],
            'additions.*' => ['required', 'string', 'max:80', 'distinct'],
        ])->validate();
    }

    private function union(array $before, array $additions): array
    {
        foreach ($additions as $tag) {
            if (! in_array($tag, $before, true)) {
                $before[] = $tag;
            }
        }

        return $before;
    }

    private function validateReview(array $review): void
    {
        if (array_diff(array_keys($review), ['schema_version', 'actor_id', 'additions', 'tracks']) || ($review['schema_version'] ?? null) !== 1 || ! is_int($review['actor_id'] ?? null) || ! is_array($review['additions'] ?? null) || ! is_array($review['tracks'] ?? null) || ! array_is_list($review['tracks']) || count($review['tracks']) < 1 || count($review['tracks']) > self::MAX_TRACKS) {
            throw ValidationException::withMessages(['additions' => 'Review the selected tracks before adding tags.']);
        }
        $ids = [];
        foreach ($review['tracks'] as $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['id', 'metadata_version', 'title', 'before_tags', 'after_tags']) || ! is_int($row['id'] ?? null) || ! is_int($row['metadata_version'] ?? null) || $row['metadata_version'] < 0 || $row['metadata_version'] > 2147483647 || ! is_string($row['title'] ?? null) || ! is_array($row['before_tags'] ?? null) || ! is_array($row['after_tags'] ?? null)) {
                throw ValidationException::withMessages(['additions' => 'The review is incomplete. Review the selected tracks again.']);
            }
            $this->validateTags($row['before_tags']);
            $this->validateTags($row['after_tags']);
            $ids[] = $row['id'];
        }
        $this->validateIds($ids);
        $this->validateTags($review['additions'], true);
    }
}
