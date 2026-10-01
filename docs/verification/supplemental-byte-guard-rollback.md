# Supplemental byte-guard rollback ownership

October 1, 2026. This bounded correction addresses the exact-head PR #89 review finding that migration 000030 unconditionally dropped a UUID trigger during rollback even if its definition had changed. Independent inspection found the same behavior in supplemental hash migration 000029. The correction changes only those two migrations and their two existing feature suites; it does not rewrite retained evidence, change original byte predicates or relax the SQLite skip policy. T04 and final PR #89 acceptance remain open until the corrected integrating source passes complete Foundation and merges.

## Reviewed behavior

Both rollback paths inspect the entire expected present guard set before their first DDL statement: 13 hash guards and 14 UUID guards. Installed guards must have the unique expected raw catalog name, schema/table ownership, timing/event and existing normalized definition. Differently cased or unrelated guards are refused. Only verified guards are dropped, using the inspected `main` schema on SQLite or the explicit database on MySQL. Already-absent guards are tolerated so an interrupted rollback can be retried. Unsupported database drivers are refused.

The original lifecycle, relational and immutable-evidence guards and every retained row remain in place. This uses the existing quiescent migration procedure; metadata preflight does not add a global writer lock. The original `up()` control flow, fields, predicate generation and SQL definitions are preserved at the functional correction boundary, with installed-guard discovery strengthened to reject case-folded ownership collisions. Later formatting changes whitespace only.

Functional source is local review checkpoint `266f81ba1b3e647c82d48421068384b53a3fdad5`, tree `e43fe2ff3ba54a3da3e5b83ef024c985968d332f`. Its parent correction `c988f61d2b14862046d463b085b3c8328172d95b` adds three cases per existing suite while retaining all 15 original case identities and bodies. The six added identities test late body/table/timing/event drift before any earlier drop, a lone differently cased late guard, and missing-guard/down-twice/up-twice behavior with original protection and temporary-schema retention.

Rejected rollback snapshots compare the complete raw schema and retained rows, including MySQL trigger `ACTION_ORDER`, and explicitly require zero DDL. For successful rollback only, MySQL renumbers this derived ordinal when a trigger is dropped. The two new successful-rollback helpers therefore preserve every other raw trigger field and independently compare surviving relative execution order within each schema/table/timing/event chain, asserting positive unique ordinals. SQLite retains its complete raw metadata comparison. This fixture correction changes neither production nor the rejected-down snapshots; exact MySQL 8.4.11 dictionary/view source and independent review established the renumbering behavior before execution.

## Actual focused execution

The isolated [run 36849859190](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36849859190), attempt 1, checked out remote `e291261f4fa298132155e0cfbea5600505826a1c`, tree `ca2c3827338af68a914053137946185054868dae`, with actual sole parent `190184bbcd4c644df7cccc76a9a5cd71f8c56956`, tree `4e0e72270195a7ac3701ef281e05a1764b401cc5`. Against that published parent, the source guard permits only the four reviewed PHP modifications and the temporary workflow addition. Against permanent functional tree `e43fe2ff`, only that temporary workflow differs. Workflow SHA-256 is `b4c7bd0fd8ec545c06117433c29f2bfcda93e7d29a881b0df734c5120aef2016`.

Genuine PHPUnit discovery and execution select only `HashByteGuardMigrationTest` and `UuidByteGuardMigrationTest`, once each engine. All 21 identities reconcile discovery, source methods and JUnit exactly once, with positive assertions for every case. PHP 8.4.26/PHPUnit 12.5.34 and zero initial tables are recorded on both engines. Provider/notification flags stay disabled, mail uses the array transport, and fixtures use only synthetic payment/delivery evidence.

| Actual job | PHP result | Separate job outcome |
| --- | --- | --- |
| SQLite `110328670742` | 21 executed, 372 assertions, zero errors/failures/skips; Hash 10 and UUID 11 identities. SQLite 3.45.1, `:memory:`. Syntax passes all four paths. | Failed only at subsequent Pint: four paths/four style issues. |
| MySQL `110328670376` | 21 executed, 2,268 assertions, zero errors/failures/skips; Hash 10/1,181 assertions and UUID 11/1,087 assertions. MySQL 8.4.11, repeatable-read, `lower_case_table_names=0`. Syntax passes all four paths. | Success; Pint is deliberately run once on SQLite. |

MySQL JUnit testcase time sums are 92.910357 seconds for Hash and 105.254225 seconds for UUID; the CLI reports 198.169 seconds. The overall run remains **failure** because of the SQLite formatting step. A passing component does not turn that run into a full acceptance pass.

| Original artifact | Bytes | ZIP SHA-256 |
| --- | ---: | --- |
| SQLite `11154743846` | 22,517 | `65749f43e4fb320ccc54afa55379b5a1cfe26467f706707a7ec3f3605486a389` |
| MySQL `11155645392` | 18,502 | `53b1fc2f98188f09e32902d109c8e2b36b9667fc48332e39fd63edb7e28ee018` |

Original digests match fresh API metadata; ZIP CRC, all 13 logged source hashes, actual parent/tree, supported runtime, discovery, census and JUnit were independently verified. The SQLite archive includes the actual Pint proposal. No test, assertion, discovery identity, warning flag or skip was removed to obtain the PHP passes.

## Formatting successor and final gates

The actual Pint proposal produces local review checkpoint `f5346209a63d8ae50a889181a7dc7b8eb91e5eb4`, tree `0a48b3471a5d58bcb208bec67e6886a4875be878`. Its 28 exact hunks affect only the same four PHP paths. Whole-file reverse/reapply checks reproduce both sources, retaining strings, comments, all predicates and all 21 case identities. Local inspection is separate from the actual locked-runtime bridge below.

The [formatting run 36851633540](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36851633540), attempt 1, and job `110334404852` pass on remote `42906878075edc3a98ecd477124c11182d7c2eb2`, tree `67baaf3583ef2142b3e99f0d35603981d49cbada`, with actual sole parent `e291261f4fa298132155e0cfbea5600505826a1c`, tree `ca2c3827338af68a914053137946185054868dae`. Its reviewed source guard allows only four PHP formatting modifications and replacement of the temporary workflow; against permanent formatted source only its temporary workflow differs. Workflow SHA-256 is `effeb0c7498e5bcb24f54b2ca3cec2c70554a23996b8cfc78ba2b557ea63bf00`.

Actual PHP 8.4.26 syntax passes all four paths. `TOKEN_PARSE` retains every non-whitespace token, including comments and literals, and verifies equality across actual tested parent, current formatted source and its complete inverse. Token counts are migration 000029 1,268, migration 000030 1,211, Hash suite 5,085 and UUID suite 5,274. All 28 unique inverse hunks (5/5/9/9 per path) reconstruct the complete old file bytes. Pint passes all four paths. The original 35,194-byte artifact `11155044876`, SHA-256 `87de3c30d06c1e232c28e2c21844da3d738d138fb3bc28d88e09a61b8e0faad0`, passes independent metadata-digest, ZIP CRC, exact 15-member, 13 current-source-hash and four actual-parent-snapshot checks. The embedded patch matches the original SQLite Pint proposal SHA-256 `5a4980a8337eefbfc48989be1f90c952c4842d28296e7ea371323cddabe11c2b`. This bridge executes no PHPUnit or frontend selection and supplies no new functional census or full acceptance.

The earlier Foundation run on `190184bb` remains evidence only for its exact source. It cannot accept this rollback correction or formatting successor. Fresh complete Foundation, strict current-run database receipts, independent exact-source review and expected-head merge remain required. No third standalone shared inquiry/image selection or duplicate 21-case replay is needed solely to format these files; the final full suites must still execute the corrected integrating source. The [shared migration recovery](mysql-migration-recovery.md), [byte-integrity follow-ups](media-integrity-followups.md) and broader work-package/release boundaries remain separate.
