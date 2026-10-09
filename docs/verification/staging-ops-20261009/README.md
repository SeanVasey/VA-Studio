# PR #63 current verification

Current tested functional source: `ca7e3ae46b312e1673a2067bb5d122e8aa9bc11e`. The kit integrates main's CI
cost policy, reviewed D1/#62 and both prior merge ledgers. The original component
[decision](independent-review/DECISION.md) approved `2c91f8e`, closing nine runtime defects. The subsequent
[integration addendum](independent-review/INTEGRATION-ADDENDUM.md) blocked `e898282d`: standalone pipeline
sweeps escaped the stopped-writer boundary and the Forge example targeted the mirror. The current repair
adds durable root admission and a shared writer barrier. The fresh [repair decision](independent-review/PIPELINE-ADMISSION-ADDENDUM.md)
**APPROVES exact `ca7e3ae4`**: I-1/I-2 closed within the controlled-runner boundary. Publication requires
an explicit source-equivalence check. Actual host activation remains external.

## Actual local evidence

| Receipt | Actual result and boundary |
| --- | --- |
| `runtime-validator.txt` | Genuine PHP 8.4.26, 13 tests / 63 assertions, exit 0: real Laravel configuration, duplicate/quoted/interpolated flags, cache/DB overrides, credentials, key custody and output privacy |
| `normalization.txt`, `normalization-final.txt`, `normalization-lexical-green.txt` | Parser coverage grew from 8 to 10 to 12 passing cases; final exit 0 includes literal/comment context and fail-closed unsupported escape mode |
| `environment-race-red.txt`, `environment-race-green.txt` | Actual same-SHA branch admitted one file but copied a later edit: 1 failure before freeze, 1 passing case after; control/artisan are harmless substitutes |
| `routine-literal-red.txt` | Intermediate parser: 11 methods, 3 subcase failures before lexical-context repair |
| `nonroot-mysql84.txt` | Genuine disposable MySQL 8.4.11 with binary logging: non-SUPER trigger refused 1419 with trust OFF, succeeds ON; app-account migrations exit 0, repeat is a no-op; 79 migrations / 184 tables / 567 triggers |
| `native-dump-guarded-final.txt` | Genuine MySQL client/server 8.4.11, one synthetic table/trigger: raw first/redump differ, normalized dumps match, restored row exact and altered INSERT remains different; exit 0 |
| `independent-review/nginx-fpm-routing.txt` | Genuine nginx 1.26.3 / PHP 8.4.26 FPM, 26 checks pass in an owned loopback TLS prefix against a synthetic FastCGI responder; unchanged kit templates |
| `integration-runtime.txt`, `integration-normalization.txt`, `integration-refresh.txt` | Kit plus reviewed #62 candidate at `e898282d`: runtime 13/63, normalization 12 and frozen-refresh 1, all exit 0; Bash syntax checked separately for each script and Shellcheck `-x` pass |
| `pipeline-admission-red.txt`, `pipeline-admission-red-corrected.txt` | Initial 8-method red has 10 failures; corrected final 9-method authority fixture against extracted old `e898282d` runner/helper has 12 failures, exit 1, including future starts, snapshot admission and orphan child lock |
| `pipeline-admission-final-paths.txt` | Current 9 methods pass, exit 0: real locks/processes, closed/no-private-write starts, active and orphan writers, partial/malformed/NUL/symlink/writable admission, and ordinary nongated local harness |
| `pipeline-repair-final-sqlite.txt` | Exact committed `ca7e3ae4`, validator/runner/profile journeys/runtime: 89 tests / 876 assertions, exit 0, no skips |
| `independent-review/pipeline-admission-final.txt`, `pipeline-current-path-final.txt`, `pipeline-admission-canonical-final.txt` | Independent exact `ca7e3ae4`: 12 admission tests, 1 additional selected physical-release cwd/private-state case, and 9 canonical tests, all exit 0; 12-method receipt does not include the later added method |
| `independent-review/pipeline-admission-red.txt` | Read-only old `e898282d` source: 3 selected product safety assertion failures, exit 1, distinct from the reviewer fixture umask error |

The original seven independent red receipts remain unchanged. Historical independent methods at
`2c91f8e` passed: actual
two-process helper concurrency **1**, lock/descriptor/nightly boundaries **6**, SQL-context **4**, repaired
activation/configuration/recovery boundaries **9**. At `2c91f8e`, the reviewer separately ran the genuine runtime
validator **13/63**, canonical normalization **12** and frozen refresh **1**, all exit 0. These older
helper probes have not been rerun against the admission repair; its new independent receipt is separate. Helpers use bounded
authority substitutes for root ownership, mount/unmount/removal and service controls; they do not prove
those operations on a real host. F8's red and green show the competing attach first crossing prune's final
read, then waiting under genuine `flock`. No actual private store was mounted or removed.

Focused Bash syntax, Shellcheck 0.10.0 (`-x` for the trusted `/etc/os-release` source), Pint and whitespace
checks passed. The one-off nonroot proof driver needed formatting; its final Pint recheck passes. No
application or migration code changed in that formatting repair. Foundation is reserved for the final
integrated exact SHA; cheap PR preflight is the development merge gate.

## Commands and retained unsuccessful attempts

```sh
source /workspace/.va-studio-toolchain/activate.sh
/workspace/.va-studio-toolchain/standalone/bin/php8.4 -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --do-not-cache-result tests/Unit/StagingRuntimeValidatorTest.php
python3 tests/ops/test_staging_dump_normalization.py
python3 tests/ops/test_staging_deploy_refresh.py
python3 tests/ops/test_staging_pipeline_admission.py
for script in ops/staging/{provision.sh,forge-deploy.sh,backup.sh,bin/vasey-staging-ctl}; do bash -n "$script"; done
/workspace/.va-studio-toolchain/root/usr/bin/shellcheck -x ops/staging/{provision.sh,forge-deploy.sh,backup.sh,bin/vasey-staging-ctl}
```

Native proofs run only through `bash scripts/dev/with-mysql-test-server.sh php <proof-driver>` with
`MYSQL_TEST_BASEDIR=/workspace/.va-studio-toolchain/mysql84`, port **33070**, and
`MYSQL_TEST_LIBRARY_PATH=/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64:/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64/mysql/private`.
The dump driver additionally receives the extracted genuine `MYSQL_DUMP_BIN` / `MYSQL_CLIENT_BIN` paths.
Credentials are generated in private temporary files and never printed or retained. The driver checks
testing mode, disposable schema/root/loopback settings and native helper datadir/socket facts before DDL
or reload. Sandbox loopback needs network permission; no external host or provider is contacted.

`native-dump-normalization-attempt1.txt` retains a harness library-path mistake: MySQL image libraries were
incorrectly applied to PHP, causing extension/startup failure. The corrected driver scopes those libraries
only to native MySQL clients. Attempts **2** and **3** retain a genuine fail-closed parser admission issue:
MySQL aligns the saved-client-charset assignment with multiple spaces; the initial strict pattern left the
known column rendering difference unchanged. The explicit setting pattern now accepts that alignment,
and final native receipts pass. The intermediate successful dump receipt and later lexical/guarded
reruns remain separately named. Earlier owned nginx harness path mistakes remain in the independent
directory; its final routing receipt passed. None of these unsuccessful runs is passing evidence.

Publication formatting removes trailing spaces from exactly three unittest progress lines: two in
`independent-review/repair-boundaries-harness-attempt2.txt` and one in `routine-literal-red.txt`.
Their outcomes and the original seven red artifacts are preserved; no result was changed.

The first two gate-repair attempts retain fixture setup errors: the stat authority stand-in did not
handle `--`, then the harness umask created a 0600 lock rather than the explicitly intended 0644 file.
The corrected fixture models ownership/ancestry only and uses genuine file modes, links, byte comparison,
processes and `flock`. No root host, real SQL writer, mount, backup or service was substituted into a
passing host claim. The final corrected pre-repair run reproduces 12 failures; current 9 methods pass.

## Recovery and limits

The root helper seals every privileged ancestor and holds a separate root-owned control lock through
each action. Application subprocesses do not inherit its descriptor. Same-SHA refresh takes a recoverable
snapshot before replacing the exact frozen/admitted environment and refuses key drift. Recovery restores
verified `env.backup` before cache rebuilding. A phase flag never proves writer/service state.

Restore comparison accepts only a string-column charset rendering difference in an eligible dump table
block; row/default/comment/routine bytes stay exact. Delimiter-looking text in SQL literals/comments cannot
change context. Explicit `NO_BACKSLASH_ESCAPES` refuses normalization instead of guessing boundaries.
Reproof removes prior success before hashing, and new success is published atomically after verification
and disposable-server cleanup. The full privileged backup/restore procedure has not run on a host here.

Actual Forge/DigitalOcean/SSH access is expected from Sean on 2026-10-10. Untested: real root ownership and
sudoers, bind/unbind/prune on the deployed filesystem, systemd/supervisor execution, Forge activation,
AppArmor, ACME/DNS, real retained-media backup/shipping/encryption and real Laravel/Stripe interoperability.
The native HTTP responder does not verify Stripe signatures or purchases. Hosting, secure TEST secrets,
hostname, real staff/catalog/rights/terms/tag and backup target remain Sean's inputs. This is private TEST
staging preparation; live checkout and paid-route mounting retain their separate conditions.

Publication formatting also removes trailing whitespace from unittest progress lines in new admission receipts: `pipeline-admission-red-corrected.txt` (1), `pipeline-admission-red.txt` (1). Assertions, failure details and outcomes are unchanged.
