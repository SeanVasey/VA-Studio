# Paid252 Codex round 6: orders keep the delivery policy they were finalized under

Development evidence from the Claude Code harness for PR #56, code commit `f643e944`. Not Foundation or final acceptance.
No schema, migration, trigger, receipt format, deadline or authorization lifetime default changed, and no file pinned in
`resources/contracts/*/profile-assets.json` was touched. This is an authorization change: it needs the independent review.

## Finding (Codex P2, `PaidGrantCommands.php:63`)

`PaidGrantCommands::run()` and `PaidGrants::sameSources()` required each order's retained `delivery_policy` to equal
the currently configured one byte for byte. Show, document preparation, status, authorize and redeem all pass through
`run()`, so revising the configured policy for future orders (its version, download limit or lifetime) would have
locked every earlier buyer out of their license and files. Verified: the new test errors with a 409 on the old call
sites.

## Fix

- **`PaidGrantPolicy::retained($retained, $current)`** replaces the equality at both sites. The retained policy must
  pass the same shape rules as a configured one (exact keys, schema 1, purpose, version pattern, 1–100 downloads,
  30–600 s lifetime) and must have the **current provenance**, so a rehearsal order never runs under a production
  configuration or the reverse. The shape rules moved into one private `wellFormed()`, which `capture()` also uses
  (503 for configuration, 409 or 422 for a retained copy).
- **Unchanged:**
  - enablement and identity provenance are still proven against the current configuration, by `capture()` and by
    `provePure()` in the fence;
  - the read receipt still binds the current captured policy;
  - every origin body must still equal its batch's retained policy (`PaidGrants.php:153`);
  - the download limit and authorization lifetime always come from the retained copy, as before.
- **Not added:** a list of supported historical policy versions. The retained copy is sealed in the batch payload, so
  any well-formed one is a policy the operator once configured. Whether to also keep an explicit allow-list of
  retired versions is Sean's call.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Red: revision journey, old equality call sites | `7a16d050` call sites + new `PaidGrantPolicy` | 1 error (`PaidGrantException`, 409), rc 2 (`backend-red-equality.txt`) |
| Green: `PaidGrantPolicyRevisionTest` | `f643e944` | 2 tests, 36 assertions, rc 0 (`backend-green-file.txt`) |
| Green: paid family, `--filter PaidGrant tests/Feature`, SQLite | `f643e944` before Pint removed one unused test import | 80 tests (78 + 2), 3,804 assertions, 1 skipped, rc 0 (`family-green.txt`) |
| Pint `--test` on the four changed PHP files | `f643e944` | passed (`pint.txt`) |

The journey test finalizes and prepares an order under the v1 policy (3 downloads, 60 s), then switches the
configuration to a v2 policy (5 downloads, 300 s). Show and status still answer, status still reports 3 downloads, a
new authorization lasts 60 s, and the master streams byte for byte. The second test checks that `retained()` refuses
another provenance, out-of-range values, a wrong purpose or version, extra keys, a list and a non-array.

## Not tested

Native MySQL (no MySQL-only path changed); a production-provenance configuration (the testing environment admits only
rehearsal).
