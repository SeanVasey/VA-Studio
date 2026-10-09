# Codex → Claude Code checkpoint, 2026-10-09

Sean explicitly chose: **finish PR #63, then hand the remaining queue to Claude Code to save Codex usage**.
This document supersedes the task-status snapshot in `../2026-10-08/CODEX-HANDOFF.md`; that earlier document
remains useful historical context. Do not restart the already merged D1/#65, #62 or #63 work.

Read in order: this document, `../2026-10-08/MONDAY-PLAN.md`, root `AGENTS.md`, then
`../2026-10-07/CLAUDE-PLAN.md` (merge ledger). Read the exact independent decisions before carrying them
onto another commit. The companion `CLAUDE-START-PROMPT.txt` is a paste-ready starting prompt.

First action: fetch the handoff branch and recheck remote main, PR #56 and PR #60.
Resume #56's independent addendum15 for the original `55976099..27a151b6` range,
then integrate current main and the handoff ledger before reviewing its merge candidate.
The remaining branches below are preserved work in progress, not validated deliverables.

## Authority and target

The Monday October 12 target is a **private Laravel Forge/DigitalOcean VPS in Stripe TEST mode**: Sean publishes
3–10 real tracks, buys one with a test card, receives the test contract and purchased files, and sees a
BeatStars catalog dry run. The application is Laravel 13 / PHP 8.4, Filament 5, Inertia / React / TypeScript.
Brand is VASEY.AUDIO; do not conflate it with VASEY/AI.

Sean approved development, setup and sandbox permissions. Continue reversible development without
repeated permission requests. His latest hosting choice is **Laravel Forge + VPS**, DigitalOcean previously
selected; he expects access **2026-10-10**. There is no supplied host/account/SSH access yet. Tailscale is
an access/network layer, not the VPS provider. Do not reopen the hosting choice.

Ask for missing user input, credentials, accounts, uploads, DNS actions, live payments or production
deployment; do not assume those actions are covered by development permission. Secrets belong in a
secure host/secret channel, never the repository, PR bodies or chat. No real Stripe provider transaction,
deployed service, real catalog publication, or host backup/restore has been proved in this session.

## Published checkpoint

Remote main at this checkpoint: `49489697ddbb1eea18a61c6f9ff6c52148a9ef45` (actual #63 merge).
The immediate #63 ledger/status commit is `681380f7`; this branch is not merged into main.

Handoff branch: `harness/claude-handoff-20261009`. It contains the #63 merge ledger/status update and this
document on top of the integrated main below. It is documentation only. **Carry this branch into the next
product branch before publication** so the #63 ledger/status record is not lost; do not overwrite current
main. The next product task is PR #56, not a new staging-kit rewrite.

| Completed item | Exact merge / head | Actual evidence and limits |
| --- | --- | --- |
| Previous Claude handoff #64 | already on main before takeover | No duplicate merge required. |
| D1 rights-scope command, #65 | merge `c8d510004bc5f57e39068f619268eca7475e5478`; expected head `8453909eb3ba09f0aa296ad9a7c51d0a62cdaf95`; sensitive source `cae38c30` | SQLite and genuine MySQL8.4.11 each **11/236**, no skips; independent APPROVE; baseline/red repairs retained; preflight **37863561491 SUCCESS**; Codex P2 repaired/resolved. Real offers still require explicit scope registration and revision linking through `vasey:rights-scope`; inspect `--help` and `docs/shared-rights-inventory.md` before use. |
| TEST profile/validator/runner, #62 | merge `0d864771fb6b2fee0522bc74f044886e07ed6fe1`; expected head `4902c45eaa7e8b12998adbc93c6b5f37ddf63d42`; final functional `1b2d0aa85b00b55d36e71eae66d61340dfc0b820` | final SQLite **76/813** and template **36** checks; native journeys **9/249** before the bounded HttpOnly repair, affected selection **287/2164**; independent latest **32** HttpOnly probes plus prior **55** probes and timing **2/48**; preflight **37870562968 SUCCESS**; five Codex threads resolved and final-head code review complete. Security review was on an earlier source, not represented as a final-head security pass. |
| Forge staging kit, #63 | merge `49489697ddbb1eea18a61c6f9ff6c52148a9ef45` at2026-10-09 06:27:12UTC; final published `78b989f6ffd5166e92db1f25df584bd761c447cf`; reviewed functional `69a405a6480edd031aad9f40e9f83c32e8adcc24` | current ops **74 PASS**, genuine PHP runtime **13/63 PASS**; native nginx/FPM transport **28 PASS** at11248 with unchanged templates; prior independent boundary9/canonical43, build6, recovery5/18 states, operations10/7 service states/5 resume refusals and Composer hook1 retain their stated sources. Public/backup5 + native backup3 states atb72, latest independent app1 method/5 states + native app3 states/ordinary cleanup at381; latest administrator-defaults4 methods/25 states atfd9, current runtime-directory6 methods/18 states at69a recorded below; preflight **37892786276 SUCCESS**. Final exact-head Codex code review completed2026-10-09T06:26:24.201926Z with no new findings; all16 threads repaired/resolved. Independent APPROVE explicitly carried; automated security review remains historical3c0050bc. |

The ledger row and Monday status line are added immediately after each product merge. Earlier #65 and
#62 rows are already included by #63. The handoff branch carries the new #63 row; preserve it when
integrating #56. Final Foundation has **not been dispatched**; reserve one complete run for the final
reviewed integrated 40-character SHA after the remaining code queue.

## What #63 now does, and what it has not proved

Primary evidence: `docs/verification/staging-ops-20261009/README.md`. Independent decisions, original red
receipts, corrected fixtures and current approval are in its `independent-review/` directory; latest decisions are
`CODEX-REPAIR-ADDENDUM.md`, `BUILD-ADMISSION-ADDENDUM.md`, `RECOVERY-ADMISSION-ADDENDUM.md`, `OPERATION-CUSTODY-ADDENDUM.md`, `COMPOSER-ORDERING-ADDENDUM.md`, `PUBLIC-BACKUP-ADDENDUM.md`, `APP-CREDENTIAL-AUTH-ADDENDUM.md`, `ADMIN-DEFAULTS-ADDENDUM.md`, `RUNTIME-DIRECTORY-ADDENDUM.md`. Fresh owner receipts are in `codex-repair/`.

The original component review closed nine issues, then an integration review found standalone pipeline
writers bypassing quiesce and a Forge cron targeting the mirror. The repaired runner uses a durable
root-owned closed/open admission file and a shared per-sweep lock. Quiesce closes admission before taking
the exclusive barrier; active writers and orphan children hold that lock, and future starts cannot write
private state. Healthy resume alone opens admission. Install only the reviewed current-release one-shot
timer/cron; remove older mirror jobs and long-running loop runners. Uncontrolled manual SQL/other tools
must separately be stopped by the operator.

Privileged actions use a separate control lock. Release ancestry and code/vendor/build files are sealed
parent first onto fresh root-owned read-only inodes. `.env` is root:app-group 0440. Old application writer
descriptors cannot change the published inode. Hard links, special files and external/runtime code
symlinks refuse. Runtime exceptions are explicit app-owned private directories (`bootstrap/cache`,
`storage/framework`, `storage/logs`, `storage/app/private`); retained bind-mounted private contents are
never traversed. Generated PHP caches/views/maintenance remain writable trust exceptions. This is not a
claim of builder attestation or immutable PHP execution.

Same-SHA configuration refresh uses one root `ctl refresh` operation to retain the lease through a
recoverable snapshot, safe capture with no-follow/nonblocking opens, actual Laravel configuration and
unchanged APP_KEY admission, protected inode installation and app cache rebuilding, then healthy resume.
Standalone public `configure` was removed after its interruption bypass was reproduced. Failures need
explicit state inspection and verified recovery; a late failure after healthy resume does not prove a
closed gate. UID0 is refused as the application account in provision and privileged entry points.

Snapshots refuse invalid current links and missing/invalid served environment/key. The backup profile
supports one assignment per line and refuses multiline quoted values before identifying a literal
APP_KEY. Restore admission requires an unambiguous release manifest and the **exact single env.backup
checksum record**. Only a genuine no-current first-install set with explicit `release_sha=none` omits the
historical key. Old `.last`/`RESTORE_CHECK` success is invalidated before a new attempt; success publishes
only after verification/cleanup. SQL comparison normalizes only known table-column charset rendering,
preserving row/default/comment/routine bytes; unsupported escape mode refuses.

Codex findings on `3c0050bc`: explicit Stripe signature forwarding (4226007102), mutable release files
(4226007106), and missing served key (4226007111). The native baseline already forwarded the signature
under nginx defaults; the repair explicitly survives inherited `fastcgi_pass_request_headers off`.
Native HTTP tests use a synthetic FastCGI responder and signed UTF-8 bytes; they prove exact header/body
transport and URI/query routing, not Stripe SDK verification, Laravel receipt persistence or purchases.

The independent reviewer additionally reproduced lexical fake-key admission, blocking FIFO admission,
wrong-file env checksum admission, and root application identity. All were repaired red first at
`11248aa3`. Current canonical old-source red counts are **9 key methods / 3 failures** and **9 file methods
/ 2 failures**. Ownership/service/cache side effects use bounded fixtures; real filesystem mode/inode,
FD/link, lock/process, actual Laravel config and HTTP transport behavior are preserved.

The Codex pass on c9ff1434 added two findings:4226283468 (a running app could poison ignored vendor/build before sealing) and4226283473 (provision accepts ancestry that ctl rejects). Tested functional7bced5ec added frozen candidate admission using the protected current release before downtime, quiesce of controlled web/workers/scheduler/gated pipeline before allocating or building any writable candidate, and repeated built-candidate admission before attachment. These behaviors remain in the later root-operation successor. Downtime includes Composer/npm. Activation failures before healthy resume keep admission closed; a later evidence-write failure after healthy resume is not described as closed admission. Manual/rogue processes outside the controlled census must be stopped separately; this still does not certify an uncompromised builder.

Provision now validates every existing ancestor and existing root, including owner/mode/no-symlink/canonical path, and refuses noncanonical lexical components before host/package/account/database/filesystem changes. The actual old Git/deploy flow admitted an ignored-vendor injection; new canonical2 methods pass. Independent new delta6 methods pass, with oldc9 selected2 methods reproducing11 safety failures. At7bced5ec, committed ops45 and runtime13/63 passed; current recovery source is recorded below. Both new threads were repaired/resolved; final review/head status is recorded in the checkpoint table. Independent build approval carried to published3b7d1b9c by unchanged runtime source; the later recovery approval supersedes that publication checkpoint.

Codex on3b7d1b9 then identified three recovery gaps:4226365351 (same-SHA unchanged environment could succeed after failed resume),4226365357 (first-install migration lacked a snapshot),4226365364 (mounted bind could lack fstab persistence). Tested a44664f4 adds read-only root `healthy <sha>` before no-op success, requires every migration attempt to take/verify a snapshot even without current, and reconciles exact fstab entries before already-mounted attach returns. Healthy requires sealed selected current, persistent private bind, exact open admission, absent maintenance, active FPM, all worker PIDs on current andHTTP200; it never repairs partial state. A separate substituted-failure test checks defensive Bash return propagation; it is not an old Python-sealer defect. Independent genuine Python exit23 proof confirms the existing root wrapper already rejects that failure. At a44664f4, committed ops50 and runtime13/63 passed. Independent recovery5methods, including18health states, pass; approval explicitly carries to exact publication565870388ff698bb94067913ede576d5cce31e74 by empty product-source diff and inspected documentation. First-install sets still cannot claim historical encryption-key recovery. The independent recovery decision and final-head gates are recorded in the checkpoint table and verification directory.

Earlier native evidence remains historical at its stated source: genuine MySQL8.4.11 binlog non-SUPER
trigger error1419 with trust OFF, app-account migration successful with trust ON (**79 migrations / 184
tables / 567 triggers**), repeat no-op; synthetic table/trigger native dump-reload normalized equality and
changed INSERT inequality. This did not run the privileged host backup procedure.

Not tested: installed root/sudoers, bind/unbind/prune on Forge, real Supervisor/systemd, AppArmor, DNS,
ACME, real retained-media backup/shipping/encryption, real host configuration, genuine Laravel/Stripe
events or purchases. Read `ops/staging/README.md`, `docs/ops/staging-runbook.md`, and
`docs/ops/staging-test-purchase.md` before any authorized host work.

## Final operation-custody repair after recovery review

Codex on56587038 added4226458399 (resume could reopen writers between snapshot and migration) and4226458404 (provision could accept an existing app database identity with no credential file). Functional9cc584c4 now delegates fresh build/sealing to root `prepare`, and fresh quiesce/snapshot/migration/cache/doctor/switch/healthy resume to root `activate`, retaining control/writer locks throughout each fixed application child. Normal configuration uses one root `refresh`. Fixed root-installed `release-step.sh` is protected root0644 single-link, launched as application identity with explicit argv and scrubbed env; app children close FDs7/8. Root does not write app evidence paths. Each phase repeats its required checks; gaps between phases are not claimed continuously quiesced.

A root600 `operation-in-progress` marker is created before quiesce, removed only after critical child completion and seal/healthy success, and preserved on failure or root-parent SIGKILL. Public operations refuse an existing marker except quiesce/status. Independent review found old standalone configure bypass; that public route is removed, and standalone quiesce/resume also use interruption custody. Recovery quiesce preserves an existing marker. Root recovery must stop/reap all surviving helpers/build/migration/cache descendants and inspect/restore state before explicitly clearing the marker; no application reset or failed-operation autoresume exists. Fresh quiesce handles already stopped FPM/workers using stopped proof instead of inapplicable HTTP503. A failure after healthy resume or outside a critical phase still needs state inspection.

App credential custody requires protected canonical secrets ancestry, no pre-existing file overwrite when the database identity is absent, no-clobber creation, and a finite root600 regular single-link literal generated file. Missing, linked, writable or incomplete custody refuses before app grants; account rotation is never automatic. Evidence retains owner2methods/8failures at565 and7methods/3failures at0e0, intermediate harness/environment errors, 60ops and genuine PHP13/63 at9cc (superseded by61ops at2eee). A redundant resume checks readonly gate/current/maintenance admission before creating any marker; it refuses healthy or invalid state without leaving spurious recovery custody. Real control flock, child lifecycle/SIGKILL and file behavior are exercised; root/services/database authority is explicitly bounded. Actual Forge root/mount/credential recovery is still unexecuted. Read the final independent decision and checkpoint gate identities above before carrying approval.

## Final Composer ordering repair

Codex on `ee9d1af2` added 4226653387: under the scrubbed process environment, Composer
booted its Laravel/Filament hooks before the captured staging `.env` existed. Exact
functional `2eee8d420aa822414841a801318b04888e4e71ac` installs the private captured
profile mode0600 after clean-checkout proof and before Composer. Built-runtime
validation and protected sealing remain in place.

Owner red runs the actual helper with genuine Git/PHP and bounded Composer effects:
3 methods, 1 failure with exit23 before any bootstrap artifact; focused green3 PASS.
At2eee, committed operations **61 PASS** and genuine PHP runtime **13/63 PASS** are
in `codex-repair/final-{ops,runtime}-2eee8d42.txt`. Independent native PHP8.4.26 and
Composer2.8.8 actual `@php` event execution: old-source1 FAIL, repaired1 PASS; exact
frozen bytes/private mode precede npm, and inherited APP_ENV does not authorize it.
The hook is a minimal owned PHP app, not actual Laravel package discovery; npm and
final admission are substitutes. Independent `COMPOSER-ORDERING-ADDENDUM.md` APPROVES
exact2eee and carries earlier reviews only on unchanged paths. Six correct Bash
scripts parsed separately, Shellcheck and whitespace pass; an initial command named
a nonexistent pipeline helper and exited127 before the corrected selection passed.
Final publication/gate identities are in the checkpoint table. No host build or
provider purchase was executed.

## Public links and backup credentials after the last review

Codex on `9e6b7fd5` added 4226801376 and4226801382. Exact functional
`b72c8efcb48ea433c58659b824ad32d4c174bf6d` rejects any symlink resolving to the
protected `.env`, and any link in or at `public` resolving outside the public tree.
Seal and readonly verify use the same predicate; ordinary public asset/directory
aliases and nonpublic internal code aliases remain valid.

Backup provisioning requires a finite root-owned0600 single-link exact generated
five-line literal profile before using it. Missing/truncated/unsafe files refuse;
missing-account retries never overwrite orphan profiles, generation is validated,
and persistence is no-clobber. Before grants, a scrubbed MySQL client with exactly
one admitted defaults file, login paths disabled and a bounded connect timeout must
return exactly `vasey_backup@127.0.0.1`. Private restore/explicit account recovery is
required on refusal, with no automatic rotation. App authentication is added in the
following source381; no actual-host credential has been tested.

Owner old-source red: 67 methods/17failures/1error (the absent authentication trace
causes an index lookup error, not a host/tool setup problem); repaired precommit and
exact committed source67 PASS. Genuine PHP runtime13/63 and Bash6/Shellcheck/whitespace
PASS atb72. Independent public/backup approval, current native authentication evidence
and publication carry are recorded in the verification directory and checkpoint table.
Independent `PUBLIC-BACKUP-ADDENDUM.md` APPROVES exactb72: **5 methods PASS**;
old9e6 has32 failing states (12 links,20 custody/authentication/creation).
Genuine MySQL8.4.11 client/server authentication accepts the correct private
generated credential, rejects a wrong password and rejects an independently
authenticated `vasey_backup@localhost` identity. Testing schema/unique disposable
helper datadir/empty socket/loopback3306 are guarded; root ownership is modeled
and native libraries use a fixed toolchain adapter after env scrubbing. Generated
private files/server are cleaned; no secrets enter receipts. The reviewer PATH
fixture mistake and initial native passing receipt are retained, with whitespace
normalization disclosed. Actual Forge authentication, grants, backup/restore/shipping
and provider purchase remain pending.

## Persisted app credential authentication at the final source

Codex on `444131c5` added 4226910300: a well-formed stale app password passed file
custody. Exact functional `381d116bbea46f9f3dd5a7bc05f80b147d77ca8e` now authenticates
before grants using a private0600 temporary client defaults file under protected
secrets ancestry. The client uses a scrubbed environment, disabled login paths, TCP,
a five-second connection timeout and exact `vasey_app@127.0.0.1` identity. A subshell
EXIT trap removes the temporary file and preserves the original result; cleanup
failure blocks grants. Passwords remain outside process arguments/environment, and
existing account credentials are never automatically rotated.

Owner old444 red: **3 methods /3 failing states**; repaired focused3 PASS. Exact381
operations **68 PASS**, genuine PHP8.4.26 runtime **13/63 PASS**, Bash6 separately,
Shellcheck and whitespace PASS. Independent `APP-CREDENTIAL-AUTH-ADDENDUM.md`
**APPROVES exact381**: one method passes five states (correct, failed, wrong user,
wrong host, forced cleanup failure), old444 has four failures. Genuine guarded
MySQL8.4.11 accepts the correct private app credential, rejects a stale password and
rejects a raw-client-proved authenticated `vasey_app@localhost` identity. Temporary
client files are removed after all three ordinary outcomes. Root ownership is
modeled; native authentication, file modes/bytes and ordinary cleanup are genuine.
Private fixture/server files are cleaned; no credentials enter receipts. Actual
Forge authentication/grants, SIGKILL cleanup, shipping and provider purchase remain
unproved. The reviewer explicitly carries approval to publication
`d7e60167037c04e3e5c26549153027a25c7d0fb2` after an independently checked empty full
source diff excluding only docs. Final publication gates are in the checkpoint table.

## Administrator defaults file custody at the final source

Codex on `d7e60167` added 4227010176. Optional `--mysql-admin-defaults` now requires
an absolute canonical path, protected root-owned ancestors without group/world
write, and a root-owned0600 regular single-link file without symlinks. The check runs
before host changes/packages or the first MySQL query. The default root socket path
is unchanged. The check never sources, changes permissions or repairs the file.

Exact functional `fd9dd035dc9069db3727bd12960bb5952eb28399`: owner oldd7 red
**3 methods /14 failing unsafe states**; focused3 PASS; committed operations
**71 PASS**, genuine PHP runtime **13/63 PASS**, Bash6 separately/Shellcheck/whitespace
PASS. Root ownership and MySQL authority are bounded fixtures; file modes, paths,
links and rejection before the traced first query are real. Independent `ADMIN-DEFAULTS-ADDENDUM.md` APPROVES exactfd9: **4 methods /25 states PASS**, including22 refusals and3 admitted states, on the actual complete CLI prelude and first-query construction; oldd7 retains22 unsafe-admission failures.
The reviewer explicitly carries approval to publication `c378295c16310a64ff4f729d7d6480b6778fb998`: full diff excluding docs, CHANGELOG and ops README is empty; the latter two contain only inspected prose-spacing changes. Actual Forge admin credentials/custody and host execution remain pending. Earlier
native authentication/transport receipts retain their explicit older source identities.

## Runtime directory initialization at the final source

Codex on `c378295c` added 4227097077: root `install -d` follows directory symlinks
that the app can place below its writable private/tmp trees. Exact functional
`69a405a6480edd031aad9f40e9f83c32e8adcc24` uses no-follow directory opens for every
component, retained parent descriptors for relative child creation/open and only
`fchown`/`fchmod` on the opened child. It covers evidence, tmp/php-*, private/branding
and nginx FastCGI paths. A detected directory entry/inode mismatch refuses; a post-open replacement cannot
redirect those metadata changes to its external link target.

New private `.gitignore` is exclusively created no-follow through the private
parent descriptor; writes and metadata use the new inode. Existing ordinary
single-link files remain unchanged; links and special files refuse without touching
their targets. Failure may leave partial changes inside admitted runtime directories.
Pinned-inode safety does not establish that concurrent app renames leave a healthy
visible path or certify an uncompromised host.

Owner immutable oldc378 red: **3 methods /12 failures /1 new-only race skip**;
current **3 methods /15 states PASS**, including a real post-open rename/link and
unchanged external fixture. Old GNU install behavior genuinely changes owned
external modes or writes through a dangling link; privileged ownership is modeled
with the fixture UID, and the fixed nginx parent is redirected to an owned prefix.
Exact committed ops **74 PASS**, genuine PHP runtime **13/63 PASS**, Bash6 separately,
Shellcheck and whitespace PASS. Independent `RUNTIME-DIRECTORY-ADDENDUM.md` APPROVES exact69a: **6 methods /18 filesystem states PASS** plus the real stage-call assertion, including before-open and post-open replacements. A .gitignore replacement after its exclusive open may leave a different visible entry while only the captured new inode receives writes/metadata; this is inode safety, not persistent path health. Old independent c378 red has12 failures/2 new-only skips; its initial7 failures are separately retained. Final publication `78b989f6ffd5166e92db1f25df584bd761c447cf` contains evidence and inspected ops README wording only, with empty product diff excluding docs and ops README. Actual Forge root/services remain unexecuted; historical native authentication/transport retains its source.

## Remaining ordered code queue — none was completed during this checkpoint

### 1. PR #56 Paid252: independent addendum 15, integrate current main, merge

Remote `harness/paid252-composition` @ `27a151b66d10b80d07d54685791a2a3d0efd44dc`. Original addenda1–14
approve development merge with conditions. Read `docs/verification/paid252-composition-20261007/independent-review/`
`DECISION.md`, `ADDENDUM-13.md`, `ADDENDUM-14.md`, and `hardening/codex-23/README.md`.

Write independent **addendum15 for `55976099..27a151b6`**. Round23 introduces a final/private-constructor
`PaidGrantRequestInstant` containing readonly Carbon time: capture validates the SAPI request start once
before identity; redeem does not re-age it after a slow proof. Check finite number/clamp/fallback, header
non-authority, immutable typed admission, expired-request refusal and C11(k) identity/lock-wait budget.
The source delta is three PHP product files and request-instant tests plus docs. Original retained red
has 6 tests with 1 failure/1 error; recorded green is 7/129, historical family136/4540 with one native-only
skip. The slow-proof test **advances the test clock through a transaction hook**, not an actual 65-second
FPM/DB-lock wait; document that boundary.

This Codex session only read the delta and prepared a detached worktree with physical dependencies. It
did **not** run or write addendum15. There is no surviving draft reviewer probe to trust.
Integrate current main plus this handoff ledger into the branch without undoing #65/#62/#63 or the cheap
CI policy. Resolve CHANGELOG/census conflicts as unions. Run current focused affected tests, independently
review the actual tested integrated commit (carry original-range decision only where source-equivalent),
check Codex/preflight, merge with expectedHeadSha, then immediately record ledger/status.

One deliberately open Codex thread remains: `PRRT_kwDOU5febs6qfw0X`, top comment4222614269,
`PaidGrantTransfer.php`, blocked downstream writes holding global spool slots. This is documented C11
pre-mount runtime verification, default-off/unmounted, not an unreported clean review. Do not resolve it
by assuming a host is configured. Conditions C2, C4–C8, and C11 host verification including(k) remain;
C1/C3/C9/C10 resolved or met, C12/C13 met. Development merge does not mount paid routes or grant live
activation. Membership CP-2/CP-3/P1B-1 and the unstarted operative257 credit writer remain separate.

### 2. PR #60: union SQLite census after #56

Remote `harness/census-normalization` @ `247ee29712d1c60363d062a5b5243771b71a9a13`. Original branch adds33 pairs, 183→216.
After #56 preserve its extra `PaidGrantSchemaRecoveryTest::test_native_schema_global_exact_case_accent_fk_and_routine_identities_refuse_before_first_owned_ddl`
pair in `scripts/ci/database-sqlite-skips.json` as a union with append order intact (expected217 unique pairs;
derive from actual merged files). Run `python3 -I scripts/ci/test-database-receipts.py`, required cheap checks,
Codex, expected-head merge and ledger/status. Do not invent a full SQLite census pass from this self-test.

### 3. Prove and land embed order leak

Remote `harness/embed-order-leak` @ `9b51c64127b4a62097cf805112ce3388a38a5824`. Candidate is `Livewire::flushState()` in
`tests/TestCase.php::setUp()` for static `SupportAutoInjectedAssets` leakage. Run an actual ordered selection
with a real Livewire test before `PublicTrackEmbedTest`: red without candidate, green with it. Passing embed
alone is insufficient. Integrate main, update evidence/CHANGELOG, focused PR/preflight/merge/ledger. It must
land before final Foundation. No ordered proof was run in this Codex checkpoint.

### 4. M-16 PHP CLI resolver

Remote `harness/php-cli-resolver` @ `53f8ef6005426481f512f6147aa3a815bebed2ce`. Under real FPM, `PHP_BINARY` is php-fpm, which is not a CLI
renderer process. WIP adds `App\Support\PhpCliBinary`, an explicit `VASEY_PHP_CLI_BINARY` resolver/probe,
generic `IsolatedContractRenderer` wiring and tests. It is not verified or reviewed. Inspect realpath,
executable, genuine `cli` and exact PHP_VERSION validation, scrubbed env, bounded subprocess output/time,
cache identity and failure privacy.

**Do not edit/re-pin frozen V1 files** in `resources/contracts/*/profile-assets.json`. Earlier handoff
suggested editing pinned Free/ProductionFree/Paid process classes; that is not an acceptable shortcut.
Useful verified-by-source-inspection extension: each pinned renderer already accepts optional
`?Closure $processFactory`. Laravel call sites obtain these objects through the container. A potential
unfrozen provider binding can inject a process factory replacing command[0] with the validated genuine
CLI and deriving CLI-specific trusted library paths, while preserving command flags/environment isolation,
input, process limits and pinned profile verification. This is **a proposal, not implemented or tested**.
Check all four renderer families including Paid after #56, run actual FPM→CLI smoke, verify every pin,
independent licensing review, PR/preflight/expected-head merge/ledger.

### 5. B2 staging environment admission

Remote `harness/staging-env-admission` @ `2ec1c559298167851bad81a806ba1d73f7602a6a`. WIP spans56 files (~1116 lines), broad test-commerce
environment policies and staff TOTP. It is unverified. Integrate current main preserving D1 and both
staging kits. Finish coherent docs/env/template/runtime admission transitions from accepted interim
`APP_ENV=local` to `staging`; require server-enforced staff MFA and refuse live mode. Run affected commerce
and auth suites, adversarial tests, independent auth/payment review, Codex/preflight/merge/ledger.
Explicitly verify D1 rights-scope commands and PrepareOrder under staging after integrating B2. Existing production/membership/paid lanes remain default-off/unmounted unless separately authorized.

### 6. Final integration / host rehearsal

Run a single complete manual Foundation on the exact reviewed integrated40-character `expected_sha`
once the queue is complete. Diagnose actual failures using focused reproduction before another full run.
Never restore automatic full matrices or weaken guards/audits. A focused development merge is not full
acceptance. Host M-01/M-05/M-07/M-11/M-13 and export M-09/M-10 remain to be executed with evidence when
Sean supplies the necessary inputs. Do not claim Monday readiness from these templates.

## Inputs still blocking — MONDAY-PLAN §4

| Input | Current state / action |
| --- | --- |
| S-2 | Forge/DigitalOcean VPS and SSH access expected tomorrow Oct10; not supplied. No provisioning or purchase of accounts/servers was performed. |
| S-3 | Staging subdomain/TLS/basic auth chosen; exact hostname unanswered. An async question asking exact hostname was sent, not answered by checkpoint. DNS waits for VPS IP and Sean's action/authorization. |
| S-4 | TEST Stripe account ID, test key, webhook secret or secure host CLI login missing. Ask for secure host entry, never chat/repo secrets. |
| S-5 | Second staff account for independent license approval missing. |
| S-6 | Seller tag WAV missing; publication depends on it. |
| S-7 | 3–10 actual tracks, metadata, rights references, WAV≤200MiB, artwork≤20MiB, optional stemsZIP≤200MiB missing. Never fabricate catalog or rights. |
| S-8 | Authored license tiers/terms/USD prices, seller legal name and assent missing. Existing profile values are templates/fixtures, not Sean approval of legal/commercial terms. |
| S-9 | BeatStars export missing; private upload only. No importer/normalizer/dry-run work was completed in this session. |
| S-10 | Encrypted off-host backup destination missing; backups include environment/key. Local backup is not off-host protection. |

S-1 interim local private mode was accepted; B2 remains target. S-11 optional approved logo/site copy.
Before real testing, confirm drill offers vs unpaid-release policy (abandoned unpaid orders retain inventory),
the roughly10-minute payment window, test pricing/zero-tax window, and test-customer-account policy. Late
payments still become exceptions; do not silently stretch the observation cutoff.

## Current workspace/tooling (environment-specific, not committed dependencies)

At checkpoint the root repository is `/workspace/VA-Studio`; worktree paths are listed below. Local
`vendor`, `.env`, tools and generated artifacts are not Git payload. If Claude uses another container,
install genuine locked dependencies/tool versions there; do not assume these paths persist or spoof PHP
SAPI/version/extensions. Never print `.env` or credentials while rebuilding the harness.

| Path | State |
| --- | --- |
| `/workspace/VA-Studio` | handoff branch, clean after commit/push |
| `/workspace/VA-Studio-ops` | merged #63 final head, clean; independent evidence all committed |
| `/workspace/VA-Studio-commerce` | merged #62 head4902c45e, clean |
| `/workspace/VA-Studio-rights` | merged #65 head8453909e, clean |
| `/workspace/VA-Studio-rights-red` | detached old red3aad7d15, retained; its untracked `tests/Feature/RightsScopeCommandTest.php` is the earlier regression fixture, preserve it |
| `/workspace/VA-Studio-paid252` | detached27a151b6, read-only prepared; physical vendor copied excluding package .git directories for renderer open_basedir; no source changes |

`source /workspace/.va-studio-toolchain/activate.sh` activates PHP8.4.26, Composer2.8.8, Node24.19,
PHPUnit12.5.34. Genuine portable CLI: `/workspace/.va-studio-toolchain/standalone/bin/php8.4`.
Artisan's Collision test runner is broken in this harness; use the actual PHPUnit entry point:

```sh
/workspace/.va-studio-toolchain/standalone/bin/php8.4 -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --do-not-cache-result <selection>
```

Contract tests need physical worktree vendor packages because renderer `open_basedir` rejects external
symlink targets. `scripts/dev/mkworktree.sh <path> <rev>` starts with symlinked packages; copy actual packages
excluding bulky package .git dirs if a renderer selection needs physical dependencies, retaining worktree
composer metadata/autoload. It creates detached worktrees; attach the intended branch explicitly.

Genuine rootless MySQL8.4.11 helper (generates private temporary credentials and cleans up):

```sh
export MYSQL_TEST_BASEDIR=/workspace/.va-studio-toolchain/mysql84
export MYSQL_TEST_LIBRARY_PATH=/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64:/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64/mysql/private
export MYSQL_TEST_PORT=33067
bash scripts/dev/with-mysql-test-server.sh <command>
```

Do not apply the MySQL-image LD_LIBRARY_PATH globally to PHP (breaks PHP/gd); scope it to native clients.
Native nginx1.26.3/FPM8.4.26/Shellcheck0.10 are under `/workspace/.va-studio-toolchain/root/usr/`.
Loopback/network may need sandbox network permission; Sean authorized setup/sandbox requests.
Parse each Bash script separately (`bash -n file1 file2` parses only file1). Inspect Pint JSON even if its
wrapper exits0. Root git fetch refspec was main-only: fetch WIP branches explicitly before inspecting them.
Use real command exit output as evidence; retain red/failed harness attempts separately.

## Closure rules

One focused PR per item; actual local affected tests, red first fixes, required cheap preflight; independent
review for payment/rights/auth/licensing/migrations on the actual tested commit. Evidence-only successor
carry requires empty product-source diff. Check final review head and unresolved threads; a generic Codex
comment alone is not evidence of no findings. Merge with expectedHeadSha and update both ledgers immediately.
Do not delete paused worktrees, weaken repository protections, edit frozen V1 assets or claim external
activation. Keep the deliberate Paid252 host condition visible. Start with #56 addendum15.
