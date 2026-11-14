# C3 commands and evidence reproduction

Final root native source is `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`. Two private MySQL 8.4.11 invocations pass 3 / 63 and 8 / 152, respectively, with zero errors/failures/skips. [Source map](source-map.md) lists all 35 JUnit invocations, including original failures; [coverage reconciliation](coverage-review.md) separates component and final integration evidence.

Exact executed argv, focused filters, source inventories, environment projections, JUnit and exits are linked per invocation in [runs.json](evidence/runs.json). Component command narratives preserve setup and toolchain details:

- [Records and native producer](evidence/records/commands-final.md), [original baseline](evidence/records/baseline/README.md), and [genuine old-writer upgrade](evidence/records/award-upgrade/README.md).
- [Schema and currentness comparison](evidence/schema/commands-final.md), with [migration runbook](evidence/schema/runbook-facts-final.md).
- [Fixture compatibility](evidence/fixture/commands.md) and the exact source/patch receipts next to each fixture result.
- [Final native runner](evidence/root/run-native-integration.sh), [explicit-autoloader runner](evidence/root/run-native-focused.py), and [private-instance setup](evidence/root/setup-private-native.py).
- Consumer, hints, root SQLite, discovery and quality command/source artifacts named in the registry and [non-JUnit command summary](evidence/command-summary.json).

## Private native environment

The final root runs used MySQL 8.4.11 in private instance `va-studio-private-mysql-policy-20261013`, port 33413, socket `/workspace/runtime/mysql-policy-run/mysql.sock`, datadir `/workspace/runtime/mysql-policy-data`, and its own database `vaseyaudio_billing_retrieval_root`. [Setup metadata](evidence/root/native-setup.json) and safe scripts retain the environment assertion and exact recorded version. This is local synthetic development evidence, not host or provider verification.

The canary wall time was 552.859 seconds; the race/postcommit invocation was 976.479 seconds. These are recorded elapsed times, not performance benchmarks or exclusive-CPU measurements. Root reported one separate C4 staging test (forced SQLite `:memory:`, 1 test / 4 assertions in 2.931 seconds) overlapping the final native phase; it shared no database state and is excluded from C3 coverage.

## Offline packet verification

From the repository root, with the referenced Git objects present:

```sh
python3 docs/verification/billing-retrieval-20261013/evidence/rebuild-manifests.py
```

This reads the committed archive only and launches no PHP, application, database or network workload. It verifies all 451 released public artifacts, 35 completed JUnit invocations, 11 separately parsed local commands, 42 source inventories, source stability, captured external program bytes, original component manifests, body-free discovery, policy carry and the actual native worker transcript. It regenerates derived manifests and packet-owned digests. Eight historical source inventories remain partial by design.

During assembly, the explicit released-artifact allowlist was copied incrementally with:

```sh
python3 docs/verification/billing-retrieval-20261013/evidence/collect-released.py --new-only
```

This command needs the original runtime paths and is not required for offline review. It verifies already archived bytes and copies only newly released allowlisted paths; it does not recopy older mutable notes. [Archive index](evidence/archive-index.json) records original and copied SHA-256/byte counts. Of 451 copies, 23 environment-bearing JSON artifacts have explicit safe-field projection and recorded omitted paths; 428 are byte-identical. Private key/environment/database/connection files and raw award row/context/receipt captures are excluded. One diagnostic file, `records/native-active-status-later.json`, is deliberately retained as verbatim PHP error text despite its suffix, not parsed as a test receipt.

The portable rebuild checks:

1. JUnit leaves against suite totals, expected counts and actual exits; passing leaves inside failed invocations keep their failed parent status.
2. Actual Git blob hashes, the explicitly retained new-test capsule, and the two-line omission patch applied only to temporary files.
3. Successful-case precedence by driver plus full case/dataset identity; historical schemas and synthetic timing scopes remain separate.
4. Both original component digest manifests and derived projected copies without reconstructing omitted private metadata.
5. All 25 public discovery listings plus manifest; root's later cleanup receipt accounts for those 26 archived files and the 24 generated configs intentionally omitted. Rebuilding does not need deleted runtime files.
6. Both native workers' distinct process/connection identities, observed waits, actual saved/deadlock outcomes, clean transaction state and exits.

Python syntax is checked without importing/executing the assembly modules. A temporary Git index makes untracked packet files visible to `git diff --cached --check`. The original formatting receipt preserves exit 2: verbatim patch context lines plus six new prose trailing spaces. The prose was corrected; the focused check passes with only archived `*.patch` files and the log quoting those warnings excluded. The exact command/exclusion list and exits are retained under `evidence/assembly-whitespace-*`. Archived patch bytes are never rewritten to satisfy formatting. The final check also passes against all 484 staged packet-owned files, including the 47 public log receipts otherwise ignored by Git.

The packet digest excludes root-owned README, independent DECISION and handoff ledgers, which have their own review/commit lifecycle. Packet assembly and offline checks are not application testing or PR preflight. Foundation CI and GitLab were not dispatched.
