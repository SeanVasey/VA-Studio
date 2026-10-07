# Independent PR25 discovery epoch recovery review

Approved exact corrected executable `ed285b6a2b34bc1a2a2c91c42b64d68f317965af`,
tree `0d22cdda957ab72b4430c7029c2f2b8a1fd990c8`, against published PR25 head
`f713ce641cb043c9171c4a3b35c2e666d2bf146e`. No remaining blocker on this source.
Approved its exact fresh-main composition
`626f7d6688c5d528dc12baec3fbd4afe647f63e7`, tree
`cf0e6724f98b247a91831053cc9c2355b1b5eee4`, including the additive CI selector.
Exact-head PR preflight/merge remain the parent task's publication gates.
This review used an isolated worktree and an independent limited-account native
database, `vaseyaudio_pr25_review`, separate from the author's database.

The only product change is the epoch migration. It resumes an exact owned
installation prefix instead of assuming MySQL can undo partially committed DDL.
It validates all reserved identities and complete existing table/constraint/index/
trigger definitions before any mutation. A table-only or empty-table prefix can
resume; a protected prefix without its seed, a guard gap or advanced incomplete
history refuses. A fully installed singleton can retain its advanced epoch after
final-assertion or migration-log uncertainty; it is not reseeded or replaced.
Recovery creates only missing objects and never drops/replaces surviving objects.
`down()` still refuses before any database access, preserving migration bookkeeping.
Application writers and competing migrators must remain stopped during installation;
this interface does not promise live/concurrent schema recovery.

Two independently reproduced identity blockers in predecessor `6a5da20` are fixed:

- SQLite allows table/index/view identities to coexist with a same-name trigger.
  Reducing `sqlite_master` with `array_column(name)` hid a foreign `cde_own_insert`
  table behind a later valid trigger; the old migration then executed 53 missing
  guard writes. The successor checks every folded reserved identity for exact
  name/type and duplicates before reducing the catalog. The original independent
  canary now refuses before DDL and preserves all schema/rows/guards.
- MySQL's trigger dictionary treats `cde_own_insért` as conflicting with ASCII
  `cde_own_insert`; PHP lowercase comparison missed that native alias. Actual
  predecessor recovery committed the table and seed, then failed its third
  attempted write with native error 1359. A prepared 55-name join now applies the
  actual `utf8mb3_general_ci` dictionary comparison, followed by exact raw-name
  ownership, before any DDL. The original native canary now refuses without writes.

The final code retains exact table engine/column/storage/comment/index/check
validation, dependency and temporary-shadow checks, trigger event/timing/body
validation, seed bounds and installation order. The missing-seed test restores
the delete guard after synthetic deletion, so its refusal proves missing-seed
handling with an intact prefix rather than being masked by a guard gap. Native
permanent-row snapshots use a separate native connection, correctly observing
permanent rows even while the primary holds temporary shadows.

The reviewer read the actual migration/test diff, original epoch/guard and snapshot
source, real Migrator and disposable-fixture behavior, retained original reviews,
raw/JUnit evidence and runtime/dependency records. Fresh results and source carry
are retained in [the reviewer evidence directory](cloud-pr25-recovery-independent-20261007/)
and [the final source receipt](cloud-pr25-recovery-independent-20261007/final-review-receipt.json).

| Actual independent execution | Result and scope |
| --- | --- |
| Predecessor native recovery subset | Nine actual MySQL cases / 3,901 assertions passed: every before/after creation boundary (112 boundaries inside one case), final assertions, real Migrator log uncertainty, partial-history/gap and temporary-shadow refusal. |
| Extra native metadata canaries | Four cases passed initially; a fifth reviewer fixture hit native CHECK/auto-increment setup error, was repaired without product edits and passed separately. Different CHECK grouping also passed refusal; corrected two-case selection was 2 / 9. |
| Predecessor independent SQLite namespace red | 1 / 2, one failure; old source adopted the foreign same-name table. |
| Predecessor independent native dictionary red | 1 / 2, one failure; old source reached DDL and native collision error. |
| Corrected successor SQLite | 4 / 40, zero errors/failures/skips: independent red-now-green, all 16 actual namespace variants, intact-prefix missing seed and installed idempotency. |
| Corrected successor native MySQL | 5 / 82, zero errors/failures/skips: independent native red-now-green, eight table/trigger namespace variants, eleven accent aliases across absent/empty/partial prefixes, intact-prefix missing seed, successful trigger-failure recovery and real Migrator log/retry preservation. |

Successor runs used own regenerated Composer metadata/autoload with read-only
package-directory symlinks. All 158 package version/source/dist identities match
the unchanged lock; reflection resolves application/test classes to this review
worktree. Actual native runtime is **MySQL 8.0.46-0ubuntu0.24.04.4**, PHP 8.4.26,
flush-at-commit 1, sync-binlog 1, doublewrite ON, binary log disabled and case mode 0.
Private task credentials are outside Git and absent from these artifacts.

Exact method-text hashes show common `up`, `down`, table metadata and shadow
validation remain byte-identical to the tested predecessor. The existing native
preflight adds only the alias lookup; SQLite adds only reserved-identity validation.
Table SQL and all 54 guard SQL definitions are unchanged. The 112-boundary sweep
therefore carries with explicit mapping rather than being repeated; corrected
native preflight success/refusal and real recovery were executed afresh.

Frozen author evidence head `f304a32b894b3fba651435f89909e3d4d44b67fd`, tree
`ba3c240cfb1ce3e18482910753c5d67c6c36354f`, adds evidence only after the executable.
All 39 author artifact digests and all three executable source hashes/blobs match.
Final author JUnit confirms SQLite 28 / 4,008 and native MySQL 8 / 118, with zero
errors/failures/skips. The preserved predecessor native sweep is 26 / 3,973;
its explicitly mapped unchanged coverage carries without counting overlapping
selections as additional tests.

Fresh-main composition retains all 112 scoped catalog/inventory/migration/test/
lock/database-configuration and native-exception blobs byte-for-byte. Main's
changes since `717f086` add no migrations. The conflict resolutions preserve
the original PR25 checkpoint under explicit historical labels alongside current
main status. The selector adds `DiscoveryEpochRecoveryTest` as the sixth
`track-discovery` file and protects it from native-only skip exceptions; the
32-file cap and existing native exception identities remain unchanged. An
independent run of `python3 scripts/ci/test-focused-tests.py` in an isolated
checkout of exact `626f7d6` passes all 50 checks. Composed product execution
carries through the recorded identical source and dependency graph; it was not
rerun merely to attach a new commit label.

All 119 original composed/corrected/author discovery artifact digests verified
without mismatch. Original selected discovery snapshot/concurrency tests remain
byte-exact; their genuine MySQL 8.4.11 14-case / 362-assertion proof remains
historical evidence for the unchanged runtime. It does not establish the new
recovery preflight on MySQL 8.4. The original pre-repair migration failure,
independent red canaries, reviewer launch/setup failures and their raw/JUnit
receipts remain visible; overlapping selections are not added together.

No author product source, shared documentation, dependencies, CI policy or skip
exceptions were edited by this review. No push, merge, hosted workflow or full
matrix was launched. Actual recovery on the target MySQL 8.4 runtime, final
Foundation/browser acceptance, production commerce and cutover remain open.
