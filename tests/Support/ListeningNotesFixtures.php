<?php

namespace Tests\Support;

final class ListeningNotesFixtures
{
    public static function enablePromotion(): void
    {
        config(['customer-listening.v2_promotion_enabled' => true,
            'customer-listening.v2_rollout_review_reference' => 'SYNTHETIC stopped-upgrade test fixture']);
    }
}
