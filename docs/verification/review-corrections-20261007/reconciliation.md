# Membership rollback and packet replay review reconciliation

This successor to the production preparation batch addresses
[PR #16 P1](https://github.com/SeanVasey/VA-Studio/pull/16#discussion_r4201166306)
and [PR #17 P2](https://github.com/SeanVasey/VA-Studio/pull/17#discussion_r4201460899)
before moving the existing PR #17 branch. The earlier published head
`956ab3ec55d0d77ab8c7734882be72723b5728b3` and its successful preflight
`37547488659` remain historical evidence; that run does not gate the correction.

## Resulting behavior

Membership migration `2026_10_06_230000` now throws an explicit `LogicException`
before any read or mutation in `down()`. Laravel cannot delete its migration
repository record after a successful no-op while leaving the four retained
tables and twelve guards installed. The refusal covers empty, populated,
partial and absent installations. Schema creation and membership domain behavior
are unchanged. Real Artisan regressions prove exact repository, schema, guard,
data, user, account and audit preservation, then ordinary migration and a real
new disposable forward migration remain usable. The account migration fixture
asserts all four child tables are empty before explicitly disposing them in
foreign-key order, with enforcement enabled. Operational rollback is never used
as test cleanup.

The refusal stops reverse migration when this migration is reached. It does not
make a larger rollback batch globally atomic or change direct out-of-order
parent-account rollback. Destructive retention removal remains separate work.

Packet creation now rechecks the actor/key selector through the captured primary
inside the existing serialized adapter transaction. An exact retained request
must match its request hash and authenticate its envelope, source history,
lines and audit before returning the original model. The fixed terminal proof
checks retained selectors and history again after callback-producing work. It
performs no additional encryption or packet/line/audit write. Changed requests
and another actor cannot recover the original capture. The fresh-creation body
and final current-source proof remain byte-identical to the predecessor.

The existing adapter still requires current source/context admission before the
miss-gap callback. Movement that prevents that admission may refuse the request.
The outer historical lookup continues to support sequential retained recovery
after source closure or catalog movement; no broader concurrent closure guarantee
or sandbox for arbitrary transaction lifecycle callbacks is claimed.

## Actual focused checks

| Execution | Exact local source | Cases / assertions | Failures / errors / skips |
| --- | --- | ---: | --- |
| Membership and account migrations, genuine MySQL 8.4.11 | `211577eece5c4f9a3c708db25e4ad67d4462721e` | 45 / 218 | 0 / 0 / 0 |
| Same migration selection, SQLite | `211577eece5c4f9a3c708db25e4ad67d4462721e` | 45 / 221 | 0 / 0 / 0 |
| Ten affected preparation/capability files, SQLite | `a1a835ac7e3b9cd16f337bacbacf20bb28643a53` | 273 / 823 | 0 / 0 / 4 |
| Final packet replay races, genuine MySQL 8.4.11 | `7a202e98296d10ec96dee11803433dd8f5391cab` | 4 / 412 | 0 / 0 / 0 |

The preparation SQLite run executed 269 passing cases. Its four zero-assertion
skips are exactly the two newly declared native race methods with two commit
orders each. Every native race proves both standalone historical misses committed
at zero transaction depth with no packet, then witnesses the loser's actual
InnoDB `users` PRIMARY record wait on the winner. Exact captures return the same
packet identity and hashes; changed captures refuse in both orders. Only one
packet, line, audit and encryption are produced. Synthetic raw worker results
retain separate session/PID identities, final transaction depths and encryption
counts. No provider request, order, grant or inventory mutation occurs.

Both native selections used fresh disposable databases over private loopback
TCP with flush-at-commit 1, sync-binlog 1, doublewrite ON and binary logging.
Ephemeral application keys are omitted from receipts. PHP 8.4.26 product checks
used the recovered local 1024M runtime; the earlier explicit 512M CI runtime
guard remains separately attributed. Source heads/trees and tracked bytes are
bound before and after execution. Fifteen root and twenty-five author autoload
origins resolve to their respective worktrees. Packet syntax and scoped Pint
passed on five PHP paths; membership syntax/Pint passed on the three affected
PHP paths. Selector/cadence/partition/strict receipt controls passed 47/14/36/34.

The final executable composition is `0916f78f843626eac2b9126504d9773c02d21e5e`,
tree `6712ec499f84393e1c32fb2cfe0c4db0b782e60d`. All six final author paths
match `7a202e`; only the three root registration paths differ from that author.
Its sole change from the executed SQLite composition is a bounded diagnostic
line in the native-only worker helper. That helper is not executed by the four
SQLite skips. The migration/runtime/framework/configuration/dependency bytes
used by the earlier 45-case selection are unchanged. These are source-equivalent
carries, not claims those selections executed on a later commit.

The [membership integration receipt](membership-integration.json),
[packet integration receipt](packet-integration.json),
[packet author receipt](packet-author.json), actual JUnit reports, raw native
worker evidence, source-carry proofs and independent reports are retained here.
The publication map binds each local source to its native tree and ordered parents.

## Independent review and negative evidence

The [membership sensitive review](membership-sensitive-review.md) and
[shared review](membership-shared-review.md) approve `211577`. Independent
execution remains attributed to its actual sources: author `62998cf` SQLite
3/44 and first composition `e96dc3d` MySQL 2/40. Review validated both complete
45-case root receipts and the author's 38-case migration runs on each engine.
The [packet sensitive review](packet-sensitive-review.md) and
[shared review](packet-shared-review.md) assess the exact final runtime, native
race evidence, root composition and strict skip/discovery contracts. The
sensitive reviewer independently passed frozen `f5fa20b` SQLite 18/77 and
reproduced the old miss-gap refusal and a terminal-proof-omitted late-drift canary.

Preserved failed evidence includes the two real-Migrator missing-record cases
on each engine, the two native parent-account FK errors, initial root connection
and missing-key harness failures, the old namespace guard's admitted waiver,
both native packet saved/blocked predecessor races, intermediate canonical-key
ordering assertions and the omitted-terminal-proof canary. Corrected guard
canaries reject forbidden waivers, missing declared methods and doubled class
separators. None of these failures is relabeled as a pass.

## CI policy and next dependency

The new manual membership selector contains exactly two files. The existing
preparation selector grows from nine to ten; every other selector and the
32-file cap remain unchanged. All 148 existing native method pairs retain their
exact order and identities, with only the two named new native methods added.
Functional preparation and membership methods have no waiver.

Only measured timing rows changed: SQLite's two migration rows, packet functional
row and four zero-assertion skip records, plus MySQL's two migration rows and
actual four-case native race row. Unrelated weights and both fallbacks are
preserved. [Timing provenance](timing-provenance.json) identifies each actual
execution; balance weights are not acceptance results. The partition control
passed again after these input changes.

Final-head preflight and expected-head merge are pending at this record's
capture; the existing PR branch still points to `956ab3ec`. One fresh cheap PR
preflight will gate expected-head merge after the reviewed final branch update.
Complete Foundation
verification remains manual for an exact reviewed 40-character expected SHA;
routine hosted MySQL/SQLite/browser matrices stay disabled. Audits, secret scans,
security checks and protections remain intact. The PR will record actual final-head
preflight, expected-head merge and fresh main/tree/ordered-parent readback after
those actions complete.
No live deployment, provider activation, customer migration or launch acceptance
is performed by this correction. All forty original criteria remain, with six
parent groups accepted and thirty-four open. The next separate dependency queue
is selective current-main compatibility for PRs #1 and #2; #3 remains blocked by
immutable PDF-profile pins and requires a versioned successor design.
