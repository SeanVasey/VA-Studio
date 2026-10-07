# Configured mail and account feature adapter callback repairs

Author source is frozen at `101e3832e8e5637cab784eb2a800d60642a746b4` in `codex/identity-adapters-callback-repair-20261007`. This is the ordinary terminal repair directly after `cdc99263b8fd9b624cce326519545e0fe5f18d05`, the committed terminal repair after `7ccf880d5af1e61b604c9dd9e27743fc7b1e5238`. Independent final review of 101e remains required. The committed-only cdc approval `da12d465abb47a20a64e7255bf9ab428b2defd27` does not approve the subsequently discovered ordinary path or the successor.

The coherent child includes the configured TLS/AUTH SMTP adapter and sealed current/original account-feature identity APIs originally authored at `d9b393762562b654b526b37651ed5cb05fd08bfe`. Approved identity installer dependency floor `3c2158e726a3bd3fdd57597adcb5756c49dff2ce` remains byte identical; its migration and dedicated dependency tests are mapped. The production transport remains unbound/default off. All actual delivery checks use an ephemeral local synthetic SMTP sink; no external credentials, messages, account relabels, provider writes, CI, push or merge occurred. SMTP 250 records submission acceptance, not mailbox receipt or identity verification. The fixture must receive the mailbox proof and pass the real identity writer before current access exists.

## Genuine failures and final behavior

The independent original committed probe (SHA-256 `443444572e6540a6994c0dfd2be339ea9d523ea5fdf9688d5aedacb57ab3521f`) installed a genuine Laravel lazy secondary PDO resolver after admission. 7cc invoked that callback after the feature policy check, allowed feature withdrawal and released the private response. Original red is retained unchanged at `historical-7cc/`, with 1 case / 3 assertions / 1 failure / zero errors. cdc now refuses unresolved primary or secondary raw PDO state before invoking any resolver. The committed reader guards this admission before old ordinary `getPdo()` calls. Its final close remains one use, read only, deadline bound and attached to the captured physical writer/frame.

Before final handoff, the author reproduced the related ordinary Laravel transaction defect on cdc. The unchanged own probe (SHA-256 `b60e892c2a2703932d54a08293f30daecde55a559cdf83793118da8d2e6e5719`) showed a lazy secondary callback withdrawing the feature after its final check, one durable module write, and a released private response. The original red 1 case / 3 assertions / 1 failure / zero errors and source-bound snapshot are retained as `ordinary-original-red.*` and `historical-cdc/ordinary-feature-callback-snapshot.json`.

101e admits only the original primary in its normal framework transaction and already resolved idle secondary connections before the ordinary terminal policy/current proof. It does not execute a lazy resolver to authorize a commit. Actual SMTP-enrolled tests prove lazy and null secondary refusal, zero callback execution, normal rollback of the module write, and successful normal commit with an already resolved idle secondary. The public API and old account policy stay unchanged.

Both original probes now pass unchanged on SQLite and native MySQL. All four final snapshots bind 101e and show callback 0, feature still enabled, refusal and no released response. Both ordinary snapshots additionally show zero committed module rows. The normal mutation continues to use Laravel transaction events; only subsequent private-output validation uses the separately owned raw read-only frame. A failure after an original normal commit retains its durable writes and produces an unknown result; it cannot restart the original request or renew identity/deadline.

## Exact author checks

Source remained frozen during these checks. Runtime PHP is the existing rootless PHP 8.4 toolchain. Native server is **8.0.46-0ubuntu0.24.04.4**, isolated schema `vaseyaudio_production_identity`. This is not hosted MySQL 8.4 acceptance. The private environment was sourced without printing it; loopback permission was used only for the synthetic sink/native checks.

| Final 101e selection | Recorded / executed | Assertions | Result |
| --- | ---: | ---: | --- |
| Whole SQLite adapter suite | 31 / 30 | 210 | Zero failures/errors; one native visibility skip |
| Original two SQLite probes | 2 / 2 | 8 | Pass |
| Native ordinary lazy/null/resolved + committed one-use positive | 4 / 4 | 35 | Pass |
| Original two native probes | 2 / 2 | 8 | Pass |
| Native concurrent credential withdrawal under READ COMMITTED | 1 / 1 | 6 | Pass |
| Pint five changed source/test paths | — | — | Pass |

The exact SQLite skip tuple is `Tests\Feature\ProductionIdentityAdapters\ProductionIdentityCommittedFrameTest::test_native_validation_reads_current_committed_credential_instead_of_an_old_repeatable_read_snapshot`; its actual native execution is green 1/6. It is a visibility test using a separate physical connection, not a claim of a broader native race matrix.

Commands use `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=` and the existing activation script. SQLite ran `php vendor/bin/phpunit tests/Feature/ProductionIdentityAdapters --log-junit …/successor-101e/sqlite-adapters.xml` and the unchanged owned `probes` directory. Native sourced the private production-identity task environment with `VA_IDENTITY_ISOLATED_NATIVE=1`, then ran the selected feature/frame files with filter `ordinary_terminal|one_use|latest_committed`, the unchanged probes, and the exact `test_native_validation_reads_current_committed_credential` filter. The first filter executes four cases; `latest_committed` matches no extra method, so the actual visibility case is explicitly recorded separately. All stdout/JUnit pairs and fixed snapshots are durable.

`source-map.json` binds 17 paths by Git blob and SHA-256. Only five paths differ from 7cc: the two runtime callback admissions, feature terminal admission and their two test files. `receipts.json` records exact source, method tuples, JUnit counters and artifact hashes. The earlier full native 7cc suite 25/146 and SQLite 25 recorded /24 executed/163 plus native-only skip remain historical original-source receipts at `historical-7cc-source/`; they are not a full successor rerun. The cdc checks remain separately labelled historical because they exposed the ordinary defect. The retained development secondary-memory fixture error is harness evidence, never acceptance.

## Consumer contract

`ProductionAccountFeatureAccess::forRequest` requires the actual T23 session marker and server authenticated actor; no old `CustomerPrincipal` conversion or adoption by email. `lock` precedes module/catalog locks. `verifyOriginalBinding` authenticates the original immutable origin/verification binding, and `proveCurrent` runs last after module callbacks. Fixed feature versions cover listening library, consent preferences and service projects. Module records must retain the explicit feature/buyer binding; no unbound old record is adopted.

For post-commit private response validation, retain the original evidence and monotonic deadline once. `IdentityCommittedFrame::begin(CurrentRows, originalDeadlineNs)` owns one raw read-only transaction at framework depth zero on the same captured permanent writer/schema. Native validation is READ COMMITTED and omits locking reads. Call `lockCommitted` before fixed nonlocking module reads, then original-binding committed proof and `proveCommitted` last. `proveCommitted` finishes the physical frame internally; `close` belongs in `finally`. Return only a prebuilt DTO. No write API, nested application transaction, identity renewal or event/audit bypass is authorized in that validation frame. The random savepoint is solely the approved physical sentinel.

Registration/binding remains root-owned and separately reviewed. Live mail account/sender/host facts, actual external delivery and full integrated acceptance are open. An identity adapter alone does not make free production licensing or fulfillment operative.
