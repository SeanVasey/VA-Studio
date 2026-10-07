<?php

namespace Tests\Unit\ProductionFeatures;

use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningRollout;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningState;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPurposePolicy;
use Tests\Support\ConsentFixtures;
use Tests\TestCase;

final class ProductionFeatureStateTest extends TestCase
{
    public function test_ordinary_production_state_remains_v1_and_repeated_intent_preserves_state(): void
    {
        $rules = new ProductionListeningState;
        $state = $rules->empty(17);
        $command = ['action' => 'save-track', 'version' => 0, 'trackId' => '12'];
        $rules->validateCommand($command);
        $saved = $rules->apply($state, $command, fn ($id) => $id === '12', false);
        $this->assertSame(1, $saved['schema']);
        $this->assertSame(['12'], $saved['favorites']);
        $this->assertSame($saved, $rules->apply($saved, $command, fn () => true, false));
        $saved['version'] = 1;
        $this->assertSame($saved, $rules->state($saved, 17, 1));
        $cleared = $rules->apply($saved, ['action' => 'clear-library', 'version' => 1], fn () => false, false);
        $this->assertSame(1, $cleared['schema']);
        $this->assertSame([], $cleared['favorites']);
        $this->assertArrayNotHasKey('notes', $cleared);
    }

    public function test_production_note_gate_is_distinct_from_any_enabled_test_rollout(): void
    {
        config(['customer-listening.v2_promotion_enabled' => true, 'customer-listening.v2_rollout_review_reference' => 'SYNTHETIC test-only review']);
        $this->assertFalse(ProductionListeningRollout::capture()['promotionEnabled']);
        $rules = new ProductionListeningState;
        $state = $rules->empty(17);
        $state['favorites'] = ['12'];
        $state['version'] = 1;
        try {
            $rules->apply($state, ['action' => 'set-track-note', 'version' => 1, 'trackId' => '12', 'body' => 'SYNTHETIC private note'], fn () => false, false);
            $this->fail('A test rollout must not enable production notes');
        } catch (ListeningException $e) {
            $this->assertSame(503, $e->status);
        }
        config(['production-customer-listening.v2_promotion_enabled' => true, 'production-customer-listening.v2_rollout_review_reference' => 'SYNTHETIC separate stopped production rollout']);
        $this->assertTrue(ProductionListeningRollout::capture()['promotionEnabled']);
        $notes = $rules->apply($state, ['action' => 'set-track-note', 'version' => 1, 'trackId' => '12', 'body' => 'SYNTHETIC private note'], fn () => false, true);
        $this->assertSame(2, $notes['schema']);
        $this->assertSame($notes, $rules->apply($notes, ['action' => 'set-track-note', 'version' => 1, 'trackId' => '12', 'body' => 'SYNTHETIC private note'], fn () => false, false));
    }

    public function test_unavailable_tracks_and_forged_tenant_or_version_state_refuse(): void
    {
        $rules = new ProductionListeningState;
        try {
            $rules->apply($rules->empty(17), ['action' => 'save-track', 'version' => 0, 'trackId' => '12'], fn () => false, false);
            $this->fail('Unavailable metadata must not be saved');
        } catch (ListeningException $e) {
            $this->assertSame(404, $e->status);
        }
        foreach ([[$rules->empty(18), 17, 0], [$rules->empty(17), 17, 1]] as [$state,$account,$version]) {
            try {
                $rules->state($state, $account, $version);
                $this->fail('Expected exact tenant/revision binding');
            } catch (ListeningException $e) {
                $this->assertSame(503, $e->status);
            }
        }
    }

    public function test_production_purpose_starts_unknown_and_cannot_inherit_test_consent_policy(): void
    {
        ConsentFixtures::configure();
        $policy = ProductionConsentPurposePolicy::capture();
        $this->assertFalse($policy['grantsEnabled']);
        $this->assertNull($policy['configured']);
        config(['production-customer-preferences' => ['grants_enabled' => 'true', 'email_marketing' => ConsentFixtures::policy()]]);
        $this->assertFalse(ProductionConsentPurposePolicy::capture()['grantsEnabled']);
        config(['production-customer-preferences.grants_enabled' => true]);
        $configured = ProductionConsentPurposePolicy::capture();
        $this->assertTrue($configured['grantsEnabled']);
        $this->assertSame(ConsentFixtures::policy()['notice'], $configured['configured']['notice']);
        config(['production-customer-preferences.email_marketing.review_reference' => '']);
        $this->assertFalse(ProductionConsentPurposePolicy::capture()['grantsEnabled']);
    }
}
