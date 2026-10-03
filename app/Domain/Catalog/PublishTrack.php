<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Models\RightsScope;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

class PublishTrack
{
    private const MAX_VERSION = 2147483647;

    private const REVIEW_KEYS = ['schema_version', 'actor_id', 'track_id', 'intent', 'metadata_version', 'publication_version', 'status'];

    private const MANIFEST_REVIEW_KEYS = [...self::REVIEW_KEYS, 'manifest_hash'];

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

    /** A server-held confirmation of the ready evidence the operator is about to publish. */
    public function reviewManifest(Track $track, User $actor): array
    {
        $this->requireStandalone();

        return DB::transaction(function () use ($track, $actor): array {
            $current = $this->lockActor($actor);
            $locked = Track::query()->lockForUpdate()->findOrFail($track->getKey());
            $this->requireState($locked, 'publish');
            $this->requireVersion($locked->metadata_version);
            $this->requireCapacity($locked->publication_version);
            $manifest = $this->publicationManifest($locked, $current);

            return ['schema_version' => 2, 'actor_id' => (int) $current->id, 'track_id' => (int) $locked->id,
                'intent' => 'publish', 'metadata_version' => $locked->metadata_version,
                'publication_version' => $locked->publication_version, 'status' => $locked->status,
                'manifest_hash' => $manifest->hash()];
        });
    }

    public function publishManifestReviewed(array $review, User $actor): Track
    {
        $this->requireStandalone();

        return DB::transaction(function () use ($review, $actor): Track {
            $current = $this->lockActor($actor);
            if (array_diff(array_keys($review), self::MANIFEST_REVIEW_KEYS) !== []
                || array_diff(self::MANIFEST_REVIEW_KEYS, array_keys($review)) !== []
                || $review['schema_version'] !== 2 || ! is_string($review['manifest_hash'])
                || ! preg_match('/\A[a-f0-9]{64}\z/D', $review['manifest_hash'])) {
                $this->reject('Review the current publication evidence before publishing this track.');
            }
            // Validate the shared identity shape without permitting schema 1 as a fallback.
            $identity = array_intersect_key($review, array_flip(self::REVIEW_KEYS));
            $identity['schema_version'] = 1;
            $this->validateReview($identity, $current, 'publish');
            $locked = Track::query()->lockForUpdate()->findOrFail($review['track_id']);
            if ($locked->metadata_version !== $review['metadata_version']
                || $locked->publication_version !== $review['publication_version'] || $locked->status !== $review['status']) {
                $this->reject('This track changed after publication review. Close and reopen the confirmation before trying again.');
            }
            $this->requireState($locked, 'publish');
            $this->requireCapacity($locked->publication_version);
            $manifest = $this->publicationManifest($locked, $current);
            if (! hash_equals($review['manifest_hash'], $manifest->hash())) {
                $this->reject('Publication evidence changed after review. Close and reopen the confirmation before trying again.');
            }

            return $this->write($locked, $current, 'publish', $manifest);
        });
    }

    private function publicationManifest(Track $track, User $actor): TrackPublicationManifest
    {
        // All current locking reads precede the first ordinary child read (MySQL Repeatable Read).
        // Track writers serialize new/replaced offers, rights and media against this track fence.
        $offers = Offer::where('track_id', $track->id)->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
        $revisionIds = $offers->pluck('current_revision_id')->filter()->unique()->sort()->values()->all();
        // These rows are immutable. Shared locks are sufficient to discover current scope IDs and
        // remain compatible with a finalizer holding the scope before its revision FK shared lock.
        $revisions = OfferRevision::whereIn('id', $revisionIds)->orderBy('id')->sharedLock()->get();
        $scopeIds = $revisions->filter(fn (OfferRevision $revision) => ($revision->snapshot['schema_version'] ?? null) === 2)
            ->map(fn (OfferRevision $revision) => $revision->snapshot['inventory']['scope_id'] ?? null)
            ->filter(fn ($id) => is_int($id) && $id > 0)->unique()->sort()->values()->all();
        RightsScope::whereIn('id', $scopeIds)->orderBy('id')->lockForUpdate()->get();

        // Existing immutable evidence/readiness is validated before opening any private bytes.
        $reader = app(ReadTrackPublicationManifest::class);
        $manifest = $reader->captureLocked($track, $actor, CarbonImmutable::instance(now())->utc());
        app(VerifyTrackPublicationFiles::class)->handle($manifest);

        // Hashing may take long enough to cross a license boundary. Every license is evaluated
        // again at this one post-hash decision instant, also used for the publication timestamp.
        return $reader->captureLocked($track, $actor, CarbonImmutable::instance(now())->utc());
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

    private function write(Track $locked, User $actor, string $intent, ?TrackPublicationManifest $manifest = null): Track
    {
        $this->requireCapacity($locked->publication_version);
        if ($intent === 'publish' && $manifest === null) {
            $blockers = app(PublicationReadiness::class)->blockers($locked);
            if ($blockers !== []) {
                $this->reject(implode(' ', $blockers));
            }
        }
        $previous = $locked->publication_version;
        $changes = ['status' => $intent === 'publish' ? 'published' : 'draft', 'publication_version' => $previous + 1];
        if ($intent === 'publish') {
            $changes['published_at'] = $manifest?->capturedAt() ?? now();
        }
        $locked->update($changes);
        AuditEvent::record($intent === 'publish' ? 'catalog.track.published' : 'catalog.track.unpublished', $locked, [
            'schema_version' => 1,
            'metadata_version' => $locked->metadata_version,
            'previous_publication_version' => $previous,
            'publication_version' => $locked->publication_version,
        ] + ($manifest === null ? [] : ['manifest_schema_version' => TrackPublicationManifest::SCHEMA_VERSION,
            'manifest_hash' => $manifest->hash()]), $actor->id);

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
