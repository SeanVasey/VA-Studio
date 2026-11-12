<?php

declare(strict_types=1);

namespace Vasey\Ops;

use App\Domain\Commerce\Inventory\ExclusiveSelectionPolicy;
use App\Domain\Commerce\UnpaidRelease\UnpaidReleasePolicy;
use App\Domain\Delivery\TestDeliveryAutoEnablePolicy;
use Illuminate\Support\Arr;

/**
 * The capability boundary, in one place so the validators that enforce it cannot drift apart: ops/staging/validate-runtime.php
 * (the staging deploy gate, which refuses a deploy), scripts/ops/validate-test-commerce-profile.php (the profile validator an
 * operator runs by hand) and ops/production/validate-runtime.php (the production admission, which takes the config key of each
 * of its four pre-launch hold flags from here and names only their environment keys). Evidence and the reason for each flag:
 * docs/verification/production-ops-kit-20261017/ (batches K2 and K3).
 *
 * This file only reads values handed to it; it boots nothing, reads no environment and prints nothing.
 */
final class CapabilityBoundary
{
    /**
     * The twelve capability flags, environment key => config key. Each defaults to false in config/ and both staging templates state
     * each as `false`. Eight are admitted on staging by TestEnvironment::admitsTestCommerce() and are off because the rehearsal does not
     * use them; the support-attachment fixture is local and testing only; and VASEY_OPERATIVE_FREE_GRANTS_ENABLED,
     * CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED and VASEY_TRANSACTIONAL_MAIL_ENABLED are not gated by environment outside local
     * and testing (an adapter or operator configuration is the only other condition), so on staging the template line and the deploy
     * gate are their only guards.
     */
    public const CAPABILITY_FLAGS = [
        'VASEY_TEST_SERVICE_PROJECTS_ENABLED' => 'services-projects.test_enabled',
        'VASEY_TEST_FREE_GRANTS_ENABLED' => 'free-grants.test_enabled',
        'VASEY_TEST_CUSTOMER_CONSENT_ENABLED' => 'customer-preferences.test_grants_enabled',
        'VASEY_TEST_REFUND_RESOLUTION_ENABLED' => 'refund-resolution.enabled',
        'VASEY_TEST_REFUND_DISPUTE_ENABLED' => 'refund-dispute.enabled',
        'VASEY_TEST_TRANSACTIONAL_NOTIFICATIONS_ENABLED' => 'transactional-notifications.test_enabled',
        'VASEY_CUSTOMER_DATA_RIGHTS_ENABLED' => 'customer-data-rights.enabled',
        'VASEY_TRANSACTIONAL_MAIL_ENABLED' => 'transactional-mail.enabled',
        'CONTACT_TEST_ORDER_INQUIRIES_ENABLED' => 'inquiries.test_order_inquiries_enabled',
        'CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED' => 'inquiries.operator_notifications_enabled',
        'VASEY_OPERATIVE_FREE_GRANTS_ENABLED' => 'free-grants.operative_enabled',
        'VASEY_SUPPORT_ATTACHMENTS_FIXTURE_ENABLED' => 'support-attachments.fixture_enabled',
    ];

    /**
     * Optional on staging (Sean's authorization for the staging alpha, 2026-11-11): environment key => the config key of the switch
     * and the policy class that admits it. Default off: config/ defaults it to false and both staging templates state it `false`.
     * Not part of stagedFlags(), so the staging gates do not hold it false; they accept it only as exactly false, or as exactly true
     * when the policy's current() accepts the effective configuration. For unpaid release that means TestEnvironment admits test
     * commerce (never production), Stripe test mode, an `acct_` account and VASEY_TEST_UNPAID_RELEASE_POLICY equal to the exact
     * UnpaidReleasePolicy::CONTRACT. Production holds it false (PRODUCTION_LAUNCH_OFF_FLAGS). Review: docs/test-unpaid-release.md.
     * Automatic test delivery enabling (D-19 amendment, 2026-11-12, under Sean's delegation) follows the same rule with
     * TestDeliveryAutoEnablePolicy::CONTRACT in VASEY_TEST_DELIVERY_AUTO_POLICY; production holds it false and its policy blank
     * (scripts/ops/private-server-preflight.php). Review: docs/ops/staging-test-purchase.md.
     */
    public const STAGING_OPTIONAL_FLAGS = [
        'VASEY_TEST_UNPAID_RELEASE_ENABLED' => ['config' => 'unpaid-release.enabled',
            'policy' => UnpaidReleasePolicy::class],
        'VASEY_TEST_DELIVERY_AUTO_ENABLED' => ['config' => 'delivery.test_auto_enable_enabled',
            'policy' => TestDeliveryAutoEnablePolicy::class],
    ];

    /**
     * Optional JSON test policies on staging: environment key => the config key holding the policy and the class whose current()
     * must accept it. Unlike the flags above, the policy value itself is the switch: blank (unset or empty) is off, and any other
     * value is admitted only when the class accepts the effective configuration. The Exclusive selection policy is in use on the
     * hosted rehearsal by Sean's decision (32 active Exclusive offers, docs/ops/staging-placeholders.md); ExclusiveSelectionPolicy
     * accepts it only where TestEnvironment admits test commerce and only in its one exact shape. Production holds it blank:
     * scripts/ops/private-server-preflight.php (`inactive_vasey_test_exclusive_selection_policy`), and the class refuses there in
     * code. Review: docs/ops/exclusive-rehearsal.md.
     */
    public const STAGING_OPTIONAL_POLICIES = [
        'VASEY_TEST_EXCLUSIVE_SELECTION_POLICY' => ['config' => 'commerce.test_exclusive_selection_policy',
            'policy' => ExclusiveSelectionPolicy::class],
    ];

    /**
     * Stated false in the base staging template beside the twelve: production-lane, production-only or one-way, so never left to a default.
     * The public track sitemap build chain is not environment-gated; promoting customer listening data to V2 cannot be undone by
     * turning it off again. VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED is deliberately not held: it admits only the
     * `vasey:staging-synthetic-catalog` console command, which refuses production whatever the flag says, and
     * docs/ops/staging-runbook.md section 5a sets it true for the seeding run (a hold would refuse that documented deploy).
     * The two production admissions (lane PCC) are production-profile only: a real mail transport and Stripe live mode are never
     * admitted on staging, so the staging gates refuse either unless it is false (the production validator reads them itself).
     */
    public const ADDITIONAL_FLAGS = [
        'DISCOVERY_SITEMAP_ENABLED' => 'discovery-sitemap.enabled',
        'VASEY_LISTENING_V2_PROMOTION_ENABLED' => 'customer-listening.v2_promotion_enabled',
        'VASEY_PRODUCTION_REAL_MAIL_ADMITTED' => 'production-runtime.real_mail_admitted',
        'VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED' => 'production-runtime.live_stripe_admitted',
    ];

    /**
     * Held false in production through launch by ops/production/validate-runtime.php (`LAUNCH_OFF_HOLDS`), which names only the
     * environment keys and reads the config key from here. Not part of stagedFlags(): the staging gates do not hold them, so the
     * rehearsal keeps its template value. DD-44 (2026-11-10): no marketing email at v1, so the marketing customer segment stays off.
     */
    public const PRODUCTION_LAUNCH_OFF_FLAGS = [
        'REPORTING_MARKETING_SEGMENT_ENABLED' => 'reporting.customer_segments.marketing_segment_enabled',
        // BL-70: Stripe test-mode service invoicing. Admitted on staging by the test-commerce gate, never in production.
        'VASEY_TEST_SERVICE_INVOICING_ENABLED' => 'services-invoicing.test_enabled',
        // Unpaid release is optional on staging (STAGING_OPTIONAL_FLAGS) and has no production path; production holds it false.
        'VASEY_TEST_UNPAID_RELEASE_ENABLED' => 'unpaid-release.enabled',
        // BL-71: the BeatStars catalog import is a staging console command that refuses production in code; the hold keeps the flag
        // from ever being set on the production host, as the staging deploy gate cannot (it must be true for the import run).
        'VASEY_STAGING_BEATSTARS_IMPORT_ENABLED' => 'staging-beatstars-import.enabled',
        // Automatic test delivery enabling is optional on staging (STAGING_OPTIONAL_FLAGS) and has no production path.
        'VASEY_TEST_DELIVERY_AUTO_ENABLED' => 'delivery.test_auto_enable_enabled',
    ];

    /**
     * Configuration files of the production lanes: every `production-*` or `production_*` file, paid-grants (Paid252),
     * paid-tax-grants (Tax255) and member-grants. An `enabled` or `mounted` flag in any of them must not be strictly true on staging.
     * Today they hold literal values with no environment switch; this keeps a future switch or default change from opening one.
     */
    public const PRODUCTION_LANE_PATTERN = '/\A(?:production[-_].+|paid-grants|paid-tax-grants|member-grants)\z/';

    /** Lanes the brief names (Paid252, Tax255, production customer identity, production free grants): a rename must update this. */
    public const NAMED_PRODUCTION_LANES = ['paid-grants', 'paid-tax-grants', 'production-tax-checkout', 'production-customer-identity', 'production-free-grants'];

    /** @return array<string, string> environment key => config key, the twelve and the four */
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
     * The optional staging flags the effective configuration does not admit: a value other than exactly false, unless it is
     * exactly true and the flag's policy accepts the configuration. Strict for the same reasons as flagsNotOff(); a missing
     * config key reads as null and is refused.
     *
     * @param  callable(string): mixed  $read  reads one config key, normally `config(...)`
     * @param  callable(string): bool  $admits  given a policy class, true only when its current() accepts the configuration
     * @return list<string> environment keys
     */
    public static function optionalFlagsRefused(callable $read, callable $admits): array
    {
        $refused = [];
        foreach (self::STAGING_OPTIONAL_FLAGS as $environmentKey => $flag) {
            $value = $read($flag['config']);
            if ($value !== false && ($value !== true || $admits($flag['policy']) !== true)) {
                $refused[] = $environmentKey;
            }
        }

        return $refused;
    }

    /**
     * The optional staging policies the effective configuration does not admit: a value other than null or the empty string,
     * unless the policy class accepts the configuration. A config key that was renamed or removed reads as null and so as off;
     * the class reading the same key then finds no policy and refuses the capability it governs, so nothing opens silently.
     *
     * @param  callable(string): mixed  $read  reads one config key, normally `config(...)`
     * @param  callable(string): bool  $admits  given a policy class, true only when its current() accepts the configuration
     * @return list<string> environment keys
     */
    public static function optionalPoliciesRefused(callable $read, callable $admits): array
    {
        $refused = [];
        foreach (self::STAGING_OPTIONAL_POLICIES as $environmentKey => $policy) {
            if (! in_array($read($policy['config']), [null, ''], true) && $admits($policy['policy']) !== true) {
                $refused[] = $environmentKey;
            }
        }

        return $refused;
    }

    /**
     * The optional staging policies that are set (neither null nor empty), so a validator can say which are on.
     *
     * @param  callable(string): mixed  $read  reads one config key, normally `config(...)`
     * @return list<string> environment keys
     */
    public static function optionalPoliciesSet(callable $read): array
    {
        return array_keys(array_filter(self::STAGING_OPTIONAL_POLICIES,
            static fn (array $policy): bool => ! in_array($read($policy['config']), [null, ''], true)));
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
