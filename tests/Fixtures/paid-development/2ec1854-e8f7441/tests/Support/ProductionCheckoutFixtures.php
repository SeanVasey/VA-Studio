<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionPolicy\PrepareProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReviewProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SaveProductionTrackCapabilities;
use Carbon\CarbonImmutable;

/** Actual catalog/source lifecycle with new synthetic checkout choices, never a legal/account approval. */
final class ProductionCheckoutFixtures
{
    public static function catalog(): array
    {
        ProductionCheckoutProviderFixtures::configure();
        $fixture = ProductionTrackPreparationFixtures::prepared();
        $machine = ProductionCheckoutProviderFixtures::machine();
        $candidate = app(SaveProductionTrackCapabilities::class)->applyReviewed(
            app(PrepareProductionTrackCapabilities::class)->review($fixture['candidate'], $fixture['source'], $machine, $fixture['actor']), $fixture['actor']);
        app(ReviewProductionTrackCapabilities::class)->applyReviewed(app(ReviewProductionTrackCapabilities::class)->review($candidate, $fixture['reviewer']),
            ProductionTrackPreparationFixtures::reference('software_choices_reviewed'), $fixture['reviewer']);
        config(['production_checkout.exemption_authoring_enabled' => true,
            'production_checkout.exemption_policy_owner_ids' => [$fixture['actor']->id]]);

        return array_replace($fixture, compact('machine', 'candidate'));
    }

    public static function exemptionPolicy(array $fixture): array
    {
        $now = CarbonImmutable::now('UTC');

        return ['schema_version' => 1, 'purpose' => 'production_checkout_exemption_qualification_policy',
            'provenance' => 'synthetic_rehearsal', 'tax_source_reference' => 'synthetic:qualified-exemption-source',
            'tax_source_sha256' => $fixture['machine']['source_commitments']['tax_calculation'],
            'qualification_reference' => 'synthetic:owner-approved-qualification-scope',
            'qualification_source_sha256' => hash('sha256', 'NONBINDING SYNTHETIC QUALIFICATION SCOPE'),
            'issuer_legal_name' => 'synthetic:NONBINDING QUALIFIER', 'qualifier_ids' => [$fixture['actor']->id],
            'effective_from' => $now->subDay()->format('Y-m-d\TH:i:s\Z'),
            'effective_until' => $now->addYear()->format('Y-m-d\TH:i:s\Z'), 'owner_approved' => true];
    }
}
