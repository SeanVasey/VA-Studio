<?php

declare(strict_types=1);

namespace Vasey\Ops;

use Illuminate\Support\Arr;

/**
 * The staging capability profile's boundary, in one place so the two validators that enforce it cannot drift apart:
 * ops/staging/validate-runtime.php (the deploy gate, which refuses a deploy) and scripts/ops/validate-test-commerce-profile.php
 * (the profile validator an operator runs by hand). Evidence and the reason for each flag:
 * docs/verification/production-ops-kit-20261017/ (batches K2 and K3).
 *
 * This file only reads values handed to it; it boots nothing, reads no environment and prints nothing.
 */
final class CapabilityBoundary
{
    /**
     * The ten capability flags, environment key => config key. Each defaults to false in config/ and both staging templates state
     * each as `false`. Seven are admitted on staging by TestEnvironment::admitsTestCommerce() and are off because the rehearsal does not
     * use them; the support-attachment fixture is local and testing only; and VASEY_OPERATIVE_FREE_GRANTS_ENABLED and
     * CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED are not gated by environment at all (an adapter bound by other code is the only
     * other condition), so on staging the template line and the deploy gate are their only guards.
     */
    public const CAPABILITY_FLAGS = [
        'VASEY_TEST_SERVICE_PROJECTS_ENABLED' => 'services-projects.test_enabled',
        'VASEY_TEST_FREE_GRANTS_ENABLED' => 'free-grants.test_enabled',
        'VASEY_TEST_CUSTOMER_CONSENT_ENABLED' => 'customer-preferences.test_grants_enabled',
        'VASEY_TEST_UNPAID_RELEASE_ENABLED' => 'unpaid-release.enabled',
        'VASEY_TEST_REFUND_RESOLUTION_ENABLED' => 'refund-resolution.enabled',
        'VASEY_TEST_TRANSACTIONAL_NOTIFICATIONS_ENABLED' => 'transactional-notifications.test_enabled',
        'CONTACT_TEST_ORDER_INQUIRIES_ENABLED' => 'inquiries.test_order_inquiries_enabled',
        'CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED' => 'inquiries.operator_notifications_enabled',
        'VASEY_OPERATIVE_FREE_GRANTS_ENABLED' => 'free-grants.operative_enabled',
        'VASEY_SUPPORT_ATTACHMENTS_FIXTURE_ENABLED' => 'support-attachments.fixture_enabled',
    ];

    /**
     * Stated false in the base staging template beside the ten: production-lane or one-way, so never left to a default.
     * The public track sitemap build chain is not environment-gated; promoting customer listening data to V2 cannot be undone by
     * turning it off again.
     */
    public const ADDITIONAL_FLAGS = [
        'DISCOVERY_SITEMAP_ENABLED' => 'discovery-sitemap.enabled',
        'VASEY_LISTENING_V2_PROMOTION_ENABLED' => 'customer-listening.v2_promotion_enabled',
    ];

    /**
     * Configuration files of the production lanes: every `production-*` or `production_*` file, paid-grants (Paid252),
     * paid-tax-grants (Tax255) and member-grants. An `enabled` or `mounted` flag in any of them must not be strictly true on staging.
     * Today they hold literal values with no environment switch; this keeps a future switch or default change from opening one.
     */
    public const PRODUCTION_LANE_PATTERN = '/\A(?:production[-_].+|paid-grants|paid-tax-grants|member-grants)\z/';

    /** Lanes the brief names (Paid252, Tax255, production customer identity, production free grants): a rename must update this. */
    public const NAMED_PRODUCTION_LANES = ['paid-grants', 'paid-tax-grants', 'production-tax-checkout', 'production-customer-identity', 'production-free-grants'];

    /** @return array<string, string> environment key => config key, the ten and the two */
    public static function stagedFlags(): array
    {
        return [...self::CAPABILITY_FLAGS, ...self::ADDITIONAL_FLAGS];
    }

    /**
     * The staged flags whose effective value is not exactly boolean false. Strict on purpose: a blank, numeric or "yes" value is
     * not what the profile states, and a config key that was renamed or removed reads as null, so the gate fails closed until it
     * is updated instead of silently passing.
     *
     * @param  callable(string): mixed  $read  reads one config key, normally `config(...)`
     * @return list<string> environment keys
     */
    public static function flagsNotOff(callable $read): array
    {
        $notOff = [];
        foreach (self::stagedFlags() as $environmentKey => $configKey) {
            if ($read($configKey) !== false) {
                $notOff[] = $environmentKey;
            }
        }

        return $notOff;
    }

    /**
     * Production-lane flags that are true, and named lanes whose configuration file is missing.
     *
     * @param  callable(string): array<mixed>  $read  reads one config file's whole array by name, normally `(array) config($stem)`
     * @return array{open: list<string>, missing: list<string>, scanned: list<string>}
     */
    public static function productionLanes(string $configDirectory, callable $read): array
    {
        $scanned = [];
        foreach (glob(rtrim($configDirectory, '/').'/*.php') ?: [] as $path) {
            $stem = basename($path, '.php');
            if (preg_match(self::PRODUCTION_LANE_PATTERN, $stem) === 1) {
                $scanned[] = $stem;
            }
        }
        sort($scanned);
        $open = [];
        foreach ($scanned as $stem) {
            foreach (Arr::dot($read($stem)) as $key => $value) {
                if ($value === true && preg_match('/(?:enabled|mounted)\z/', (string) $key) === 1) {
                    $open[] = $stem.'.'.$key;
                }
            }
        }

        return ['open' => $open, 'missing' => array_values(array_diff(self::NAMED_PRODUCTION_LANES, $scanned)), 'scanned' => $scanned];
    }
}
