# Provisional quote verification

Application candidate: local commit `bdd8c00afd67652305523bf8cf8e5f45e07b9cd5`, source tree `76f1999ade4a405db8d6d68f6a0365a161e6c1ee`. Subsequent verification-document changes do not alter application code or tests. The integrating PR records the remote commit, identical tree check and final GitHub results.

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

## Scope exercised

- Authoritative integer USD subtotals from exact published commercial revisions, complete internal offer snapshots, canonical hashes, restrictive references and immutable ORM/bulk-SQL history.
- Strict four-ID selections, bounded cart size, duplicate-track rejection, injected prices/fields, wrong product identity, unpublished/changed records, missing or modified files, rights holds and fresh creation digests.
- Owner-scoped idempotency, canonical order/string-ID normalization, conflicting/case-distinct keys, exact expiry and expiry during a lock delay.
- Actual HTTP create/replay/read, guest/session isolation, login/logout ownership reset without an intervening quote request, private response projections, CSRF, rate limits, generic debug-error responses and private cache headers.
- Frontend server values, no client amounts in requests, retry-key reuse after uncertain failure and remount, changed-cart response rejection, mismatched responses, expiry restart and disabled design fixtures.

## Concurrency evidence

Three MySQL-only tests use separate PHP processes, committed fixtures, shared isolated media and explicit barriers inside the quote transaction. They check identical-key replay, conflicting selections with one key, and a rights hold committed while quote creation waits on a track lock. The last case verifies an actual `INNODB_TRX` lock wait under `REPEATABLE READ`; the CI test account requires permission to observe that state. SQLite skips these tests rather than claiming equivalent evidence. Their final execution results belong to the PR's MySQL CI record.

Independent review identified the potential for a repeatable-read snapshot to be established before waiting for track locks. Existing-quote lookups now use locking reads, keeping the dependent read view after the authoritative track/offer locks. The administrative-hold race specifically exercises this correction.

Quote endpoints also serialize session initialization to avoid two simultaneous quote requests generating different owner secrets. HTTP tests check the configured session-blocking policy; no independent HTTP-worker race is claimed. Unblocked nonquote routes retain their previous session behavior, and concurrent session-state loss may make a review unreachable. This fails closed and does not transfer ownership or delete history.

## Remaining boundaries

These are provisional selection reviews with `payable: false`, unresolved tax and null final totals. They contain no invented buyer/seller legal identity, tax authorization or affirmative assent. They do not create orders, collect payment, reserve rights or issue a contract/grant/entitlement.

Creation forces fresh media digests. Reads and same-key replays retain the existing bounded 60-second digest cache. Full tax/promotion policy, approved disclosures, purchase identity, exclusive inventory, provider reconciliation and fulfillment remain separate work. The three focused database races do not establish those future mechanisms or general production concurrency correctness.

No browser/device visual QA, external legal validation, production load/restore exercise, live payment or BeatStars cutover was performed. Cold asset hashing and quote storage/retention need production capacity and policy acceptance.
