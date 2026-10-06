<?php

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Existing draft validation only: no publication/readiness decision and no mutation. */
final class OfferDraftInput
{
    public function validate(array $data): array
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
