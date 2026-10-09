#!/usr/bin/env python3
"""Reviewer probe: run the real preflight against near-miss staging files (independent of the branch self-test)."""
import importlib.util, json, os, subprocess, sys, tempfile
from pathlib import Path
ROOT = Path(sys.argv[1]).resolve()
spec = importlib.util.spec_from_file_location("st", ROOT / "scripts/ops/test-private-server-preflight.py")
st = importlib.util.module_from_spec(spec); spec.loader.exec_module(st)
SCRIPT = ROOT / "scripts/ops/private-server-preflight.php"

def run(source, *options):
    with tempfile.TemporaryDirectory(prefix="rv-preflight-") as d:
        p = Path(d) / "cfg"; p.write_text(source); os.chmod(p, 0o600)
        r = subprocess.run(["php", str(SCRIPT), "--env-file", str(p), *options], capture_output=True, text=True)
        try:
            out = json.loads(r.stdout)
        except Exception:
            return {"rc": r.returncode, "raw": r.stdout[:300]}
        return {"rc": r.returncode, "result": out["result"], "profile": out.get("profile"),
                "blocked": sorted(c["id"] for c in out["checks"] if c["status"] == "blocked")}

S = st.staging()
cases = {
    "staging valid": (S, "--profile=staging"),
    "staging APP_ENV=Staging": (st.replace(S, "APP_ENV", "Staging"), "--profile=staging"),
    "staging APP_ENV=local": (st.replace(S, "APP_ENV", "local"), "--profile=staging"),
    "staging APP_ENV=testing": (st.replace(S, "APP_ENV", "testing"), "--profile=staging"),
    "staging STRIPE_MODE=live": (st.replace(S, "STRIPE_MODE", "live"), "--profile=staging"),
    "staging sk_live_ test key": (st.replace(S, "STRIPE_TEST_SECRET_KEY", "sk_" + "live_SYNTHETICONLY1234"), "--profile=staging"),
    "staging rk_live_ in unrelated var": (st.replace(S, "MAIL_PASSWORD", "rk_" + "live_SYNTHETICONLY1234"), "--profile=staging"),
    "staging rk_test_ as test key": (st.replace(S, "STRIPE_TEST_SECRET_KEY", "rk_" + "test_SYNTHETICONLY1234"), "--profile=staging"),
    "staging funds mode live": (st.replace(S, "PRODUCTION_CHECKOUT_FUNDS_MODE", "live"), "--profile=staging"),
    "staging funds mode test": (st.replace(S, "PRODUCTION_CHECKOUT_FUNDS_MODE", "test"), "--profile=staging"),
    "staging switch yes": (st.replace(S, "STRIPE_TEST_CHECKOUT_ENABLED", "yes"), "--profile=staging"),
    "staging debug true": (st.replace(S, "APP_DEBUG", "true"), "--profile=staging"),
    "staging file under production profile": (S,),
    "production valid (default)": (st.configured(),),
    "production valid explicit": (st.configured(), "--profile=production"),
    "production with test switch on": (st.replace(st.configured(), "STRIPE_TEST_CHECKOUT_ENABLED", "true"),),
    "duplicate profile flags": (S, "--profile=staging", "--profile=production"),
    "repeated runtime flag": (S, "--runtime", "--runtime"),
    "unknown profile": (S, "--profile=stage"),
}
res = {k: run(*v) for k, v in cases.items()}
print(json.dumps(res, indent=1))
expect_pass = {"staging valid", "production valid (default)", "production valid explicit"}
bad = [k for k, v in res.items() if (v.get("result") == "FILE_CHECKS_PASSED") != (k in expect_pass)]
print("UNEXPECTED:", bad)
sys.exit(1 if bad else 0)
