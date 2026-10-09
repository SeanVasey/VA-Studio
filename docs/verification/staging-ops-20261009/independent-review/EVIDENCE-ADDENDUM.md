# Independent evidence addendum: PR #63

**The APPROVE decision carries from runtime source `2c91f8e620cd870a2e213b4af790a1dbfab8de9a` to evidence candidate `a4cae6d78c60b18717db97bfcbbef78b1c0dd0ce`. The requested disposable-driver guard condition is closed.**

The candidate adds only the verification directory. This command exited **0** with no difference:

```sh
git diff --exit-code 2c91f8e620cd870a2e213b4af790a1dbfab8de9a..a4cae6d78c60b18717db97bfcbbef78b1c0dd0ce -- app tests scripts ops .github
```

The native dump driver now refuses a non-testing environment, non-disposable schema, non-loopback host or non-root helper account before constructing PDO. Before any DDL/load, it additionally checks genuine MySQL 8.4, the helper's unique temporary datadir, empty socket and loopback bind. The independent reviewer ran four isolated genuine PHP subprocesses, changing each initial guard setting in turn: all refused with `Disposable test-server environment required.`, and none reached PDO construction or produced a SQLSTATE/PDO error. No database connection was attempted by these checks.

The inspected [guarded native receipt](../native-dump-guarded-final.txt) retains the integrator's successful disposable MySQL 8.4.11 execution: raw dump/redump differ, normalized dumps match, restored row remains exact, altered INSERT remains distinguishable, and one trigger survives. The guard does not weaken that proof. The nonroot migration driver's import/formatting changes preserve its prior SQL and assertions by inspection; no application migration changed, and this addendum does not claim a new 79-migration execution.

The [verification README](../README.md) accurately distinguishes implemented checks, intentional red outcomes, the three unsuccessful native attempts, genuine transport/database evidence and substituted privileged operations. It does not claim real Forge activation or a real Stripe purchase. No materially misstated evidence was identified. Actual host operations and the release conditions in [DECISION.md](DECISION.md) remain open; no expensive migration or HTTP suite was repeated.

Reviewed evidence fingerprints:

| File | SHA-256 |
| --- | --- |
| `native-dump-normalization.php` | `559c97cef5b71a74bf1ef97b785f5ef2a650f8adb3d98493d99e8e09da5f639a` |
| `nonroot-migrations.php` | `6c83b2e7f19ce3e6aec74c8e472280f34d322e94318325b7f136d8936148e490` |
| `README.md` | `d296a690bb64df8329ab4a63a45796c87cefaa5b58ff6fa4279267944a1f35c9` |

`git diff --check` exited zero. Only this addendum was written; no source, original receipt, commit, branch or remote was changed by the reviewer. A later main/ledger integration still requires a fresh explicit source-equivalence assessment at its exact candidate.
