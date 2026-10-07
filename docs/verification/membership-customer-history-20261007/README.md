# Synthetic customer credit history

This dependency-ready WP-11 child adds `CreditLedger::customerHistory` for one
explicit bucket under the current buyer's internal principal. It retains the
original plan version/policy and every bounded, verified append-only movement,
with each after-balance. It exposes no owner key, account/user identity, actor,
source invoice token, request/resource digest, audit row or credential. Reads
perform no award/expiry/other write. Existing `read` keeps its response keys and
shares the exact history/authority proof. Both reads reject a clock reversal or
crossing of the retained expiry during callbacks, rather than returning stale
spendability.

No migration, guard, configuration, CI policy or registration changes. PR17's
unconditional membership rollback refusal is retained. Full current history is
validated; this increment introduces no pagination/cursor that could omit
corrupt earlier movements. The unchanged maximum event cap refuses oversized
histories.

Renewal, cancellation, rollover, dunning, paid period continuity and legacy
migration remain open under U-10 and actual source obligations. Current synthetic
policy explicitly rejects rollover/renewal. This child therefore claims no
subscription lifecycle, paid invoice, grant, entitlement or production benefit.
The service remains local/testing, default-off. No HTTP/UI surface is mounted.

Next dependency-ready child: account-scoped bucket discovery with terminal
primary range proof, then a controller in `app/Http/Controllers` and routes in
`routes/customer.php` using the authenticated customer principal and
`CustomerPrivacy` response protection; mount minimized retained policy/history
in `resources/js/Pages/CustomerLibrary.tsx`. Those shared route/UI files remain
outside this child. Current bucket IDs are not a buyer discovery journey.

Focused evidence and exact executable source are recorded in the follow-up
receipt. The first new-suite run had 11 passed / 13 cases, 49 assertions and two
fixture errors: customer guards rejected isolated access-version/active changes.
Corrected fixtures move inactive/access-version together or supply an explicitly
stale principal. No guard was weakened. The next SQLite selection passed
102 / 103 cases, 458 assertions, one declared MySQL-only engine case skipped;
these are predecessor evidence before the final extra-audit regression.

Frozen executable `8ea44c0a2bd3b0bb2bbc61da268478b028e1d052`, tree
`1011d423df354183779a9bc2bacabe9a02a0542e`: final six-file SQLite passed
104 cases / 461 assertions, 103 passed, zero failures/errors, exactly one
existing MySQL-only altered-MyISAM case skipped. Final new-history MySQL passed
14 cases / 58 assertions, zero failures/errors/skips. Syntax/Pint/diff checks
passed. The 158 installed Composer package version/reference pairs match the
unchanged lockfile. Final source SHA-256 and artifact/command/result bindings are
in `receipt.json`; metadata publication has identical executable PHP bytes.

The previous native13-case run passed55 assertions, but its test file was edited
while running; it is explicitly predecessor loaded-class evidence, not final
source coverage. The14-case frozen run provides final native evidence. No
membership migration was changed or rerun. Independent sensitive review of
8ea44c0 passed its own14 cases/58 assertions; root retains its separate receipt.
