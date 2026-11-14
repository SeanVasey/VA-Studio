# C4b schema lane — pure and SQLite evidence, native work pending

Owned worktree: `/workspace/VA-Studio-member-original-facts`.
Original installed source: `7edd614497b930c5bc02141537cc7e24dce6fb27`.
Schema implementation commit: `6b76eea88c9be83889d0940bd77fa2c6ab70e2b1`.
Config-reference detachment correction commit: `3561eee8351d83aec11af996687f4b2d63ea271c`. Its final source fingerprints are retained in `source-detach.sha256` (the first implementation fingerprints remain in `source.sha256`).

No database, migration, provider, network or CI test body was executed by this lane. The generated native printer example is synthetic; it is not actual SHOW output. Parent subsequently ran the complete SQLite schema file: 6 tests / 17 assertions, zero failures/errors/skips, XML time 15.556634s in the retained combined canary (whose separate authoring case initially errored). This lane did not rerun it. Native red/green proofs remain unexecuted; `native-plan.md` and `native/run.py` now prepare the exact selection. In particular, this packet does not claim native currentness, native savepoint/read-only compatibility, or a passing race test.

## Pure installed-plan comparison

Exact commands, cwd the owned worktree:

```sh
source /workspace/.va-studio-toolchain/activate.sh
git show 7edd614497b930c5bc02141537cc7e24dce6fb27:app/Domain/Grants/Member/MemberGrantSchema.php > /workspace/runtime/member-original-facts-evidence/schema/MemberGrantSchema.before.php
php /workspace/runtime/member-original-facts-evidence/schema/compare-plan.php > /workspace/runtime/member-original-facts-evidence/schema/compare-plan.log
```

The runtime script renames only the old class to load it beside the new one, invokes the original builders through driver/prefix-only facade doubles, and compares every definition, complete create statement and all 16 guards. No PDO is constructed. All four comparisons passed with exit 0: SQLite/main and MySQL/synthetic, each with empty and `unit_` prefixes. The retained log hashes the complete plans; these original-source values are the four new pure test golden values.

## Focused pure checks

Initial and final (before reference-detachment follow-up):

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_HOST=127.0.0.1 DB_PORT=1 vendor/bin/phpunit tests/Unit/MemberGrantExpectedPlanTest.php tests/Unit/MemberGrantSqlDefinitionTest.php --log-junit /workspace/runtime/member-original-facts-evidence/schema/pure-first.xml
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_HOST=127.0.0.1 DB_PORT=1 vendor/bin/phpunit tests/Unit/MemberGrantExpectedPlanTest.php tests/Unit/MemberGrantSqlDefinitionTest.php --log-junit /workspace/runtime/member-original-facts-evidence/schema/pure-final.xml
```

Both passed: 21 tests, 44 assertions, 0 failures/errors/skips, exit 0. Final XML duration 0.010172s. These tests extend `PHPUnit\Framework\TestCase`, do not boot the application and do not use a DB. Coverage includes old installer equivalence, unissued snapshot rejection, bounded current-table printer differences, engine/collation/unique/check/default/counter changes, literal byte preservation, and trigger definer handling.

After parent identified retained PHP references in copied config arrays, the snapshot now recursively rebuilds bounded scalar/array values. The same focused command with `--log-junit /workspace/runtime/member-original-facts-evidence/schema/pure-detach.xml` passed: **24 tests, 49 assertions, 0 failures/errors/skips, exit 0**. The added tests mutate caller-held nested host and current/previous key references after capture, verify captured values remain unchanged, and refuse recursive/object config values. Log/XML/exit are retained.

All eight owned files passed `php -l`. Initial lint found `Cannot use temporary expression in write context` for an inline WeakMap assignment in CurrentSchema. It was corrected to initialize the registry before assignment, before any PHPUnit execution. Initial scoped Pint failed on formatting in CurrentSchema and SqlDefinition; retained `pint-first.log`/exit identify fixers. Scoped `pint` corrected only owned files and scoped `pint --test` passed. The detachment follow-up used scoped Pint on CurrentSchema and ExpectedPlanTest; retained its log. No check result is inferred from an unexecuted command.

Owned scoped Pint/lint paths are the four schema implementation files, two Unit tests, and two Feature files in `source.sha256`. `git diff --check` and `git diff --cached --check` passed before the implementation commit.

## Implemented boundaries and pending proof

- Snapshot is issuer-registered; lookup cannot adopt caller plans.
- Current assertions use captured raw native/framework identities and detached relevant config; terminal methods do not call Rows/frame framework getters.
- Shares the original MembershipRows/owned MembershipAwardTransaction savepoint. A separate nested schema savepoint was rejected during static review because SQLite outer RELEASE removes later savepoints. Release must succeed before reissue; no rewind or transaction start/commit/rollback from schema verifier.
- Committed raw uses the existing frame's private captured source/sentinel. The parent issuer owns frame registration; only the combined operation finishes it.
- All five qualified member tables are opened before any record consumption. Current full SHOW structure and all 16 known SHOW CREATE TRIGGER statements are compared to the internally generated plan.
- Unknown extra-trigger inventory remains a supplementary information_schema snapshot limitation under old RR. The current known-trigger/table claim is separate.
- Native feature file includes an old-source mode (`MEMBER_SCHEMA_BASELINE=1`) selecting the existing `MemberGrantSchema::assertOwned(PDO)` API, never an absent new method. It deliberately asserts refusal after proving stale dictionary rows; proposed cases are a changed table, missing required guard, and changed required guard before pins. These cases have not run. Writer metadata waits are bounded to 5 seconds. A root-coordinated private-native slot and exact-source runner are still required.
