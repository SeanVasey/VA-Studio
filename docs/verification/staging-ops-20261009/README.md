# PR #63 current verification

Current tested functional source: `7bced5ecb91593e28854297a2ca14247931c3448`. The kit integrates main's CI
cost policy, reviewed D1/#62 and both prior merge ledgers. The original component
[decision](independent-review/DECISION.md) approved `2c91f8e`, closing nine runtime defects. The subsequent
[integration addendum](independent-review/INTEGRATION-ADDENDUM.md) blocked `e898282d`: standalone pipeline
sweeps escaped the stopped-writer boundary and the Forge example targeted the mirror. The current repair
adds durable root admission and a shared writer barrier. The fresh [repair decision](independent-review/PIPELINE-ADMISSION-ADDENDUM.md)
**APPROVES exact `ca7e3ae4`**: I-1/I-2 closed within the controlled-runner boundary. Publication requires
an explicit source-equivalence check. The later Codex repair below changes helper/sealing/backup/HTTP
boundaries and requires its own independent approval. Actual host activation remains external.

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

## Later Codex repair at `11cf2dcc`

Codex code review on published `3c0050bc` raised 4226007102 (signature), 4226007106 (mutable release files)
and 4226007111 (missing served key). Security review also completed on that prior source. All new source
must be independently reviewed and rechecked by the cheap preflight before merge.

The native baseline disproves the claim that the original nginx default drops the signature: exact
Stripe header and UTF-8 body arrive at genuine FPM, **28 checks pass**. An inherited
`fastcgi_pass_request_headers off` instead fails the signature assertion. The explicit signature parameter
repairs that boundary; default and inherited-off runs both pass 28. These are synthetic signed-body/header
transport checks, not Stripe SDK verification, Laravel receipt persistence or a real provider purchase.

| Historical committed receipt at11cf (`codex-repair/`) | Actual result |
| --- | --- |
| `committed-ops-tests.txt` | **39 methods pass**, exit 0: parser12, refresh1, admission9, sealing7, backup7, protected configure3 |
| `committed-runtime-tests.txt` | Genuine PHP 8.4.26, **13 tests / 63 assertions**, exit 0 |
| `committed-signature-inherited-off.txt` | Genuine nginx1.26.3/FPM8.4.26, **28 checks pass**, exit 0 |
| `sealing-red.txt` | Corrected old-source authority fixture, **5 product safety failures**: writable bytes, held writer descriptor, hard links, unsafe symlinks and executable protection |
| `key-custody-red.txt` | **6 methods / 5 failures** before repair: invalid current, missing env/hash/manifest admission |
| `signature-default-baseline.txt`, `signature-inherited-off-red.txt` | Original default **28 PASS**; actual parent-off signature failure, exit1 |

Each of five Bash scripts parsed independently; Shellcheck0.10 `-x` exits0. No app/config/database,
contract asset/profile, #62 validator or runner source changed. The 89/876 commerce/runtime selection,
prior native dump/migration and old independent admission counts remain historical at their named source;
the changed HTTP boundary has fresh native proof. No full matrix was launched.

The sealer freezes parents before opening children and replaces regular code/vendor/build/environment
files on fresh root-owned read-only inodes, so an old app descriptor cannot modify their installed bytes.
It rejects hard links, special files and external/runtime code symlinks and preserves executables/public
readability. Only explicit application-owned 0700 runtime paths are skipped; retained private bind contents
are never traversed. Generated PHP caches/views/maintenance remain writable trust exceptions. This is
post-sealing source protection, not builder attestation or immutable runtime execution.

`configure` requires closed admission, stopped web/writers, the selected served release in maintenance,
a safely captured private evidence file, real Laravel admission and unchanged key before replacing
root:app-group0440 `.env`. Cache creation runs as the app; failures stay quiesced for verified recovery.
Root recovery installs the hash-verified env on a fresh protected inode. Served snapshots refuse invalid
current/key before publishing `.last`; restore removes old success before demanding the release manifest
and mandatory env/hash. Only explicit `release_sha=none` first-install sets omit a historical key.

Retained harness errors are not product reds: `signature-harness-unicode-attempt.txt` used Python's
Latin-1 string-body default for a snowman before correction to UTF-8 bytes; `sealing-harness-ancestry-attempt.txt`
forgot to model protected ancestry through this sandbox's writable `/tmp`; `configuration-first-run.txt`
forgot root ancestry authority at the final verification. Corrected runs preserve real inode/mode/link/FD,
byte and PHP configuration behavior. Ownership authority, services, cache-command side effects and installed
root tools are bounded fixtures; no host root, real backup/restore shipping, Forge service or database was
exercised. Real host acceptance remains open.

## Independent finding repairs at `11248aa3`

The reviewer independently reproduced three further failures at `11cf2dcc`: a key-looking line inside
another multiline value was admitted, a candidate FIFO blocked before regular-file admission, and an
environment hash manifest checking a different file was accepted. The reviewer also required explicit
nonroot application identity. Canonical regression runs on the old source retain **9 key methods / 3
failures** and **9 file methods / 2 failures** in `independent-findings-{key,files}-red.txt`.

The repair requires one complete assignment per line before literal key extraction, compares the exact
single `env.backup` checksum record, opens untrusted candidates nonblocking before regular-file checks,
and rejects UID 0 at privileged application entry points. Quoted/plain valid keys, genuine first install
and the served key with its correct hash remain covered positive cases.

Exact committed receipts: `final-ops-11248aa3.txt` **43 PASS**; `final-runtime-11248aa3.txt` **13/63 PASS**;
`final-signature-11248aa3.txt` **28 native nginx/FPM checks PASS** with inherited request headers disabled.
Five scripts parsed separately and Shellcheck `-x` passed. Ownership/root/service boundaries and real-host
limits above still apply. The independent final decision is recorded separately; these owner checks are
not a substitute for that decision.

Publication formatting removes trailing whitespace from new unittest progress lines and one empty-stdout assertion line; assertions, failure details and outcomes are unchanged.

Independent [Codex repair addendum](independent-review/CODEX-REPAIR-ADDENDUM.md) **APPROVES exact functional11248aa3**, explicitly carried to evidence candidate1f84d0b3 by empty product-source diff. Independent boundary9 and canonical43 pass; all four new issues are closed. Final publication is an evidence-only successor and retains the same source.

## Build/ancestry admission repair at `7bced5ec`

Codex review completed on c9ff1434 and raised4226283468 (live app poisoning ignored generated artifacts)
and4226283473 (provisioning a root whose existing ancestry ctl later rejects). The actual owned Git/deploy
flow with a separate live application-process fixture reproduced vendor injection while real tracked-source
Git comparison accepted it. The extracted actual old lexical root check admitted an app-owned parent.
`build-admission-red-corrected.txt` retains **2 methods /2 safety failures**; the first red's root case used
an absent-guard stand-in, corrected to the real lexical check before repair. `root-lexical-red.txt` retains
**2 methods /2 subcase failures** on intermediate double-slash/trailing-slash admission.

New-release deploy validates the frozen candidate using the protected current release when present,
quiesces controlled services/writers before allocation/build, then repeats built-candidate admission before
attachment/sealing. Activation failures after quiesce and before healthy resume keep writer admission closed for recovery; downtime includes
Composer/npm. Already running rogue/manual application-account processes outside the controlled census
must separately be stopped. Existing builder-attestation limits remain explicit.

Provision requires canonical lexical components and every existing ancestor/root to be a canonical,
root-owned directory without group/world write permission before package/account/database/filesystem
changes. Missing descendants are created only under that admitted prefix.

Exact committed owner receipts: `final-ops-7bced5ec.txt` **45 PASS**, `final-runtime-7bced5ec.txt` **13/63 PASS**;
Bash5 separately andShellcheck `-x` PASS. Native28 signature proof remains at11248 with empty HTTP template
diff; it was not relabeled or rerun. The first current ops attempt lacked RELEASES in its new fixture,
failed1 case, and is retained as `build-admission-ops-harness-attempt.txt`. Corrected intermediate45 PASS
and the exact committed45 PASS remain separate. No privileged host operation or provider call occurred.

Independent [build admission addendum](independent-review/BUILD-ADMISSION-ADDENDUM.md) **APPROVES exact7bced5ec**, carried to34b8d2dc by empty runtime-source diff and inspected prose. Six delta probes pass; oldc9 selected2methods reproduce11 safety failures. The publication wording now explicitly limits closed admission to activation failures before a healthy resume. Final evidence-only successor source remains unchanged.
