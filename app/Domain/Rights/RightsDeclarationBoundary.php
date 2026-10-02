<?php

namespace App\Domain\Rights;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\OfferSnapshot;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Supported rights writers share actor -> sorted affected tracks -> declaration locks. */
final class RightsDeclarationBoundary
{
    private const REVIEW_KEYS = ['schema_version', 'actor_id', 'intent', 'declaration_id', 'track_id', 'status', 'evidence_hash', 'display'];

    private const DISPLAY_KEYS = ['track_title', 'provenance_reference', 'sample_disclosure'];

    public function transaction(User $actor, Closure $command): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Rights declaration commands require a standalone transaction.');
        }

        return DB::transaction(function () use ($actor, $command): mixed {
            $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
            if ($current === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($current)->authorize('administer-catalog', [true]);
            if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
                throw new AuthorizationException('Admin multi-factor authentication is required.');
            }

            return $command($current);
        });
    }

    /** A preliminary identity read never holds rights before the track; association drift refuses. */
    public function lockIdentity(RightsDeclaration $identity): array
    {
        $id = $this->id($identity->getKey(), 'rights');
        $sourceTrackId = $this->id(RightsDeclaration::query()->select(['id', 'track_id'])->findOrFail($id)->track_id, 'rights');

        return $this->lockDeclaration($id, $sourceTrackId);
    }

    public function lockDeclaration(int $id, int $sourceTrackId, ?int $targetTrackId = null): array
    {
        $tracks = $this->lockTracks([$sourceTrackId, $targetTrackId ?? $sourceTrackId]);
        $locked = RightsDeclaration::query()->lockForUpdate()->findOrFail($id);
        if ($this->id($locked->track_id, 'rights') !== $sourceTrackId) {
            $this->reject('This declaration moved to another track. Close and reopen the review.');
        }
        $this->requirePending($locked);

        return [$locked, $tracks[$sourceTrackId]];
    }

    public function lockTracks(array $ids): array
    {
        $ids = array_values(array_unique(array_map(fn ($id) => $this->id($id, 'track_id'), $ids)));
        sort($ids, SORT_NUMERIC);
        $tracks = [];
        foreach ($ids as $id) {
            $tracks[$id] = Track::query()->lockForUpdate()->findOrFail($id);
        }

        return $tracks;
    }

    public function capture(RightsDeclaration $locked, Track $track, User $actor, string $intent): array
    {
        if (! in_array($intent, ['edit', 'verify'], true)) {
            $this->reject('Choose an edit or verification review.');
        }
        $this->requirePending($locked);
        if ($intent === 'verify') {
            $this->requireVerificationEvidence($locked);
        }
        $trackId = $this->id($track->getKey(), 'rights');

        return [
            'schema_version' => 1,
            'actor_id' => (int) $actor->getKey(),
            'intent' => $intent,
            'declaration_id' => $this->id($locked->getKey(), 'rights'),
            'track_id' => $trackId,
            'status' => 'pending',
            'evidence_hash' => CanonicalJson::hash(['rights_hash' => $this->evidenceHash($locked),
                'track' => ['id' => $trackId, 'title' => $track->title]]),
            // This exact snapshot supplies the mounted form/confirmation; never refresh it afterward.
            'display' => ['track_title' => $track->title, 'provenance_reference' => $locked->provenance_reference,
                'sample_disclosure' => $locked->sample_disclosure],
        ];
    }

    public function validateReview(array $review, User $actor, string $intent): void
    {
        if (array_diff(array_keys($review), self::REVIEW_KEYS) !== [] || array_diff(self::REVIEW_KEYS, array_keys($review)) !== []
            || $review['schema_version'] !== 1 || $review['intent'] !== $intent || $review['status'] !== 'pending'
            || ! is_int($review['actor_id']) || $review['actor_id'] < 1
            || ! is_int($review['declaration_id']) || $review['declaration_id'] < 1
            || ! is_int($review['track_id']) || $review['track_id'] < 1
            || ! is_string($review['evidence_hash']) || ! preg_match('/\A[a-f0-9]{64}\z/D', $review['evidence_hash'])
            || ! is_array($review['display']) || array_diff(array_keys($review['display']), self::DISPLAY_KEYS) !== []
            || array_diff(self::DISPLAY_KEYS, array_keys($review['display'])) !== []
            || array_filter($review['display'], fn ($value) => ! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) !== []) {
            $this->reject('Review the current declaration before changing rights evidence.');
        }
        if ($review['actor_id'] !== (int) $actor->getKey()) {
            throw new AuthorizationException('This rights review belongs to a different operator.');
        }
    }

    public function compareReview(array $review, RightsDeclaration $locked, Track $track, User $actor, string $intent): void
    {
        $current = $this->capture($locked, $track, $actor, $intent);
        if (! hash_equals($current['evidence_hash'], $review['evidence_hash'])
            || CanonicalJson::encode($current) !== CanonicalJson::encode($review)) {
            $this->reject('This declaration or track changed after review. Close and reopen it before trying again.');
        }
    }

    public function requireVerificationEvidence(RightsDeclaration $locked): void
    {
        if ($locked->status !== 'pending' || ! $locked->provenance_reference || ! $locked->sample_disclosure) {
            $this->reject('Verification requires a pending declaration with provenance and sample disclosure.');
        }
    }

    public function evidenceHash(RightsDeclaration $declaration): string
    {
        return app(OfferSnapshot::class)->rightsHash($declaration);
    }

    public function id(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        $maximum = (string) PHP_INT_MAX;
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)
            && (strlen($value) < strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0))) {
            return (int) $value;
        }
        throw ValidationException::withMessages([$field => 'Choose a current positive record identity.']);
    }

    private function requirePending(RightsDeclaration $locked): void
    {
        if ($locked->status !== 'pending') {
            $this->reject('Only pending rights declarations may be edited or verified. Record a new declaration to correct verified evidence.');
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['rights' => $message]);
    }
}
