# BeatStars catalog dry run on the real export (S-9), 2026-11-07

First run of `scripts/migration/beatstars-dry-run.php` on Sean's real BeatStars Studio export. This is development evidence
from the Claude Code harness on `main` `6ceea924`. Nothing was imported or applied, and no database was opened
(`database_writes: 0`). Every source file stays in a private `0700` store outside Git. Only hashes and counts are recorded here,
following `docs/migration/beatstars-export-normalization.md`.

## Sources

The export, manifests and contracts are ledger entries SRC-024 to SRC-034 in
[`docs/migration/source_ledger.csv`](../../migration/source_ledger.csv). The dry run reads SRC-024
(`6c779d03…ff42e`, 99 rows: 76 `BEAT`, 23 `SONG`).

## Derived sheet

The normalizer refuses three shapes in the export as it was received. A deterministic script, kept with the private sources, writes a derived copy:

| Column | Export | Derived |
| --- | --- | --- |
| `BPM` | `79.0` (every row) | `79` (only an integral `N.0` changes) |
| `Key` | `E_FLAT_MINOR`, `NONE` (50 rows) | `Eb minor`; `NONE` becomes blank (unknown, not guessed) |
| `Basic License Price` (added) | `Enabled ($40.0)` in `Basic License ($40 MP3)` | `40.0` (enabled on all 99) |
| `Rights Reference` (added) | absent | Sean's statement, 2026-11-07: `Sean Vasey (owner statement 2026-11-07: beats sole-owned, no samples)` |

Every other cell is copied unchanged. The mapping uses `authorized_read_only_audit` and `derive_from_title` slugs. It sets the
artist constant `Sean Vasey` (from the export's own "Prod. by Sean Vasey" filenames) and maps `PUBLIC` to `public`. Its only
offer is `Basic License`, and it ignores 27 columns explicitly (other license tiers, free download, collaborators, media paths
and audit notes).

## Results

| Run | Export (derived) SHA-256 | Mapping SHA-256 | Result |
| --- | --- | --- | --- |
| 1, rights column empty | `9e9b2df7…d675` | `fb23752e…77bf` | exit 3: 99 rows withheld, the only finding `missing_rights_reference` (99) |
| Probe, placeholder reference (deleted after) | n/a | same | exit 0: 99 normalized, `catalog.json` 81,297 bytes |
| 2, owner statement | `68b54b17…967d` | `fb23752e…77bf` | **exit 0 `dry_run_clean`: 99 rows, 99 normalized, 0 findings** |
| 2, repeated into the same directory | same | same | exit 0, all three files `unchanged` (byte-identical) |

Run 2 outputs: `catalog.json` `0033fc34…cae3` (the `snapshot_sha256` that the importer review will bind),
`beatstars-dry-run.json` `964a8a8e…bcff` and `beatstars-dry-run.md` `d0a010df…f9e8`.

## Contracts (SRC-027 to SRC-034): checked, not used

The six verbatim `.docx` contracts equal the exported templates exactly, both the normalized template text and the tier
variables. The store does not use them yet: offers and contract rendering are separately authorized work. For that work:

- The templates use three placeholders that are not in BeatStars' variable list: `{NONMONETIZED_VIDEO_STREAMS_ALLOWED}`
  (tiers 01 to 05), `{SAMPLES_DEFINITION}` (all six) and `{TRACK_PRICE_WORD}` (Exclusive). They need defined values or
  approved wording before any contract is rendered.
- Every tier's governing law resolves to `Iowa`, while the archived older contracts used Texas. Sean has to confirm Iowa.
- The non-exclusive tiers contain the typo "The Licensee to shall make payment". The verbatim text is retained as it is; any
  correction needs Sean's approval.
- None of the contracts contains the seller's street address.

## Still open (not gates for this dry run)

- **Publication rights for the 23 songs.** Sean's statement covers the beats. 19 song titles credit featured artists, and only
  2 have BeatStars-recorded splits. Cleared rights remain a publication gate (AGENTS.md).
- **Media.** It was not transferred or validated here. The export's own audit notes report one Studio master that is probably
  rendered from MP3 (TK3114951, no stems) and MP3 stems for TK3156047.
- **Import.** Applying it is Sean's decision. It needs the importer review (`scripts/migration/persistent-catalog.php review`)
  against a live target, which does not exist until the staging host (stage E).
