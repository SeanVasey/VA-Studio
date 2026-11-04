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
