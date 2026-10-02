<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

class PublishTrack
{
    private const MAX_VERSION = 2147483647;

    private const REVIEW_KEYS = ['schema_version', 'actor_id', 'track_id', 'intent', 'metadata_version', 'publication_version', 'status'];

    /** Immediate trusted server command; the supplied Track is an identity, not an expected revision. */
    public function unpublish(Track $track, User $actor): Track
    {
        return $this->immediate($track, $actor, 'unpublish');
    }

    /** Immediate callers retain their existing transaction semantics; interactive callers must use a review. */
    public function handle(Track $track, User $actor): Track
    {
        return $this->immediate($track, $actor, 'publish');
    }

    public function review(Track $track, User $actor, string $intent): array
    {
        $this->requireStandalone();

        return DB::transaction(function () use ($track, $actor, $intent): array {
            $current = $this->lockActor($actor);
            $this->requireIntent($intent);
            $locked = Track::query()->lockForUpdate()->findOrFail($track->getKey());
            $this->requireState($locked, $intent);
            $this->requireVersion($locked->metadata_version);
            $this->requireCapacity($locked->publication_version);

            return [
                'schema_version' => 1,
                'actor_id' => (int) $current->id,
                'track_id' => (int) $locked->id,
                'intent' => $intent,
                'metadata_version' => $locked->metadata_version,
                'publication_version' => $locked->publication_version,
                'status' => $locked->status,
            ];
        });
    }

    public function publishReviewed(array $review, User $actor): Track
    {
        return $this->applyReviewed($review, $actor, 'publish');
    }

    public function unpublishReviewed(array $review, User $actor): Track
    {
        return $this->applyReviewed($review, $actor, 'unpublish');
    }

    private function immediate(Track $track, User $actor, string $intent): Track
    {
        return DB::transaction(function () use ($track, $actor, $intent): Track {
            $current = $this->lockActor($actor);
            $locked = Track::query()->lockForUpdate()->findOrFail($track->getKey());

            // A legacy caller-owned MySQL RR view can survive the lock. These immediate APIs
            // preserve existing nesting; reviewed publication owns its entire readiness transaction.
            return $this->write($locked, $current, $intent);
        });
    }

    private function applyReviewed(array $review, User $actor, string $intent): Track
    {
        $this->requireStandalone();

        return DB::transaction(function () use ($review, $actor, $intent): Track {
            $current = $this->lockActor($actor);
            $this->validateReview($review, $current, $intent);
            $locked = Track::query()->lockForUpdate()->findOrFail($review['track_id']);
            if ($locked->metadata_version !== $review['metadata_version']
                || $locked->publication_version !== $review['publication_version']
                || $locked->status !== $review['status']) {
                $this->reject('This track changed after publication review. Close and reopen the confirmation before trying again.');
            }
            $this->requireState($locked, $intent);

            return $this->write($locked, $current, $intent);
        });
    }

    private function write(Track $locked, User $actor, string $intent): Track
    {
        $this->requireCapacity($locked->publication_version);
        if ($intent === 'publish') {
            $blockers = app(PublicationReadiness::class)->blockers($locked);
            if ($blockers !== []) {
                $this->reject(implode(' ', $blockers));
            }
        }
        $previous = $locked->publication_version;
        $changes = ['status' => $intent === 'publish' ? 'published' : 'draft', 'publication_version' => $previous + 1];
        if ($intent === 'publish') {
            $changes['published_at'] = now();
        }
        $locked->update($changes);
        AuditEvent::record($intent === 'publish' ? 'catalog.track.published' : 'catalog.track.unpublished', $locked, [
            'schema_version' => 1,
            'metadata_version' => $locked->metadata_version,
            'previous_publication_version' => $previous,
            'publication_version' => $locked->publication_version,
        ], $actor->id);

        return $locked;
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

    private function validateReview(array $review, User $actor, string $intent): void
    {
        if (array_diff(array_keys($review), self::REVIEW_KEYS) !== [] || array_diff(self::REVIEW_KEYS, array_keys($review)) !== []
            || $review['schema_version'] !== 1 || $review['intent'] !== $intent
            || ! is_int($review['actor_id']) || $review['actor_id'] < 1
            || ! is_int($review['track_id']) || $review['track_id'] < 1) {
            $this->reject('Review the current track before changing publication.');
        }
        if ($review['actor_id'] !== (int) $actor->id) {
            throw new AuthorizationException('This publication review belongs to a different operator.');
        }
        $this->requireVersion($review['metadata_version']);
        $this->requireVersion($review['publication_version']);
        if ($review['status'] !== ($intent === 'publish' ? 'draft' : 'published')) {
            $this->reject('Review the current track before changing publication.');
        }
    }

    private function requireIntent(string $intent): void
    {
        if (! in_array($intent, ['publish', 'unpublish'], true)) {
            $this->reject('Choose publish or unpublish before reviewing the track.');
        }
    }

    private function requireState(Track $track, string $intent): void
    {
        if ($track->status !== ($intent === 'publish' ? 'draft' : 'published')) {
            $this->reject('This track no longer has the publication state required by this confirmation. Close and reopen it.');
        }
    }

    private function requireVersion(mixed $version): void
    {
        if (! is_int($version) || $version < 0 || $version > self::MAX_VERSION) {
            $this->reject('The track publication review has an invalid revision. Review the current track before trying again.');
        }
    }

    private function requireCapacity(mixed $version): void
    {
        $this->requireVersion($version);
        if ($version === self::MAX_VERSION) {
            $this->reject('This track cannot accept another publication revision.');
        }
    }

    private function requireStandalone(): void
    {
        // Ordinary readiness reads must not inherit an earlier MySQL Repeatable Read snapshot.
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Reviewed track publication requires a standalone transaction.');
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['publication' => $message]);
    }
}
