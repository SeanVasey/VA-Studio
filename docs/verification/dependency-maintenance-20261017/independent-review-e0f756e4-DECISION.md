DECISION: APPROVE WITH CONDITIONS at e0f756e40a616ce37f9affacc982864d285888a0

Independent reviewer: a separate Claude Code subagent with no part in writing the batch, 2026-10-18. Imported verbatim by the lead.

I found no P0 or P1 issues, and the lock content is correct. The two conditions are wording and Dependabot config changes, and neither needs a re-run. Everything below was checked at that exact head. The worktree was untouched: status clean, `public/build` absent.

## Findings

**P2-1: The native MySQL case is unproven, and the packet over-states it.**
- Run 1 on 13.36.0 threw at the pre-commit `operation_deadline` check (`MembershipReservationContext.php:786`, reached from the `committing` event at `MembershipReservationOperations.php:131`).
- Run 2 on 13.36.0 and the baseline run on 13.35.0 both threw from the post-commit catch-all (`Operations.php:172`), which discards the inner cause.
- Run 2 and the baseline match each other (same exception, throw sites and 9 assertions). Run 1 is a different phase of the same operation.
- So the 13.35.0 control supports "not caused by this batch". It does not support "identical cause" (CHANGELOG wording).
- "Under CPU contention" is not shown. Run 2 took 417 s wall for 12.97 s user and 5.0 s sys, and the baseline 313 s wall for 12.3 s user and 3.6 s sys. That fits waiting on storage or scheduling equally well. The server ran with full durability (`flush_log=1`, `sync_binlog=1`, binlog and doublewrite on), and no load average was retained for those reruns.
- The 13.36 delta does not touch `ManagesTransactions`, `DatabaseTransactionsManager`, `DatabaseTransactionRecord`, the transaction events or `Session/*`. These are byte-identical to 13.35.0.
- It is not a merge blocker under the cost policy. Remedy: reword to "fails on both versions; cause not isolated; the first run's throw site was the wall-clock deadline". Record it as an open release-gate item, to be run on the hosted MySQL runner (Foundation final or a Focused dispatch) or on a disposable server with relaxed durability. Do not claim a native pass.

**P2-2: The Dependabot npm group covers version-coupled pins.**
- `npm-minor-patch` would bundle a Playwright minor with everything else.
- `scripts/ci/apply-playwright-webkit-offline-backport.mjs:10,51-55` rejects any `@playwright/test` other than 1.63.0, so one such bump would fail preflight for the whole group. That defeats the stated "avoid a cascade" rationale.
- Remedy: add `exclude-patterns: ["@playwright/test", "playwright", "playwright-core"]` to that group. Consider `vitest` and `typescript` too, since they are also exact pins.

**P3-1: A pre-existing failure, not from this batch.**
- `ProductionMemberOriginals/MemberGrantDefinitionsTest::test_remaining_config_resolution_hook_refuses_committed_authoring_receipt` fails on SQLite at `:114` (`$calls` is 0).
- It fails the same way on a scratch copy of `git archive e0f756e4` with 13.35.0 vendor, in isolation, and under the `phpunit.xml` defaults.
- The author's selection did not include it. It is in the MySQL native selection (`scripts/ci/database-mysql-selection.json:82`).
- Remedy: raise it as an adjacent item, and do not attribute it to this PR.

**P3-2: Packet binding.**
- The packet's counts bind to `1a3844f6`. The head also carries main's B4f code (`AccountFeatureRequestGuard`, `ProductionFeatureOperation`, `ProductionCustomerFeatures.tsx` and tests).
- Remedy: add an addendum with the lead's merged-head results (frontend 1,151; B4f PHP), and adjust "nothing executable changed after the lock commit".

**P3-3: The PR mapping, which the packet says it never checked, is correct.**
- #133 is laravel/framework 13.35.0 to 13.36.0, #107 is vite 8.3.3 to 8.3.4, #105 is @types/node to 24.19.2, #104 is @inertiajs/react 3.8.0 to 3.9.1, and #106 is jsdom 30.0.1 to 30.1.2. All five are open.
- Dependabot's own #133 lock is much larger (151 additions, 142 deletions) than this batch's 16 lock lines, so the lock will not be identical.

## Assessment

**1. Lock correctness**
- Composer: 1 of 158 records changes (laravel/framework). `content-hash` and `plugin-api-version` are unchanged, and `composer validate --strict` exits 0.
- npm: 24 records change, 2 are removed (`symbol-tree`, nested `whatwg-url`) and none are added. Root dependencies equal `package.json`.
- Pin style is preserved: `^` on inertia, @types/node and vite, and exact on jsdom. @types/node stays 24.x.
- Stripe, tc-lib-pdf, PHPUnit and Playwright are unchanged.
- Internal dependency ranges: 0 problems. Node engines: 0 mismatches against 24.15.0.
- `npm ls` is clean, `npm ci --dry-run` reports up to date, and every `resolved` URL is the npm registry.
- `composer audit --locked` finds no advisories, and `npm audit` finds 0 vulnerabilities.

**2. Laravel 13.36 delta**
- I compared the vendor trees to the upstream commits in the Composer VCS cache. Apart from split-repo dotfiles they match, so the delta is exactly 79 `src` files (44 modified, 35 added, mostly new validation rule classes).
- The relevant changes are the `Connection` reconnect guard (new `$reconnecting` property, only when a lost connection occurs outside a transaction), an equivalent `Container` contextual short-circuit, the Kernel command registration, and the unique-listener rollback.
- The app uses none of these: `increment`/`decrement`, `Schema::getColumn`, queued listeners, secured Markdown (`withSecuredEncoding`), notification locale, or `Rule` macros.
- Reflection: every statically named `Class::class, 'prop'` pair in `app/` exists in 13.35.0 and 13.36.0, with identical results. That covers `Connection::{transactionsManager, beforeStartingTransaction, pdo, transactions, events}`, `Container::{instances, bindings, aliases}`, `Store::{attributes, id, name}`, `Model::{attributes, original}` and the rest.
- `get_object_vars` is applied only to `DatabaseTransactionRecord`, which did not change.
- The artisan command set is identical: 186 commands, 16 `vasey:*`, and the `inspire` closure present.

**3. Dependabot**
- The YAML parses. Groups are limited to minor and patch, so majors stay separate.
- Security updates are unaffected (`applies-to` defaults to version updates). The `@types/node` major ignore is preserved.
- See P2-2.

**4. Evidence integrity**
- I recomputed every count from the retained JUnit files and they match `outcomes.json`: frontend 66 files and 1,141 tests, SQLite 1,330 tests and 14,702 assertions with 23 skips, MySQL 10 cases with 662 assertions and 1 error. The npm audit counts (165 to 163 dependencies) are consistent with the 2 removals.

**5. Merged head**
- Main is an ancestor. Head versus main differs only in the three lock and manifest files, `dependabot.yml`, CHANGELOG and the packet. There is no lock difference between `1a3844f6` and `e0f756e4`, so the merge does not interact with the lock changes.

## My commands

- Independent SQLite sample of 9 transaction-hook and reflection files, with the author's wrapper: 46 tests, 390 assertions, 1 failure (P3-1), 2 skipped.
- Control: one test on a scratch copy of `git archive e0f756e4` with 13.35.0 vendor, same failure.
- Scratch copies were deleted.

## Not verified

- No native MySQL run.
- No browser, WebKit or hosted CI, no fresh Composer install, no Vite dev server.
- The full PHP suite and the B4f PHP files (the lead's).
- npm transitive source (jsdom 30.1.2 dependencies) and the Dependabot service's actual parsing of the config.
- No PR check status.
