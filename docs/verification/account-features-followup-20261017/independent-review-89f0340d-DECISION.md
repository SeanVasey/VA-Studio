DECISION: APPROVE at 89f0340db45c38d606ef7ca2e00db989eddb6c02. There are no P0 or P1 findings. The P2 and P3 items below can go to the follow-up batch under the freeze-and-batch rule.

Independent reviewer: a separate Claude Code subagent with no part in writing the batch, 2026-10-18. Imported verbatim by the lead.

**Scope and merge check**
- The merge brings in documentation only. `git show --remerge-diff 89f0340d` is empty, so no conflict was resolved by hand.
- On the main side, `git diff --name-only efa6eea5 cca1d18e` lists only `docs/`, `README.md` and `CHANGELOG.md`.
- Outside the docs, the tested commit and the head are identical: `git diff --stat 2e0f0dee 89f0340d -- app resources tests routes config bootstrap database scripts .github composer.* package*.json phpunit.xml .env.example` is empty. The later commits 9786adcd and afa32ef3 touch only `docs/` and `CHANGELOG.md`.

**Assessment**

*Guard (`app/Http/AccountFeatures/AccountFeatureRequestGuard.php:53`): sound.*
- Admitting `EncryptedStore` opens no bypass. The check is an exact `in_array(::class, [Store, EncryptedStore], true)` with no `instanceof`.
- In `vendor/.../Session/SessionManager.php:196-220`, the framework only ever builds one of those two classes.
- `EncryptedStore.php` adds only `$encrypter` and overrides `prepareForUnserialize`/`prepareForStorage`. `attributes`, `id` and `name` are declared on `Store`, so the reflection reads at lines 86-88 and the identity checks at line 73 stay correct.
- If decryption fails, the session comes back empty with no marker, and `scope()` refuses it (lines 92-99).
- Nothing else in `app/` checks the exact session store class. `app/Support/SupportAttachmentRequestContext.php:68-69` uses `instanceof Store`, which already admits `EncryptedStore`, so it does not break under `SESSION_ENCRYPT=true` (see P3-5).

*Hook reindex (`ProductionFeatureOperation.php:139-144`): correct.*
- `Connection::beforeStartingTransaction` appends (`Connection.php:1092`), and so does the only app writer that appends mid-operation (`IdentityCommittedFrame.php:169`). That is exactly the list case the fix reindexes.
- `PaidTaxBeginningDispatcher.php:71,81` replaces the whole array and never leaves a gap.
- An associative replacement or a list that already had gaps keeps its keys. The next operation then refuses it at line 81, which fails closed exactly as before.
- A duplicated tail is removed completely and the list is reindexed.

*UI (`resources/js/Pages/ProductionCustomerFeatures.tsx:83-86, 119-121`): acceptable as a UX guard.*
- It uses `limits.playlists` from the server, which `validListeningLibrary` already checks. The non-blank check matches the reviewed precedent at `CustomerListeningLibrary.tsx:195-197`.
- The submit handler repeats the guard (line 119).
- Accessibility: the button uses native `disabled`, and `aria-describedby` points at the hint only while the hint is shown.
- Sending the trimmed name satisfies `ProductionListeningState.php:61` (`trim($value) !== $value`).

*Test rewrite: an acceptable replacement, and the intent is confirmed.*
- `design.md:42` says "no lazy resolver in terminal proof", and the design also captures the connection set and rechecks it after callbacks.
- The raw-PDO check in `IdentityMetadataAdmission.php:144` dates from `cbe111ec`.
- I ran main's version of the test file against the head code: 3 tests, 13 assertions, 1 failure, 1 error. So the old tests really were stale.
- The old `assertTrue($fired)` is now negated, and that negation guards the safety property. The packet's mutation run (inspect() changed to resolve the connection) fails both tests.
- The coverage the old consent test gave for the late purpose guard (`ConsentException`) is still provided by `ProductionFeatureBoundaryTest.php:284-302`.

**Findings**

- **P2-1** `ProductionCustomerFeatures.tsx:124`: Rename is enabled for a name that is only spaces (`!name`) and sends the name untrimmed. The server refuses it and the page falls into the same "clear the library and reload" path. Remedy: apply the same trimmed-name guard and send `name.trim()`, as `CustomerListeningLibrary.tsx:221` already does.
- **P3-1** Packet README lines 186 and 221 overstate how the refusal is attributed. My uncommitted probe, run from the scratchpad, registered a peer inside the `saved` callback in three variants:
  - an already-resolved peer whose config is written mid-operation;
  - an already-resolved peer whose config is written before the operation;
  - a config write with no connection object at all.

  All three were refused the same way (`ProductionFeatureException` 503, version 0). The connection-set and config recheck in `requireCurrent` refuses any mid-operation change, so the refusal is over-determined. The control only shows that an idle peer resolved before the operation is admitted.

  Remedy: correct the two sentences. Importing this record into the packet is enough; a docs-only follow-up does not affect this approval.
- **P3-2** `ProductionFeatureSourceClosureTest.php:38,74`: because the refusal is over-determined, these tests pin "the resolver is never invoked" but not the Closure clause at `IdentityMetadataAdmission.php:144` itself. Remedy (follow-up): add a case on the feature operation itself where the lazy peer is registered before `change()`, so the refusal happens at capture time.
- **P3-3** `ProductionCustomerFeatures.tsx:83`: JavaScript `trim()` removes more Unicode whitespace than PHP `trim()`. The client blocks a name made only of non-breaking spaces, which the server would accept, and rewrites padded names to a value other than what was typed. This is the conservative direction and the packet discloses it. Optional remedy: trim only PHP's character set.
- **P3-4** `ProductionAccountFeaturesHttpTest.php` (the custom-subclass case): checking the `__construct` frame does not separate the store clause from the earlier refusal at line 44. The control case and the logged `instanceof` mutation run cover the gap. Optional remedy: also assert the throw line.
- **P3-5** (adjacent, out of scope) `SupportAttachmentRequestContext.php:69` admits any `Store` subclass, which is looser than this guard's exact-class rule. It is worth a note for a later consistency pass.
- **P3-6** Packet README line 241 says "unchanged at 3/13" inside a paragraph headed with both commits, which reads ambiguously; line 243 gives the 4/34 result after `2e0f0dee`. Remedy: clarify. The `final-verification.yml:151` comment (9202 discovered tests) is stale now that there are 9212. The packet discloses this, and lane F2c owns that file.
- **Process note:** main has carried two failing tests in `ProductionFeatureSourceClosureTest` since PR #127, and the packet logs show the HTTP file failing under the shipped `.env.example`. PR #127's evidence did not include either. The next Foundation run is the backstop.

**Spot checks** (my runs, in `/home/user/wt/b4f`, using the briefed PHP command; `public/build` stayed absent and the tree stayed clean)

| Run | `SESSION_ENCRYPT` | Result |
| --- | --- | --- |
| `--filter test_request_guard_admits_exactly_the_two_framework_session_store_classes` | true | 4 tests, 65 assertions, OK |
| same | false | 4 tests, 65 assertions, OK |
| `ProductionFeatureSourceClosureTest.php` | true | 4 tests, 34 assertions, OK |
| same | false | 4 tests, 34 assertions, OK |
| `--filter 'test_hook_appended_during_the_commit_phase…\|test_commit_phase_associative…'` on `ProductionFeatureTransactionEventsTest.php` | true | 5 tests, 53 assertions, OK |
| `cca1d18e` version of the SourceClosure test, run against head code | true | 3 tests, 13 assertions, 1 failure, 1 error |
| Reviewer probe, three mid-operation peer variants | true | all refused, 503 |
| `npx vitest run tests/frontend/production-account-features.test.tsx` (Node v24.22.0) | n/a | 21 of 21 passed |

All 37 packet logs match the README's figures, including:
- HTTP file before the guard fix: 28/233 with 16 failures and 1 error; after it, 32/465 under both settings.
- Mutation runs: guard 4/61 with 1 failure, hook control 5/51 with 1 failure, inspect 4/21 with 3 failures.
- Hook red run: 5/29 with 3 failures.
- Frontend red run: 9 failed, 12 passed; full frontend suite 66 files and 1151 tests; typecheck and build both exit 0.
- Native-selection checks: 2432 selected tests in 233 files, of 9207, 9211 and 9212 discovered.

**What I did not verify**
- No native MySQL runs; I relied on the packet's logs.
- I did not rerun the whole HTTP file (32 tests), the full PHP or frontend suites, typecheck, build, or the packet's mutation and red runs.
- I did not reproduce main's HTTP failure under `SESSION_ENCRYPT=true`.
- I did not test session drivers other than array.
- No real browser and no assistive technology.
- No Foundation CI run, and I did not check the state of the PR #127 review threads on GitHub.
