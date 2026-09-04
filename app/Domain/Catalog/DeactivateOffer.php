<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeactivateOffer
{
    public function handle(Offer $offer, User $actor): Offer
    {
        Gate::forUser($actor)->authorize('administer-catalog');

        return DB::transaction(function () use ($offer, $actor) {
            $trackId = Offer::findOrFail($offer->id)->track_id;
            Track::query()->lockForUpdate()->findOrFail($trackId);
            $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            if ($locked->is_active) {
                $locked->update(['is_active' => false]);
                AuditEvent::record('catalog.offer.deactivated', $locked, ['revision_id' => $locked->current_revision_id], $actor->id);
            }

            return $locked;
        });
    }
}
