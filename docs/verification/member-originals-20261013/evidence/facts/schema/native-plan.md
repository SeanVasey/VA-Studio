# Planned native member-schema regression selection

Preparation only. No MySQL connection, database command, native test, or repository edit was performed while preparing this packet. `native/run.py` was parsed with Python `ast.parse` only. Run only after the coordinator grants this lane the exclusive private-native slot. Parent's later SQLite result is separately recorded below.

## Exact sources and test identities

Old checkout: `/workspace/VA-Studio-member-policy-admission`, clean exact `15a65e0761382a9ad50c4b279605a644a4eeb285` at preparation. This is genuine pre-fix source with the existing `MemberGrantSchema::assertOwned(PDO)` implementation. It is not represented as HEAD `7edd6144`. Read-only `git diff 15a65e0761382a9ad50c4b279605a644a4eeb285 7edd614497b930c5bc02141537cc7e24dce6fb27 -- app/Domain/Grants/Member/MemberGrantSchema.php app/Domain/Memberships/Production/MembershipRows.php tests/Support/FinalizationDatabaseMigrations.php database/migrations composer.json composer.lock` was empty. The runner repeats this proof and records relevant old/base blob SHA-256 values before execution. Parent approved reuse, avoiding another worktree.

Fixed checkout: `/workspace/VA-Studio-member-original-facts`, **exact clean SHA provided by parent at execution**. Do not assume the preparation-time HEAD remains final. The runner refuses dirty source or a different HEAD, verifies application/schema/Rows/migration trait autoload paths, fingerprints all tracked files plus private Composer metadata and external programs before/after, and records the supplied exact SHA.

External test: `native/MemberGrantNativeCurrentSchemaTest.php`, byte copy of the committed file introduced in `6b76eea88c9be83889d0940bd77fa2c6ab70e2b1`. Both phases use these same bytes. No product/test repository file is rewritten to switch sources.

Old selection: the `test_current_definitions_refuse_changes_hidden_by_an_older_rr_dictionary_snapshot` data sets `#0` (extra current table column) and `#1` (missing required named guard), selected with PHPUnit's `method#0-1` filter. `MEMBER_SCHEMA_BASELINE=1` invokes only the existing old `assertOwned(PDO)` API. Imports/reference declarations for new classes are not instantiated by this selection. Each case must first complete the original metadata assertions and demonstrate that the subsequent dictionary reads still equal the earlier RR values. The desired refusal assertion then fails because old code actually admits the current changed schema. Missing classes, signatures, errors, skips, timeout/setup failures, wrong test selection, or any other failure text invalidate the baseline receipt.

Fixed selection: all four cases in the external file: the same changed-table and missing-required-guard cases, changed required guard body, and positive current SHOW/read-only committed-frame case. Must pass all four without errors/failures/skips. Actual assertions, durations and exits will come from retained JUnit, not predicted counts.

The unmodified native regression file performs complete disposable migrations per case and teardown with `FinalizationDatabaseMigrations`. This is six native cases total across two serialized phases. Existing native evidence suggests migrations dominate duration; this runner does not launch other suites or CI.

## Private instance and schema boundary

Existing private MySQL recipe:

- Expected MySQL version: `8.4.11` (must query and record again at execution).
- Host/client socket: `/workspace/runtime/mysql-policy-run/mysql.sock`.
- Published localhost port: `33413`; runner connects by that private Unix socket.
- Expected server-reported port: `3306`, socket `/run/mysql-policy/mysql.sock`, datadir `/var/lib/mysql/`.
- Configured private data mount from existing instance recipe: `/workspace/runtime/mysql-policy-data` (recorded as configured, not a new host-verification claim).
- **Only owned schema:** `vaseyaudio_member_original_facts`.

Before schema creation the runner verifies native socket type, exact configured endpoint and server identity. It starts no server/container and changes no instance settings. It creates only the named owned schema if absent. Framework migrations/drop cleanup target that explicit schema. The test's independent writer inherits the same schema/config and sets its own session `lock_wait_timeout=5`. Temporary table/guard changes are restored after the reader transaction rolls back. No provider or production access, customer input, payment, active entitlement import, CI dispatch or host/cutover claim is involved. No environment secrets/keys/password values are recorded; only allowlisted non-secret environment fields and source hashes enter evidence.

## Commands (planned, not yet run)

Activate the installed toolchain, then run each phase separately when its source is frozen and the native slot is granted:

```sh
source /workspace/.va-studio-toolchain/activate.sh
python3 /workspace/runtime/member-original-facts-evidence/schema/native/run.py \
  --phase old --root /workspace/VA-Studio-member-policy-admission \
  --sha 15a65e0761382a9ad50c4b279605a644a4eeb285 --label native-old-01
```

```sh
source /workspace/.va-studio-toolchain/activate.sh
python3 /workspace/runtime/member-original-facts-evidence/schema/native/run.py \
  --phase fixed --root /workspace/VA-Studio-member-original-facts \
  --sha PARENT_FROZEN_40_CHARACTER_SHA --label native-fixed-01
```

The literal placeholder is intentionally not executable. Use the root-approved actual fixed head. Existing evidence labels cause refusal; every initial error is retained and a later justified run needs a new label. No automatic retry loop.

Each result directory records setup stdout/exit, actual queried version/source paths, exact PHPUnit argv/cwd/allowlisted environment, complete source/loader/probe hashes before and after, PHPUnit stdout/exit/XML, selected case identities, verification result, and artifact hash manifest. The old expected PHPUnit exit is **1 with exactly two desired assertion failures**. A successful runner exit means the expected red receipt was validated; it does not relabel failing tests as passing. Fixed expected PHPUnit exit is 0 with four passing cases. Any verification problem is retained as `verification-error.txt`.

Known residual: supplementary unknown-extra-trigger inventory can remain an older information_schema view under RR. The native claim here concerns current full table structure and the 16 **known required** trigger definitions. Synthetic rows/DDL and a private mysqld do not prove host/provider behavior.

## Separate SQLite result already observed by parent

The complete `MemberGrantCurrentSchemaTest` file passed in parent's serialized canary: **6 tests, 17 assertions, 0 failures/errors/skips**, as recorded in `/workspace/runtime/member-original-facts-evidence/sqlite-schema-authoring-canary.xml`. The combined selection's authoring case initially errored independently; that initial combined result remains retained, and parent reports the corrected separate authoring case passing 1 test/9 assertions. Do not report the initial combined selection as passing. This native preparation performed no SQLite rerun.
