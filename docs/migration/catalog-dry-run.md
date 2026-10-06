# Synthetic catalog dry runs

**Implemented scope:** a provider-independent T34/WP-12 development increment for deterministic catalog draft planning and private restartable reports. It reads explicit synthetic manifests and synthetic target snapshots. It does not boot Laravel, query a database, inspect BeatStars, read media, import records, update an existing target, publish a product or create purchase rights.

The source audit and real adapters remain unresolved. This increment exercises the identity/title portion of **M-001**, the slug portion of **M-002**, and visibility disposition in **M-012** from the existing [migration map](migration_field_map.csv). M-002 remains partial: host ownership, path/query treatment, canonical decisions and redirects are not implemented here. No source acquisition, parity acceptance, historical reconciliation or cutover gate is closed.

## Run the synthetic fixture

Install the repository's locked PHP dependencies. Create a private report directory outside the checkout; use its canonical absolute path. The command refuses public-permission output directories, group/world-writable non-sticky ancestors, symlink components, hardlinked files, foreign output contents and concurrent writers. A sticky temporary directory such as `/tmp` is permitted.

```sh
umask 077
migration_report="$(mktemp -d /tmp/vasey-migration-XXXXXX)"
php scripts/migration/catalog-dry-run.php \
  --manifest "$(pwd -P)/tests/Fixtures/migration/synthetic-catalog.json" \
  --target "$(pwd -P)/tests/Fixtures/migration/synthetic-target.json" \
  --output "$migration_report" --limit 1
```

Run the same command again to advance the next bounded segment. The three-row fixture plans two draft candidates and one exact mapped skip. Exit code `2` means a retained partial checkpoint; `0` means complete with no conflicts; `3` means complete with explicit conflicts; `1` means invalid input, unsafe storage or a failed operation. A successful synthetic report is not approval to import anything. There is no apply/commit option.

The command writes only `checkpoint.lock`, `checkpoint.pending` and `checkpoint.json` in that private directory. Standard output contains status, counts and hashes, without titles or raw source values. `checkpoint.json` contains the source IDs, hashes, field names and dispositions needed to inspect each synthetic row. Keep an existing checkpoint and both exact input files together. A completed replay is byte-identical to the original completed report.

## Input identity

Both files must be at most 1 MiB and use exact `App\Support\CanonicalJson` version `vasey-json-v1` encoding, optionally followed by one line feed. Object keys are byte-sorted; list order is preserved; strings remain UTF-8; numeric values are integers. Comparing the original bytes to this representation rejects duplicate JSON members, alternate numeric forms and ignored values before accepting their identities. Unknown fields and object/list substitutions are refused. Use `CanonicalJson::encode($value)."\n"` when producing a fixture rather than hand-formatting it.

The manifest requires schema version 1, fixture marker `vasey-synthetic-migration-v1`, synthetic batch/source/operator IDs, acquisition method `synthetic_fixture`, explicit UTC acquisition and source-as-of timestamps, and up to 1,000 records. Each record has a synthetic source ID, source SHA-256, and exact title/slug/visibility value. Titles must begin `SYNTHETIC `; identifiers and slugs use the bounded `synthetic-` namespace. The record digest is computed over the canonical **synthetic value object**. It does not attest to original BeatStars export bytes or media. Real raw artifact hashes, protected acquisition and adapter provenance require later work.

The target snapshot contains its synthetic identity, up to 1,000 target projections and up to 1,000 immutable mapping fixtures. A mapping binds source-system plus source-ID, source-value digest, transform version, target ID and the hash of the entire target projection. Target identities and mapping ownership must be unique. Separate source namespaces cannot inherit each other's mappings. Mapping data is part of the snapshot fingerprint, never a mutable hint outside the run identity.

The fixed transform is `vasey-synthetic-catalog-draft-v1`. No runtime policy, credentials, current database configuration or external account is selected from an input file.

## Plan and conflicts

Rows sort by source ID and source-value digest. Each row is exactly one of:

- `create_draft`: an unmapped, unambiguous candidate with its original title/slug identity, preserved source visibility, draft target visibility and an opaque deterministic candidate reference. This reference is not an allocated target record ID.
- `skip`: the source mapping, transform, retained target digest and exact expected draft projection all agree.
- `conflict`: duplicate source IDs, competing planned slugs, occupied target slugs, changed sources or targets, missing mapped targets, changed transforms, incompatible mapped projections, unknown visibility or sold-state disposition.

Public, private and unlisted source states remain explicit in evidence; eligible proposals are always draft. Sold and unknown records require an explicit disposition instead of silently becoming ordinary draft candidates. There are no updates, deletes or lossy automatic resolutions. Counts reconcile to the number of source rows. Raw titles stay in the input; the report carries field names and proposed-value hashes. Rights, prices, contracts, consent, media roles and historical grants are neither inferred nor accepted by this schema.

## Restart and file safety

Every checkpoint binds schema, canonicalization, transform, batch/source identity, full input-manifest and target-snapshot hashes, the full plan hash and total row count. Before resuming, the planner recomputes the whole plan and compares the entire retained prefix, cursor, counts and completion state. Changed inputs, mappings, reordered results, extra fields and inconsistent counts fail closed. A `--limit` of 1–1,000 controls checkpoint progress; it does not bypass validation of the complete bounded input.

The writer holds an exclusive nonblocking lock, writes a private pending file and atomically replaces the checkpoint only after the bytes are flushed. An interruption before replacement preserves the last complete checkpoint. A surviving pending file is retained and refused: inspect it alongside the checkpoint and exact inputs, then remove only that unreferenced pending report if appropriate before retrying. Never remove source evidence or change a checkpoint to skip a conflict. Report cleanup is a local operator action; this tool does not implement a production retention schedule.

## Verification and next dependency

```sh
php vendor/bin/phpunit tests/Unit/CatalogDryRunTest.php tests/Unit/CatalogDryRunCommandTest.php
```

On 2026-10-06, the focused command passed **58 cases / 537 assertions**, zero failures or skips, in 3.379 seconds on PHP 8.4.26. Pint passed all five PHP files. The documented fixture also completed three real CLI passes with `--limit 1`: exit codes `2, 2, 0`, final counts `create_draft=2`, `skip=1`, `conflict=0`, and stable plan SHA-256 `6bfbd368cce8b8742dd349da64a0e3974d1e0995f040a36af035e1544228e08e`.

Focused verification covers deterministic sorted plans; interrupted/completed replay; source/target/mapping drift; duplicates and slug collisions; draft/private/public/sold/unknown handling; malformed/ambiguous JSON; explicit synthetic admission; count reconciliation; private path/output admission; lock contention; and preserved checkpoints after failure. These are database-independent tests. SQLite or MySQL runtime claims would not add evidence for this tool's pure/file behavior. The integrating PR records the exact tested commit and independent review.

Next implement an adapter from an authorized immutable source snapshot with actual acquired fields and hashes, plus a read-only target snapshot adapter. Real catalog commits require reviewed mapping/policy, audited source mappings, target writer fences, conflict disposition, transactional idempotency and current domain validation. Media validation, historical contracts/grants/consent, product-family adapters and legacy URLs remain their separate dependent work under the [cutover runbook](cutover_runbook.md).
