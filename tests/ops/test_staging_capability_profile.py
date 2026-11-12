"""The staging capability profile: what the two env templates state, and the application facts that justify each value.

The staging templates (ops/staging/env.staging.example, ops/staging/test-commerce/env.test-commerce.example) must state every
application capability flag explicitly. This reads the real config files and policy classes (no copies of the facts), so a new
config key, a flipped default or a changed admission rule fails here and sends the profile back for review. It reads files only:
no application boot, database, network or host."""
import json
import re
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
BASE = REPO / "ops/staging/env.staging.example"
OVERLAY = REPO / "ops/staging/test-commerce/env.test-commerce.example"
VALIDATOR = REPO / "scripts/ops/validate-test-commerce-profile.php"

ENV_CALL = re.compile(r"""env\(\s*['"]([A-Za-z0-9_]+)['"]""")
ASSIGNMENT = re.compile(r"^(#?)\s*([A-Z][A-Z0-9_]*)=(.*)$")

# Environment key -> (config file, config key). The ten flags of batch K2 plus A5's refund/dispute flag and lane CX's data rights and
# order-ready email flags, in the validator's CAPABILITY_FLAGS, less unpaid release, which moved to OPTIONAL on 2026-11-11.
FLAGS = {
    "VASEY_TEST_SERVICE_PROJECTS_ENABLED": ("services-projects", "test_enabled"),
    "VASEY_TEST_FREE_GRANTS_ENABLED": ("free-grants", "test_enabled"),
    "VASEY_TEST_CUSTOMER_CONSENT_ENABLED": ("customer-preferences", "test_grants_enabled"),
    "VASEY_TEST_REFUND_RESOLUTION_ENABLED": ("refund-resolution", "enabled"),
    "VASEY_TEST_REFUND_DISPUTE_ENABLED": ("refund-dispute", "enabled"),
    "VASEY_TEST_TRANSACTIONAL_NOTIFICATIONS_ENABLED": ("transactional-notifications", "test_enabled"),
    "VASEY_CUSTOMER_DATA_RIGHTS_ENABLED": ("customer-data-rights", "enabled"),
    "VASEY_TRANSACTIONAL_MAIL_ENABLED": ("transactional-mail", "enabled"),
    "CONTACT_TEST_ORDER_INQUIRIES_ENABLED": ("inquiries", "test_order_inquiries_enabled"),
    "CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED": ("inquiries", "operator_notifications_enabled"),
    "VASEY_OPERATIVE_FREE_GRANTS_ENABLED": ("free-grants", "operative_enabled"),
    "VASEY_SUPPORT_ATTACHMENTS_FIXTURE_ENABLED": ("support-attachments", "fixture_enabled"),
}
# Optional on staging (Sean's authorization for the staging alpha, 2026-11-11): CapabilityBoundary::STAGING_OPTIONAL_FLAGS,
# environment key -> (config file, config key, policy class). Both templates still state each as `false`: turning one on is a
# per-host decision with its exact policy line, never a template default. The deploy gate accepts exactly false, or exactly true
# with the policy class accepting the effective configuration.
OPTIONAL = {
    "VASEY_TEST_UNPAID_RELEASE_ENABLED": ("unpaid-release", "enabled", "App\\Domain\\Commerce\\UnpaidRelease\\UnpaidReleasePolicy"),
    # D-19 amendment (2026-11-12, under Sean's delegation): automatic enabling of test delivery for never-controlled ready orders.
    "VASEY_TEST_DELIVERY_AUTO_ENABLED": ("delivery", "test_auto_enable_enabled", "App\\Domain\\Delivery\\TestDeliveryAutoEnablePolicy"),
}
# Stated explicitly but outside the twelve: production-lane or one-way, so never left to a default on staging.
EXTRA_FALSE = ("DISCOVERY_SITEMAP_ENABLED", "VASEY_LISTENING_V2_PROMOTION_ENABLED", "VASEY_PRODUCTION_REAL_MAIL_ADMITTED",
               "VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED")

# Keys config/ reads that the kit deliberately leaves to Laravel's own defaults: cache and queue back ends the kit does not use
# (it runs database cache, queue and session on one MySQL), providers it does not use (mail is log, no Postmark, Resend, Slack,
# Papertrail, SQS, DynamoDB or Beanstalkd), and connection tuning with sound defaults. A key outside this list and outside
# both templates is a new setting nobody has decided about.
FRAMEWORK_DEFAULTS = {
    "app.php": ("APP_PREVIOUS_KEYS",),
    "auth.php": ("AUTH_GUARD", "AUTH_MODEL", "AUTH_PASSWORD_BROKER", "AUTH_PASSWORD_RESET_TOKEN_TABLE", "AUTH_PASSWORD_TIMEOUT"),
    "cache.php": (
        "CACHE_STORAGE_DISK", "CACHE_STORAGE_PATH", "DB_CACHE_CONNECTION", "DB_CACHE_LOCK_CONNECTION", "DB_CACHE_LOCK_TABLE",
        "DB_CACHE_TABLE", "DYNAMODB_CACHE_TABLE", "DYNAMODB_ENDPOINT", "MEMCACHED_PASSWORD", "MEMCACHED_PERSISTENT_ID",
        "MEMCACHED_PORT", "MEMCACHED_USERNAME", "REDIS_CACHE_CONNECTION", "REDIS_CACHE_LOCK_CONNECTION",
    ),
    "database.php": (
        "DB_CHARSET", "DB_COLLATION", "DB_ENCRYPT", "DB_FOREIGN_KEYS", "DB_SOCKET", "DB_SSLMODE", "DB_TRUST_SERVER_CERTIFICATE",
        "DB_URL", "MYSQL_ATTR_SSL_CA", "REDIS_BACKOFF_ALGORITHM", "REDIS_BACKOFF_BASE", "REDIS_BACKOFF_CAP", "REDIS_CACHE_DB",
        "REDIS_CLUSTER", "REDIS_DB", "REDIS_MAX_RETRIES", "REDIS_PERSISTENT", "REDIS_PREFIX", "REDIS_URL", "REDIS_USERNAME",
    ),
    "filesystems.php": ("AWS_ENDPOINT", "AWS_URL"),
    "logging.php": (
        "LOG_DEPRECATIONS_TRACE", "LOG_PAPERTRAIL_HANDLER", "LOG_SLACK_EMOJI", "LOG_SLACK_USERNAME", "LOG_SLACK_WEBHOOK_URL",
        "LOG_STDERR_FORMATTER", "LOG_SYSLOG_FACILITY", "PAPERTRAIL_PORT", "PAPERTRAIL_URL",
    ),
    "mail.php": ("MAIL_EHLO_DOMAIN", "MAIL_LOG_CHANNEL", "MAIL_SENDMAIL_PATH", "MAIL_URL", "POSTMARK_MESSAGE_STREAM_ID"),
    "queue.php": (
        "BEANSTALKD_QUEUE", "BEANSTALKD_QUEUE_HOST", "BEANSTALKD_QUEUE_RETRY_AFTER", "DB_QUEUE", "DB_QUEUE_CONNECTION",
        "DB_QUEUE_TABLE", "QUEUE_FAILED_DRIVER", "REDIS_QUEUE", "REDIS_QUEUE_CONNECTION", "SQS_PREFIX", "SQS_QUEUE", "SQS_SUFFIX",
    ),
    "services.php": ("POSTMARK_API_KEY", "RESEND_API_KEY", "SLACK_BOT_USER_DEFAULT_CHANNEL", "SLACK_BOT_USER_OAUTH_TOKEN"),
    "session.php": (
        "SESSION_CONNECTION", "SESSION_COOKIE", "SESSION_EXPIRE_ON_CLOSE", "SESSION_PARTITIONED_COOKIE", "SESSION_STORE",
        "SESSION_TABLE",
    ),
}
ALLOWED = {key for keys in FRAMEWORK_DEFAULTS.values() for key in keys}


def config_keys():
    keys = {}
    for path in sorted((REPO / "config").glob("*.php")):
        for key in ENV_CALL.findall(path.read_text()):
            keys.setdefault(key, set()).add(path.name)
    return keys


def assignments(path):
    """Every KEY=value line, as (commented, value) in file order, so duplicates stay visible."""
    found = {}
    for line in path.read_text().splitlines():
        match = ASSIGNMENT.match(line)
        if match:
            found.setdefault(match.group(2), []).append((bool(match.group(1)), match.group(3).strip()))
    return found


def source(relative):
    return (REPO / relative).read_text()


class TemplatesCoverEveryConfigKey(unittest.TestCase):
    def test_every_config_key_is_in_a_template_or_the_framework_allowlist(self):
        in_templates = set(assignments(BASE)) | set(assignments(OVERLAY))
        undecided = sorted(k for k in config_keys() if k not in in_templates and k not in ALLOWED)
        self.assertEqual(
            undecided, [],
            "config/ reads these environment keys but neither staging template mentions them: add each (commented if it must "
            "stay unset) with the reason, or, for a framework default the kit leaves alone, add it to FRAMEWORK_DEFAULTS",
        )

    def test_the_allowlist_has_no_stale_or_redundant_entries(self):
        keys = config_keys()
        in_templates = set(assignments(BASE)) | set(assignments(OVERLAY))
        self.assertEqual(sorted(k for k in ALLOWED if k not in keys), [], "allowlisted keys config/ no longer reads")
        self.assertEqual(sorted(k for k in ALLOWED if k in in_templates), [], "allowlisted keys a template now states")
        for filename, allowed in FRAMEWORK_DEFAULTS.items():
            for key in allowed:
                self.assertIn(filename, keys.get(key, set()), f"{key} is not read by config/{filename}")


class TheTenFlagsAreExplicit(unittest.TestCase):
    def test_both_templates_state_each_flag_once_as_an_uncommented_false(self):
        for template in (BASE, OVERLAY):
            found = assignments(template)
            for flag in (*FLAGS, *OPTIONAL):
                self.assertEqual(found.get(flag), [(False, "false")], f"{template.name}: {flag}")
        base = assignments(BASE)
        for flag in EXTRA_FALSE:
            self.assertEqual(base.get(flag), [(False, "false")], f"{BASE.name}: {flag}")
        # A blank assignment is not an unset key: the review reference stays commented.
        self.assertTrue(all(commented for commented, _ in base["VASEY_LISTENING_V2_ROLLOUT_REVIEW_REFERENCE"]))

    def test_every_flag_reads_its_environment_key_and_defaults_to_false(self):
        for flag, (config, key) in {**FLAGS, **{name: entry[:2] for name, entry in OPTIONAL.items()}}.items():
            text = source(f"config/{config}.php")
            self.assertRegex(text, rf"'{re.escape(key)}'\s*=>\s*env\('{flag}',\s*false\)", f"config/{config}.php {key}")
        for flag, (config, key) in {"DISCOVERY_SITEMAP_ENABLED": ("discovery-sitemap", "enabled"),
                                    "VASEY_LISTENING_V2_PROMOTION_ENABLED": ("customer-listening", "v2_promotion_enabled"),
                                    "VASEY_PRODUCTION_REAL_MAIL_ADMITTED": ("production-runtime", "real_mail_admitted"),
                                    "VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED": ("production-runtime", "live_stripe_admitted")}.items():
            self.assertRegex(source(f"config/{config}.php"), rf"'{key}'\s*=>\s*env\('{flag}',\s*false\)")

    def test_the_shared_boundary_declares_exactly_these_flags_under_these_config_keys(self):
        text = source("scripts/ops/capability-boundary.php")
        for constant, expected in (("CAPABILITY_FLAGS", {flag: f"{config}.{key}" for flag, (config, key) in FLAGS.items()}),
                                   ("ADDITIONAL_FLAGS", {"DISCOVERY_SITEMAP_ENABLED": "discovery-sitemap.enabled",
                                                         "VASEY_LISTENING_V2_PROMOTION_ENABLED": "customer-listening.v2_promotion_enabled",
                                                         "VASEY_PRODUCTION_REAL_MAIL_ADMITTED": "production-runtime.real_mail_admitted",
                                                         "VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED": "production-runtime.live_stripe_admitted"})):
            block = re.search(rf"const {constant} = \[(.*?)\n    \];", text, re.S)
            self.assertIsNotNone(block, constant)
            self.assertEqual(dict(re.findall(r"'([A-Z0-9_]+)' => '([a-z-]+\.[a-z0-9_]+)'", block.group(1))), expected, constant)

    def test_both_validators_use_the_shared_boundary_and_neither_keeps_its_own_copy(self):
        for relative in ("ops/staging/validate-runtime.php", "scripts/ops/validate-test-commerce-profile.php"):
            text = source(relative)
            self.assertIn("scripts/ops/capability-boundary.php" if relative.startswith("ops/") else "capability-boundary.php", text, relative)
            self.assertIn("use Vasey\\Ops\\CapabilityBoundary;", text, relative)
            self.assertNotIn("const CAPABILITY_FLAGS", text, f"{relative} keeps its own flag list")
            self.assertNotIn("PRODUCTION_LANE_PATTERN", text.replace("CapabilityBoundary::PRODUCTION_LANE_PATTERN", ""), f"{relative} keeps its own lane rule")
        gate = source("ops/staging/validate-runtime.php")
        self.assertIn("CapabilityBoundary::flagsNotOff(", gate)
        self.assertIn("CapabilityBoundary::productionLanes(", gate)
        self.assertIn("'runtime.flag_off_'.strtolower(", gate)
        self.assertIn("'runtime.production_lanes_closed'", gate)
        # Fail closed when the shared file is absent: a thrown exception becomes the refusal runtime.unavailable.
        self.assertRegex(gate, r"if \(! is_file\(\$boundary\) \|\| is_link\(\$boundary\)\) \{\s*throw new RuntimeException;")

    def test_the_boundary_declares_exactly_the_optional_flags_and_keeps_them_out_of_the_held_set(self):
        text = source("scripts/ops/capability-boundary.php")
        block = re.search(r"const STAGING_OPTIONAL_FLAGS = \[(.*?)\n    \];", text, re.S)
        self.assertIsNotNone(block)
        found = re.findall(r"'([A-Z0-9_]+)' => \['config' => '([a-z-]+)\.([a-z0-9_]+)',\s*'policy' => \\?([A-Za-z\\]+)::class\]", block.group(1))
        uses = dict((name.rsplit("\\", 1)[-1], name) for name in re.findall(r"(?m)^use ([A-Za-z\\]+);$", text))
        self.assertEqual({flag: (config, key, uses.get(policy, policy.lstrip("\\"))) for flag, config, key, policy in found}, OPTIONAL)
        for flag in OPTIONAL:
            self.assertNotIn(f"'{flag}' =>", re.search(r"const CAPABILITY_FLAGS = \[(.*?)\n    \];", text, re.S).group(1))
            self.assertNotIn(f"'{flag}' =>", re.search(r"const ADDITIONAL_FLAGS = \[(.*?)\n    \];", text, re.S).group(1))
            # Production holds every optional staging flag false.
            self.assertIn(f"'{flag}' => '{OPTIONAL[flag][0]}.{OPTIONAL[flag][1]}'",
                          re.search(r"const PRODUCTION_LAUNCH_OFF_FLAGS = \[(.*?)\n    \];", text, re.S).group(1))
            self.assertRegex(source("ops/production/validate-runtime.php"), rf"const LAUNCH_OFF_HOLDS = \[[^\]]*'{flag}',")
        # An optional flag passes only as exactly false, or exactly true with the policy accepting.
        self.assertRegex(text, r"if \(\$value !== false && \(\$value !== true \|\| \$admits\(\$flag\['policy'\]\) !== true\)\) \{\s*\$refused\[\] = \$environmentKey;")

    def test_the_deploy_gate_and_the_profile_validator_admit_an_optional_flag_only_through_its_policy(self):
        admits = (r"static function \(string \$policy\): bool \{\s*try \{\s*app\(\$policy\)->current\(\);\s*return true;"
                  r"\s*\} catch \(Throwable\) \{\s*return false;\s*\}\s*\}")
        gate = source("ops/staging/validate-runtime.php")
        self.assertRegex(gate, r"CapabilityBoundary::optionalFlagsRefused\(static fn \(string \$key\): mixed => config\(\$key\), " + admits)
        self.assertIn("$check('runtime.optional_'.strtolower($flag), ! in_array($flag, $refused, true));", gate)
        self.assertLess(gate.index("CapabilityBoundary::optionalFlagsRefused("), gate.index('echo "PASS runtime.profile'))
        validator = source("scripts/ops/validate-test-commerce-profile.php")
        self.assertRegex(validator, r"CapabilityBoundary::optionalFlagsRefused\(static fn \(string \$key\): mixed => config\(\$key\), " + admits)
        self.assertIn("'boundary.optional_capabilities_admitted'", validator)
        self.assertIn("'profile.optional_flags_explicit'", validator)
        out_of_profile = re.search(r"const OUT_OF_PROFILE_FLAGS = \[(.*?)\];", validator, re.S).group(1)
        for flag, (config, key, _) in OPTIONAL.items():
            self.assertNotIn(f"'{config}.{key}'", out_of_profile, f"{flag} is optional, not out of profile")

    def test_the_overlay_shows_the_exact_unpaid_release_policy_commented(self):
        # The one line the operator uncomments to turn the capability on must be the contract the policy class accepts.
        policy = source("app/Domain/Commerce/UnpaidRelease/UnpaidReleasePolicy.php")
        contract = re.search(r"public const CONTRACT = \[(.*?)\];", policy, re.S).group(1)
        expected = dict(re.findall(r"'([a-z_]+)' => '([a-z0-9_-]+)'", contract))
        expected["schema_version"] = int(re.search(r"'schema_version' => (\d+)", contract).group(1))
        lines = [value for commented, value in assignments(OVERLAY).get("VASEY_TEST_UNPAID_RELEASE_POLICY", []) if commented]
        self.assertEqual(len(lines), 1, "the overlay must show the policy once, commented")
        self.assertEqual(assignments(OVERLAY)["VASEY_TEST_UNPAID_RELEASE_POLICY"], [(True, lines[0])])
        self.assertTrue(lines[0].startswith("'") and lines[0].endswith("'"))
        self.assertEqual(json.loads(lines[0][1:-1]), expected)
        self.assertEqual(len(expected), 10)
        self.assertNotIn("VASEY_TEST_UNPAID_RELEASE_POLICY", {name for name, entries in assignments(BASE).items()
                                                             if any(not commented for commented, _ in entries)})

    def test_the_overlay_shows_the_exact_automatic_delivery_policy_commented(self):
        policy = source("app/Domain/Delivery/TestDeliveryAutoEnablePolicy.php")
        contract = re.search(r"public const CONTRACT = \[(.*?)\];", policy, re.S).group(1)
        expected = dict(re.findall(r"'([a-z_]+)' => '([a-z0-9_-]+)'", contract))
        expected["schema_version"] = int(re.search(r"'schema_version' => (\d+)", contract).group(1))
        lines = [value for commented, value in assignments(OVERLAY).get("VASEY_TEST_DELIVERY_AUTO_POLICY", []) if commented]
        self.assertEqual(len(lines), 1, "the overlay must show the policy once, commented")
        self.assertEqual(assignments(OVERLAY)["VASEY_TEST_DELIVERY_AUTO_POLICY"], [(True, lines[0])])
        self.assertTrue(lines[0].startswith("'") and lines[0].endswith("'"))
        self.assertEqual(json.loads(lines[0][1:-1]), expected)
        self.assertEqual(len(expected), 10)
        self.assertNotIn("VASEY_TEST_DELIVERY_AUTO_POLICY", {name for name, entries in assignments(BASE).items()
                                                            if any(not commented for commented, _ in entries)})

    def test_the_deploy_gate_checks_every_staged_flag_before_it_passes(self):
        gate = source("ops/staging/validate-runtime.php")
        self.assertLess(gate.index("CapabilityBoundary::flagsNotOff("), gate.index('echo "PASS runtime.profile'))
        self.assertLess(gate.index("CapabilityBoundary::productionLanes("), gate.index('echo "PASS runtime.profile'))

    def test_the_two_templates_agree_on_every_flag(self):
        for flag in (*FLAGS, *OPTIONAL, *EXTRA_FALSE):
            if flag in assignments(OVERLAY):
                self.assertEqual(assignments(OVERLAY)[flag], assignments(BASE)[flag], flag)


class AdmissionMatchesTheValues(unittest.TestCase):
    """The reason each flag is false on staging, read from the policy class that honours it."""

    def assert_source(self, relative, pattern):
        self.assertRegex(source(relative), re.compile(pattern, re.S), f"{relative}: admission rule changed; re-review the staging profile")

    def test_flags_admitted_on_staging_go_through_the_test_commerce_gate(self):
        gate = r"TestEnvironment::admitsTestCommerce\(\)"
        self.assert_source("app/Domain/Services/Projects/ServiceProjectPolicy.php", gate + r" && config\('services-projects\.test_enabled'\) === true")
        self.assert_source("app/Domain/Grants/Free/FreeGrantPolicy.php", gate + r" && config\('free-grants\.test_enabled'\) === true\s*\|\|")
        self.assert_source("app/Domain/Customers/Preferences/LocalConsentRuntime.php", gate + r" && config\('customer-preferences\.test_grants_enabled'\) === true")
        self.assert_source("app/Domain/Notifications/TransactionalNotificationPolicy.php", r"! TestEnvironment::admitsTestCommerce\(\).*?config\('transactional-notifications\.test_enabled'\) !== true")
        self.assert_source("app/Domain/Commerce/UnpaidRelease/UnpaidReleasePolicy.php", r"! TestEnvironment::admitsTestCommerce\(\).*?config\('unpaid-release\.enabled'\) !== true")
        self.assert_source("app/Domain/Delivery/TestDeliveryAutoEnablePolicy.php", r"! TestEnvironment::admitsTestCommerce\(\).*?config\('delivery\.test_auto_enable_enabled'\) !== true")
        self.assert_source("app/Domain/Commerce/RefundResolution/RefundResolutionPolicy.php", r"app\(UnpaidReleasePolicy::class\)->account\(\).*?config\('refund-resolution\.enabled'\) !== true")
        self.assert_source("app/Domain/Commerce/RefundDispute/RefundDisputePolicy.php",
                           r"\$this->account\(\);\s*RefundDisputeException::require\(config\('refund-dispute\.enabled'\) === true.*?TestEnvironment::admitsTestCommerce\(\)")
        self.assert_source("app/Domain/Inquiries/OrderInquiry.php", r"function readable\(\): void\s*\{\s*if \(! TestEnvironment::admitsTestCommerce\(\)\)")
        self.assert_source("app/Domain/Customers/DataRights/DataRightsPolicy.php",
                           r"config\('customer-data-rights\.enabled'\) === true && app\(CustomerAccessPolicy::class\)->enabled\(\)")
        self.assert_source("app/Domain/Customers/CustomerAccessPolicy.php", gate + r" && config\('customer\.test_accounts_enabled'\) === true")
        self.assert_source("app/Domain/Inquiries/OrderInquiry.php", r"config\('inquiries\.test_order_inquiries_enabled'\) !== true")

    def test_the_support_fixture_is_admitted_in_local_and_testing_only(self):
        self.assert_source("app/Domain/SupportAttachments/FixtureAttachmentPolicy.php", r"app\(\)->environment\(\['local', 'testing'\]\) && config\('support-attachments\.fixture_enabled'\) === true")
        self.assert_source("app/Support/SupportAttachmentUi.php", r"app\(\)->environment\('local', 'testing'\) && config\('support-attachments\.fixture_enabled'\) === true")

    def test_operative_free_grants_are_not_environment_gated_so_staging_states_them_false(self):
        policy = source("app/Domain/Grants/Free/FreeGrantPolicy.php")
        arm = re.search(r"\|\|\s*(config\('free-grants\.operative_enabled'\)[^;]*);", policy)
        self.assertIsNotNone(arm)
        self.assertIn("app()->bound(FreeGrantIdentity::class)", arm.group(1))
        self.assertNotIn("admitsTestCommerce", arm.group(1), "the operative arm gained an environment gate: update the profile comment")

    def test_operator_inquiry_notifications_ship_one_mail_transport_bound_only_behind_the_flag_and_refused_in_production(self):
        self.assert_source("app/Domain/Inquiries/Notifications/InquiryNotificationWork.php",
                           r"config\('inquiries\.operator_notifications_enabled'\) === true\s*&& app\(\)->bound\(InquiryAlertTransport::class\)")
        # FP-013 ships exactly one transport (mail) and exactly one binding of it. Another implementation or binding, or a
        # changed admission rule below, sends the flag and the template comments back for review. A binding is any container
        # registration call or array write naming the interface, by short name, fully qualified name or string.
        transport = "app/Domain/Inquiries/Notifications/MailInquiryAlertTransport.php"
        name = r"""(?:(?:\\?App\\Domain\\Inquiries\\Notifications\\)?InquiryAlertTransport::class|['"]\\?App\\{1,2}Domain\\{1,2}Inquiries\\{1,2}Notifications\\{1,2}InquiryAlertTransport['"])"""
        binding = re.compile(r"(?:\b(?:bind|bindIf|singleton|singletonIf|scoped|scopedIf|instance|extend|alias|swap)\(\s*" + name + r"|\[\s*" + name + r"\s*\]\s*=[^=])")
        implementing = re.compile(r"implements\s+[^{]*\bInquiryAlertTransport\b")
        for sample in ("$this->app->bind(InquiryAlertTransport::class, X::class)", "$app->bindIf(InquiryAlertTransport::class, X::class)",
                       "$app->singletonIf( \\App\\Domain\\Inquiries\\Notifications\\InquiryAlertTransport::class, X::class)",
                       "$app->scoped('App\\\\Domain\\\\Inquiries\\\\Notifications\\\\InquiryAlertTransport', X::class)",
                       "$app->instance(\"App\\Domain\\Inquiries\\Notifications\\InquiryAlertTransport\", $x)",
                       "$app[InquiryAlertTransport::class] = fn () => new X;",
                       "$this->app[ \\App\\Domain\\Inquiries\\Notifications\\InquiryAlertTransport::class ] = $x;"):
            self.assertRegex(sample, binding)
        for sample in ("app()->bound(InquiryAlertTransport::class)", "app(InquiryAlertTransport::class)",
                       "$app[InquiryAlertTransport::class] === null"):
            self.assertNotRegex(sample, binding)
        self.assertRegex("final class X implements Other, \\App\\Domain\\Inquiries\\Notifications\\InquiryAlertTransport\n{", implementing)
        implementers, binders = [], []
        for path in sorted((REPO / "app").rglob("*.php")) + sorted((REPO / "routes").rglob("*.php")) + sorted((REPO / "bootstrap").glob("*.php")):
            text = path.read_text()
            relative = path.relative_to(REPO).as_posix()
            if implementing.search(text):
                implementers.append(relative)
            if binding.search(text):
                binders.append(relative)
            self.assertNotRegex(text, r"(?:bind|bindIf|singleton|singletonIf|scoped|scopedIf|instance)\(\s*\\?(?:[A-Za-z\\]+\\)?FreeGrantIdentity::class",
                                f"{path}: a binding now exists")
        self.assertEqual(implementers, [transport], "another alert transport ships; re-review the flag")
        self.assertEqual(binders, [transport], "the alert transport is bound somewhere else; re-review the flag")
        self.assertEqual(len(re.findall(r"InquiryAlertTransport::class", source(transport))), 1)
        # Bound only on the literal flag, in an admitted environment, with a configured operator; no other caller binds it.
        self.assert_source(transport, r"function bindWhenConfigured\(Application \$app\): bool\s*\{.*?"
                                      r"if \(\$config->get\('inquiries\.operator_notifications_enabled'\) !== true \|\| ! self::admitted\(\)\s*"
                                      r"\|\| ! is_scalar\(\$operator\) \|\| preg_match\('/\\A\[1-9\]\[0-9\]\{0,15\}\\z/D', \(string\) \$operator\) !== 1\) \{\s*"
                                      r"return false;\s*\}\s*\$app->bind\(InquiryAlertTransport::class, self::class\);")
        callers = [path.relative_to(REPO).as_posix() for path in sorted((REPO / "app").rglob("*.php")) if "bindWhenConfigured(" in path.read_text()]
        self.assertEqual(callers, [transport, "app/Providers/AppServiceProvider.php"])
        # The environment gate: local, testing and staging through the shared helper; production only with a code literal that
        # ships false and is never an environment read. Staging is therefore admitted, and the staging deploy gate keeps the
        # flag false there; in production both this gate and the pre-launch hold refuse.
        self.assert_source(transport, r"function admitted\(\): bool\s*\{\s*return TestEnvironment::admitsTestCommerce\(\)\s*"
                                      r"\|\| \(app\(\)->environment\(\) === 'production' && config\('inquiries\.operator_alert_mail_production_admitted'\) === true\);")
        self.assertRegex(source("config/inquiries.php"), r"(?m)^    'operator_alert_mail_production_admitted' => false,$")
        self.assertEqual(source("config/inquiries.php").count("'operator_alert_mail_production_admitted'"), 1)
        # It sends only when the flag and the gate still hold, under the order-ready mail policy (flag, SMTP mailer, sender and
        # preflight facts; fake-only in local and testing), to the operator account's own address, never through a global override.
        self.assert_source(transport, r"if \(config\('inquiries\.operator_notifications_enabled'\) !== true \|\| ! self::admitted\(\) \|\| ! \$receipt\) \{\s*throw")
        self.assert_source(transport, r"\$configuration = app\(TransactionalMailPolicy::class\)->configuration\(\);")
        self.assert_source(transport, r"DB::table\('users'\)->where\('id', \$operatorId\)->first\(\['email', 'email_verified_at'\]\)")
        self.assert_source(transport, r"if \(config\('mail\.to'\) !== null \|\| \$this->redirected\(\$mailer\)\) \{\s*throw")
        self.assertNotRegex(source(transport), r"mail\.from|MAIL_FROM|support@")
        # No document may still say that no alert transport ships, or that the flag is inert.
        stale = re.compile(r"ships none|none ships|no InquiryAlertTransport ships|bound alert transport, and none"
                           r"|operator notification are bound only by an\s+(?:\*\s+)?adapter that other code would supply", re.S)
        hold = "CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED"
        for relative in ("ops/staging/env.staging.example", "ops/staging/test-commerce/env.test-commerce.example",
                         "ops/production/validate-runtime.php", "ops/production/README.md", "docs/ops/production-activation-packet.md"):
            text = source(relative)
            self.assertIn(hold, text, relative)
            self.assertNotRegex(text, stale, f"{relative}: stale wording about the alert transport")
            for line in text.splitlines():
                if hold in line or "operator notification" in line:
                    self.assertNotRegex(line, r"none (?:ships|is bound)|inert", f"{relative}: stale wording about the alert transport")
        for relative in ("ops/production/validate-runtime.php", "ops/production/README.md", "docs/ops/production-activation-packet.md"):
            self.assertRegex(source(relative), re.compile(r"default-off[\s*]+mail[\s*]+(?:alert[\s*]+)?transport"), f"{relative}: must say that a mail transport ships")

    def test_order_ready_email_is_ungated_outside_local_and_testing_and_refuses_without_preflight_facts(self):
        policy = "app/Domain/Notifications/Mail/TransactionalMailPolicy.php"
        self.assert_source(policy, r"config\('transactional-mail\.enabled'\) !== true")
        self.assert_source(policy, r"app\(\)->environment\('local', 'testing'\) && ! Mail::getFacadeRoot\(\) instanceof MailFake")
        self.assert_source(policy, r"'DECLARED_FACTS_VALID'")
        self.assertNotIn("admitsTestCommerce", source(policy), "the order-ready email gained an environment gate: update the profile comment")

    def test_the_production_lanes_have_no_environment_switch(self):
        for lane in ("paid-grants", "paid-tax-grants", "production-tax-checkout", "production-customer-identity", "production-free-grants"):
            self.assertNotIn("env(", source(f"config/{lane}.php"), f"config/{lane}.php gained an environment switch: the staging profile must name it")


class NothingEnablesAProductionPath(unittest.TestCase):
    def test_the_base_template_enables_nothing(self):
        for name, entries in assignments(BASE).items():
            for commented, value in entries:
                if not commented and name.endswith("_ENABLED"):
                    self.assertEqual(value, "false", f"{BASE.name}: {name}")

    def test_the_overlay_enables_exactly_the_guest_purchase_chain(self):
        # Unpaid release is optional on staging (2026-11-11) but deliberately not enabled here: the operator turns it on per host
        # with its exact policy line. Adding it to this pinned set would make it a template default; that needs a new decision.
        enabled = sorted(name for name, entries in assignments(OVERLAY).items()
                         if name.endswith("_ENABLED") and any(not c and v == "true" for c, v in entries))
        self.assertEqual(enabled, sorted([
            "STRIPE_WEBHOOK_ENABLED", "STRIPE_TEST_CHECKOUT_ENABLED", "STRIPE_TEST_PAYMENT_PROCESSING_ENABLED",
            "STRIPE_TEST_FINALIZATION_ENABLED", "VASEY_TEST_CONTRACT_ISSUANCE_ENABLED", "VASEY_TEST_FULFILLMENT_ACTIVATION_ENABLED",
            "VASEY_TEST_DELIVERY_ACCESS_ENABLED", "VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED", "VASEY_TEST_CUSTOMER_IDENTITY_ENABLED",
            "VASEY_TEST_PURCHASE_CLAIMS_ENABLED",
        ]), "the overlay enables a flag outside the reviewed guest-purchase chain")

    def test_neither_template_sets_a_production_family_key_uncommented(self):
        for template in (BASE, OVERLAY):
            for name, entries in assignments(template).items():
                if name.startswith("PRODUCTION_"):
                    self.assertTrue(all(commented for commented, _ in entries), f"{template.name}: {name} must stay commented")

    def test_the_stripe_keys_the_overlay_sets_are_test_mode_only(self):
        overlay = assignments(OVERLAY)
        self.assertEqual(overlay["STRIPE_MODE"], [(False, "test")])
        self.assertEqual(assignments(BASE)["STRIPE_MODE"], [(False, "test")])
        self.assertRegex(overlay["STRIPE_TEST_SECRET_KEY"][0][1], r"^<sk_test_KEY>$")
        for template in (BASE, OVERLAY):
            for name, entries in assignments(template).items():
                for commented, value in entries:
                    self.assertIsNone(re.search(r"(?:sk|rk|pk)_live_", value), f"{template.name}: {name} holds a live-mode key shape")


if __name__ == "__main__":
    unittest.main()
