<?php

namespace Tests\Support;

use App\Domain\Rights\Models\LicenseVersion;
use App\Models\User;

/** Synthetic scope choices for tests only; not approved terms or production defaults. */
final class ScopedLicenseFixtures
{
    public static function terms(): array
    {
        return array_replace(TypedLicenseFixtures::terms(), [
            'schema_version' => 3,
            'territory' => ['mode' => 'countries', 'country_codes' => ['US', 'CA']],
            'duration' => ['mode' => 'fixed_months', 'starts_at' => 'grant', 'months' => 120],
        ]);
    }

    public static function source(): string
    {
        return TypedLicenseFixtures::source()."\n{{territory}}\n{{duration}}";
    }

    public static function draft(?User $actor = null): LicenseVersion
    {
        return LicenseFixtures::draft($actor, self::terms(), ['authored_source' => self::source()]);
    }
}
