# Independent review: Paid252 composition, Addendum 6 (round 11: issued authorizations kept per order)

**Range:** `283e5c8f..5e99f610db65c6d5afc8df91da8b3b96e3218705`, fetched from `origin/harness/paid252-composition`.

| Commit | Content |
| --- | --- |
| `f785c494` | Client-only change to `PaidGrantJourney.tsx` and the journey test, for Codex P2 thread 4222425879 |
| `5e99f610` | Docs |

- No PHP changed in this range.
- The Addendum 5 files committed in this range are byte-identical to my copies.
- Nothing was committed or pushed by me, and no product code was modified.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.** There is no Low or higher finding. Two informational notes follow under (c).

## (a) Can tokens reach the DOM, survive a denial, or show under the wrong order?

**No to all three.**

- **DOM.** Each `issued` entry renders only its filename, its expiry and a button. The `key` is the authorization id, and React does not put keys in the DOM. As before, the token exists in the DOM only in the short-lived hidden form input, which is blanked and removed after submission.
- **Denial.** `refuse()` (`PaidGrantJourney.tsx:95`) and `pagehide` (`:98`) set `issued` to `[]`.
  - A download-frame refusal is not treated as a denial, because the sealed body does not distinguish 403 from 503. It therefore keeps the other issued tokens.
  - Those tokens reappear only after a successful order read, which requires current ownership. If access has really changed, that read gets a 403, which calls `refuse()`.
- **Wrong order.** `shown` filters `issued` by `originId === origin.id` (`:192`). `originId` is set from the authorize request's own origin (`:142`), and `origin` only ever comes from a validated server read.
  - Another order, including one belonging to a different account signed in through another tab, shows none of them.
  - Submitting under the wrong account would also be refused by the server's ownership checks.

My test case checks two tokens:
- neither appears in the DOM;
- another order of the same account shows no Download button, and reopening the right order shows both again;
- a 403 status read removes every button.

## (b) Does keeping issued tokens through a hidden tab or across reads weaken privacy-on-hide?

**No, not in what is displayed.**

- A hidden tab still clears everything shown, and nothing renders without an open order.
- My case confirms the hidden tab leaves no token, no filename and no Download button in the DOM, and that the tokens show again only after a fresh read of the order.

**In memory, tokens now outlive a hidden tab and later reads.** Before this change, any `call()` or hide dropped them. This is the same class of exposure as round 7's kept replay:
- the round 7 replay (request key and nonce) can already recover the token through the server's idempotent authorize;
- redeeming requires the owner's authenticated session plus CSRF, not the token alone.

So this does not widen the rule beyond round 7.

## (c) Unbounded growth and expired tokens

**A6-I1 (Info): the list is unbounded within one page lifetime.**
- The server issues at most 3 per line per 60 s, and an order has at most 10 lines.
- Nothing prunes entries that have expired or that were redeemed in another tab. A long-lived page can therefore accumulate many stale buttons for one order.
- Memory is negligible. This is UX clutter only.
- Suggested fix: hide entries that a later status read lists as `expired` or `attempted`, or cap the list per line.

**A6-I2 (Info): an expired entry still shows a Download button.**
- This is acceptable. Redeem's first frame refuses with 410 through `live()` once `expires_at` has passed, before the spool snapshot and before any attempt is recorded. No spool slot is taken, and the round-10 retry stays hidden because status shows the entry `expired`.
- An entry spent in another tab gets 409, with the same outcome.
- My documenting case shows:
  - an entry that expired in 2000 still shows its button;
  - a frame refusal for one entry keeps the other.

## Runs (`review-evidence/addendum6/5e99f610/`, head `5e99f610`, vitest 5.0.1, Node 24.21.0)

| Command | Result | rc |
| --- | --- | --- |
| `npx vitest run tests/frontend/paid-grant-journey.test.tsx tests/frontend/paid-grant-review-addendum1.test.tsx tests/frontend/paid-grant-review-addendum4.test.tsx tests/frontend/paid-grant-review-addendum5.test.tsx tests/frontend/paid-grant-review-addendum6.test.tsx` | 5 files, 35/35 (the four paid files plus my 3 new cases) | 0 |
| `npx tsc --noEmit` | clean | 0 |

`node_modules` was symlinked for these runs only and then removed.

Not tested: a real browser.

**Untracked files added:**
- this file;
- `review-evidence/addendum6/`;
- `tests/frontend/paid-grant-review-addendum6.test.tsx`.
