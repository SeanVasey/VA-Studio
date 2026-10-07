# Membership rollback shared integration review

Approved for focused development integration at `211577eece5c4f9a3c708db25e4ad67d4462721e`, tree `e129b27ad12311c9f02924f93162779c813e427d`. No remaining blocker in the seven-path membership corrective scope was found. This disposition does not release the separate PR #17 preparation-packet race hold or substitute for a fresh cheap preflight on its final head. The successful older preflight `37547488659` applies to its older head.

The preserved baseline is `9a5feca718b91df6df8e77b0deabde11c56b6257`, tree `72394289e71845b84aaa8a3976ceb1c21dd70699`. The first composition `e96dc3dcfc18979cbf6eda1fee47e23c327f7a80` has ordered parents shared `d907168fa072f4084474a43c2e3d8f8f5c836dcf` and author `62998cf19662cfd1c17330cf9ff9b9c3630e1b04`. The final two successors change only the new selector and its Python guard; the three authored paths are byte-identical to the exact author freeze and independently executed first composition.

| Owned scope | Paths | Review result |
| --- | ---: | --- |
| Migration 230000, membership migration test, component verification document | 3 | Exact author bytes preserved; rollback refusal precedes mutation and Migrator repository deletion |
| Customer account migration fixture, focused selector/control, manual workflow choice | 4 | Empty children disposed in dependency order; exactly two affected classes selected; old policy preserved |

Migration 230000 now unconditionally throws `LogicException` from `down()`. A successful no-op had allowed Laravel's real Migrator to delete the migration repository row while retaining its schema. The refusal preserves that record, including for an empty or partial installation. The complete file prefix before `down()`, including `up()`, every schema definition and every immutable guard, remains unchanged. No membership domain or administrator behavior changed.

The new empty/populated regressions call the real Artisan rollback command with the real migration path and one step. They compare complete migration bookkeeping, four table definitions and indexes, twelve triggers, membership rows, users, accounts and audits. The populated fixture retains a real synthetic grant and reservation. Ordinary migration succeeds afterward, and a generated disposable forward migration actually creates its table and repository row and accepts a marker insert while retaining the earlier evidence. These are meaningful command regressions; the negative receipts separately demonstrate the missing record against old application bytes.

The account fixture first asserts all four membership tables are empty, then drops events, buckets, versions and plans in reverse foreign-key order. Foreign-key enforcement stays enabled. All original membership/account assertion, failure and expected-exception lines are retained in order. The sensitive reviewer separately inspected all eleven restrictive membership foreign keys and confirmed this is the sole affected installed-schema parent fixture.

The first selector guard used doubled namespace separators and therefore did not recognize an injected waiver. An unchanged external, disposable canary proved that failure against the actual e96 guard: the forbidden membership waiver was accepted. The corrected guard uses canonical separators and explicitly checks the membership class. The same canary against final 211577 produces one expected assertion failure and no error, proving the forbidden waiver is rejected. Neither the actual policy file nor product source was edited for this reproduction.

All twelve original PHP selector tuples, both engine choices, the 32-file budget and all 148 native skip pairs are unchanged. The new manual `membership-migrations` choice selects only `MembershipCreditMigrationTest` and `CustomerAccountMigrationTest`; neither class has a waiver. The workflow adds only that enum choice. Existing manual cadence, exact-head verification, audits, security checks and repository policy remain unchanged. All original forty acceptance criteria and their status/dependency metadata are unchanged, as are frontend, locks, configuration, administration and unrelated domain source. All 1,606 baseline paths remain; 1,600 unrelated paths are byte-identical; all 1,607 final tracked file hashes were verified against committed and working bytes.

Fresh independent discovery expanded exactly 45 cases in the two selected files. The unchanged strict database-receipt parser matched every case and owning file in both actual root JUnit reports, validated suite counters and enforced the unchanged skip policy.

| Completed execution | Exact source | Cases / assertions | Errors / failures / skips | JUnit seconds |
| --- | --- | ---: | --- | ---: |
| Root affected SQLite, independently parsed | 211577 | 45 / 221 | 0 / 0 / 0 | 8.445255 |
| Root genuine MySQL 8.4.11, independently parsed | 211577 | 45 / 218 | 0 / 0 / 0 | 230.725532 |
| Reviewer selector controls | 211577 | 47 controls | Passed | — |
| Root manual cadence controls, inspected | 211577 | 14 controls | Passed | — |
| Sensitive review actual SQLite | 62998cf | 3 / 44 | 0 / 0 / 0 | 0.863282 |
| Sensitive review actual MySQL | e96dc3d | 2 / 40 | 0 / 0 / 0 | 12.024189 |

Both final root processes exited zero and retained exact head/tree and clean tracked status before and after. Their generated configurations select the exact two files and use explicit synthetic testing connections; the ephemeral key is not retained. MySQL used a fresh disposable 8.4.11 server over private loopback TCP, with flush-at-commit 1, sync-binlog 1, doublewrite ON and binary logging enabled. Membership contributes 38 cases/115 assertions on each engine. The account fixture contributes SQLite 7/106 and MySQL 7/103: its existing native temporary-trigger control returns after its MySQL driver assertion because MySQL has no temporary triggers. No case is skipped.

The first attempted ten-file root runs remain separately preserved as unsuccessful evidence. Their SQLite report records 113 cases, 314 assertions and 58 errors after a missing local key; the MySQL report records 113 connection errors and zero assertions after the focused wrapper overwrote the runner's private connection with hosted defaults. They are not passing results and were not relabeled as executions of final 211577. The final runner uses the local MySQL connection directly and supplies an ephemeral test key. It makes no product change for those harness failures.

This reviewer did not edit repository source, run another PHP product suite/native matrix, dispatch hosted CI or publish a ref. The independent work was actual source comparison, an injected-policy canary, selector controls, discovery and raw receipt validation. Artifact hashes and the complete source manifest are retained beside this report.

The refusal stops reverse rollback when execution reaches 230000. It does not make a larger rollback batch globally atomic, certify out-of-order parent removal or authorize destructive retention cleanup. Selected native behavior is not a concurrency proof. Production payments, enrollment, fulfillment, cutover and full final acceptance remain deferred.
