<?php

namespace Tests\Support;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyVersion;
use App\Domain\Commerce\Policy\ReviewProductionTrackPolicy;
use App\Domain\Commerce\ProductionPolicy\PreparationContextV1;
use App\Domain\Commerce\ProductionPolicy\PrepareProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReviewProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SaveProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Domain\Commerce\ProductionPreparation\PrepareProductionTrackPreparationPacket;
use App\Domain\Commerce\ProductionPreparation\SaveProductionTrackPreparationPacket;
use Illuminate\Support\Facades\DB;

/** Actual local synthetic review/catalog lifecycle, never a merchant seed or legal/provider approval. */
final class ProductionTrackPreparationFixtures
{
    public static function prepared(bool $save = false, string $key = 'synthetic-packet-request', ?string $licenseUntil = null): array
    {
        $selection = QuoteFixtures::selection();
        $actor = $selection['actor'];
        if ($licenseUntil !== null) {
            $license = LicenseFixtures::published($actor, content: ['effective_until' => $licenseUntil]);
            $selection['offer'] = app(SaveOfferDraft::class)->handle($selection['offer'], ['track_id' => $selection['track']->id,
                'license_version_id' => $license->id, 'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$selection['media']['master_wav']->id]], $actor);
            $selection['revision'] = app(PublishOffer::class)->handle($selection['offer'], $actor);
            $selection['items'][0]['licenseVersionId'] = $license->id;
            $selection['items'][0]['offerRevisionId'] = $selection['revision']->id;
        }
        $reviewer = LicenseFixtures::admin();
        $reviewer->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $authored = ProductionTrackPolicyFixtures::authored();
        $source = ProductionTrackPolicyFixtures::create($actor, $authored);
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $source->id)->sole();
        app(ReviewProductionTrackPolicy::class)->applyReviewed(app(ReviewProductionTrackPolicy::class)->review($version, $reviewer),
            self::reference('authored_source_acknowledged'), $reviewer);
        $machine = ProductionTrackCapabilitiesFixtures::machine($authored);
        $candidate = app(SaveProductionTrackCapabilities::class)->applyReviewed(app(PrepareProductionTrackCapabilities::class)->review(null, $source, $machine, $actor), $actor);
        app(ReviewProductionTrackCapabilities::class)->applyReviewed(app(ReviewProductionTrackCapabilities::class)->review($candidate, $reviewer),
            self::reference('software_choices_reviewed'), $reviewer);
        $context = PreparationContextV1::forMachine($machine);
        $capture = app(PrepareProductionTrackPreparationPacket::class)->review($candidate, $context, $selection['items'], $key, $actor);
        $packet = $save ? app(SaveProductionTrackPreparationPacket::class)->applyReviewed($capture, $actor) : null;

        return $selection + compact('reviewer', 'authored', 'source', 'machine', 'candidate', 'context', 'capture', 'packet', 'key');
    }

    public static function reference(string $affirmation): array
    {
        return ['reference' => 'synthetic:preparation-review', 'source_sha256' => hash('sha256', 'NONBINDING PREPARATION TEST'), $affirmation => true];
    }

    public static function rows(): array
    {
        $rows = [];
        foreach ([PacketEvidence::PACKETS, PacketEvidence::LINES, 'audit_events', 'quotes', 'orders', 'inventory_reservations', 'license_grants', 'checkout_intents'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
        }

        return $rows;
    }
}
