"""Drift guards between the application code and the staging kit (ops/staging, and so the derived production kit).

The kit was written against one state of the application. Three of its assumptions go stale silently when the code
moves, and each one fails late and expensively on a real host rather than in CI:

* forge-deploy.sh scrubs inherited environment variables by family before it runs any artisan command, so a config
  key outside those families would leak Forge's deployment-shell value into a build or migration;
* provision.sh refuses a PHP older than the one composer.json requires, instead of letting `composer install` fail in
  the middle of a deploy;
* the supervisor workers must listen on every queue the code dispatches to, or those jobs wait forever.

These read the real files (no copies of the facts) and use no network, database or host."""
import fnmatch
import importlib.util
import json
import re
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
STAGING = REPO / "ops/staging"
FORGE_DEPLOY = (STAGING / "forge-deploy.sh").read_text()
PROVISION = (STAGING / "provision.sh").read_text()
WORKERS = (STAGING / "workers/vasey-staging-workers.conf").read_text()

ENV_CALL = re.compile(r"""env\(\s*['"]([A-Za-z0-9_]+)['"]""")
SCRUB_ARM = re.compile(r'^\s*([A-Za-z0-9_*]+(?:\|[A-Za-z0-9_*]+)*)\) unset "\$name" ;;$', re.M)


def config_env_keys():
    keys = set()
    for path in sorted((REPO / "config").glob("*.php")):
        keys.update(ENV_CALL.findall(path.read_text()))
    return keys


def scrub_patterns(script=FORGE_DEPLOY):
    # Only the arms of the scrub loop: its own `case "$name" in` block.
    start = script.index('while IFS= read -r name; do')
    end = script.index('done < <(compgen -e)', start)
    patterns = []
    for arm in SCRUB_ARM.findall(script[start:end]):
        patterns.extend(arm.split("|"))
    return patterns


def built_production_forge_deploy():
    """forge-deploy.sh as the production kit ships it: built by ops/production/build-kit.py into a scratch directory."""
    spec = importlib.util.spec_from_file_location("build_kit_for_drift", REPO / "ops/production/build-kit.py")
    build_kit = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(build_kit)
    with tempfile.TemporaryDirectory(prefix="va-drift-kit-") as scratch:
        build_kit.build(Path(scratch) / "kit")
        return (Path(scratch) / "kit/forge-deploy.sh").read_text()


def uncovered_keys(patterns):
    return sorted(k for k in config_env_keys() if not any(fnmatch.fnmatchcase(k, p) for p in patterns))


def code_queues():
    names = {"default"}  # a job with no onQueue() runs on the connection's default queue
    for path in sorted((REPO / "app").rglob("*.php")):
        names.update(re.findall(r"""->onQueue\(\s*['"]([a-z0-9-]+)['"]\s*\)""", path.read_text()))
    media = (REPO / "config/media.php").read_text()
    names.update(re.findall(r"""^\s*'queue'\s*=>\s*'([a-z0-9-]+)'""", media, re.M))
    return names


def worker_queues():
    names = set()
    for arg in re.findall(r"artisan queue:work \S+ --queue=([A-Za-z0-9,_-]+)", WORKERS):
        names.update(arg.split(","))
    return names


class EnvironmentScrubCoversConfig(unittest.TestCase):
    def test_the_scrub_patterns_were_found(self):
        patterns = scrub_patterns()
        self.assertIn("APP_*", patterns)
        self.assertIn("STRIPE_*", patterns)
        self.assertGreaterEqual(len(patterns), 25, patterns)

    def test_every_config_env_key_is_scrubbed_before_artisan_runs(self):
        keys = config_env_keys()
        self.assertGreater(len(keys), 150, "the config scan found suspiciously few env() keys")
        patterns = scrub_patterns()
        uncovered = sorted(k for k in keys if not any(fnmatch.fnmatchcase(k, p) for p in patterns))
        self.assertEqual(
            uncovered, [],
            "config/*.php reads these environment keys but forge-deploy.sh's scrub loop does not unset them, so a value "
            "inherited from Forge's deployment shell would override the candidate .env: add their family to the case arm",
        )

    def test_the_built_production_deploy_scrubs_every_config_env_key_too(self):
        # The kit builder renames VASEY_STAGING_ to VASEY_PRODUCTION_ throughout forge-deploy.sh, so a scrub pattern that names a
        # staging-prefixed key (VASEY_STAGING_SYNTHETIC_*, say) passes the check above yet scrubs the wrong name in production.
        patterns = scrub_patterns(built_production_forge_deploy())
        self.assertIn("APP_*", patterns)
        self.assertGreaterEqual(len(patterns), 25, patterns)
        self.assertEqual(
            uncovered_keys(patterns), [],
            "the production kit's forge-deploy.sh (after build-kit.py's renames) does not unset these config env keys: use a "
            "scrub pattern that survives the VASEY_STAGING_ -> VASEY_PRODUCTION_ rename (for example VASEY_*_SYNTHETIC_*)",
        )

    def test_the_scrub_runs_before_the_first_deploy_step(self):
        self.assertLess(FORGE_DEPLOY.index("done < <(compgen -e)"), FORGE_DEPLOY.index('step "prepare under one root-held build lease'))


class PhpFloorMatchesComposer(unittest.TestCase):
    def test_provision_refuses_php_older_than_composer_requires(self):
        composer = json.loads((REPO / "composer.json").read_text())
        constraint = composer["require"]["php"]
        match = re.fullmatch(r"\^(\d+\.\d+\.\d+)", constraint)
        self.assertIsNotNone(match, f"composer.json php constraint {constraint!r} is no longer a simple caret floor: update the kit's check")
        floor = match.group(1)
        self.assertIn(f'version_compare(PHP_VERSION, "{floor}", ">=")', PROVISION)
        self.assertIn(f"older than {floor}", PROVISION)

    def test_the_check_runs_before_any_install_or_deploy_step(self):
        check = PROVISION.index("version_compare(PHP_VERSION")
        self.assertLess(check, PROVISION.index("for ext in pdo pdo_mysql"))
        self.assertLess(check, PROVISION.index("mysql_q "))


class WorkersCoverEveryQueue(unittest.TestCase):
    def test_the_queue_scan_found_the_known_queues(self):
        self.assertTrue({"default", "media", "payments", "contracts", "inquiry-alerts"} <= code_queues(), code_queues())

    def test_every_queue_the_code_dispatches_to_has_a_worker(self):
        missing = sorted(code_queues() - worker_queues())
        self.assertEqual(missing, [], "jobs dispatched to these queues would never run: add them to the --queue= list of a worker program")

    def test_no_worker_listens_on_a_queue_nothing_dispatches_to(self):
        stale = sorted(worker_queues() - code_queues())
        self.assertEqual(stale, [], "a worker listens on a queue the code no longer uses: remove it, or extend the scan if it is dispatched dynamically")

    def test_the_enforced_retry_after_floor_exceeds_the_longest_worker_timeout(self):
        # forge-deploy.sh refuses a .env whose DB_QUEUE_RETRY_AFTER is below its floor; a worker that may run longer than
        # that would have its job handed to a second worker while it is still running.
        longest = max(int(t) for t in re.findall(r"--timeout=(\d+)", WORKERS))
        floor = int(re.search(r'envget DB_QUEUE_RETRY_AFTER\)" -ge (\d+)', FORGE_DEPLOY).group(1))
        self.assertGreater(floor, longest)
        default = int(re.search(r"'retry_after' => \(int\) env\('DB_QUEUE_RETRY_AFTER', (\d+)\)", (REPO / "config/queue.php").read_text()).group(1))
        self.assertEqual(floor, default, "the deploy's floor and the application's default for DB_QUEUE_RETRY_AFTER diverged")


if __name__ == "__main__":
    unittest.main()
