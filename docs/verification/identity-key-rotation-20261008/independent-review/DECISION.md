# PR #57 independent review: production identity APP_KEY rotation

**Reviewer:** Claude, independent reviewer for authorization, identity and evidence integrity (not the author).
**Reviewed head:** `f59f9b827ef21832e00e627c55c739ee37710719` (detached worktree `/home/user/VA-Studio-review-idkeys`).
**Code under review:** `git diff 705a712e...72e58022 -- app tests`. The merge `f59f9b82` only brings in main `f7224ed3` (Free256).
**Date:** 2026-10-08. **Scope:** a development merge of default-off families. This is not production acceptance and not Foundation CI.

## Verdict: APPROVE WITH CONDITIONS (development merge)

The authorization design is sound. No path lets a stale or rotated credential, session marker or principal regain access:

- A digest made under a key that is not configured never verifies.
- Removing a key stops it verifying at every site.
- Every candidate loop is constant-time across keys.
- With no previous keys, the SQL is byte-identical to single-key main, verified with a MySQL general log (§2).

**Merge blocker (C1):** at this head the Free256 frozen-bytes test is red. It must be updated in the same merge (ruling in §5). One Medium finding (F1, the mixed-configuration fence lockout) is acceptable for a development merge, but it must be resolved or covered by a runbook before production use.

### Conditions before the development merge

- **C1.** Update the two pins in `tests/Feature/ProductionFreeGrants/ProductionFreeGrantFrozenBytesTest.php:28-29` to the hashes in §5. At `f59f9b82` this test fails: `review-evidence/sqlite-07-ProductionFreeGrants.txt`, 158 tests, 1 failure, rc=1, and the failure is exactly this pin. Merging without the update breaks main.
- **C2.** Record F1 and F4 in the PR / verification README as open production conditions. The PR's README already lists F4 partly and F5.

## Findings

### Medium

**F1. A mixed-configuration window creates two address fences, and every identity request for that address is then refused.**

Sites:
- `app/Domain/Customers/ProductionIdentity/IdentityRequests.php:43-51`: `candidates()` returns 2 rows, so `count($addresses) !== 1` throws `IdentityException` (422).
- `app/Domain/Customers/CustomerIdentityChallenges.php:180-194`: `fence()` returns null, so no challenge and a generic acknowledgement.

How it happens: during a rolling deploy or rollback, one process has only the old key and another has the new key with the old key listed. Each finds no fence under its own candidates for a never-seen address, so each inserts its own. The README says ambiguity is "reachable only from data written before this fix", but that is not the only route.

Who can trigger it: anyone who knows the address and sends requests that hit both process generations during the window. No key is needed.

Effect: enrollment and recovery for that address are refused for as long as both keys are configured. Sign-in of an already-enrolled customer is not affected (it uses no fence). This fails closed and grants no access. It is still a per-address account-recovery denial with no self-service repair.

Reproduced:
- SQLite: `test_mixed_configuration_window_creates_two_fences_and_then_refuses_every_request_for_the_address`.
- Native MySQL with real separate processes: `test_mixed_configuration_processes_create_two_fences_and_the_address_is_then_refused`, evidence `review-evidence/native-mixed-configuration-two-fences.json`.

Before production, do one of the following:
- (a) A rotation runbook that drains every old-config web and queue process before admitting traffic, rollback included. A rollback must keep the new key in `APP_PREVIOUS_KEYS`.
- (b) A deterministic, reviewed resolution instead of refusal, for example the fence that an origin or the oldest challenge references, plus an operator repair command.

### Low

**F2. Wider gap-lock footprint per lookup.**

Each lookup now also takes the gap lock for the absent current-key position. Observed natively while waiting: `X` on `supremum pseudo-record` in `pia_address_unique`, next to the exact `X,REC_NOT_GAP` fence record. There is no table lock and no PRIMARY range (`review-evidence/native-two-candidate-recovery-wait.json`).

After rotation, every recovery for an existing customer therefore briefly blocks inserts of new fences (and of `request_hash` challenges) whose hash falls in the same gap.

- No new deadlock class was observed: `lock_deadlocks` delta was 0 for the two-candidate wait and over 4+4 rounds of concurrent first requests, single-key versus two-candidate (`native-concurrent-first-requests.json`).
- Lock order is identical across processes with the same configuration (current key first).
- A reversed order, as in a rollback configuration, only pairs gap locks, which are mutually compatible, with record locks on an existing fence. So it cannot deadlock on the fence alone.
- The pre-existing insert-race deadlock class (lookup gap, then insert) is the same as main and is retried by `transaction(..., 5)`.

**F3. Suppression after rotation can submit the same address a second time.**

A new withdrawal under the new key carries a new-key `recipient_hmac`. `ProductionSuppressionIntents.php:53-77` matches targets by byte equality (line 56), and the schema triggers require equality (`ProductionSuppressionSchema.php:389-392`). So a second target and a second provider attempt are created for the same address.

Confirmed in `ReviewSuppressionKeyRotationTest`: 2 targets and 2 provider calls with the same recipient. A retained pre-rotation withdrawal reuses its original target with no resend.

Compliance assessment:
- A withdrawal is never left unhonoured. The consent status still reads `withdrawn` through `recipientMatches()`, and a removed key makes reads refuse or return `unknown`, never `granted`.
- Duplicate suppression is idempotent over-suppression.
- Residual: `ProductionSuppressionRows::LIMIT = 8` targets per binding (line 62 `count($targets) >= LIMIT` throws). Each rotation followed by re-withdrawal consumes one. After 8, a new withdrawal's provider suppression cannot be recorded, although the consent state stays withdrawn.

This is acceptable for a development merge of default-off families. Production condition: Sean's decision on per-rotation targets, or matching targets through `recipientMatches()` with the captured email.

**F4. Key-length asymmetry.**

The current key is accepted at any non-empty length (`IdentityPolicy.php:91-93`), but previous keys must be at least 32 bytes (`IdentityPolicy.php:98`). Free256 requires a current key of at least 32 bytes (`ProductionFreeGrantRecords.php:56`).

A short current key's digests become unverifiable once it rotates into `previous_keys` (shown in `test_previous_key_order_and_duplicates_do_not_change_verification_or_the_selected_fence`). Laravel's AES-256 encrypter rejects such keys in practice, so this is practically unreachable. Recommendation: require at least 32 bytes for the current key too, in a follow-up.

### Informational and production conditions

- **F5. Compromise-driven rotation is unsupported.** Keeping a compromised key in `previous_keys` keeps forgeries under it verifiable (given database write access). Removing it permanently refuses every identity, consent and evidence row it wrote, because no re-keying or re-enrollment exists. This is documented in the PR README. It needs a design before production.
- **F6.** `IdentityPolicy.php` and `IdentityEvidence.php` carry the semantic change but are not among Free256's frozen pins. Consider pinning `IdentityPolicy.php` when C1 is applied (optional).
- **F7.** `SupportAttachmentRequestContext::configuration()` and `ConfigurationBoundIdentityTransport` close `app.key` but not `app.previous_keys`. Only an in-process configuration mutation could change `previous_keys` between mint and prove, and a removed key fails closed. No action needed.

## Review items

### 1. Authorization soundness

- **`keys()`.** Current key first; an absent or empty current key throws `IdentityException`; previous keys must be strings of at least 32 bytes and are deduplicated. `IdentityPolicy::digest/matches/digests` and `CustomerIdentityChallenges::keys()` (which maps the failure to `CustomerAccessException`) all derive from it.
- **Constant-time comparison.** `matches()` (`IdentityPolicy.php:113-120`) and `CustomerIdentityChallenges::matches/issuedProof` evaluate `hash_equals(...) || $matched` with the HMAC on the left, so every key is computed and compared.
- **Unconfigured key.** A forgery under an unconfigured key is refused: the PR's tests plus `test_evidence_reforged_in_the_database_under_an_unconfigured_key_is_refused_everywhere`, where a database-level forgery of `credential_binding` and `observation_hash` under K3 refuses `principal()`, `authenticate()` and recovery requests. It verifies only when K3 is configured, which is key membership, not a bypass.
- **Removed key.** Removal refuses everywhere: identity (PR tests), Free256 (`ReviewFree256IdentityRotationTest`: index, show, authorize, redeem, accept and session admission all return `identity_refused`, with no row written), consent and suppression.
- **Current-key-only sites stay current-key.**
  - Session marker (`ProductionCustomerPrincipal::sessionBindingDigest`; the diff is docblock-only).
  - The principal's private digests (`matchesPrivate`) and `read()`'s projected `owner_digest` / `credential_binding`.
  - A principal minted before rotation is refused by `current()` and `lock()` (`test_principal_minted_before_rotation_is_refused_by_lock_and_current_after_rotation`).
  - Rotation between `lock()` and `proveCurrent()` is refused in both directions (`test_rotation_between_lock_and_prove_current_refuses`).
- **Any-key sites.** These verify stored evidence only: owner, credential and recipient bindings, evidence hashes, proof, payload, completion, lease and consent recipient. Under rotation, a pre-rotation bearer proof still completes within its 600 s TTL, which is acceptable because rotation is not revocation.
- **Projected digests.** I found no site that persists the projected current-key digests and later compares them with stored evidence. Searched: `['owner_digest']`, `['credential_binding']`, `matchesPrivate`, and the consumers of `verifyHistoricalBinding`.

### 2. Candidate lookups

- **Exact reads.** `IdentityRequests::candidates()` (`:153-164`) runs one exact `= ?` locking read per candidate, in key order, and merges by id.
- **`IN (...)` rejection.** The author's evidence is consistent with what I observed: the waiting worker holds only IX, the exact fence record, and the absent-candidate gap.
- **Serialization.** Two concurrent two-candidate recoveries serialize on the one old-key fence: worker 1 waits on the PRIMARY record held by the parent, worker 2 queues on the fence's unique-index record. Both are saved, there is 1 fence and 2 challenges, each challenge's `bound_credential_binding` equals the enrollment binding, and the deadlock delta is 0.
- **Lockout abuse.** Who can create a second matching row? Only application code inserts fences and challenges, the unique index forbids duplicates of one hash, and with one consistent configuration every process finds the existing fence under any key before inserting. A second row therefore needs either pre-fix rotation data or mixed configuration (F1). An outsider cannot compute a second-key digest without the key.
- **Zero previous keys.** The candidate loop runs exactly one `rows($table, '<col> = ?', [$hash], 1)`, the same arguments as main's single statement. The insert and re-read are unchanged, and `IdentityRows::rows` builds the same SQL text. This was verified against the MySQL general log in `review-evidence/mysql-04-sql-shape-zero-previous-keys.txt`. The scenario was: enroll, exact replay, completion, recover, a second address, and a legacy `CustomerIdentityChallenges::request`. It was run once with the head code and once with the two files restored to their `705a712e` bytes. Both runs produced 6932 statements. No production identity lookup line differs, and after masking the random 64-hex and base64 literals the diff is 0 lines. Two behavioural differences remain, neither reachable in a consistent database:
  - A re-read returning no row now throws `IdentityException` where main hit an undefined-index error.
  - `CustomerIdentityChallenges` with one key takes the original `insertOrIgnore` + lock path unchanged (`fence()` only branches when there are more than 1 keys).

### 3. Recovery `bound_credential_binding` copy

`IdentityRequests.php:93` copies `$observations[array_key_last(...)]['credential_binding']`. This is the same row that `recoverable()` (`:142-145`) just verified with `matches('credential', $user['password'], ...)`, under the fence lock, in the same transaction. The copy happens only when `$available` is true, so it cannot bind a recovery to any credential other than the one just verified.

At completion, `CompleteIdentity.php:115` re-verifies the binding against the current password. `historical()` (`ProductionCustomerAccess.php:181`) still compares it byte-for-byte with the prior observation.

- `test_copied_binding_is_the_last_verified_observation_and_a_changed_credential_refuses_the_other_pending_recovery`: two pending recoveries; after one completes, the other is refused even though `access_version` is unchanged, so the binding is the load-bearing guard. A later recovery copies the new current-key observation.
- `test_historical_byte_comparison_refuses_a_bound_binding_recomputed_or_relabelled_under_another_value`: with every row carrying valid current-key evidence, a bound binding equal to the pre-fix recomputation, or an old-key binding of a different credential, is refused by `historical()`. Restoring the bytes restores access.

### 4. Consent and suppression

The `recipientMatches()` sites are `ProductionConsentRecords:51`, `ProductionConsentWithdrawal:96`, `ProductionSuppressionRecords:51` and `ProductionConsentPreferences:185,195`. All are any-key verification of stored digests, and new events are written with the current key only. On a removed key, `event()` throws, and a status mismatch reads `unknown`: fail closed, never `granted`. The duplicate-target behaviour is analysed in F3: it is not a correctness or compliance risk now, and it is acceptable for a development merge.

## 5. Free256 frozen-bytes ruling (required)

**Re-review against Free256.** `ProductionFreeGrants::customerCommand` (`:204-225`) runs: `lock()`, then `durableBinding()`, then the provenance and principal match, then `$work`, then `proveCurrent()`, mapping `IdentityException` to `identity_refused`. The other Free256 uses go through `customerCommand` (Library index/show, Downloads authorize/redeem, Grants review/accept) or through `ProductionFreeGrantRequestIdentity::from`, which uses `ProductionCustomerSessions` (unchanged and still pinned).

- **`ProductionCustomerPrincipal.php`.** The change is docblock-only. Executable bytes are identical, so the principal-match contract (`matchesPrivate`, `durableBinding`, `sessionBindingDigest`) is unchanged.
- **`ProductionCustomerAccess.php`.** The only executable change replaces `hash_equals(stored, digest(current))` with `IdentityPolicy::matches(...)` at:
  - origin `owner_digest` (`:151`, `:233`);
  - last observation `credential_binding` (`:272`);
  - last observation `recipient_hmac` (`:273`), where the local `$recipient` now holds the normalized email instead of its digest; it is not part of the returned proof.

  With one key, `matches` is exactly the old `hash_equals`, so `lock()`/`read()` return byte-identical raw arrays and Free256's `proveCurrent` comparison is unchanged. With rotation, both sides of `proveCurrent` and `match()` are current-key projections from the same process. A rotation mid-command, or a stale pre-rotation principal, is refused and still surfaces as `identity_refused` (`test_rotation_inside_a_customer_command_after_lock_is_identity_refused_by_prove_current`, `test_customer_commands_after_rotation_...`). No new exception type can escape: `matches()` and `keys()` throw only `IdentityException`.
- **`durableBinding`.** It is key-independent, because it contains the stored `observation_hash` and the canonical policy hash. It is identical before rotation and after re-sign-in (asserted in the review tests). Free256 origins keyed by account remain readable.

**Ruling:** Free256's lock, proveCurrent, durableBinding and principal-match contract, including the identity-refused mapping, holds unchanged. **The two pins may move**, in this merge, to these exact sha256 values at `f59f9b82`:

| File | Old pin (fad3ab44) | New pin (f59f9b82) |
| --- | --- | --- |
| `app/Domain/Customers/ProductionCustomerAccess.php` | `7dbaeed358ab1051dd34608fbf966645ae7756f163700733bf9708f1233aba42` | `f0fc0b70a058954ecc0c2853b0836af547b5f4cd4654d1ba71116a03d18b20f7` |
| `app/Domain/Customers/ProductionCustomerPrincipal.php` | `ab0ddc7dcabb41fd8d003582c9a78814a90f5cd4fa80be4bc2c5208192306b0c` | `d49b587d1a4c22bda4797b90c65d15ee0e41f3fb3a01dda5a599bb817425457b` |

Every other pinned file is byte-identical at this head. Any further change to either file needs a new review against Free256.

## Evidence (`review-evidence/`, rc on its own line in each .txt)

Runner: `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <path>`. Each file header records the source HEAD, the `app`/`tests` diff hash (empty, so tracked sources equal `f59f9b82`), the untracked review tests present, uptime and the command.

**SQLite runs**

| File | Suite | Result |
| --- | --- | --- |
| `sqlite-01` | `ProductionIdentityKeyRotationTest` | 10/10, rc=0 |
| `sqlite-02` | `ProductionConsentKeyRotationTest` | 2/2, rc=0 |
| `sqlite-03` | `CustomerIdentityKeyRotationTest` | 2/2, rc=0 |
| `sqlite-04` | `ProductionIdentityAdapters` | 58 tests, 2 skipped, rc=0 |
| `sqlite-05` | `ProductionIdentity` | 66 tests, 8 skipped, rc=0 |
| `sqlite-06` | `ProductionFeatures` | 89 tests, 3 skipped, rc=0 |
| `sqlite-07` | `ProductionFreeGrants` | 158 tests, **1 failure = FrozenBytes pin (C1)**, rc=1 |
| `sqlite-08..11` | `CustomerIdentity{Concurrency,Http,Migration,}Test` | rc=0; Concurrency is 9 MySQL-only skips |
| `sqlite-12` | `ReviewIdentityKeyRotationAdversarialTest` | 8/8, rc=0 |
| `sqlite-13` | `ReviewFree256IdentityRotationTest` | 3/3, rc=0 |
| `sqlite-14` | `ReviewSuppressionKeyRotationTest` | 1/1, rc=0 |

**Native runs.** Private `/usr/sbin/mysqld` 8.4.11 on `127.0.0.1:42611`, `--no-defaults --mysqlx=OFF`, scratch datadir:

| File | Suite | Result |
| --- | --- | --- |
| `mysql-01` | `ReviewIdentityKeyRotationNativeRaceTest` | 3/3, rc=0, plus the `native-*.json` lock shapes and deadlock counters |
| `mysql-02` | `ProductionIdentityNativeRaceTest` | 6/6, rc=0 |
| `mysql-03` | `CustomerIdentityConcurrencyTest` | 9/9, rc=0 |
| `mysql-04` | SQL shape, zero previous keys, base vs head | identical statement text, rc=0 |

The private mysqld was shut down cleanly with `mysqladmin shutdown`. The process is gone, port 42611 is closed, and both the scratch datadir (`scratchpad/review-idkeys-mysql`) and the socket directory (`/tmp/claude-0/rvidk`) were deleted. Other agents' daemons were not touched.

Two first attempts of review tests failed because of bugs in the review tests themselves: an arrow-function by-reference capture, and a wrong assumption that the second worker would wait on PRIMARY. Both were fixed before the recorded runs, and the product code was unchanged. The debug output showed the true lock queue, which is recorded under F2.

**Adversarial tests kept in the worktree (untracked, not committed)**

| File | sha256 |
| --- | --- |
| `tests/Feature/ProductionIdentity/ReviewIdentityKeyRotationAdversarialTest.php` | `52325724…` |
| `tests/Feature/ProductionIdentity/ReviewIdentityKeyRotationNativeRaceTest.php` | `7c5b1c89…` |
| `tests/Feature/ProductionFreeGrants/ReviewFree256IdentityRotationTest.php` | `6e3fca71…` |
| `tests/Feature/ProductionSuppression/ReviewSuppressionKeyRotationTest.php` | `d61d5f5f…` |
| `tests/Support/review-identity-rotation-race-worker.php` | `4ba543cb…` |

## Untested

- Real deploys, queue-worker restarts and HTTP-level rotation.
- Foundation CI and browser tests.
- Rotation with more than two keys under native concurrency.
- The suppression `LIMIT` exhaustion path.
- The legacy identity `CustomerAccess::stamp()` replay after rotation, which is out of scope and noted by the author.

SQL-shape check status: complete (`mysql-04`).
