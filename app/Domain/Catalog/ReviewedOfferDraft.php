<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use LogicException;

/** Captured operator edits only. Legacy partial saves retain their caller-owned transaction contract. */
final class ReviewedOfferDraft
{
    public const FIELDS = ['license_version_id', 'price_minor', 'currency', 'deliverable_asset_ids'];

    public const REOPEN_MESSAGE = 'This offer draft changed or its edit review is no longer available. Close and reopen the editor, then review your changes.';

    public function review(Offer $offer, User $actor): array
    {
        return $this->transaction($actor, function (User $current) use ($offer): array {
            if (! $offer->exists) {
                $this->reject();
            }
            // A retained track id is only a lock-order hint, checked against the locked offer.
            [$track, $locked] = $this->lockOffer($offer->track_id, $offer->getKey());
            $review = $this->capture($track, $locked, $current);
            $this->actor($current);

            return $review;
        });
    }

    public function updateReviewed(array $review, array $data, User $actor): Offer
    {
        return $this->transaction($actor, function (User $current) use ($review, $data): Offer {
            $this->validateReview($review, $current);
            [$track, $offer] = $this->lockOffer($review['track_id'], $review['offer_id']);
            if (! $this->same($review, $this->capture($track, $offer, $current))) {
                $this->reject();
            }
            if (array_diff(array_keys($data), self::FIELDS) !== []) {
                throw ValidationException::withMessages(['offer' => 'Only draft license, price, currency and deliverable fields may be edited here.']);
            }
            $validated = app(OfferDraftInput::class)->validate(['track_id' => $track->id] + $data);
            // Normalize the strict command's known FK integers without changing legacy validation/return values.
            $validated['track_id'] = (int) $validated['track_id'];
            $validated['license_version_id'] = (int) $validated['license_version_id'];
            $before = $this->display($offer);
            $offer->fill($validated);
            $after = $this->display($offer);
            $changed = array_values(array_filter(self::FIELDS, fn (string $field): bool => ! $this->same($before[$field], $after[$field])));
            if ($changed === []) {
                $this->actor($current);
                [$finalTrack, $finalOffer] = $this->lockOffer($review['track_id'], $review['offer_id']);
                $this->assertTrack($review, $finalTrack);
                if (! hash_equals($review['offer_hash'], CanonicalJson::hash($finalOffer->getAttributes()))
                    || $review['offer_audit_id'] !== $this->auditId(Offer::class, $finalOffer->id)) {
                    $this->reject();
                }
                $this->actor($current);

                return $finalOffer;
            }
            $intended = $this->persisted($offer);
            $offer->save();
            $saved = $this->persisted($offer);
            // Framework timestamps may advance; saving observers must not substitute fields or publication state.
            if (! $this->same(array_diff_key($intended, ['updated_at' => true]), array_diff_key($saved, ['updated_at' => true]))) {
                $this->reject();
            }
            $auditInput = ['actor_id' => $current->id, 'action' => 'catalog.offer.draft_saved',
                'subject_type' => Offer::class, 'subject_id' => (int) $offer->id, 'context' => [
                    'schema_version' => 1, 'current_revision_id' => $offer->current_revision_id,
                    'changed_fields' => $changed, 'canonicalization_version' => CanonicalJson::VERSION,
                    'before_hash' => CanonicalJson::hash($before), 'after_hash' => CanonicalJson::hash($after),
                ]];
            $audit = AuditEvent::create($auditInput);
            // Observer/audit work may change persisted identity or withdraw authority in this transaction.
            $this->actor($current);
            [$finalTrack, $finalOffer] = $this->lockOffer($review['track_id'], $review['offer_id']);
            $this->assertTrack($review, $finalTrack);
            $finalAudit = AuditEvent::find($audit->getKey());
            if (! $this->same($saved, $this->persisted($finalOffer))
                || (int) $audit->id !== $this->auditId(Offer::class, $finalOffer->id)
                || $finalAudit === null || ! $this->same($auditInput, $finalAudit->only(array_keys($auditInput)))) {
                $this->reject();
            }
            $this->actor($current);

            return $finalOffer;
        });
    }

    private function transaction(User $actor, Closure $operation): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Reviewed offer draft editing requires a standalone transaction.');
        }

        return DB::transaction(fn () => $operation($this->actor($actor)));
    }

    private function actor(User $actor): User
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

    private function lockOffer(mixed $trackId, mixed $offerId): array
    {
        if (! is_int($trackId) || $trackId < 1 || ! is_int($offerId) || $offerId < 1) {
            $this->reject();
        }
        $track = Track::query()->lockForUpdate()->find($trackId);
        $offer = Offer::query()->lockForUpdate()->find($offerId);
        if ($track === null || $offer === null || $offer->track_id !== $track->id) {
            $this->reject();
        }

        return [$track, $offer];
    }

    private function capture(Track $track, Offer $offer, User $actor): array
    {
        // Raw JSON strings retain waveform identity without projecting floating point amplitudes.
        // The first ordinary snapshot reads follow both current resource locks.
        return ['schema_version' => 1, 'intent' => 'edit_offer_draft', 'actor_id' => (int) $actor->id,
            'track_id' => (int) $track->id, 'offer_id' => (int) $offer->id,
            'track_hash' => CanonicalJson::hash($track->getAttributes()), 'offer_hash' => CanonicalJson::hash($offer->getAttributes()),
            'track_audit_id' => $this->auditId(Track::class, $track->id), 'offer_audit_id' => $this->auditId(Offer::class, $offer->id),
            'display' => $this->display($offer), 'track' => $track->only(['id', 'title'])];
    }

    private function display(Offer $offer): array
    {
        return ['track_id' => (int) $offer->track_id, 'license_version_id' => (int) $offer->license_version_id,
            'price_minor' => $offer->price_minor, 'currency' => $offer->currency, 'deliverable_asset_ids' => $offer->deliverable_asset_ids];
    }

    private function persisted(Offer $offer): array
    {
        // Native JSON formatting and numeric FK hydration are storage-defined; known values are semantic.
        return array_replace($offer->attributesToArray(), ['track_id' => (int) $offer->track_id,
            'license_version_id' => (int) $offer->license_version_id,
            'current_revision_id' => $offer->current_revision_id === null ? null : (int) $offer->current_revision_id]);
    }

    private function assertTrack(array $review, Track $track): void
    {
        if (! hash_equals($review['track_hash'], CanonicalJson::hash($track->getAttributes()))
            || $review['track_audit_id'] !== $this->auditId(Track::class, $track->id)) {
            $this->reject();
        }
    }

    private function auditId(string $type, int $id): int
    {
        return (int) (AuditEvent::query()->where('subject_type', $type)->where('subject_id', $id)->orderByDesc('id')->value('id') ?? 0);
    }

    private function validateReview(array $review, User $actor): void
    {
        $keys = ['schema_version', 'intent', 'actor_id', 'track_id', 'offer_id', 'track_hash', 'offer_hash',
            'track_audit_id', 'offer_audit_id', 'display', 'track'];
        if (array_diff(array_keys($review), $keys) !== [] || array_diff($keys, array_keys($review)) !== []
            || $review['schema_version'] !== 1 || $review['intent'] !== 'edit_offer_draft'
            || ! is_array($review['display']) || ! is_array($review['track'])) {
            $this->reject();
        }
        foreach (['actor_id', 'track_id', 'offer_id', 'track_audit_id', 'offer_audit_id'] as $field) {
            if (! is_int($review[$field]) || $review[$field] < (str_ends_with($field, 'audit_id') ? 0 : 1)) {
                $this->reject();
            }
        }
        foreach (['track_hash', 'offer_hash'] as $field) {
            if (! is_string($review[$field]) || ! preg_match('/\A[a-f0-9]{64}\z/D', $review[$field])) {
                $this->reject();
            }
        }
        if ($review['actor_id'] !== (int) $actor->id) {
            throw new AuthorizationException('This offer edit belongs to a different operator.');
        }
    }

    private function same(mixed $left, mixed $right): bool
    {
        try {
            return CanonicalJson::encode($left) === CanonicalJson::encode($right);
        } catch (InvalidArgumentException|JsonException) {
            return false;
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['offer' => self::REOPEN_MESSAGE]);
    }
}
