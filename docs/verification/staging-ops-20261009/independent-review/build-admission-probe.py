"""Independent bounded build/ancestry probes; no root, service, mount or provider actions."""
import argparse
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


REPO = Path(__file__).resolve().parents[4]
OPTIONS = argparse.ArgumentParser()
OPTIONS.add_argument("--source-sha")
ARGUMENTS, TEST_ARGUMENTS = OPTIONS.parse_known_args()
SOURCE_SHA = ARGUMENTS.source_sha or subprocess.check_output(
    ["git", "-C", str(REPO), "rev-parse", "HEAD"], text=True
).strip()


def source(path):
    return subprocess.check_output(
        ["git", "-C", str(REPO), "show", f"{SOURCE_SHA}:{path}"], text=True
    )


class BuildAdmissionProbe(unittest.TestCase):
    def deploy(self, current=True, refuse_admission=False, fail_build=False, actor=False):
        script = source("ops/staging/forge-deploy.sh")
        body = script[script.index('if [ -e "$REL" ]; then'):script.index('# ---------------------------------------------------------------- 7.')]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            mirror = root / "mirror"
            mirror.mkdir()
            for name, content in {
                ".gitignore": "/vendor/\n/public/build/\n.env\n",
                "composer.lock": "{}\n",
                "package-lock.json": "{}\n",
            }.items():
                (mirror / name).write_text(content)
            subprocess.run(["git", "init", "-q", str(mirror)], check=True)
            subprocess.run(["git", "-C", str(mirror), "add", "."], check=True)
            subprocess.run(
                ["git", "-C", str(mirror), "-c", "user.name=Independent reviewer",
                 "-c", "user.email=reviewer@example.invalid", "commit", "-qm", "synthetic build"],
                check=True,
            )
            sha = subprocess.check_output(["git", "-C", str(mirror), "rev-parse", "HEAD"], text=True).strip()
            release = root / "releases" / sha
            previous = root / "releases" / ("b" * 40)
            (root / "releases").mkdir()
            (root / "private").mkdir()
            (root / "evidence").mkdir()
            candidate = root / "candidate.env"
            candidate.write_text("APP_DEBUG=false\n")
            if current:
                previous.mkdir()
                (previous / ".env").write_text(candidate.read_text())
                (root / "current").symlink_to(previous)
            live = root / "controlled-live"
            live.touch()
            gate = root / "modeled-writer-admission"
            gate.write_text("open\n")
            injection = root / "injection-receipt"
            child = None
            if actor:
                child = subprocess.Popen([
                    "python3", "-c", """import pathlib,sys,time
release,live,receipt=map(pathlib.Path,sys.argv[1:])
while True:
    target=release/'vendor/autoload.php'
    if live.exists() and target.exists():
        target.write_text('unapproved ignored application code')
        receipt.touch()
        break
    time.sleep(0.002)
""", str(release), str(live), str(injection),
                ])
            shell = """step() { :; }
die() { echo "$*" >&2; exit 1; }
runtime_gate() {
  echo "admit:$(basename -- "$1")" >> "$TRACE"
  if [ "$REFUSE_ADMISSION" = 1 ]; then die 'synthetic profile refusal'; fi
}
ctl_fixture() {
  echo "$1" >> "$TRACE"
  case "$1" in
    quiesce)
      printf 'closed\\n' > "$GATE"
      rm -f -- "$LIVE"
      if [ "$ACTOR_PID" != 0 ]; then kill "$ACTOR_PID" 2>/dev/null || true; fi ;;
    allocate) mkdir -p "$REL" ;;
    attach) mkdir -p "$REL/storage/app/private" ;;
    resume) printf 'open\\n' > "$GATE" ;;
  esac
}
CTL=(ctl_fixture)
php_fixture() {
  if [ "$FAIL_BUILD" = 1 ]; then return 61; fi
  mkdir -p "$REL/vendor/composer"
  printf 'approved ignored artifact' > "$REL/vendor/autoload.php"
  printf '{}' > "$REL/vendor/composer/installed.json"
  sleep 0.2
}
npm() { mkdir -p "$REL/public/build"; printf '{}' > "$REL/public/build/manifest.json"; }
stat() { if [ "$2" = %d:%i ]; then echo 1:1; else command stat "$@"; fi; }
"""
            environment = {
                "PATH": "/usr/bin:/bin", "ROOT": str(root), "REL": str(release),
                "RELEASES": str(root / "releases"), "CURRENT": str(root / "current"),
                "RUNTIME_ENV": str(candidate), "SHA": sha, "VASEY_MIRROR": str(mirror),
                "STAMP": "synthetic", "EVIDENCE": str(root / f"evidence/synthetic-{sha[:12]}"),
                "PHP": "php_fixture", "COMPOSER_BIN": "synthetic-composer",
                "LIVE": str(live), "GATE": str(gate), "ACTOR_PID": str(child.pid if child else 0),
                "TRACE": str(root / "trace"), "REFUSE_ADMISSION": str(int(refuse_admission)),
                "FAIL_BUILD": str(int(fail_build)),
            }
            try:
                result = subprocess.run(["bash", "-eu", "-c", shell + body], env=environment, capture_output=True, text=True)
                return {
                    "code": result.returncode, "error": result.stderr,
                    "trace": (root / "trace").read_text().splitlines() if (root / "trace").exists() else [],
                    "injected": injection.exists(), "allocated": release.exists(), "gate": gate.read_text(),
                    "vendor": (release / "vendor/autoload.php").read_text() if (release / "vendor/autoload.php").exists() else None,
                }
            finally:
                if child:
                    if child.poll() is None:
                        child.terminate()
                    child.wait(timeout=2)

    def test_controlled_live_actor_cannot_modify_ignored_vendor(self):
        result = self.deploy(actor=True)
        self.assertEqual(result["code"], 0, result["error"])
        self.assertFalse(result["injected"], "actual ignored vendor was injected while Git accepted the candidate")
        self.assertEqual(result["vendor"], "approved ignored artifact")
        self.assertEqual(result["trace"][0], "admit:" + "b" * 40)
        self.assertLess(result["trace"].index("quiesce"), result["trace"].index("allocate"))
        self.assertEqual(result["gate"], "closed\n")

    def test_current_admission_refusal_precedes_quiesce_and_allocation(self):
        result = self.deploy(refuse_admission=True)
        self.assertNotEqual(result["code"], 0)
        self.assertEqual(result["trace"], ["admit:" + "b" * 40])
        self.assertFalse(result["allocated"])
        self.assertEqual(result["gate"], "open\n")

    def test_build_failure_keeps_durable_admission_closed(self):
        result = self.deploy(fail_build=True)
        self.assertEqual(result["code"], 61, result["error"])
        self.assertIn("quiesce", result["trace"])
        self.assertNotIn("resume", result["trace"])
        self.assertEqual(result["gate"], "closed\n")

    def test_first_install_quiesces_before_allocation(self):
        result = self.deploy(current=False)
        self.assertEqual(result["code"], 0, result["error"])
        self.assertEqual(result["trace"][0], "quiesce")
        self.assertLess(result["trace"].index("quiesce"), result["trace"].index("allocate"))
        self.assertNotIn("snapshot", result["trace"])

    def test_provision_refuses_ownership_modes_links_and_noncanonical_roots(self):
        script = source("ops/staging/provision.sh")
        guard = script[script.index("root_ancestry()"):script.index('\n[[ "$HOST"')] if "root_ancestry()" in script else ""
        invocation = script[script.index('[[ "$ROOT"'):script.index('[[ "$DB_NAME"')]
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            parent = base / "protected"
            parent.mkdir()
            linked = base / "linked"
            linked.symlink_to(parent)
            dangling = base / "dangling"
            dangling.symlink_to(base / "absent")
            shell = """die() { echo "$*" >&2; exit 1; }
stat() {
  if [ "$2" = %u ]; then
    if [ "$3" = "$BAD_OWNER" ]; then echo 1000; else echo 0; fi
  elif [ "$2" = %a ] && [ "$3" = /tmp ]; then echo 755;
  else command stat "$@"; fi
}
"""

            def probe(root_path, bad_owner=""):
                return subprocess.run(
                    ["bash", "-eu", "-c", shell + guard + invocation],
                    env={"PATH": "/usr/bin:/bin", "ROOT": root_path, "BAD_OWNER": bad_owner},
                    capture_output=True, text=True,
                )

            accepted = probe(str(parent / "absent" / "descendant"))
            self.assertEqual(accepted.returncode, 0, accepted.stderr)
            self.assertEqual(probe(str(parent)).returncode, 0)
            cases = {
                "app owned ancestor": (str(parent / "absent"), str(parent)),
                "app owned existing root": (str(parent), str(parent)),
                "symlink ancestor": (str(linked / "absent"), ""),
                "dangling ancestor": (str(dangling / "absent"), ""),
                "double slash": (str(parent) + "//absent", ""),
                "trailing slash": (str(parent / "absent") + "/", ""),
                "dot under absent directory": (str(parent / "absent") + "/./descendant", ""),
            }
            for name, (root_path, bad_owner) in cases.items():
                with self.subTest(name=name):
                    self.assertNotEqual(probe(root_path, bad_owner).returncode, 0, name + " was admitted")
            for mode in (0o775, 0o757, 0o777):
                with self.subTest(mode=oct(mode)):
                    parent.chmod(mode)
                    self.assertNotEqual(probe(str(parent / "absent")).returncode, 0)
            parent.chmod(0o755)

    def test_root_guard_precedes_host_mutations(self):
        script = source("ops/staging/provision.sh")
        admission = script.index('root_ancestry "$ROOT"')
        for mutation in ("apt-get update", "install -d -m 0755 -o root -g root", "systemctl enable --now"):
            with self.subTest(mutation=mutation):
                self.assertLess(admission, script.index(mutation))


if __name__ == "__main__":
    print(f"Exact assessed source: {SOURCE_SHA}", flush=True)
    unittest.main(argv=[__file__] + TEST_ARGUMENTS, verbosity=2)
