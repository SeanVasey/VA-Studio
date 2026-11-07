"""forge-deploy.sh must refuse when the root-installed kit helpers differ from the kit it runs from.

ctl, the backup helper and the fixed release steps run from copies that only provision.sh installs. On the staging host a
deploy of a SHA that fixed release-step.sh still ran the old installed copy and failed the same way twice. The check runs
the real block from forge-deploy.sh against fixture directories; paths under /usr/local and /etc are redirected."""
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
DEPLOY = (REPO / "ops/staging/forge-deploy.sh").read_text()
START = "# ---------------------------------------------------------------- 0. installed helpers match this kit"
END = "# ---------------------------------------------------------------- 0. runtime .env checks"
SOURCES = ["bin/vasey-staging-ctl", "backup.sh", "release-step.sh", "seal-release.py", "normalize-mysql-dump.py",
           "nginx/vasey-staging-http.conf"]


class HelperDriftTest(unittest.TestCase):
    def run_block(self, root: Path, kit: Path, mirror: Path):
        block = DEPLOY[DEPLOY.index(START):DEPLOY.index(END)]
        block = block.replace("/usr/local/", f"{root}/installed/usr/local/").replace("/etc/nginx/", f"{root}/installed/etc/nginx/")
        block = block.replace('"${BASH_SOURCE[0]}"', f'"{kit}/forge-deploy.sh"')
        script = ("set -euo pipefail\n"
                  "die() { echo \"DIE: $*\"; exit 1; }\nstep() { echo \"STEP: $*\"; }\n"
                  f"VASEY_MIRROR={mirror}\nSHA={'a' * 40}\n" + block + "echo DONE\n")
        return subprocess.run(["bash", "-c", script], capture_output=True, text=True)

    def fixture(self, root: Path):
        mirror = root / "mirror"
        kit = mirror / "ops/staging"
        for source in SOURCES:
            (kit / source).parent.mkdir(parents=True, exist_ok=True)
            (kit / source).write_text(f"# {source} v2\n")
        (kit / "forge-deploy.sh").write_text("#\n")
        installed = {}
        for line in re.findall(r"^(?:required|optional) (\S+) (\S+)$", DEPLOY, re.M):
            target = root / "installed" / line[1].lstrip("/")
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text((kit / line[0]).read_text())
            installed[line[0]] = target
        return mirror, kit, installed

    def test_matching_helpers_pass_and_every_drift_refuses(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            mirror, kit, installed = self.fixture(root)
            self.assertEqual(sorted(SOURCES), sorted(installed))
            run = self.run_block(root, kit, mirror)
            self.assertIn("installed kit helpers match", run.stdout)
            self.assertIn("DONE", run.stdout)
            for source, target in installed.items():
                original = target.read_text()
                target.write_text("# stale\n")
                run = self.run_block(root, kit, mirror)
                self.assertIn("DIE: installed kit helpers differ", run.stdout, source)
                self.assertIn(str(target), run.stdout)
                self.assertNotIn("DONE", run.stdout)
                target.write_text(original)

    def test_a_missing_required_helper_refuses_and_a_kit_without_the_nginx_split_is_allowed(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            mirror, kit, installed = self.fixture(root)
            installed["release-step.sh"].unlink()
            self.assertIn("DIE:", self.run_block(root, kit, mirror).stdout)
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            mirror, kit, installed = self.fixture(root)
            (kit / "nginx/vasey-staging-http.conf").unlink()
            installed["nginx/vasey-staging-http.conf"].unlink()
            self.assertIn("DONE", self.run_block(root, kit, mirror).stdout)

    def test_a_deploy_script_installed_outside_the_checkout_skips_the_comparison(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            mirror, kit, installed = self.fixture(root)
            installed["release-step.sh"].write_text("# stale\n")
            elsewhere = root / "libexec"
            elsewhere.mkdir()
            run = self.run_block(root, elsewhere, mirror)
            self.assertIn("DONE", run.stdout)
            self.assertNotIn("DIE:", run.stdout)


if __name__ == "__main__":
    unittest.main()
