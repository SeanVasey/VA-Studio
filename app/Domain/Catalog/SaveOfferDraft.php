<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveOfferDraft
{
    private const FIELDS = ['track_id', 'license_version_id', 'price_minor', 'currency', 'deliverable_asset_ids'];

    public function handle(?Offer $offer, array $data, User $actor): Offer
    {
        return DB::transaction(function () use ($offer, $data, $actor) {
            // Match rights writers before track locks and actor-attributed audit foreign keys.
            $currentActor = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
            if ($currentActor === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($currentActor)->authorize('administer-catalog', [true]);
            if (array_diff(array_keys($data), self::FIELDS)) {
                throw ValidationException::withMessages(['offer' => 'Only draft fields may be saved. Publish a revision to change the active offer.']);
            }
            $existing = $offer?->exists ? Offer::findOrFail($offer->id) : null;
            $trackId = $existing?->track_id ?? ($data['track_id'] ?? null);
            Validator::make(['track_id' => $trackId], ['track_id' => ['required', 'integer', 'exists:tracks,id']])->validate();
            Track::query()->lockForUpdate()->findOrFail($trackId);
            $locked = $existing ? Offer::query()->lockForUpdate()->findOrFail($existing->id) : new Offer;
            if ($existing) {
                // Omitted fields come from the locked row, never a stale pre-lock read.
                $data += $locked->only(self::FIELDS);
                if ((string) $data['track_id'] !== (string) $locked->track_id) {
                    throw ValidationException::withMessages(['track_id' => 'Create a new offer to change its track.']);
                }
            }
            $locked->fill($this->validate($data))->save();
            AuditEvent::record('catalog.offer.draft_saved', $locked, ['current_revision_id' => $locked->current_revision_id], $currentActor->id);

            return $locked;
        });
    }

    private function validate(array $data): array
    {
        $price = $data['price_minor'] ?? null;
        if (! is_int($price) && (! is_string($price) || ! preg_match('/\A[0-9]+\z/D', $price))) {
            throw ValidationException::withMessages(['price_minor' => 'Use integer minor units; floating point amounts are not accepted.']);
        }
        $validated = Validator::make($data, [
            'track_id' => ['required', 'integer', 'exists:tracks,id'],
            'license_version_id' => ['required', 'integer', 'exists:license_versions,id'],
            'price_minor' => ['required', 'regex:/\A[0-9]+\z/D', 'integer', 'min:0', 'max:2147483647'],
            'currency' => ['required', 'regex:/\A[A-Z]{3}\z/D'],
            'deliverable_asset_ids' => ['present', 'array', 'max:3'],
            'deliverable_asset_ids.*' => ['integer', 'distinct', 'exists:media_assets,id'],
        ])->validate();
        $validated['price_minor'] = (int) $validated['price_minor'];
        $validated['deliverable_asset_ids'] = array_map('intval', $validated['deliverable_asset_ids']);

        return $validated;
    }
}
