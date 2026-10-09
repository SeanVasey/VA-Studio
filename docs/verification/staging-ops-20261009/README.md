# PR #63 current verification

Current tested functional source: `69a405a6480edd031aad9f40e9f83c32e8adcc24` (no-follow runtime directory initialization, following admin defaults custody, app authentication, public-link/backup proof, Composer ordering and operation custody). The kit integrates main's CI
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

## Recovery admission repair at `a44664f4`

Codex on3b7d1b9 added4226365351 (same-SHA unhealthy retry reports success),4226365357 (first-install
migration skips snapshot),4226365364 (already-mounted private bind omits persistent fstab entry).
`same-sha-health-red.txt` retains2methods/2failures; `recovery-admission-red.txt` retains13methods/2failures.
Expanded3method red adds a Bash failure-return property using a substituted sealed_release() { return 1; },
not an actual old Python-sealer defect. The independent genuine Python exit23 check confirms the old
seal_tool already dies on such failure. `recovery-admission-expanded-red.txt` has2 product safety failures
and1 defensive-return property failure; the explicit return guard is defense in depth.

The same-SHA no-op now requires read-only root `healthy <sha>`: sealed selected current, persistent bind,
exact open admission, no maintenance, activeFPM, every supervised PID on that release andHTTP200. Partial
state refuses without repair. Every migration attempt now snapshots/proves first, including first-install
retries. Mounted attach reconciles the exact fstab line before success and refuses unrelated mounts;
served-release selection adds defensive propagation of a returned failure; genuine Python failures were already fatal.

Exact committed current ops `final-ops-a44664f4.txt` **50 PASS**, runtime `final-runtime-a44664f4.txt`
**13/63 PASS**. New canonical health method exercises13complete/partial states with genuine process cwd
and readonly gate/fstab/maintenance bytes; service/root/mount/HTTP authority remain bounded fixtures.
Earlier native28transport proof remains at11248 with unchanged HTTP source. Both precommit recovery49
then50 receipts are preserved; neither is relabeled as the committed run. Five Bash syntax/Shellcheck
checks pass. First-install restore sets still do not establish historical encryption-key custody.

Publication formatting strips only unittest progress whitespace and the empty-stdout assertion suffix in the new recovery reds; outcomes and traces are unchanged.

Independent [recovery addendum](independent-review/RECOVERY-ADMISSION-ADDENDUM.md) **APPROVES exacta44664f4**, carried toea9e0568 by empty product-source diff and inspected docs. Expanded independent5methods pass (initial4methods includes18health states); historical3methods reproduce7safety failures, and actual old Python wrapper already refuses its genuine error. Evidence-only publication preserves runtime source.

Two trailing spaces after empty assertion messages in the independent recovery red receipt were removed for `git diff --check`; command results and failure outcomes are unchanged.

## Root operation lease and database custody, core source 0e0df68d

Supersedes the earlier publication checkpoints above. Codex on56587038 found4226458399 (root control/writer locks ended before migration, permitting independent resume) and4226458404 (existing app identity could have no persisted credentials).

Tested source `0e0df68d7eca40c892eb8cfc959264f240ffd04c` uses three narrow root operations. `prepare` retains control/writer locks from fresh quiesce through fixed unprivileged checkout/build and sealing. `activate` retains them from fresh quiesce through verified snapshot, fixed unprivileged migration/cache/doctor, switch and healthy resume. `refresh` encloses snapshot/configuration/resume. The fixed root-installed helper requires protected ancestry, root0644 regular single-link custody; children close privileged FDs7/8. Root does not write application evidence paths. A protected root600 operation marker persists on failure or parent death, blocking all public mutating actions until explicit root recovery stops surviving children and inspects/restores state. Successful child completion plus sealing/healthy resume clears it. Between phases another action may run, so activate re-establishes fresh quiesce and snapshot; failures outside phases need inspection rather than a presumed closed gate.

Provisioning checks protected credential-store ancestry, refuses pre-existing files for absent identities before overwrite, uses no-clobber creation, and requires a finite root600 single-link regular app credential containing exactly the literal generated profile. It does not source the file or rotate an existing account. Missing/unsafe/incomplete custody requires private operator recovery.

Owner red: [2 methods /8 failures](codex-repair/operation-custody-red.txt); seven original unsafe credential cases and a modeled independent-resume gap causing migration refusal. This is bounded host/database authority, not a genuine host mutation. Four implementation/harness attempts are retained separately (including missing PHP PATH discovery); no failed attempt is represented as green. Trailing receipt whitespace is normalized solely for diff checks, without changing outcomes.

Exact committed owner commands:

- `source /workspace/.va-studio-toolchain/activate.sh; python3 -m unittest discover -s tests/ops -p 'test_staging_*.py' -v`: [57 PASS](codex-repair/final-ops-0e0df68d.txt), including real flock/process continuity, killed root parent with surviving migration child, fixed build and refresh lease, failed migration/blocked retries, readonly health, real Laravel configuration and sealing/backup boundaries. Root/service/database identities remain explicit fixtures.
- Genuine portable PHP8.4.26 PHPUnit entry point selecting `tests/Unit/StagingRuntimeValidatorTest.php`: [13 tests /63 assertions PASS](codex-repair/final-runtime-0e0df68d.txt).
- Each of six Bash scripts parsed separately; Shellcheck0.10 `-x` and whitespace PASS at this source. Native HTTP templates and prior28 transport cases are unchanged; no new actual host/provider claim.

Independent operation/custody decision is pending at this receipt commit; publication and merge require its exact-source approval, final preflight/code review and resolved findings. Actual Forge service/root/mount/MySQL credential recovery remains unexecuted.

### Latest lifecycle successor a7cf5d55

Independent review found a public legacy configure bypass at0e0: a killed root controller could leave its cache child alive while later resume opened admission. Actual independent red is retained. `a7cf5d55e6f89da367cf59181c05ef9efa7a8d10` removes that public action (configuration remains internal to refresh) and gives standalone stop/start interruption custody. Recovery quiesce never clears an existing interrupted-operation marker. Fresh activate re-quiesce now proves an already stopped backend/worker census rather than requiring HTTP503 from an absent backend or redundantly stopping STOPPED workers.

Owner new [7 methods /3 safety failures at0e0](codex-repair/operation-public-red.txt) reproduce cold quiesce refusal and killed public stop/start missing markers. Current exact committed [59 operations PASS](codex-repair/final-ops-a7cf5d55.txt), genuine [PHP8.4.26 runtime13/63 PASS](codex-repair/final-runtime-a7cf5d55.txt), Bash6 individually parsed, Shellcheck and whitespace PASS. The prior57/13 receipts retain their original core source identity. Root/service/database authority remains bounded fixtures, not installed Forge evidence. Independent final decision and exact publication gates are still required before merge.

### Operation-custody functional source 9cc584c4

`9cc584c471a90bacac8979c185496d0cc412a292` adds readonly resume admission before recovery-marker creation. A redundant/queued resume after successful activation refuses an open gate or absent maintenance without poisoning a healthy host with a stale marker. Owner [8 methods /1 failure at a7cf](codex-repair/operation-resume-admission-red.txt) retains that actual-body regression. Exact committed owner [60 operations PASS](codex-repair/final-ops-9cc584c4.txt), genuine [PHP8.4.26 runtime13/63 PASS](codex-repair/final-runtime-9cc584c4.txt), Bash6/Shellcheck/whitespace PASS. Earlier 57/59 receipts remain historical at their exact sources. Final independent decision and publication gate are recorded by the evidence-only successor; no host/provider operation is asserted.

Independent [operation/custody addendum](independent-review/OPERATION-CUSTODY-ADDENDUM.md) **APPROVES exact9cc584c4**: final10methods PASS, including7warm/cold/partial states and5readonly resume refusals. Current60ops and PHP13/63 owner receipts inspected. All new review receipts and failed fixture attempts are released and retained; normalization is disclosed in the addendum. Final evidence-only publication retains this product source and requires an explicit carry plus exact-head preflight/code-review gates.

## Composer environment ordering, exact source `2eee8d42`

Codex on `ee9d1af2` found 4226653387: Composer invokes Laravel package discovery and
Filament upgrade before the captured environment was installed, under a scrubbed
process environment. The helper now installs the captured private `.env` mode0600
before invoking Composer. Git checkout cleanliness is still proved before installing
this ignored file; built-runtime admission and protected sealing remain later checks.

Owner [red](codex-repair/composer-profile-red-ee9d1af2.txt) runs the actual fixed helper
with genuine Git and PHP interpreting a bounded Composer fixture: **3 methods,
1 failure**, helper exit23 because the first hook has no frozen profile. The fixture
checks exact captured bytes and private mode before creating any bootstrap artifacts;
Composer/npm effects and runtime admission are substitutes. The repaired focused
[green](codex-repair/composer-profile-green.txt) is **3 PASS**. Exact committed source
receipts: [operations](codex-repair/final-ops-2eee8d42.txt) **61 PASS**, genuine PHP8.4.26
[runtime](codex-repair/final-runtime-2eee8d42.txt) **13 tests /63 assertions PASS**.

Six Bash scripts parsed separately and Shellcheck0.10 `-x` passed: provision, backup,
Forge deploy, fixed release step, privileged controller and
`scripts/ops/run-test-commerce-pipeline.sh`; whitespace check passed. An initial syntax
command named a nonexistent pipeline helper and exited127; the corrected complete
selection above passed. Existing native HTTP and migration receipts retain their
historical identities. No new actual host, provider, build or purchase acceptance
is asserted.

Independent [Composer ordering addendum](independent-review/COMPOSER-ORDERING-ADDENDUM.md)
**APPROVES exact `2eee8d42`**: actual PHP8.4.26/Composer2.8.8 hook execution,
**1 method FAIL** at `ee9d1af2`, **1 method PASS** at the repair. The owned minimal PHP
hook sees frozen bytes at mode0600 before npm and no inherited APP_ENV; it is not
actual Laravel package discovery. Prior bounded approvals carry on unchanged paths.
Final evidence-only publication requires an explicit empty-source-diff carry and
exact-head preflight/code-review gates before merge.

## Public-link and backup-credential repair, exact source `b72c8efc`

Codex on `9e6b7fd5` found 4226801376 (a friendly public symlink could expose the
protected environment through static serving) and 4226801382 (an empty/truncated
backup profile could pass provisioning). The sealer now refuses any link resolving
to `.env` and any link under, or at, `public` that resolves outside that tree.
Both sealing and readonly verification apply the same predicate; ordinary public
asset/directory aliases and nonpublic internal code links remain admitted.

Backup provisioning requires a finite root-owned0600 single-link regular file with
exactly the five generated literal lines. Missing, empty, truncated, linked, writable,
extra/NUL/missing-newline or oversized data refuses. An absent account cannot overwrite
an orphan profile; generation is checked and persistence is no-clobber. A scrubbed
MySQL client with one admitted defaults file and `--no-login-paths` must authenticate
exactly `vasey_backup@127.0.0.1` before grants. Refusal requires private restore or
explicit account recovery; there is no automatic rotation.

Owner [red](codex-repair/public-backup-red-9e6b7fd5.txt): **67 methods, 17 failures
/1 error** at `9e6b7fd5`; the error is the missing authentication trace causing an
index lookup failure, not a host/tool setup error. Files/links/bytes/modes are real;
root/database authentication and grants are bounded fixtures. Repaired precommit
[green](codex-repair/public-backup-green.txt): **67 PASS**. Exact committed source
receipts: [operations](codex-repair/final-ops-b72c8efc.txt) **67 PASS** and genuine
PHP8.4.26 [runtime](codex-repair/final-runtime-b72c8efc.txt) **13 tests /63 assertions
PASS**. Six Bash scripts parsed separately; Shellcheck0.10 `-x` and whitespace pass.
Prior transport/migration evidence remains historical. Independent review and exact
publication gates remain required; actual Forge acceptance is unexecuted.

Independent [public/backup addendum](independent-review/PUBLIC-BACKUP-ADDENDUM.md)
**APPROVES exact `b72c8efc`**. Bounded actual-function selection: **5 methods PASS**;
old `9e6b7fd5`: **32 failing states** (12 links and20 custody/authentication/creation).
The first reviewer fixture PATH mismatch is retained separately. Genuine
[MySQL8.4.11 authentication](independent-review/public-backup-native.txt) accepts
the correct privately generated credential, rejects a wrong password, and rejects
an independently proved authenticated `vasey_backup@localhost` identity. The guarded
driver requires the unique disposable helper datadir, empty socket, testing schema
and loopback3306; root ownership is modeled, client/server/password authentication
are genuine, native libraries use a trusted toolchain adapter after environment
scrubbing. Initial passing receipt is preserved; generated credentials/server are
cleaned and no secret enters the receipts. Native-driver Pint passes with unchanged
bytes. Owner receipt progress and empty assertion-message trailing spaces are
normalized only for diff checks; results are unchanged. Actual Forge credentials,
grants, backup/restore/shipping and provider purchase remain unproved. The exact
publication still requires explicit empty-source-diff carry and final merge gates.

## Persisted app authentication, exact source `381d116b`

Codex on `444131c5` found 4226910300: a well-formed stale app password could pass
file-custody admission. Provisioning now authenticates before grants using a mode0600
temporary defaults file under protected secrets ancestry, a scrubbed client environment,
disabled login paths, TCP and a five-second connection timeout. The required identity
is exactly `vasey_app@127.0.0.1`. A subshell EXIT trap removes the temporary file and
preserves the original status; cleanup failure refuses grants. Credentials are never
process arguments or environment values, and existing passwords are never rotated.

Owner [old-source red](codex-repair/app-auth-red-444131c5.txt): **3 methods /3 failing
states**; [focused green](codex-repair/app-auth-green.txt): **3 PASS**. Exact committed
source receipts: [operations](codex-repair/final-ops-381d116b.txt) **68 PASS**, genuine
PHP8.4.26 [runtime](codex-repair/final-runtime-381d116b.txt) **13 tests /63 assertions
PASS**. Six Bash scripts parse separately, Shellcheck0.10 `-x` and whitespace pass.
Trailing spaces in new owner unittest progress/assertion lines are normalized only
for diff checks; failure details and outcomes are unchanged.

Independent [app authentication addendum](independent-review/APP-CREDENTIAL-AUTH-ADDENDUM.md)
**APPROVES exact `381d116bbea46f9f3dd5a7bc05f80b147d77ca8e`**. One independent method
passes five states: correct credential, failed authentication, wrong user, wrong host
and forced cleanup failure. Old `444131c5` retains four failing states. The
[genuine MySQL8.4.11 proof](independent-review/app-credential-auth-native.txt) accepts
the correct private credential, rejects a stale password and rejects a raw-client-proved
authenticated `vasey_app@localhost` identity. Temporary client files are removed after
all three ordinary outcomes; private fixtures/server are cleaned. Root ownership is
modeled, while native client/server authentication, bytes/modes and cleanup are genuine.
Actual Forge credentials/grants, SIGKILL cleanup, backup shipping and Stripe TEST
purchase remain unverified. Publication still requires explicit source-equivalence
carry and final exact-head preflight/code-review gates before merge.

## Administrator defaults custody, exact source `fd9dd035`

Codex on `d7e60167` found 4227010176: `--mysql-admin-defaults` accepted a readable,
app-owned or replaceable administrator file. The optional file now requires a
canonical absolute path without symlinks, protected root-owned ancestors without
group/world write, and a root-owned0600 regular single-link leaf. Admission runs
before packages, host changes or the first MySQL query. The default local socket
path is unchanged. No file is sourced, chmodded or repaired by this admission.

Owner [old-source red](codex-repair/admin-defaults-red-d7e60167.txt): **3 methods
/14 failing unsafe states**; [focused green](codex-repair/admin-defaults-green.txt):
**3 PASS**. Missing, symlinked, hard-linked, directory/FIFO, public/read-only/writable,
app-owned leaf/ancestor, writable/symlinked ancestor and noncanonical/relative paths
refuse before a traced first query. Valid private file and default socket succeed.
Root authority and MySQL are bounded fixtures; filesystem modes, links and paths
are genuine. Exact committed source [operations](codex-repair/final-ops-fd9dd035.txt)
**71 PASS**, genuine PHP8.4.26 [runtime](codex-repair/final-runtime-fd9dd035.txt)
**13 tests /63 assertions PASS**, Bash6 separately/Shellcheck/whitespace PASS.
Trailing owner receipt spaces are normalized only for diff checks. Independent
approval and final exact-head publication gates remain required. Earlier native
app/backup authentication and transport evidence retains its original source;
actual Forge root/admin custody, services and provider purchase remain unexecuted.

Independent [administrator defaults addendum](independent-review/ADMIN-DEFAULTS-ADDENDUM.md)
**APPROVES exact `fd9dd035dc9069db3727bd12960bb5952eb28399`**. The actual complete
CLI prelude and first-query construction pass **4 methods /25 states**: 22 refusals
and3 admitted states. Old `d7e60167` retains **22 unsafe-admission failures**. Root
ownership and MySQL are bounded fixtures; real paths, modes, links and early call
ordering are exercised. Owner71/13-63 receipts inspected. Bash/Shellcheck `-x` pass;
an initial omitted `-x` caused SC1091 and is disclosed, not passing evidence.
Only three independent red progress-line trailing spaces are normalized. Prior
source approvals carry on unchanged paths. This publication also corrects prose
spacing in ops README/CHANGELOG; explicit reviewed source equivalence and final
exact-head preflight/Codex gates remain required before merge.

## Runtime directory custody, exact source `69a405a6`

Codex on `c378295c` found 4227097077: root `install -d` could follow an app-placed
runtime directory link and change its target's ownership/mode. App/nginx runtime
initialization now opens every path component with `O_DIRECTORY|O_NOFOLLOW`, retains
parent descriptors for relative child creation/open, and uses `fchown`/`fchmod` on
the pinned child descriptor. Entry/inode mismatch refuses after initialization.
New private `.gitignore` uses exclusive no-follow creation and descriptor writes/
metadata; an existing ordinary single-link file is preserved, links and special
files refuse. Failure can leave partial changes inside admitted runtime directories.
A concurrent rename is not claimed to leave a healthy visible path.

Owner [immutable old-source red](codex-repair/runtime-directory-red-c378295c.txt):
**3 methods /12 failures /1 skip** at `c378295c`; the skip is explicitly the new-only
post-open descriptor race. The old GNU installer genuinely changes owned external
fixture modes or creates a file through a dangling link. Privileged ownership is
modeled by substituting the fixture UID. [Focused green](codex-repair/runtime-directory-green.txt):
**3 methods /15 states PASS**, including ordinary/existing file preservation and a
post-open rename/link before real descriptor metadata changes. No external fixture
bytes/modes/entries change. The fixed nginx parent is redirected to an owned prefix;
all other actual initializer control flow and native filesystem operations remain.
Exact committed [operations](codex-repair/final-ops-69a405a6.txt) **74 PASS**, genuine
PHP8.4.26 [runtime](codex-repair/final-runtime-69a405a6.txt) **13 tests /63 assertions
PASS**, Bash6 separately/Shellcheck/whitespace PASS. Trailing receipt whitespace is
normalized only for diff checks. Independent approval, publication equivalence and
final exact-head gates remain required. Actual root/Forge permissions and services
are unexecuted; earlier native authentication and transport receipts stay historical.

Independent [runtime directory addendum](independent-review/RUNTIME-DIRECTORY-ADDENDUM.md)
**APPROVES exact `69a405a6480edd031aad9f40e9f83c32e8adcc24`**. Final **6 methods
/18 filesystem states PASS**, plus actual stage-call assertion. Expanded old
`c378295c` reproduces **12 failures /2 explicit new-only skips**; the initial
seven-failure observation remains separately bounded. Four concrete replacement
races preserve protected targets, including safe captured-inode writes after
`.gitignore` pathname replacement. That last case may succeed while an independent
writer changes the visible entry; it does not certify persistent pathname health.
Detected directory replacement refuses. Root ownership is modeled with the fixture
UID; actual kernel paths/modes/links/descriptor operations are exercised. Owner74/
PHP13-63 receipts inspected; Bash/Shellcheck pass. Three progress-line trailing
spaces in each independent red are normalized only for diff checks. Publication
also clarifies ops README wording without a product-source change. Exact reviewed
publication equivalence and final preflight/Codex gates remain required.
