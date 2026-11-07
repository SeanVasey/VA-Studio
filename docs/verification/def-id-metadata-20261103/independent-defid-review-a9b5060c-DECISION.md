# DEF-ID independent security review — `a9b5060c`

Reviewer: independent Claude subagent, 2026-11-04. Lane DEF-ID, branch `claude/def-id-20261104` (= `a9b5060c`),
source `2bed2004` on main `048244a6`, test-only fix `a9b5060c`. Packet `docs/verification/def-id-metadata-20261103/README.md`.

## Decision: APPROVE WITH CONDITIONS

No P0/P1/P2 code finding. No existing guard is weakened. The refusals are complete for the five stated surfaces. The
conditions are evidence and documentation items. None of them needs a source change:

1. Run the packet's native MySQL selection (held-frame/commit, write-commit, account raw-config refusal) against the
   exact merge SHA before treating this as more than SQLite shape evidence (AGENTS.md: SQLite does not prove MySQL).
   The MySQL-only facts are untested here: `Pdo\Mysql`/`MySqlConnection` admission on the `CommandTransaction`
   terminal path and plain-ness of production MySQL `database.*` options.
2. Update the packet with the actual commands and outcomes (P3-4). It still says every check is UNRUN and names the
   Codex branch and worktree.
3. Merge `a9b5060c` (or a descendant that changes only the reviewed files). The review worktree `/home/user/wt/defid`
   is checked out at `c0f992e0` on `claude/defid-native-a9b-ipv6-20261104`. That commit is an unrelated cherry-pick
   touching only `CatalogSocketBinding` and its test and packet. The in-scope files are byte-identical to
   `a9b5060c` (`git diff --stat a9b5060c HEAD`), so the runtime results below apply to the reviewed source.

## Questions assessed

**1. Weakening.** None found.
- `resolvedConnectionMetadata()` (`IdentityMetadataAdmission.php:51`) is the only caller of `inspect(null, false)`.
  `inspect` is private, and `capture`/`admit`/`requireCurrent` keep `requireOwnership = true` by default (`:145`,
  `:191-192`). Its only caller is `ResolvedConnection::current` (`ResolvedConnection.php:15`), which never certified
  ownership: the old body was `DB::getConnections()[DB::getDefaultConnection()]`. Every caller of `ResolvedConnection`
  (`HeldSourceTransaction`, `CommittedReadContext`, `PrimaryBoundary`, `CommandTransaction:52`) still makes its own
  PDO-identity, depth and `inTransaction` checks. The selector returns a `Connection`, not a proof object. It confers
  no authority that `DB::connection()` did not already confer.
- `HeldSourceTransaction.php` is byte-unchanged (not in the diff). Its Tx1 check (`transactionLevel() === 1 &&
  inTransaction()`, raw PDO identity, driver) and its SAVEPOINT / RELEASE / SAVEPOINT anchor proof run as before. The
  only difference is that each `ResolvedConnection::current('held_transaction')` lookup now runs full raw admission
  first.
- The witness's ownership-certifying use is stricter than before. `capture($this->primary)` (`:53`) replaces
  `$this->manager->connection()`, which could invoke a connector. `requireCurrent($this->primary)` (`:168`) runs
  before every phase's existing checks.
- `CommittedReadContext::prove` adds `$connection === $selected` (`:89`) to its unchanged checks.
- `ExecutionContextV1` funds/environment policy is equivalent. `TestEnvironment::isStaging($env)` is exactly what
  `refusesProductionOnly()` computes (`TestEnvironment.php:37-49`). `in_array($environment, ['local','testing'], true)`
  matches `app()->environment(['local','testing'])` for a string environment. The 8-case environment matrix passes on
  both baseline and candidate.

**2. Completeness of refusals.** Each surface now admits before any resolution, offset or getter:
`ResolvedConnection` callers, the `CommittedReadContext` capture and reuse path (`HeldSourceTransaction::requireCurrent`
→ admission comes before the retained closure), and witness construction and every phase (`statementAdmission`
checks the class before `getAttribute`, then `capture` comes before `DB::getFacadeRoot()`/`app()`/`config()`).
`ExecutionContextV1::current` admits before any policy value is read, and `retained` admits before the environment is
read. Raw reads use `getRawValue`/static `getValue`, so no hooks run. The only methods called before the class checks
are on internal PDO classes, and `Pdo*` class exactness and `isInternal()` are checked first. I found no remaining
resolution path before admission on these surfaces. The packet's stated scope limits are accurate:
`CommandTransaction`'s initial `DB::connection()`/`getPdo()` and the legacy IdentityDatabase/IdentityRows entry points
are unchanged.

**3. Legitimate production use.**
- Booted app, `APP_ENV=production`, sqlite, after a real HTTP-kernel request: global resolving callbacks are 0/0/0.
  Config nodes: app 94, database 101, production-customer-identity 6, production_checkout 16, against the 4096
  budget. `checkoutConfiguration()` and `resolvedConnectionMetadata()` both pass.
- Console boot with zero resolved connections: `checkoutConfiguration()` succeeds and creates no connection. This
  confirms the packet's "before any database is resolved" claim more strongly than the test does (P3-2).
- Octane is not installed, and deploy is Forge php-fpm. Queue workers re-read config and environment on every call,
  and nothing is cached across jobs. Laravel's connection `name` and the `offsetSet` env closure are the canonical
  shapes the guard expects.
- Behaviour that changes on purpose: a resolved but not yet connected peer connection (lazy `Closure` PDO), or a
  non-SQLite/MySQL connection class, now refuses every `ResolvedConnection` lookup. This includes the post-commit
  terminal check in `CommandTransaction` (P3-1). App code creates no peer connection on checkout paths. The only
  non-default `DB::connection($name)` is `PruneExpiredCacheRows`, a console command.

**4. Stale snapshot.** None possible. `checkoutConfiguration()` takes a fresh, atomic snapshot of policy and
environment on every `current()` call. `CurrentPolicy.php:34` re-reads `current()` to compare with the expected
binding. Nothing is cached across calls. `retained()` reads only the environment. It builds the context from the
authenticated binding values and then runs `Evidence::same($context->binding(), $binding)`. The test changes current
config to live/`acct_DIFFERENT` and shows that retained output stays equal to the original binding. An environment
change to staging still refuses a retained live binding.

**5. Tests.** The 53 cases prove what they claim, with the qualifications in P3-2 and P3-3. Baseline failures are
genuine assertion failures, with nonzero callback counters or a missing domain refusal (no errors). The 13 cases that
pass on the baseline are exactly the compatibility positives: canonical current/retained, retained-replaced-parent,
8 environment cases, canonical held/committed, selector does not relax ownership, foreign reader.
The `a9b5060c` fix is correct. `ReflectionProperty::getRawValue()` needs an object and cannot read a static property.
`getValue()` reads `Facade::$resolvedInstance`, and the existing restore closure's `setValue(null, $before)` is the
supported static form, so the facade cache is restored before rollback, assertions and teardown. Before the fix, the
five facade cases errored before any replacement, so no state was corrupted.

## Findings

**P3-1 — Peer connection state now affects the post-commit checkout result.**
`app/Domain/Customers/ProductionIdentity/IdentityMetadataAdmission.php:171-196` via
`app/Domain/Commerce/ProductionCheckout/CommandTransaction.php:52`.
Failure path: a framework after-commit callback resolves a second connection without querying it (its PDO is still a
`Closure`), or registers a non-canonical connection class. The terminal `ResolvedConnection::current()` then throws
`primary_changed` after a successful commit. The write is retained, but the response is a refusal. Probe: lazy peer
gives `CheckoutException`; connected peer passes; a peer inside a transaction passes the metadata-only selector and
refuses `admit()`.
This is safe and intended ("previously tolerated … now refuses earlier"), and no current checkout path creates
peers. Fix: record this in the packet as a known operational constraint (default connection only on checkout
paths). Alternatively add a regression test that pins the post-commit refusal so it is a deliberate decision.

**P3-2 — "Does not initialize a database" is weaker than its name.**
`tests/Feature/ProductionCheckoutExecutionMetadataTest.php:34-46`. `tests/TestCase.php:39` (`setUpTraits` →
`requireFixtureDatabase()`) always resolves the default connection first. The test therefore proves only that the
connection set is unchanged, not that `current()` works with zero connections. My console probe shows the stronger
property holds. Fix: purge the connections before calling `current()`, using raw `connections = []` with restore,
then assert the manager's connection map stays empty.

**P3-3 — Negative cases assert only the exception class, not the reason code.**
`tests/Feature/ProductionCheckoutMetadataAdmissionTest.php:77-78, 111-112` and
`ProductionCheckoutExecutionMetadataTest.php:63, 99, 132`. The zero-callback counter already proves the refusal came
before any callback, so this is precision only. Fix: assert `held_transaction` / `committed_read_frame` /
`unsupported` so a later, unrelated guard cannot satisfy the test.

**P3-4 — Packet does not record the actual evidence.**
`docs/verification/def-id-metadata-20261103/README.md:3, 45-47`. It still states that every executable check is UNRUN,
names branch `codex/def-id-metadata-prep-20261103` and worktree `/workspace/VA-Studio-def-id-prep`, and does not
record the `a9b5060c` fix. AGENTS.md work protocol §5 requires exact commands and outcomes. Fix: append the harness
results (53/169 candidate; 40/53 failing on baseline; affected suites) with their SHAs, plus the untested MySQL
conditions.

**P3-5 — The witness now freezes all app/database/identity config and the full connection set between phases.**
`app/Domain/Customers/ProductionIdentity/IdentityOriginalCommitWitness.php:53, 168`. Before this change, the witness
compared only the key, previous keys, identity config and default connection, and tolerated new idle connections.
Now, between capture and `observeOriginalPositiveCommit`, a callback that leaves any `app.*`/`database.*` change
(for example `App::setLocale('fr')` without restoring it) or resolves any new connection refuses with
`historical_commit_required`. Probe: a locale round trip passes; a leftover locale change refuses. The packet already
flags this. Fix: keep the strict behaviour, but confirm in the native selection that real producer callbacks do not
mutate these values, and document it as an invariant for witness producers.

Not a finding: the witness and `IdentityMetadataAdmission` snapshot now hold plain copies of `database.*` secrets.
The witness already retained the whole `Repository`. Its `__debugInfo`, `__serialize` and `jsonSerialize` guards are
unchanged. `ExecutionContextV1::current`'s local `$policy` copy (which includes `secret_key`) is not retained. The
caught `IdentityException` is replaced, not chained.

## Evidence (this review, SQLite, PHP 8.4.27)

- Candidate `/home/user/wt/defid` (in-scope bytes = `a9b5060c`): the two new files gave OK (53 tests, 169 assertions).
- Baseline `/home/user/wt/defid-base` (main `048244a6` plus the identical overlaid tests, verified by `diff`):
  53 tests, 74 assertions, 40 failures, 0 errors. All are callback-count or missing-refusal assertions.
- Selected affected suites on the candidate gave OK (115 tests, 796 assertions, 2 skipped), exit 0. The 13 files:
  ProductionCheckout SourceTransaction, CommittedReceipt, WriteCommit, FreshPolicy, PrimaryBoundary,
  RefusedFrameCallback, ObserverExclusion and Journey; ProductionFeatures PlainConfiguration and SourceClosure;
  ProductionTaxCheckout TaxSourceV2, TaxCommittedReadReceipt and FreshTaxCheckoutPolicyMetadata.
- Broad run of 64 test files (792 tests), candidate and baseline in parallel: stopped by the 30-minute harness limit at
  about 295/792 on each side. No failures or errors up to that point, and identical skip patterns. Incomplete: not
  evidence of a full pass.
- Probes (scratchpad scripts, no worktree edits): HTTP-kernel and console metadata shape, pre-DB
  `checkoutConfiguration`, lazy/connected/in-transaction peer behaviour, and witness-snapshot locale mutation, as
  described above.

## Not checked

- Any MySQL or native run, concurrency, or real commit/peer semantics. Requests were not driven through real mounted
  checkout HTTP routes (`mounted` is false).
- The complete 792-test affected set. The broad run was cut off at about 37%.
- The full repository suite, lint/Pint, build, and audits.
- Octane or another long-lived worker in practice, and Laravel read/write-split (`readPdo`) configurations.
- Legacy identity entry points and `CommandTransaction`'s initial `DB::connection()`/`getPdo()`, which the packet
  places out of scope.
- `c0f992e0` (the IPv6 catalog socket change) was not reviewed.
