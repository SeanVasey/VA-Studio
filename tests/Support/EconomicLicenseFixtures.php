<?php

namespace Tests\Support;

use App\Domain\Rights\Models\LicenseVersion;
use App\Models\User;

/** NONBINDING, synthetic declarations only. Never production policy or default rates. */
final class EconomicLicenseFixtures
{
    public static function terms(): array
    {
        return array_replace(ScopedLicenseFixtures::terms(), [
            'schema_version' => 4,
            'ownership' => [
                'source_recording' => ['policy_key' => 'economic-fixture'],
                'source_composition' => ['policy_key' => 'economic-fixture'],
                'resulting_recording' => ['policy_key' => 'economic-fixture'],
                'resulting_composition' => ['policy_key' => 'economic-fixture'],
            ],
            'publishing_income' => ['mode' => 'share', 'policy_key' => 'economic-fixture', 'licensor_bps' => 1234],
            'recording_royalty' => ['mode' => 'rate', 'policy_key' => 'economic-fixture', 'rate_bps' => 567, 'basis' => 'gross_receipts'],
            'policies' => [['key' => 'economic-fixture', 'version' => 'test-v1', 'text' => 'NONBINDING SYNTHETIC economic policy. No real rights or payment obligation.']],
        ]);
    }

    public static function source(): string
    {
        return ScopedLicenseFixtures::source()."\n{{ownership.source_recording}}\n{{ownership.source_composition}}\n{{ownership.resulting_recording}}\n{{ownership.resulting_composition}}\n{{publishing_income}}\n{{recording_royalty}}\n{{policy_texts}}";
    }

    public static function draft(?User $actor = null): LicenseVersion
    {
        return LicenseFixtures::draft($actor, self::terms(), ['authored_source' => self::source()]);
    }
}
