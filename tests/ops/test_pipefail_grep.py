"""A `grep -q` at the end of a pipeline must not decide a check in a script that sets `pipefail`.

`grep -q` exits at its first match. If the writer has more to say it then dies of SIGPIPE, `pipefail` reports the
writer's 141 instead of grep's 0, and the check fails although the text was there. That refused a real staging host
(Forge Laravel VPS, Ubuntu 24.04): `apt-cache policy php8.4-fpm | grep -q 'Candidate: [0-9]'` failed with PHP 8.4
installed and a candidate listed. `grep PATTERN >/dev/null` reads all of its input, so the writer never sees a closed
pipe. Here-strings and redirected files are not pipelines and stay allowed.

Reads the real scripts; no network or host."""
import re
import subprocess
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
PIPED_QUIET_GREP = re.compile(r"\|\s*grep\s+(?:-[A-Za-z]*q[A-Za-z]*|--quiet|--silent)\b")


def pipefail_scripts():
    for path in sorted((REPO / "ops").rglob("*.sh")):
        text = path.read_text()
        if re.search(r"^\s*set\s+-[A-Za-z]*o\s+pipefail|^\s*set\s+-o\s+pipefail", text, re.M):
            yield path, text


class PipefailGrepTest(unittest.TestCase):
    def test_no_pipefail_script_decides_a_check_with_a_piped_quiet_grep(self):
        offenders = []
        for path, text in pipefail_scripts():
            for number, line in enumerate(text.splitlines(), 1):
                if not line.lstrip().startswith("#") and PIPED_QUIET_GREP.search(line):
                    offenders.append(f"{path.relative_to(REPO)}:{number}: {line.strip()}")
        self.assertEqual([], offenders)

    def test_the_census_covers_the_kits(self):
        names = {str(path.relative_to(REPO)) for path, _ in pipefail_scripts()}
        for required in ("ops/staging/provision.sh", "ops/clamav/install-clamd.sh", "ops/monitoring/scheduler-liveness-check.sh"):
            self.assertIn(required, names)

    def test_the_failure_mode_is_real(self):
        # A writer with more output than one pipe write: grep -q closes the pipe and pipefail reports SIGPIPE.
        script = "set -o pipefail; seq 1 300000 | grep -q 1"
        self.assertNotEqual(0, subprocess.run(["bash", "-c", script]).returncode)
        fixed = "set -o pipefail; seq 1 300000 | grep 1 >/dev/null"
        self.assertEqual(0, subprocess.run(["bash", "-c", fixed]).returncode)


if __name__ == "__main__":
    unittest.main()
