# Independent native fixture correction review

Approved executable `e9557569766cdbade50024868faef4d3187ba90e`, tree
`9588a17ab23c8cb017343fa5a910701c3c3998af`, for focused development integration.
Changed guard-test SHA-256:
`53bb16d3b94cab6689f8ef403471766623776cffa89b110f22a193429b482c00`.
No source or CI policy correction is requested.

The single changed test path is additive. Removing only the new SQLite helper,
four helper calls and two new ordinary-DML cases restores every predecessor
byte. All 19 prior adversary cases retain their original assertions: twelve
recursive-trigger-off SQL mutation/replacement cases, two transactional-DDL
callback cases and five PRAGMA-based damaged-history cases. No exception tuple,
skip or CI setting changed. SQLite now supplies the mechanics those fixtures
actually require; MySQL trigger DDL cannot promise transactional rollback.

Independent execution in a separate worktree and fresh normal-durability
MySQL 8.4.11 passed **3 cases / 31 assertions**, with no errors, failures or skips.
PHP 8.4.26 used own regenerated autoload paths, and all 158 installed package
versions/references matched the lock. Both native DML callbacks actually fired
at transaction level one; refusal restored complete relevant rows, native
migration bookkeeping and all native trigger definitions, retaining the same
primary PDO at transaction level zero. The isolation canary used a separate
SQLite PDO and verified untouched native records; destruction callbacks restored
the original default connection and primary PDO. Its deliberate SQLite segment
is not native trigger or concurrency proof.

I read back the author JUnit artifacts and matched all **1,124** recorded source
hashes to this reviewed worktree. The author native outer-harness selection passed
79 / 596 with zero errors/failures/skips; it contains 14 actual MySQL cases and
65 explicit SQLite canaries. The author SQLite selection passed 78 of 79 / 582,
with one preexisting native-engine-only skip. These are author executions, not
additional reviewer executions.

An initial reviewer marker supplied null to the existing nonnull subject_id
column and failed before fixture isolation. Both rollback canaries passed in
that attempt. The marker correction and final clean run are retained separately,
alongside a corrected autoload-wrapper invocation diagnostic. Neither changed
author source. The original failed 77-case composed run remains preserved at
`d5c6934d26c193b8ae53d4f1b4bd85b28228d324`.

No hosted/full-matrix run, native race, browser/provider acceptance or launch
readiness is claimed. Final acceptance stays manual and pinned to the exact
reviewed integrated candidate under the existing low-cost policy.
