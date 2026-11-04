# CX queued recovery and durable scan progress — source draft

This isolated continuation starts at accepted mail regression source
`d8984c2747352d5579fc386481c1dd160eb805bf`. It checkpoints interrupted development;
it is **not reviewed, behaviorally verified, merge ready, or commerce acceptance**.
No delivery, queue, cursor, schema, native concurrency, or real SMTP result is
claimed for this new source. The earlier d898 results do not cover these changes.

## Owned scope and assumptions

The two command files, mail adapter, two scan services, one migration, three test
files and one test worker form one draft batch. There are no new environment keys.
Configuration remains default off. Existing jobs retain their original delivery
ID, one try, after-commit behavior, transport and delivery claim authority.

`vasey:transactional-mail-wake-queued` requests the original job for queued rows
only. It does not reopen submitting, failed, uncertain or accepted rows, and a
broker acknowledgment is not a transport receipt. The existing queue command and
new recovery command use separate fixed progress purposes. Short transactions
reserve consideration before adapter work, retain a cycle/high-water mark, and
revisit refused or interrupted work after wraparound. No clock lease or implicit
retry of an uncertain physical commit is intended.

## Verification actually performed on November 4

ROOT ran PHP 8.4.27 syntax checks for all ten PHP files and Laravel Pint. The first
Pint check reported one formatting issue in TransactionalMailScanProgressTest;
Pint fixed only that formatting. The final tooling receipt is private workspace
`/workspace/pcc-evidence/HANDOFF-SOURCE-TOOLING-20261104.json`.

The PHP binary fingerprint matches the historical binary, but the Docker bridge
identity changed after the execution environment restarted. Historical admission
is therefore invalid for fresh domain tests. No test body, application boot,
discovery, migration or native worker was executed for this draft. The Oracle
MySQL 8.4.11 container is stopped. This sparse checkout has no admitted vendor or
full source runtime. Do not run it using ambient credentials.

## Required next work, before any publication

1. Review the complete source batch independently, especially schema ownership,
   permanent-versus-temporary MySQL dependencies, exact schema normalization,
   reservation CAS, savepoint/frame identity, uncertain commit cleanup, finite
   conflicts and adapter calls outside all resolved transactions. The earlier
   design review is direction only, not approval of this implementation.
2. Qualify the current runtime and original private Oracle admission afresh. Keep
   historical receipts immutable. Materialize and qualify the exact source and
   dependency graph through the original absent-only controls, without install.
3. Bind a finite execution packet to the resulting exact commit. Verify actual
   listings and affected original command/mail regressions as well as the new
   recovery and progress tests; do not inherit d898 acceptance automatically.
4. Exercise genuine failures before and after broker push, retained queued work,
   terminal refusal, changed authority, duplicate job claims, bounded starvation,
   wraparound, late lower IDs, crash after reservation, foreign/peer transactions,
   migration refusal and retained progress. Inspect teardown and event-listener
   isolation. Preserve failures rather than changing the tests to fit the code.
5. The two native tests need real concurrent workers: a physical cursor-row wait
   with distinct reservations/stale-cycle refusal, and duplicate jobs with one
   claimed fake transport. Qualify actual inherited native admission, source and
   private storage in every worker; fake Mail is not real SMTP acceptance.
6. Add native-only methods to the append-only SQLite skip census, preserving the
   first 150 entries and main-first merge order. Regenerate actual native
   selection and both workflow count comments after integration. The draft does
   not yet include this routing/count update; do not run it in routine suites.
7. Fix validated findings coherently, review the exact tested commit, and carry
   only unchanged evidence with explicit source impact proof. B precedes C
   publication; PR191 still lacks a completed security assessment.

The tests and worker are proposed verification, not proof that these properties
hold. No policy values, customer data, live payment collection, new scheduler,
automatic terminal retry, deployment or membership mounting is authorized here.

## Claude harness review fixes and verification (2026-11-04)

Independent code review of `fc4c6f38`: **REQUEST CHANGES** ([decision](independent-cx-recovery-review-fc4c6f38-DECISION.md)). The cursor
design held (no permanent skip, no double reservation, no terminal reopen, no clock lease); the blockers were in the tests,
routing and docs. Fixed on `claude/cx-recovery-review-20261104`:

- P1-1 (`0689cd31`): the recovery test built a bare `QueueManager`; it now registers the framework's connectors.
- P1-2 (`0689cd31`): the two native methods appended to the SQLite skip census and the three files added to the native include
  list. The count comments are regenerated at integration on top of the C4 census branch (`6d96042f`), which repairs a
  pre-existing `pattern_files` gap (MIG's `LegacyHistoryDeclarationMigrationTest`) that otherwise makes the recount refuse.
- P2-2 (`f00716f5`): `docs/transactional-mail.md` and CHANGELOG describe the stored position and the wake-queued operator step.
- P3-1 (`3152989b`): the progress frame always rolls back, even when the savepoint rollback throws.
- Two test-design defects found by the first native runs:
  - `d101ca72`: the schema test left a VIEW named `transactional_mail_scan_progress`; `migrate:fresh` drops tables but not
    views on MySQL, so every later migration refused (native at `947ad6d4`: 11 tests, 6 errors).
  - `8b4f31a4`: the delivery worker lacked the in-process test policies the fixtures enable, so it refused as
    `authority_withdrawn` (native at `26ce147d`: 1 failure; the claim race itself behaved).
- P2-1 (MySQL schema exactness) is answered by execution: the migration, `assertOwned()` and every reservation ran on MySQL.

| Check | Source | Result |
| --- | --- | --- |
| Scan progress, recovery, recovery-native (skips) and base `TransactionalMailTest`, SQLite | `8b4f31a4` | OK, 46 tests, 499 assertions, 2 skipped (native-only) |
| Scan progress, recovery-native (two real concurrent workers) and recovery, private MySQL 8.4.11 | `790aef20` (8b4f31a4 + a2144d4a) | OK, 11 tests, 162 assertions |

Deferred to the ledger: P3-2 (MySQL snapshot fixed before the cursor lock), P3-3 (the duplicate-job test does not force both
workers to wait on the row; the stale check runs after the pass), P3-4 (ownership checks on every reservation), P3-5 (wake has no
age threshold; documented as an operator step), P3-6 (SQLite install not atomic), P3-7 notes. Still untested: real SMTP, a real
queue worker or broker, the full PHP suite, Foundation CI. A focused re-review of the fixes is required before publication.
