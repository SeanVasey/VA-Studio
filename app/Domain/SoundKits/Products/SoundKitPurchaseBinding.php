<?php

namespace App\Domain\SoundKits\Products;

use App\Domain\Media\MediaFailure;
use App\Domain\SoundKits\SoundKitManifest;
use App\Support\CanonicalJson;
use Illuminate\Validation\ValidationException;

/**
 * What a future kit purchase must bind, produced and hash-pinned now so the payment lane has an exact input (BL-53, FP-007).
 * It is the entitlement-ready half only: it creates no quote, order, payment, grant, entitlement or download, and
 * `payable` is always false. The existing quote -> order -> verified payment -> grant -> delivery chain is track-bound
 * (order lines reference track offer revisions; grants, contracts and delivery assets are track-owned), so wiring a kit line
 * into it is a payment-domain change that is NOT done here (docs/sound-kit-storefront.md, "What remains").
 *
 * Refuses unless the product is listed and its archive re-verifies against retained scan evidence and the private bytes now.
 */
final class SoundKitPurchaseBinding
{
    /** @return array{snapshot: array<string, mixed>, snapshot_sha256: string} */
    public function capture(SoundKitProduct $product): array
    {
        $products = app(SoundKitProducts::class);
        $product = $product->fresh();
        if ($product === null || ! $products->listed($product)) {
            throw ValidationException::withMessages(['title' => 'This kit is not published and ready to sell.']);
        }
        $revision = $product->archiveRevision()->first();
        try {
            $manifest = app(SoundKitManifest::class)->verified($revision);
        } catch (MediaFailure) {
            throw ValidationException::withMessages(['revision_public_id' => 'The kit archive could not be verified as clean and intact.']);
        }
        $license = $product->licenseVersion()->first();
        $snapshot = [
            'schema' => 'sound-kit-purchase-binding-v1', 'canonicalization' => CanonicalJson::VERSION, 'family' => 'wav_sample_kit',
            'payable' => false, 'commercial_blockers' => ['kit_checkout_unbuilt'],
            'product_id' => $product->public_id, 'offer_terms_hash' => $products->termsHash($product),
            'kit_revision_id' => $revision->public_id, 'kit_revision_number' => $revision->number, 'manifest_sha256' => $revision->manifest_sha256,
            'archive' => $manifest['archive'], 'member_count' => count($manifest['members']),
            'license' => ['version_id' => $license->id, 'submission_hash' => $license->submission_hash],
            'price_minor' => $product->price_minor, 'currency' => $product->currency,
        ];

        return ['snapshot' => $snapshot, 'snapshot_sha256' => CanonicalJson::hash($snapshot)];
    }
}
