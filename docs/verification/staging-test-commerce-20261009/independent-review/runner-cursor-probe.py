#!/usr/bin/env python3
"""Review-only scripted artisan model for retained failure rows across bounded sweeps."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[4]
U1 = "11111111-1111-4111-8111-111111111111"
U2 = "22222222-2222-4222-8222-222222222222"
cases = (
    ("finalize", "vasey:finalize-test-payments", "retry", "paid"),
    ("contracts", "vasey:issue-test-contracts", "changed", "ready"),
    ("activate", "vasey:activate-test-fulfillment", "asset_unavailable", "activated"),
)
failures = 0
for stage, command, retained, healthy in cases:
    with tempfile.TemporaryDirectory(prefix="va-review-cursor-") as tmp:
        base = Path(tmp)
        app = base / "app"
        app.mkdir(mode=0o700)
        (app / "artisan").touch()
        fake = base / "fake-php"
        fake.write_text("""#!/usr/bin/env bash
set -u
command="$2"
printf '%s\\n' "${*:2}" >> "$REVIEW_CALLS"
[[ "$command" == "$REVIEW_COMMAND" ]] || exit 0
after=''
for arg in "$@"; do case "$arg" in --after=*) after="${arg#--after=}" ;; esac; done
if [[ -z "$after" ]]; then
  printf '%s %s\\nNEXT_AFTER=%s\\n' "$REVIEW_U1" "$REVIEW_RETAINED" "$REVIEW_U1"
elif [[ "$after" == "$REVIEW_U1" ]]; then
  printf '%s %s\\nNEXT_AFTER=%s\\n' "$REVIEW_U2" "$REVIEW_HEALTHY" "$REVIEW_U2"
fi
""")
        fake.chmod(0o700)
        calls = base / "calls.log"
        env = {
            "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "APP_ROOT": str(app), "PHP_BIN": str(fake),
            "STATE_DIR": str(base / "state"), "PAGE_LIMIT": "1",
            "CONTRACT_PAGE_LIMIT": "1", "MAX_PAGES": "1",
            "COMMAND_TIMEOUT_SECONDS": "10", "RECONCILE_INTERVAL_SECONDS": "0",
            "REVIEW_CALLS": str(calls), "REVIEW_COMMAND": command,
            "REVIEW_U1": U1, "REVIEW_U2": U2,
            "REVIEW_RETAINED": retained, "REVIEW_HEALTHY": healthy,
        }
        for sweep in (1, 2):
            result = subprocess.run(["bash", str(ROOT / "scripts/ops/run-test-commerce-pipeline.sh")],
                                    cwd=ROOT, env=env, capture_output=True, text=True, timeout=30)
            print(f"{stage} sweep {sweep}: exit={result.returncode}")
            for line in result.stdout.splitlines():
                if f"pipeline {stage} " in line:
                    print(line)
        selected = [line for line in calls.read_text().splitlines() if line.startswith(command + " ")]
        print(f"{stage} calls: {selected}")
        resumed = len(selected) == 2 and f"--after={U1}" in selected[1]
        print(f"{'PASS' if resumed else 'FAIL'} {stage}: next bounded sweep must advance past the retained first row")
        failures += not resumed
print(f"RESULT: {failures} of {len(cases)} stage cursor checks failed")
raise SystemExit(1 if failures else 0)
