<?php

namespace App\Support\Environment;

/**
 * The one place that decides which application environments may run test commerce.
 *
 * `staging` is a hosted rehearsal of the store: it admits the Stripe-test-mode commerce chain and the
 * synthetic test-customer and test-delivery capabilities exactly as `local` does, requires staff MFA as
 * production does, and never admits live funds, production identity or any production-only path.
 * Each gate keeps its own default-off flag, Stripe `test` mode and credential-shape checks; this class
 * decides only environment admission. Development conveniences (fixtures, private alpha, persistent
 * content launchers) and production-lane rehearsals deliberately stay explicitly local/testing.
 */
final class TestEnvironment
{
    public const STAGING = 'staging';

    /** Exact names, never patterns: `staging-eu` or `Staging` is not staging. */
    public const TEST_COMMERCE = ['local', 'testing', self::STAGING];

    public const STAFF_MFA = ['production', self::STAGING];

    /** Stripe-test-mode commerce and synthetic test capabilities: local, testing or staging. */
    public static function admitsTestCommerce(): bool
    {
        return in_array(app()->environment(), self::TEST_COMMERCE, true);
    }

    /** Staff MFA is required wherever the panel is a hosted, networked installation. */
    public static function requiresStaffMfa(): bool
    {
        return in_array(app()->environment(), self::STAFF_MFA, true);
    }

    /** True in staging, where live funds, production identity and verified-production provenance are refused. */
    public static function refusesProductionOnly(): bool
    {
        return self::isStaging(app()->environment());
    }

    /**
     * Compares an already captured environment value without resolving the container, for policies
     * that read the environment binding by reflection and must never invoke it.
     */
    public static function isStaging(mixed $environment): bool
    {
        return $environment === self::STAGING;
    }
}
