# Service review corrections and terminal authority regressions

Approved exact final composition `7dc8bd8028d6b4eb4a73cbc9b10e9b9402cec63e`,
tree `f5bca4c342de5b5a8742a51b7956b06d67ce6511`, for focused development
integration. No remaining blocker from this review. It has the identical tree
to independently tested `e9cae388dae632b235c1f05870fd9930a12f56aa`: corrected
runtime `8188af0f6ef83a787a7579741d9fd1da2b3fd1d2` plus the two promoted feature
test files and one precisely registered native SQLite exception.

This receipt **supersedes the earlier service approval at `13d7ae7`**. The
earlier receipt remains historical evidence for unchanged migrations, input,
encrypted history and actual native concurrency. Three subsequently reproduced
terminal authority defects required correction; their failures remain retained.
This review owns only the new
[regression/evidence directory](cloud-service-followup-independent-20261007/),
this new report, two normal `tests/Feature` files and the additive native skip
tuple requested by the publication owner. Root owns all application/UI repairs.

## Actual defects and repaired behavior

1. On byte-exact `13d7ae7` runtime, a genuine authored-quote audit callback
   withdraws the permanent operator's required MFA, then constructs a temporary
   `users` table containing its prior rows. The unqualified terminal PDO read
   accepts that copy. Actual native MySQL commits the quote while permanent MFA
   is withdrawn: one service event remains, the retained snapshot changes and
   the independent one-case / four-assertion run fails. The original tested
   canary source and raw probe/console/JUnit are retained.
2. On the same runtime, a genuine subclass of the actual Filament
   `AppAuthentication` provider evaluates enrollment, then withdraws stored MFA
   after the real quote audit arms its callback. The old terminal check runs
   the provider after verifying its user row, so the quote still commits.
   The independent SQLite one-case / three-assertion run fails with the genuine
   provider fired and one retained event.
3. The first shadow/provider repair `62de352` still resolves transient
   `CustomerAccess` to compute the credential stamp after its final physical
   user/account/graph reads. A genuine Laravel resolving callback, armed by
   the real acceptance audit, withdraws the stored customer password there.
   Quote acceptance still commits: events advance from one to two and retained
   rows change. The independent SQLite one-case / three-assertion run fails.
   Root kept that source frozen until the actual reproduction was retained.

The final runtime captures the original connection, primary PDO, driver,
database and table prefix at its own transaction start. Qualified SQLite main
and native MySQL schema reads refuse temporary table identities; every raw read
checks that the captured connection, PDO, database, prefix and transaction remain
current. A private savepoint also detects direct commit/reopen uncertainty.
Required MFA providers, credential-stamp computation and policy-object
resolution run before terminal policy/physical proof. The remaining comparison
uses pure precomputed values. The final graph/user/account reads follow those
callbacks; no provider or stamp service is resolved after that proof. Write,
replay and staff-view graph evidence remains bound to the exact encrypted rows.

The original native shadow case and provider case pass on `62de352` (two cases /
nine assertions), while the credential-resolver failure remains attributable to
that intermediate source. All three original regression intents now refuse on
the final runtime, with every genuine callback exercised and the entire original
user/account/project/event/audit snapshot preserved.

| Final independent execution on `e9cae38` | Actual result |
| --- | --- |
| Native MySQL, all three promoted authority regressions | 3 / 13, zero errors/failures/skips; all commands refuse and complete snapshots remain exact. |
| SQLite, promoted authority regressions | 3 selected / 8 assertions: two pass; one explicit native temporary-table case is skipped and precisely registered. |
| Private middleware/logger-failure canaries, no database cases | 2 / 10 pass; fixed-class report and private 503 survive logging failure. |
| Additive native-skip selector controls | All 50 checks pass; every preceding 158 tuple retains its original order. |

## Bounded listing and private reporting corrections

Root correction `8c7feaee` selects the 50 newest service definitions by
descending draft ID and still resolves each selected definition's exact current
immutable revision. The mounted form explains the 50-definition limit. Its
actual regression creates 51 authentic reviewed definitions, confirms the newest
is available and submits a brief bound to its exact version/hash. No caller
amount, actor or ownership authority is introduced by this ordering change.

Both controller and middleware unexpected-exception paths now call one private
failure helper. It reports only the fixed message and exception class, omitting
exception message, trace, request body, credentials and private buyer input,
then returns the same bounded private 503. A logging exception preserves that
response. Root's genuine brief/audit failure verifies rollback and exactly one
sanitized report; the independent middleware and logging-failure canaries each
exercise the real helper. Expected authorization/validation/domain refusals
retain their existing responses. Root's two new regressions plus three existing
HTTP cases pass 5 / 76 on `8c7feaee`.

Root final `8188af0` journey/P2 selection passes 10 / 66. Its earlier `62de352`
journey/HTTP/registration/P2 selection passes 15 / 156, with seven mounted cases
and TypeScript; carry is limited to the unchanged registration/controller/UI
paths. Root's final seven-file scoped Pint and 50 selector controls also pass.
These are separately attributed observations, not independent reruns or a full
matrix. Earlier valid native migration-prefix and worker/MVCC proofs are not
repeated: the table/guard/migration/dependency graph stays byte-exact, and the
new raw readers retain the same primary current-lock reads and lock order.

## Durable regression and source evidence

The actual authority cases now live in
`tests/Feature/ServiceProjectCurrentAuthorityTest.php` and
`tests/Feature/ServiceProjectCredentialResolverTest.php`. Promotion preserves
the refusal/callback/full-row assertions; only class namespace, optional proof
output, formatting and the native-only SQLite skip were added. The exact
temporary-user method is appended to the skip registry, preserving every older
tuple. It runs normally on native MySQL and remains in final verification.
Independent focused native execution uses the same promoted files.

Exact source/blob hashes, raw case census, initial failure probes, unchanged
schema/dependency bindings and root attribution are in
[the final receipt](cloud-service-followup-independent-20261007/final-review-receipt.json).
Its durable digest map includes tracked artifacts only; transient runner caches
are excluded. The original denied-read, schema retry and dependency-key proofs
remain in the prior service receipt; no failing evidence was replaced.

Actual native runtime is MySQL **8.0.46-0ubuntu0.24.04.4**, PHP 8.4.26, on the
existing isolated reviewer schema/account. MySQL 8.4, complete Foundation,
browser/device acceptance and production payment, identity, delivery and launch
remain unexecuted. No hosted workflow, push, merge or provider action was taken
by this reviewer.
