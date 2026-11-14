DECISION: APPROVE at `e947cdb3e08a570362d3380727384d51b97ab43d`. I found no P0, P1 or P2 issues. Seven P3 findings are listed below for the per-lane follow-up batch.

# Independent review: paid Tax history index (T1)

**Reviewer:** an independent Claude subagent. I did not write this change. I made no edits, commits or pushes and changed no git state. The worktree is clean at `e947cdb3`, and `public/build` stayed absent throughout. Imported verbatim by the lead.

## Assessment

**1. Owner closure and authorization: sound.**
- The index takes no request input at all: no path parameter, query or body. `PaidTaxPrivacy` refuses query strings, bodies and wrong methods first.
- The owner is locked inside the original frame (`PaidTaxReads.php:60`).
- The account filter is bound from the session principal (`PaidTaxReads.php:68-70`).
- Every fetched row is checked, the sentinel included (`PaidTaxReads.php:130-146`):
  - its `account_id` equals the principal's account;
  - the sealed buyer's account and user match the row's `actor_id`;
  - `sameOwner` matches the current durable binding on 7 identity keys;
  - the retained policy still holds.
- Any mismatch refuses the whole list (404, 409 or 503) and never returns a partial list.
- The final fence (`PaidTaxReads.php:84-93`) re-proves owner, window and policy. The proofs run again after commit and immediately before bytes.
- I found no path that lists or reaches another account's rows.
- The index skips `verifyHistoricalBinding`, which `show` runs. That is acceptable because a listed locator confers nothing; every downstream endpoint re-runs the full producer SourceV2 closure.

**The page route** (`routes/paid-tax-grants.php:8`):
- It is registered only when `TaxHttpMount::admitted` (literal `true`, `local`/`testing` only).
- Its controller repeats the mount check before any policy, identity or SQL work (`PaidTaxController.php:47`).
- It refuses non-GET/HEAD methods, query strings and bodies (`:48-49`).
- It requires the Tax policy, a session principal and a customer-guard user (`:50`, `:133-141`).

**Tokens** stay in component memory and in one hidden native form POST, never in a URL. `batchIdentity` going public (`PaidTaxGrants.php:279`) is safe: it is a pure function of its argument with no I/O or secrets, and its only callers are `graph()` and `locator()`.

**2. `captureIndex`: verified.**
- The index never constructs SourceV2, the Locator, `PaidTaxConsumerCommitAdmission` or `TaxPaidOriginalCommitDispatcher`.
- Each mode requires `owner === null && !used`, so a read captured in one mode cannot be captured in the other.
- `capture()` is byte-unchanged. `proveBeforeBytes` changed text but behaves identically in producer mode (`PaidTaxProjectionRead.php:56-64`).
- `PaidTaxRows` still adds its existing TransactionCommitted/RolledBack state listeners, exactly as `show` does. So "no commit observer" means no producer dispatcher or admission, which is what the packet claims.

**3. Privacy: no leak found.**
- The shell's only Inertia prop is `errors`; no app code calls `Inertia::share`.
- The Blade view receives no private data, and the page sets no props for history.
- `protect()` sets `no-store, private`, noindex, no-referrer and nosniff.
- The `HandleInertiaRequests` change (`:18`) matches one exact path, adds `Cookie` to Vary and cannot weaken any other page.
- The JSON routes keep the root privacy seam and its headers (tested).
- Errors raised inside the controller go through `PaidTaxPrivacy::refusal`, which logs only the exception class.

**4. Sentinel and concurrency: sound.**
- The window `account_id=? AND id>=min`, read with limit 22 (`PaidTaxReads.php:78-81`), detects any insert, delete or change at or above the oldest fetched row.
- The rows above `min` are exactly the top N, and auto-increment ids rule out a hidden lower insert in practice.
- The unwrapped list SELECT matches the status endpoint precedent (`PaidTaxDownloads.php:58-64`).

**5. `app.tsx`: correct.** The three lazy registrations match their default exports.
- **Guard test:** it catches what it claims. I mutated a scratch copy four ways:
  - dropping a branch;
  - pointing a lazy import at the wrong page;
  - a computed render name;
  - an `inertia()` helper call naming an unregistered page.

  The guard failed on each mutation, with the expected message.
- **Offline worker:** it never intercepts `/build/*` or private paths.
- **`@vite`:** dynamic chunks load through Vite's runtime preload. I did not build to confirm this.

**6. Evidence: matches, with one explained difference.** My counts below agree with the packet. My full vitest run shows 1,172 tests, not 1,162; the extra 10 are the 3 + 2 + 5 cases PR #139 added to `production-account-features.test.tsx`. The evidence tarball's SHA-256 (`bca2db1c…`) matches the README.

**7. Merged head: no interaction.** PR #139 touched `AccountFeatureRequestGuard`, `ProductionFeatureOperation` and `ProductionCustomerFeatures.tsx`, not `HandleInertiaRequests` or `app.tsx`. The new lazy registration loads the updated page, whose `{scope}` default export is unchanged.

## Findings

- **P3-1. Null producer dereferenced on a refusal.** At `PaidTaxProjectionRead.php:71`, `$this->producer->abortOwnedFrame()` runs on null in index mode. The resulting Error is swallowed, so behavior is unaffected. Remedy: use `?->`.
- **P3-2. List SELECT has no lock-wait cap.** The SELECT at `PaidTaxReads.php:68-70` runs outside `PaidTaxRows::held()`. It gets no 5 s cap, and a lock wait or deadlock (1205/1213) reaches the client as a generic 503 and is logged as an error. The status endpoint has the same gap. Remedy: add a capped locking-list helper on `PaidTaxRows` and use it in both places.
- **P3-3. Harmless refusals look like a sign-out.** Any 403/404 makes the client clear everything and say "Access changed … sign in" (`PaidTaxJourney.tsx:132,155,159`). That includes harmless cases: the same buyer finalizing in another tab, one unlistable legacy row, or a batch whose `show` refuses. Remedy: show a distinct message for a changed window, and treat only an identity refusal as a sign-out.
- **P3-4. CHANGELOG misstates paging.** CHANGELOG line 13 says "20 per page", but there is no paging. Orders older than the newest 20 cannot be reached from the page. Remedy: say "newest 20", and track the limitation.
- **P3-5. Client repeats tax arithmetic.** `amounts()` (`PaidTaxJourney.tsx:51-63`) re-derives the inclusive/exclusive tax rules. It is a display guard that fails closed, but it brushes against AGENTS.md's rule against duplicating pricing logic in the UI. Remedy: label it non-authoritative, or rely on the server projection's shape.
- **P3-6. Shell lacks frame protection.** The shell has no `frame-ancestors` or X-Frame-Options header. SameSite=Lax cookies mitigate this, and the V1 PaidGrants page has the same gap. Remedy: add `frame-ancestors 'none'` to private shells.
- **P3-7. Signed-out visitors get raw JSON.** An unauthenticated browser visit to the page receives a JSON 403. This affects user experience only.

## My spot checks, all at e947cdb3

| Check | Result |
| --- | --- |
| `PaidTaxHistoryIndexTest`, whole file (SQLite) | OK, 23 tests / 264 assertions (2 m 34 s) |
| `PaidTaxRootHttpBoundaryTest`, whole file (SQLite) | OK, 46 / 891 |
| `PaidTaxGrantJourneyTest`, whole file (SQLite) | OK, 21 / 278 |
| `PaidTaxGrantJourneyTest`, `--filter test_history_index_lists_only_current_owner_batches` (SQLite) | OK, 1 / 14 |
| Same filtered case on my own private MySQL 8.4.11 (scratchpad datadir, port 33619, since shut down) | OK, 1 / 14 (273 s) |
| `npx vitest run tests/frontend/paid-tax-journey.test.tsx tests/frontend/inertia-page-registry.test.ts` | 21 passed (18 + 3) |
| Full `npx vitest run` | 68 files, 1,172 passed |

The PHP runs used the prescribed sqlite env/phpunit command; the MySQL run swapped in the packet's MySQL variables.

## Not verified

- `PaidTaxGrantJourneyTest` and `PaidTaxHistoryIndexTest` as whole files on MySQL.
- Concurrency on MySQL with two connections, and lock-wait timing.
- `npm run build`, the real chunk and manifest layout, and the client-bundle scan.
- Any browser, keyboard or contrast check.
- The page's Inertia asset-version 409 and 429 paths.
- Pint, `composer audit` and `npm audit`.
- Any CI.
