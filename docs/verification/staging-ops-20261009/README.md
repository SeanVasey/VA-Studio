# PR #63 current verification

Reviewed functional source: `2c91f8e620cd870a2e213b4af790a1dbfab8de9a`. The kit integrates main's CI cost
policy and the reviewed D1 command. [Independent decision](independent-review/DECISION.md): **APPROVE** for
a focused development merge. All nine identified runtime defects are repaired; actual host activation
remains an external acceptance step. Evidence-only successors need a source-equivalence check.

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

The original seven independent red receipts remain unchanged. Final independent methods pass: actual
two-process helper concurrency **1**, lock/descriptor/nightly boundaries **6**, SQL-context **4**, repaired
activation/configuration/recovery boundaries **9**. The reviewer separately ran the genuine runtime
validator **13/63**, canonical normalization **12** and frozen refresh **1**, all exit 0. Helpers use bounded
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
bash -n ops/staging/{provision.sh,forge-deploy.sh,backup.sh,bin/vasey-staging-ctl}
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
