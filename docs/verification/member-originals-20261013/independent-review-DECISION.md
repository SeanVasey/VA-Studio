# Independent review — C4b member originals

**DECISION: APPROVE for a development merge of `453c1af816592fffb856b7cb47b2c891a14c58c1`, subject to the required cheap preflight on the eventual exact merge head and no intervening unreviewed application change.** No known P0/P1 remains open in this reviewed batch. This is not production activation, complete Foundation acceptance, host/provider verification, or approval of C4c's unwritten effect transaction.

Reviewer: separate non-author session `/root/membership_award_independent_review`, 2026-10-13. The reviewer examined implementation and evidence throughout the batch, reported the corrections below, and independently reconciled the final packet. The reviewer did not author application/test changes or execute application/database tests. This decision file is the reviewer's sole repository edit in the final review.

## Exact scope and source

The actual main/PR base is C4a merge `990020c17fa3f5f7b446b1d41b32b1a1bb48249d`. The final reviewed candidate is `453c1af816592fffb856b7cb47b2c891a14c58c1`.

The final initialization correction was authored at `9399dd998cc3d972b94f6753e576e2c756438657`, integrated at `32f083d28db0609a52cade793849a8767a855ae3`, and carried to native withdrawal source `ec90cc1a097db8925d87689d0ac62fdff0165c5a`. Independently compared application, bootstrap, configuration, migrations, public/resources, routes, tests and dependency/build inputs: those inputs are unchanged between author `9399dd99`, integrated `32f083d2`, `ec90cc1a` and the final candidate. The scripts comparison has exactly two author-to-root exceptions: `scripts/ci/database-mysql-selection.json` adds the member cases to the native selection, and `scripts/ci/database-sqlite-skips.json` records the five native-only cases. These are CI policy deltas, not application/test-body equivalence or additional execution evidence. From integrated `32f083d2` through `ec90cc1a` to the final candidate, the complete named runtime/test tree, including scripts, is unchanged. Later changes outside that tree are the documented workflow census comments, status and evidence. Approval therefore covers the actual tested implementation while retaining the two explicit CI policy differences.

Scope includes immutable staff/MFA authoring; current member schema/facts admission; original buyer, invoice, reservation, catalog and authored terms capture; the real PDF child and private candidate store; transaction-free materialization; and fresh combined current/committed source checks. Preparation returns a detached, non-authority candidate. It writes no origin, artifact, activation or credit event. Both authoring and customer use remain default-off and unmounted. The [runtime contract](../../ops/member-originals-runtime.md) and [implementation notes](implementation-notes.md) accurately preserve this boundary.

## Source findings and closure

All identified E1–E11 corrections were reviewed at their actual source and carried into the final candidate:

- **E1:** render input now detaches caller-owned array references after bounded validation. The original value-mutation regression fails on the old source and passes after correction.
- **E2/E3/E5:** the I/O barrier admits captured concrete native handles, raw transaction state, container/configuration and facade/manager identities without resolving lazy callbacks. Direct PDO connections are included. Budget and I/O frame restoration through unserialization refuses.
- **E4:** callback work for both sibling sources precedes both raw checks. The captured finish boundary rejects callback-capable route changes before the shared framework finish, checks again afterward, and permits only one finish. The integrated SQLite test installs a real config hook during committed proof and verifies it never executes inside that finish. Observer ownership is checked after the final current proof and after normal commit, before restoration.
- **E6/E8:** the renderer owns the actual child process. Its optional no-argument observation hook cannot substitute a process or bytes, and budget/I/O admission follows the hook. CR handling is a layout correction; the exact original text digest remains intact.
- **E7:** candidate file and workspace paths are admitted through native stream checks before trusting path metadata, with actual stream type/URI and terminal inode checks. The old file and directory wrapper cases incorrectly admitted; corrected cases refuse. This is local behavior evidence, not host filesystem certification.
- **E9:** facts retain detached original identity configuration and actor attributes/original/exists before the first callback. Both terminal raw phases compare those originals. All six actual old-source incorrect admissions are reproduced and corrected without adding a late callback or renewing a deadline.
- **E10/E11:** the original paired honor bound shortens the same workflow budget and cannot be renewed. After the one committed finish, an exact issuer-owned one-use admission checks original reservation/configuration/producer bounds without reusing closed Rows or refreshing producers. The same two tests fail with the exact old Inspection class over coherent current dependencies and pass after correction. Their controlled clock consumption is not physical timing evidence.

The native budget correction removes only two repeated private initialization proofs. The exact member capture is part of a consumed private constructor permit; ordinary inspection supplies null and every writer rejects non-null. Producer locks, ledger audit, intent authentication and immediate original deadline/admission checks remain. Both public readiness paths still force a complete combined proof, as do the committing and separate committed phases. Partial construction cannot export an active source ticket or readiness receipt. The full ordinary public reservation constructor proof remains. No cap was increased, no successful proof was cached across callbacks, and no original source window was renewed.

The final added cases exercise withdrawal during first capture and before final readiness, plus shaped capture refusal on inspect/reserve/release/expire. The actual native positive/retry result establishes that the correction works on the recorded private instance; it does not establish a general latency guarantee.

## Independently checked evidence

Exact commands, argv, captured inputs and historical outcomes remain in [commands.md](commands.md), [source-map.md](source-map.md) and [coverage-review.md](coverage-review.md). The following are separate successful invocations on the corrected runtime/test source, each with exit 0 and zero errors, failures and skips:

| Selection | Tests / assertions | Source binding | Runner elapsed |
| --- | ---: | --- | ---: |
| Complete affected SQLite selection | 22 / 509 | Author `9399dd99`; 2,034 captured inputs match integrated `32f083d2` and `ec90cc1a` | 311.722 s |
| Ordinary public reservation lock/proof | 1 / 20 | Same corrected source; 2,034 captured inputs | 16.148 s |
| Native preparation and exact repeat | 1 / 48 | Author `9399dd99`; 1,324 captured inputs match integrated source | 421.294 s |
| Native withdrawal during materialization | 1 / 30 | Exact `ec90cc1a`; 1,388 captured inputs | 286.271 s |

The two composed native invocations are **two distinct cases / 78 assertions**, not a whole-file or whole-suite run. Each recorded input inventory was unchanged during execution. Both native invocations use the application without diagnostic overlays and retain the original caps. Durations include fixture setup and repeated preparation and are not per-frame response-time measurements.

The native setup receipts record **MySQL 8.4.11**, PHP 8.4.26, private host port 33413, socket `/workspace/runtime/mysql-policy-run/mysql.sock`, and dedicated datadir `/workspace/runtime/mysql-policy-data`; operation/root selections use separate synthetic databases. These are private local execution facts, not production host evidence.

Other component results retain their own boundaries: renderer/files 76/243; facts old 6/18 with six intended failures versus corrected same external regression plus neighbors 10/35; and native member schema old 2/10 with two intended failures versus fixed 4/19. Earlier reservation wrappers 17/345 and clock 1/18 remain historical after the final constructor correction; they are not promoted to final-source reruns. The original-honor/post-finish pair is 2/43 with two intended old-Inspection failures versus 2/56 passing within the corrected selection.

The packet's coverage catalog independently recomputes to **239 driver/case identities / 1,530 assertions across 13 successful invocations**: pure 107/220, SQLite 123/1,189 and MySQL 9/121. It is explicitly a mixed-source catalog, not an integrated final suite. Overlapping component cases are not added twice. Passing leaves from errored historical invocations remain separate.

Local quality receipts support 159 policy self-tests and Pint for 78 operative PHP files at their recorded earlier source, followed by Pint for the four changed PHP files with the other 74 unchanged. The unchanged policy scripts were not rerun. Final discovery lists 2,145 selected cases / 210 files from 8,478 cases, with 24 nonempty shards and 47 untimed fallback weights; discovery is not execution.

### Packet integrity and reproducibility

The reviewer ran the offline metadata verifier in a separate runtime copy, without modifying the repository packet or executing PHP/database tests:

```text
python3 /workspace/runtime/c4b-final-review-453c1af8/packet/evidence/rebuild-manifests.py --git-repo /workspace/VA-Studio-member-originals
```

It exited 0 with empty stderr. All 626 packet files were byte-identical before/after, including all 11 generated summaries. Its result reports 600 archived artifacts, 68 test invocation records, 65 source comparisons, 14 partial historical comparisons and 239 passed driver/case identities.

Separately verified all **620 artifact-digest entries** against hashes/sizes and tracked Git paths. This is distinct from the **600 archived artifacts**, including 55 gzip members. The 18 final-source comparisons retain seven exact matches and 11 explicit deltas. The portable map resolves 27 references through reachable root history while preserving original author identities and historical overrides/absences; rebuilding was verified with this repository's objects, not through a new external clone experiment.

The five existing frozen profile manifests and their 38 member entries are unchanged. Only the new `production-member-v1` profile is added. Its candidate manifest SHA-256 is `caa3218c162ef3c9192785858e6cd9fad89ab828a345466652dda21e74a1c852`; its six members match the recorded renderer source. It becomes subject to the frozen-member rule on merge.

## Retained failures and limits

The original uninstrumented native invocation at `f4732dbe` remains **1 test / 16 assertions, one error, zero assertion failures/skips, exit 2**, 272.394 seconds. Its second selected case was unrun, not skipped. The first diagnostic's missing-TMPDIR setup error remains separate (1/0, one error, exit 2). The subsequent timing overlay also failed (1/16, one error, exit 2), reaching the original committed-frame refusal at approximately 60.367 seconds after commit at approximately 59.142 seconds. These diagnose cumulative original-budget exhaustion; none is relabeled green.

Original renderer, schema, callback, timestamp, setup, parser and test-hook failures remain available. In particular, the old transport/CR pair contains one assertion failure and one error; the inert test-hook failure is not represented as a product regression. Missing historical shell exits stay unknown. Fourteen historical source comparisons remain partial, including incomplete exact red-test captures; later inventories do not retroactively establish absent before/after records.

Conditions carried forward:

- Focused native preparation/retry and withdrawal do not cover every SQLite callback/observer/authoring branch or a complete MySQL suite. Historical component and synthetic-clock results retain their stated scope.
- Representative latency and physical blocked-call behavior remain activation conditions. Original budgets are refusal bounds, not promises that every SQL/filesystem operation is interruptible within them.
- Required known member tables and guards receive current native definition checks. Complete unexpected-trigger inventory can still reflect a pre-existing REPEATABLE READ dictionary snapshot; that deferred limitation remains open. The SQLite pre-existing BINARY-override limitation likewise remains explicit.
- Local private-file checks do not establish host crash durability or authorize automatic orphan repair. Adoption still requires a fresh trusted render and exact candidate verification; a two-link interrupted candidate refuses under the documented recovery contract.
- C4c must independently implement and verify consume/activation inside the actual owned effect transaction, including the native origin/redemption insertion and join. This preparation candidate itself grants no entitlement.
- Commercial/legal/account/host inputs and all existing activation gates remain open. No live credentials, real payment, customer entitlement import, deployment or cutover is authorized by this decision.

The cheap Development preflight has not run for this final review candidate. The lead must obtain it on the exact eventual head, confirm no open P0/P1, and merge with the expected SHA under the existing policy. A receipt/status-only successor requires explicit carry review. Foundation CI remains manual exact-SHA final verification; no Foundation or GitLab dispatch, full acceptance or production readiness is claimed here.
