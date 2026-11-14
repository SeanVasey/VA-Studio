# F2 test-database admission contract guidance

DECISION: **ACCEPT THE BOUNDED DESIGN WITH THE CONSTRAINTS BELOW**. This is design guidance, not implementation/evidence approval or completion of F2.

Read-only source snapshot: `b132f53df88896f01250cafbed8e4d2ab3cd9774` in `/tmp/VA-Studio-staff-mfa-leftovers-20261015`. Reviewed the shared test lifecycle, disposable/native helper, production/staging/local MFA boot fixtures, finalization parent/child lifecycle and representative native schema cases. No application tests, DB/network/CI actions or shared edits were performed.

## Admission contract

Capture an immutable process-selected tuple **before parent TestCase setup/application bootstrap**: `APP_ENV=testing`, supported `DB_CONNECTION` (`sqlite` or `mysql`), exact nonempty `DB_DATABASE`, and the applicable disposable marker. PHPUnit configuration already supplies the ordinary SQLite/testing values. Read the external process selection, not Laravel's mutable application-mode binding or `.env` fallback, and reject missing/invalid values. Do not normalize an unsafe selection into a valid one. Capture per test and keep that original tuple for cleanup; later environment changes cannot authorize another schema.

Override the shared `setUpTraits()` boundary to validate before calling Laravel's parent method, which invokes RefreshDatabase, DatabaseMigrations, DatabaseTruncation and transaction setup. The current post-parent `Tests\TestCase::setUp()` location is too late. Check resolved connection configuration **and actual PDO**: `PDO::ATTR_DRIVER_NAME`; on MySQL, `SELECT DATABASE()`; on SQLite, the main entry in `PRAGMA database_list`. `DB::getDatabaseName()` alone is configured metadata. Preserve exact SQLite `:memory:` with an actual unnamed main database; do not automatically admit a file database or an unknown driver. Reject URL/config overrides that redirect the effective driver/database. Refusal must occur before destructive SQL, without printing credentials or connection URLs.

A connection alias is not a database mismatch. `FinalizationLifecycleFixture` deliberately selects `lifecycle_fixture`, copying the original connection configuration. Permit that name when its effective configuration and actual PDO driver/database match the captured selection. Admit every explicitly selected migration/truncation connection, or refuse unsupported additional targets; validating only the default does not cover a different `--database` or `connectionsToTruncate` target. This boundary does not claim to guard arbitrary raw worker SQL, bootstrap callbacks or later test-authored DDL.

## Preferred local and CI markers

Prefer an explicit **schema-valued local marker**, for example `VA_LOCAL_DISPOSABLE_MYSQL=<DB_DATABASE>`, rather than a Boolean permission for any MySQL database. Require exact equality to the captured selected schema and actual PDO schema, a valid bounded identifier and a dedicated disposable test database declared by the local runner. The marker is an operator declaration, not proof of private-server isolation. The recipe must still use the owned private server/datadir/socket/schema and record its actual lifecycle. Do not set CI flags to obtain local admission.

Keep the existing CI pathway: `VA_CI_DISPOSABLE_MYSQL=1`, `CI=true`, captured external `APP_ENV=testing`, exact selected/configured/PDO database and the established disposable-name restrictions. A missing CI condition must refuse. Keep `DisposableNativeDatabase`'s existing per-lane dedicated-schema checks; a shared marker must not silently authorize its additional destructive native cases. Do not call that helper wholesale from the shared guard: its CI branch requires mutable `app()->environment('testing')`, incompatible with deliberate production/staging route boots. A broad local `prod|live` substring rule also misclassifies synthetic names containing “production” or “delivery”; define local naming deliberately and retain narrower lane admission.

## Finalization setup and teardown

Replace the trait's application-mode-only admission with the captured database contract. Recheck **after any pre-refresh hook and immediately before the effective migration command**. Compute and validate the actual migration target/options once; use those same options for the command, rather than validating one recipe and recomputing another. Capture the resolved connection name and supported cleanup options for the destruction callback. Current cleanup recomputes `migrateFreshUsing()` and may otherwise follow changed defaults/options.

Before `db:wipe`, recheck the selected connection's current configuration and actual PDO against the original captured tuple. A changed default, URL, connection configuration or `USE other_schema` must not cause cleanup of another schema. Bind cleanup explicitly to the captured target; preserve view/type policy and the existing Kernel reset/RefreshDatabaseState cleanup in `finally`, even on refusal. Keep operational guarded migration `down()` behavior unchanged.

## Required compatibility and controls

| Case | Required evidence |
| --- | --- |
| Default SQLite `:memory:` | Ordinary refresh/migration fixtures remain admitted. |
| External non-testing mode or missing/mismatched selection | Refusal before a destructive-command spy/sentinel changes. |
| Local MySQL marker absent/wrong, or actual PDO driver/schema differs | Refusal before migration/truncation; matching explicit private selection is positive. |
| CI marker pathway | Each required flag/name/identity condition has a negative control; existing CI selection remains positive. |
| Production/staging/local MFA route boot | External testing selection remains valid while the original enrollment-required/optional route assertions remain unchanged. Never reset product mode merely to pass admission. |
| Finalization lifecycle aliases and view options | Preserve all four existing success/body-error × alias/view combinations and the subsequent clean setup, including child process propagation of the selected marker. |
| Finalization target/config/PDO/options drift | Setup refuses a changed effective target; teardown refuses another schema without wiping it, and bookkeeping still resets. |
| Representative native schema/dictionary fixture | Its primary selected-schema admission remains valid; separately owned peer-schema creation/cleanup rules remain unchanged. |

Use focused controls and actual affected cases. This review does not request a full native matrix, a complete counterpart census, host verification, provider calls or Foundation/GitLab dispatch. Local acceptance applies only to its recorded source/selection. Native omissions and F2 fixture normalization outside this admission boundary remain open.

Preparation note: `.env.testing.example` and singular `database-receipt.py` filenames were absent; the actual source is `phpunit.xml` and plural receipt scripts. These were failed reads, not application/test failures.

## Immutable source bindings

- `tests/TestCase.php`: `da50491fabe195ca3f6ac9efca53b1992c1967af01f3dc6d970ac6389bca9a93`
- `tests/Support/FinalizationDatabaseMigrations.php`: `38d248d182fffb7aa6c0583ebb8235c5b60b03fe8865d679b9eb804728a792ff`
- `tests/Support/DisposableNativeDatabase.php`: `34399fe6bbb82344636f8e81b9b588ad892fa2b6377bde516c68253d048e3e01`
- `tests/Feature/OperatorMfaTest.php`: `e8d6367ec4eda71dc53afd958410d052b977a18632be7d32d2647c99002522eb`
- `tests/Feature/StagingOperatorMfaTest.php`: `ccc76e4230132dd176f59d4e84f7c6f21e72879a3f13274076741423be97c414`
- `tests/Feature/FinalizationDatabaseLifecycleTest.php`: `83fcde0b2f3ccbbe7b81978ca362281cdb79a69e7ef1347873c4484ec45cc522`
- `tests/Support/FinalizationLifecycleFixture.php`: `7ef29a58fae5d0ce0a81b5c381d9261824b773432bd6051de93b120d91f852f6`
- `tests/Feature/NativeSchemaIsolationTest.php`: `cfcca4b9e35ba152d123e5b3a633e4da4a5bf8c06c1189baac6ba1d56aa80076`
- `tests/Feature/ProductionMemberOriginals/MemberGrantNativeCurrentSchemaTest.php`: `92031df931efdd9aa0def8d63718a64509c0fb366ef7d2c989f2633f2cc0a4cd`
- `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutNativeSchemaTest.php`: `6568b2d9772c948a7215840dd184f4cb1741656e94e21937c71f426fcb1ad4ca`
- `phpunit.xml`: `493369cfb7feea104da4bec214c2cc6526ca2f1b9d937c50a2961fc59cb61aaf`
- `config/database.php`: `432c3fd22702ecce64f7458b7f563292288c700f3c83f547727aa5a92c99bff2`
