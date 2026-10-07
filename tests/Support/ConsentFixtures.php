<?php

namespace Tests\Support;

use App\Domain\Customers\Preferences\ConsentPolicy;

final class ConsentFixtures
{
    public static function configure(): void
    {
        config(['customer-preferences.test_grants_enabled' => true, 'customer-preferences.email_marketing' => self::policy()]);
    }

    public static function policy(string $version = 'synthetic-notice-v1'): array
    {
        return ['purpose' => ConsentPolicy::PURPOSE, 'version' => $version,
            'notice' => 'Synthetic test notice only. This fixture is not production legal copy or owner approval.',
            'review_reference' => 'synthetic-fixture-review-only'];
    }

    public static function grant(int $version = 0): array
    {
        $policy = app(ConsentPolicy::class)->configured();

        return ['action' => 'grant-consent', 'version' => $version, 'purpose' => ConsentPolicy::PURPOSE,
            'noticeVersion' => $policy['version'], 'noticeHash' => $policy['notice_hash'], 'affirmative' => true];
    }

    public static function withdraw(int $version = 0): array
    {
        return ['action' => 'withdraw-consent', 'version' => $version, 'purpose' => ConsentPolicy::PURPOSE];
    }
}
