#!/usr/bin/env python3
"""Execute the actual ctl dispatcher with owned fixtures and harmless authority substitutes.

Root identity/ownership is simulated only for the disposable fixture ancestry. Mount,
unmount and rm are stand-ins: no privileged operation or recursive removal occurs.
Both competing processes run the same candidate helper, so a genuine helper flock
must serialize them. /etc paths used by this helper are redirected to owned fixtures.
"""
import os
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

REPO = Path(__file__).resolve().parents[4]
SOURCE = REPO / "ops/staging/bin/vasey-staging-ctl"

BASH_ENV = r'''
id() { if [ "$*" = -u ]; then echo 0; else command id "$@"; fi; }
stat() {
  if [ "${1:-}" = -c ]; then
    local format=${2:-} path=${!#}
    case "$format" in
      %u)
        case "$path" in "$REVIEW_BASE"*|/tmp|/) echo 0; return ;; esac ;;
      %a) [ "$path" != /tmp ] || { echo 755; return; } ;;
      '%u %a')
        case "$path" in "$REVIEW_BASE"/etc/vasey-staging/*.lock)
          printf '0 %s\n' "$(command stat -c %a "$path")"; return ;;
        esac ;;
      %d:%i)
        if [ "$path" = "$REVIEW_TARGET" ]; then
          if [ "${REVIEW_PROCESS:-}" = prune ] && [ ! -f "$REVIEW_BASE/read-observed" ]; then
            # Freeze the observed identity; pause immediately after the final detached read.
            : > "$REVIEW_BASE/read-observed"
            while [ ! -f "$REVIEW_BASE/continue" ]; do sleep 0.01; done
            echo 99:11; return
          fi
          if [ -f "$REVIEW_BASE/attached" ]; then echo 99:22; else echo 99:11; fi
          return
        fi
        [ "$path" != "$REVIEW_BASE/root/private" ] || { echo 99:22; return; } ;;
    esac
  fi
  command stat "$@"
}
chown() { :; }
mountpoint() { [ -f "$REVIEW_BASE/attached" ]; }
mount() { : > "$REVIEW_BASE/attached"; printf 'attach\n' >> "$REVIEW_BASE/trace"; }
umount() { command rm -f "$REVIEW_BASE/attached"; printf 'detach\n' >> "$REVIEW_BASE/trace"; }
rm() {
  if [ "${1:-}" = -rf ]; then
    printf 'rm-while-mounted=%s\n' "$([ -f "$REVIEW_BASE/attached" ] && echo yes || echo no)" >> "$REVIEW_BASE/trace"
    return 0
  fi
  command rm "$@"
}
'''


def wait_for(path, process, timeout=5):
    end = time.monotonic() + timeout
    while not path.exists():
        if process.poll() is not None:
            out, err = process.communicate()
            raise AssertionError("prune exited before reaching final read: " + out + err)
        if time.monotonic() >= end:
            raise AssertionError("prune did not reach final read")
        time.sleep(0.01)


class ControlConcurrencyProbe(unittest.TestCase):
    def test_concurrent_attach_cannot_rebind_between_prune_proof_and_removal(self):
        with tempfile.TemporaryDirectory(prefix="va-ctl-review-") as directory:
            base = Path(directory)
            root = base / "root"
            sha = "a" * 40
            target = root / "releases" / sha / "storage/app/private"
            target.mkdir(parents=True)
            (root / "private").mkdir(mode=0o700)
            configuration = base / "etc/vasey-staging"
            configuration.mkdir(parents=True)
            (configuration / "control.lock").touch(mode=0o600)
            (configuration / "ctl.lock").touch(mode=0o600)
            user = subprocess.check_output(["id", "-un"], text=True).strip()
            group = subprocess.check_output(["id", "-gn"], text=True).strip()
            (configuration / "staging.conf").write_text("\n".join([
                "VASEY_STAGING_HOST=staging.synthetic.invalid", "VASEY_APP_USER=" + user,
                "VASEY_APP_GROUP=" + group, "VASEY_ROOT=" + str(root), "VASEY_PHP=/usr/bin/false",
                "VASEY_PHP_FPM_SERVICE=synthetic-review-only", "VASEY_PROBE_NETRC=" + str(base / "unused.netrc"),
                "VASEY_KEEP_RELEASES=1", "",
            ]))
            fake_env = base / "authority.sh"
            fake_env.write_text(BASH_ENV)
            helper = base / "ctl"
            helper.write_text(SOURCE.read_text().replace("/etc/vasey-staging", str(configuration)).replace("/etc/fstab", str(base / "fstab")))
            (base / "fstab").touch()
            environment = {"PATH": "/usr/bin:/bin", "BASH_ENV": str(fake_env),
                           "REVIEW_BASE": str(base), "REVIEW_TARGET": str(target)}
            prune = subprocess.Popen(["bash", str(helper), "prune"], env=environment | {"REVIEW_PROCESS": "prune"},
                                     text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            attach = None
            try:
                wait_for(base / "read-observed", prune)
                attach = subprocess.Popen(["bash", str(helper), "attach", sha], env=environment | {"REVIEW_PROCESS": "attach"},
                                          text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                # On the broken candidate attach completes while prune is paused. With a genuine
                # action lock it stays blocked, then completes only after prune releases the lock.
                try:
                    attach_output, attach_errors = attach.communicate(timeout=0.5)
                    self.assertEqual(attach.returncode, 0, attach_output + attach_errors)
                    print("Independent competing attach completed while prune held its final read.")
                except subprocess.TimeoutExpired:
                    self.assertFalse((base / "attached").exists())
                    print("Independent competing attach waited while prune held its final read.")
                (base / "continue").touch()
                prune_output, prune_errors = prune.communicate(timeout=5)
                self.assertEqual(prune.returncode, 0, prune_output + prune_errors)
                attach_output, attach_errors = attach.communicate(timeout=5)
                self.assertEqual(attach.returncode, 0, attach_output + attach_errors)
                trace = (base / "trace").read_text()
                print(trace, end="")
                self.assertIn("rm-while-mounted=no", trace,
                              "the root helper dispatches recursive removal after a concurrent permitted attach rebinds PRIVATE")
                self.assertNotIn("rm-while-mounted=yes", trace)
            finally:
                (base / "continue").touch(exist_ok=True)
                for process in (prune, attach):
                    if process is not None and process.poll() is None:
                        process.kill()
                        process.communicate()


if __name__ == "__main__":
    unittest.main(verbosity=2)
