# Provisional quote verification

Initial locally tested application: commit `bdd8c00afd67652305523bf8cf8e5f45e07b9cd5`, source tree `76f1999ade4a405db8d6d68f6a0365a161e6c1ee`.

Corrected CI candidate: GitHub commit `24e7221bdd81aefdd087c336cf43dacf9394abcb`, source tree `95ca8b07f32f9292d5b980870e66272df477be59`, identical to local commit `757187392e1ae2d304a1b85f86e382c7fcea2314`. This includes the independently reviewed media rollback and lock-observer corrections described below. [PR #23](https://github.com/VASEYDEV/VASEYAUDIO/pull/23) records the final head; the following evidence update changes documentation only.

## Executed locally

PHP 8.4.1 / SQLite, FFmpeg/ffprobe 6.1.1, Linux prlimit, Node 24.19.0 and npm 11.9 identify the executed development environment.

| Command | Observed result |
| --- | --- |
| `php artisan test --compact` | 124 passed, 919 assertions; 3 explicit MySQL-only skips |
| `npm --offline test` | 27 tests passed |
| `npm --offline run build` | TypeScript and Vite production build passed |
| `php vendor/bin/pint --dirty` | Passed |
| `git diff --check` | Passed |

The full suite includes retained media, licensing and catalog regressions. Quote fixtures use real processed synthetic audio/artwork and independently reviewed, explicitly nonbinding synthetic licenses. No production seller policy or customer transaction is created.

## Executed in GitHub Actions

Both [pull-request CI](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/34058781522) and [push CI](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/34058778901) completed successfully for corrected commit `24e7221bdd81aefdd087c336cf43dacf9394abcb`. The completed job logs establish these results:

| Check | Observed result |
| --- | --- |
| PHP / MySQL | 127 passed, 964 assertions, including all three independent-process quote races |
| PHP / SQLite | 124 passed, 919 assertions; 3 explicit MySQL-only skips |
| Frontend tests | 27 passed |
| TypeScript and Vite production build | Passed |
| Composer validation and production dependency audit | Passed; no security vulnerability advisories reported |
| npm production dependency audit | Passed; zero vulnerabilities reported |

Independent review assessed the initial tested application and both corrective changes, verified the corrected GitHub tree against the local source, and confirmed successful CI. No unresolved blocking finding remained within this provisional review scope. The reviewer relied on executed local/CI evidence and did not claim an additional duplicate test run.

## Scope exercised

- Authoritative integer USD subtotals from exact published commercial revisions, complete internal offer snapshots, canonical hashes, restrictive references and immutable ORM/bulk-SQL history.
- Strict four-ID selections, bounded cart size, duplicate-track rejection, injected prices/fields, wrong product identity, unpublished/changed records, missing or modified files, rights holds and fresh creation digests.
- Owner-scoped idempotency, canonical order/string-ID normalization, conflicting/case-distinct keys, exact expiry and expiry during a lock delay.
- Actual HTTP create/replay/read, guest/session isolation, login/logout ownership reset without an intervening quote request, private response projections, CSRF, rate limits, generic debug-error responses and private cache headers.
- Frontend server values, no client amounts in requests, retry-key reuse after uncertain failure and remount, changed-cart response rejection, mismatched responses, expiry restart and disabled design fixtures.

## Concurrency evidence

Three MySQL-only tests use separate PHP processes, committed fixtures, shared isolated media and explicit barriers inside the quote transaction. They check identical-key replay, conflicting selections with one key, and a rights hold committed while quote creation waits on a track lock. The last case verifies an actual row-lock wait under `REPEATABLE READ` using an independent autocommit observer and Performance Schema lock/connection tables; the CI test account requires permission to observe that state. All three explicitly passed in both corrected MySQL job logs linked above. SQLite skips these tests rather than claiming equivalent evidence.

Independent review identified the potential for a repeatable-read snapshot to be established before waiting for track locks. Existing-quote lookups now use locking reads, keeping the dependent read view after the authoritative track/offer locks. The administrative-hold race specifically exercises this correction.

Quote endpoints also serialize session initialization to avoid two simultaneous quote requests generating different owner secrets. HTTP tests check the configured session-blocking policy; no independent HTTP-worker race is claimed. Unblocked nonquote routes retain their previous session behavior, and concurrent session-state loss may make a review unreachable. This fails closed and does not transfer ownership or delete history.

## Remaining boundaries

These are provisional selection reviews with `payable: false`, unresolved tax and null final totals. They contain no invented buyer/seller legal identity, tax authorization or affirmative assent. They do not create orders, collect payment, reserve rights or issue a contract/grant/entitlement.

Creation forces fresh media digests. Reads and same-key replays retain the existing bounded 60-second digest cache. Full tax/promotion policy, approved disclosures, purchase identity, exclusive inventory, provider reconciliation and fulfillment remain separate work. The three focused database races do not establish those future mechanisms or general production concurrency correctness.

No browser/device visual QA, external legal validation, production load/restore exercise, live payment or BeatStars cutover was performed. Cold asset hashing and quote storage/retention need production capacity and policy acceptance.

## CI findings

The first [MySQL run](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/34058482948) exposed a pre-existing media migration rollback defect: it attempted to drop an index before removing a foreign key that depended on it. The new committed-fixture race tests exercise migration rollback, exposing an ordering path not reached by the prior transactional suite. Incomplete rollback caused subsequent missing-schema failures. This is distinct from quote business validation.

The rights-hold race also failed its original `INNODB_TRX` observation of `LOCK WAIT`; its observed state remained `RUNNING`. The corrected observer uses a separate autocommit connection and requires a live worker-to-parent wait on the selected track's primary-key record in Performance Schema. It retains proof of actual contention before releasing the administrative lock. The exact cause of the earlier sampled state is not established. The corrected runs above passed with the live observer.

The rollback correction explicitly removes both media foreign keys before dropping their index and columns; its `up()` path is unchanged. Populated SQLite migration rollback was independently executed successfully. The corrected MySQL race suite also exercises full migration teardown instead of bypassing the failing rollback path.
