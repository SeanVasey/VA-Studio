# C4a commands and evidence verification

Packet target: `docs/verification/member-source-currentness-20261013/`. Root owns README, DECISION coordination, handoff ledgers and CI maps. This lane owns commands, source/coverage maps and evidence only. Frozen root source is `0095e46aefa85463268027d89c829a4886537ad6` on actual C3 merge `7b467c38`; the integrated native canary passes 1 / 21 with zero errors, failures or skips. The carried [CLAUDE-PLAN ledger](../../handoff/2026-10-07/CLAUDE-PLAN.md) contains the C3 merge/preflight record rather than duplicating it here.

## Executed command receipts

- [R4 final command narrative](evidence/r4/commands-final-release.md) records configuration, the migration-backed staging case, initial frame teardown error, whole SQLite and selected native commands. The [baseline runner](evidence/r4/baseline/run.sh) and source-specific command files bind the same external old/fixed probe.
- [Catalog final command narrative](evidence/catalog/commands-final-release.md) records all nine JUnit invocations, including original parser and native setup errors. Each source receipt retains actual argv and runner hash. Earlier command snapshots remain separate history.
- [Frozen-root native command](evidence/root/run-native-integration.sh), [runner](evidence/root/run-native-focused.py), [setup](evidence/root/setup-private-native.py) and [native notes](evidence/root/README.md) retain the actual integrated canary at `0095e46a`.
- Root [quality summary](evidence/root-quality-summary.json) verifies the nine policy/style commands from actual argv, runner/log hashes and exits. Original shard failure and corrected passes remain separate.
- [Discovery shell](evidence/selection/discovery.sh), listing manifest and source receipt retain body-free enumeration at `ca0fe30b`. The unreachable native endpoint is `127.0.0.1:1`.

No command is inferred from a filename. The parser run named “green” actually errored; the first native “red” timed out before mutation. The later corrected red reached the intended original-source refusal, and fixed source passes separately.

## Private native environment

Both component lanes use PHP 8.4.26 and private MySQL 8.4.11 on host port 33413, socket `/workspace/runtime/mysql-policy-run/mysql.sock` and datadir `/workspace/runtime/mysql-policy-data`. Separate owned schemas are `vaseyaudio_member_policy_admission`, `vaseyaudio_member_catalog_20261013` and root `vaseyaudio_member_currentness_root`. Metadata distinguishes host and container server paths/ports. This is local synthetic technical evidence, not host/provider/activation verification.

R4 selected native four passes 4 / 14; catalog fixed native five passes 5 / 46; root integrated reservation canary passes 1 / 21. Catalog whole SQLite eligibility passes 16 / 123. Their commands and source identities stay separate. Recorded elapsed times are not performance benchmarks. The accidentally migration-backed early staging method (SQLite in memory, 1 / 4, 2.931 seconds) overlapped C3 native work without a shared database; it is disclosed in R4 notes.

## Offline reproduction

From the repository root, with the referenced Git objects present:

```sh
python3 docs/verification/member-source-currentness-20261013/evidence/rebuild-manifests.py
```

The verifier launches no PHP, application, test body, SQL or network service. It checks 279 public archive entries, 17 completed JUnit invocations, 23 compact source comparisons, both component release manifests (63 and 109 members), the 83-member root local release and 15-member native release, source stability/program hashes, discovery partition identities, policy/style carry and cleanup receipts. Two early parser source comparisons remain historically partial.

Seventeen large full public source receipts are retained in deterministic gzip form; compact executable/resource-input projections keep the readable source maps focused. Six environment objects are projected to explicit public flags/paths. Original/public/archived hashes, lengths and omitted JSON paths stay in the archive index. No keys, environment files, private database/connection files or raw customer/award records are copied. A projected receipt is never represented as byte-identical to its private original.

During assembly, newly released artifacts were copied with:

```sh
python3 docs/verification/member-source-currentness-20261013/evidence/collect-released.py --new-only
```

That command needs the owner runtime paths; offline review needs only the committed archive. It verifies existing archived bytes and never rereads older mutable command snapshots. Prepared or historical source capsules remain labelled and add no test execution claims.

All 25 discovery listings plus their manifest were archived before root deleted 50 generated files. The remaining 24 generated configurations are intentionally unarchived; their names, hashes and lengths remain in the cleanup receipt. Offline reconstruction verifies 2,103 selected cases / 202 files from 8,245 / 602 across 24 complete disjoint shards. Thirty-nine untimed files use historical fallback weights.

Initial root quality at `cc612650` has one shard-policy failure in 52 tests, other suites 61/27/14 passing and Pint eight files passing. The explicit include correction at `ca0fe30b` passes all 154 policy tests. The later exact diff to `0095e46a` changes comments only, and all eight executed Pint inputs remain unchanged. This is explicit carry, not a rerun at the later candidate.

The packet digest covers only the four lane-owned maps and evidence. Root README, independent DECISION and external ledgers are excluded. Root authorized the frozen-source packet commit after the native release. The packet author makes no independent acceptance decision.
