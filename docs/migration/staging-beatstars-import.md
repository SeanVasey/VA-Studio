# Staging import of the BeatStars catalog as drafts (BL-71)

**Scope.** `vasey:staging-beatstars-import` loads Sean's real BeatStars catalog into the **staging** store as **unpublished
drafts with their media attached**, so Sean can review each track in the admin and publish it himself. It is the first caller of the
reviewed draft importer (`CatalogDraftImporter::review()` and `apply()`) and it reuses the ordinary media services.

**It does not** create offers, licenses, rights declarations or rights clearances, apply any price from the export, publish or
verify anything, touch customers, orders, consent or DNS, or read any BeatStars API. A draft stays a draft until Sean publishes it
through the ordinary admin flow, which refuses it until rights are verified and an approved license offer exists
(`PublicationReadiness`). The per-track readiness report lists exactly what still blocks each track.

Everything the command reads is private staging input and never enters Git: the export CSV, the mapping, the media files and the
readiness report stay under `/srv/vasey-staging/private/import/beatstars-20261107/`. Tests use only synthetic fixtures
(`tests/Fixtures/migration/beatstars-synthetic/import-*`) and media generated at run time.

## What the command does

1. **Admission** (before any write; a failure prints `Refused: ...` and writes nothing).
   - `APP_ENV` is `local`, `testing` or `staging`; production is refused whether it is the configured or the running environment,
     and the two must agree (`artisan --env=` mismatches are refused).
   - `VASEY_STAGING_BEATSTARS_IMPORT_ENABLED=true` (default false), and Stripe in test mode.
   - The synthetic rehearsal catalog is gone (reset staging first, `docs/ops/staging-runbook.md`).
   - Two existing, **named** staff accounts, `--operator` (the actor of every write) and `--reviewer` (a second person who confirms the
     run), each with catalog authority and the admin panel's MFA enrollment. A `Synthetic ` rehearsal account is refused: real
     content is never attributed to one.
   - ffmpeg, ffprobe, prlimit and the approved seller tag (`MEDIA_TAG_PATH`, `MEDIA_TAG_SHA256`) as for any master processing; the
     command never generates or overrides a tag.
   - The staged snapshot is a valid private snapshot and the media root is a safe directory (below).
2. **Drafts.** The snapshot's records go through `CatalogDraftImporter::review()` and `apply()` in segments of 25. Each unmapped
   record becomes a `draft` track (title, slug, artist, BPM, key, genre, mood, tags, description) with its source id, record hash and a
   `catalog_import_mappings` row; the review's conflicts (duplicate slug, existing target slug, `sold` or `unknown` visibility, changed
   evidence) stop the run before anything is applied. A record is imported **once**: on a re-run, mapped records are skipped.
   BeatStars visibility (`PUBLIC` and so on) is evidence only; every track is created as a draft.
3. **Media**, per draft in export-id order, through `IngestMediaUpload`, the same `MediaProcessor` the media worker runs (ClamAV, the
   approved seller tag, derivatives):

   | Export column | Becomes | Rule |
   | --- | --- | --- |
   | `Master WAV Path` | `master_wav` source, then the tagged preview, the MP3 and the WAV master derivatives | `.wav` |
   | `High-Res Local Artwork Path`, else `Artwork Path` | `artwork` source and its derivative | `.png`, `.jpg`, `.jpeg`; the high-resolution file is tried first and the standard file is the fallback when it is missing, refused, rejected or fails |
   | `Stems ZIP Path` | `stems_zip` source and derivative; **the stems association is not created** | `.zip`, WAV-only archive |
   | `Preview MP3 Path` | **not ingested** | The pipeline generates the tagged preview from the master. The listed BeatStars MP3 is only checked for presence and reported; an untagged preview must not become a public file. |

   **The import never confirms the stems association.** Associating stems with the master and preview is a staff verification that
   they are the same recording, and it satisfies publication readiness, so Sean makes it in the admin (the ordinary stems
   association action) before publishing. The export listing one master and one stems file under one track id is evidence for him,
   not a confirmation. Every imported track with stems shows `stems_association: unconfirmed` and the blocker
   `stems_association_unconfirmed` in the report until he does.
4. **Report.** A per-track readiness report goes to a private file (`--report`), and a count-only summary to stdout. The report path must be a `.json` name in a private directory outside the snapshot directory and the media root, and an existing file there is replaced only if it is an earlier report of this import.

### How files are found

A listed path is an absolute Mac path. The files are copied (below) so that the listed path sits under the **media root**: the file for
`/Volumes/Studio/x.wav` is `<root>/Volumes/Studio/x.wav`. Resolution is an exact match and never searches. A listing is refused, per
track and per role and without stopping the run, as:

| Report outcome | Meaning |
| --- | --- |
| `not_listed` | The export cell is blank (for example the 28 tracks without a stems ZIP). |
| `missing` (shown as `media_missing`) | Listed, but no file at that path under the root. Nothing is fabricated; copy the file and re-run. |
| `refused:path_not_absolute`, `path_traversal` (`.`, `..`, empty components), `path_invalid` (control characters, backslash, padding) | The cell is not a clean absolute path. |
| `refused:wrong_extension` | The extension is not one the role allows. |
| `refused:symlink` | Any component, including the file, is a symlink. |
| `refused:not_regular_file`, `empty_file`, `unreadable`, `outside_root`, `hardlink` (more than one link), `too_large` (over the intake cap: 200 MiB for the WAV master and the MP3 preview, 1 GiB for the stems ZIP since 2026-11-10, 20 MiB artwork) | Not a single-link, regular, non-empty, readable, in-cap file inside the root. |
| `rejected` | The ordinary intake refused the file (content type, size limit: 200 MiB for WAV, 1 GiB for the stems ZIP, 20 MiB for artwork). |
| `failed:<code>` | Processing failed (for example `malware_detected`, `tag_not_configured`, a stems manifest problem, `queue_refused`). |

**Gate before importing stems ZIPs over 200 MiB (2026-11-10).** Run acceptance step 6 of [media processing](../media-processing.md) on the staging host's own ClamAV first: one stems ZIP that expands to the 767 MiB ceiling must process end to end, and the 2047 MiB bomb check must alert. Until both pass, a large stems ZIP may end in `archive_timeout` or `scanner_unavailable` retries, which fail closed and never mark it clean. A stems run holds the quarantine copy, the workspace snapshot, the extracted members and the rebuilt ZIP at once, about 3.5 GiB of private storage per run, plus ClamAV temporary files.

The media root must be a canonical absolute directory (no symlink), owned by the user running the command, and not writable by group
or others. A file that changes while it is taken in is reported as `failed:source_changed_during_read`.

## Operator commands

Run on the staging host as the deploy user, from the release directory. `<IMPORT>` is
`/srv/vasey-staging/private/import/beatstars-20261107`. Nothing below is copied into Git.

### 1. Prerequisites

- The release containing this command is deployed; staff `--operator` and `--reviewer` exist (`php artisan vasey:create-admin`) and
  have enrolled the authenticator app at `/admin`.
- The seller tag is configured and ClamAV answers (`php artisan vasey:doctor`, `docs/ops/staging-runbook.md` section 3).
- The synthetic rehearsal catalog has been reset (section 5a).

### 2. Copy the media (from Sean's Mac)

`paths.txt` lists the absolute paths, one per line, from the five path columns; `--files-from` with `/` as the source keeps each
absolute path under the destination:

```sh
python3 - <<'PY' > /tmp/beatstars-paths.txt
import csv
cols = ["Master WAV Path", "Stems ZIP Path", "Preview MP3 Path", "Artwork Path", "High-Res Local Artwork Path"]
seen = set()
for row in csv.DictReader(open("/path/to/beatstars-catalog-derived-20261107-r2.csv", encoding="utf-8-sig")):
    for col in cols:
        value = row[col].strip()
        if value and value not in seen:
            seen.add(value)
            print(value)
PY
rsync -a --files-from=/tmp/beatstars-paths.txt / deploy@STAGING:<IMPORT>/files/
rm /tmp/beatstars-paths.txt
```

Then on the host, drop any group or world write bit rsync preserved from the Mac (the media root must not be writable by others):

```sh
umask 077
chmod -R go-w <IMPORT>/files
chmod 0750 <IMPORT>/files
```

### 3. Stage the snapshot

The snapshot is the dry-run tool's output for the derived export and mapping
(`docs/migration/beatstars-export-normalization.md`). The derived export must keep the five path columns; they are ignored by the
mapping and read by this command from the hashed raw export.

```sh
umask 077
mkdir -p -m 0700 <IMPORT>/dryrun <IMPORT>/source/raw <IMPORT>/reports
php scripts/migration/beatstars-dry-run.php \
  --export <IMPORT>/input/beatstars-catalog-derived-20261107-r2.csv \
  --mapping <IMPORT>/input/beatstars-mapping-20261107.json \
  --output <IMPORT>/dryrun
cp <IMPORT>/dryrun/catalog.json <IMPORT>/source/catalog.json
cp <IMPORT>/input/beatstars-catalog-derived-20261107-r2.csv <IMPORT>/source/raw/beatstars-catalog-derived-20261107-r2.csv
chmod 0600 <IMPORT>/source/catalog.json <IMPORT>/source/raw/*
```

The dry run exits `0` only with zero findings (and then writes `catalog.json`); fix any finding first. The `raw/<basename>` copy must be
the exact export the dry run read: the snapshot binds its SHA-256 and byte count, and the command refuses a changed file.

### 4. Turn the flag on for the run only

Set `VASEY_STAGING_BEATSTARS_IMPORT_ENABLED=true` in the staging environment file and reload configuration exactly as for
`VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED` (`docs/ops/staging-runbook.md`, section 5a). Production refuses the flag in
`ops/production/validate-runtime.php` (`holds.vasey_staging_beatstars_import_enabled`) and the command refuses production in code.

### 5. Check, then import in batches

`--check` runs every admission check, the draft importer's read-only review and the file checks, asks no password and writes
nothing. It prints counts only:

```sh
php artisan vasey:staging-beatstars-import --check \
  --operator=OPERATOR_EMAIL --reviewer=REVIEWER_EMAIL \
  --source=<IMPORT>/source --files-root=<IMPORT>/files
```

Expect `records=99 already_mapped=0 to_create=99`, `review: create_draft=99 skip=0 conflict=0` and, per role, how many files are `ok`,
`missing`, `refused` or `not_listed`. A non-zero `conflict` exits 1 and names the reason counts.

The write run asks for both passwords at hidden prompts in an interactive session (it refuses `--no-interaction`; a piped password is
accepted, the confirmed passwords are the gate). On staging `--attest` is required: it records one `staff.console_attestation`
audit row per account before the first write.

```sh
php artisan vasey:staging-beatstars-import --attest --limit=10 \
  --operator=OPERATOR_EMAIL --reviewer=REVIEWER_EMAIL \
  --source=<IMPORT>/source --files-root=<IMPORT>/files \
  --report=<IMPORT>/reports/readiness.json
```

- The first run creates all 99 drafts (segments of 25), then takes in media for up to `--limit` drafts (ClamAV and the pipeline take
  minutes per track, so batch). Repeat the same command until the summary shows no more drafts needing media; every run continues where
  the last stopped, and finished steps are kept and never duplicated.
- `--from=<export track id>` starts the media pass at that id, in id order (string order), to skip drafts that fail on a known cause.
- A draft whose listed files are all missing or refused costs only file checks and does not use `--limit`.
- The run holds a cache lock, so a second run cannot overlap it.

Exit status: `0` when nothing was rejected or failed (missing files are not failures), `1` for a refusal, a stop, or media that was
rejected or failed (the report names each).

**Order matters.** The draft importer refuses a mapped track that has gained media, so every record is imported before the first file is
attached; after that the importer is not asked again. Records added to the export later cannot be imported with this command once media
exists. Add them through a new snapshot before any media is attached, or create them in the admin.

### 6. Read the report, then turn the flag off

`<IMPORT>/reports/readiness.json` (mode `0600`, rewritten atomically on every run, including after a stop) holds, per track: the draft
id, slug and title; each of master, artwork and stems as `ready`, `quarantined` or `absent` with what the export listed
(`ok`, `missing`, `not_listed`, `refused:<code>`); the stems association (`associated` or `unconfirmed`); the last attempt's outcomes; `media_missing`; and
`publication_blockers` straight from `PublicationReadiness`. It holds no file path. Stdout carries a count summary and the blocker
histogram only: no path, title, identifier or personal value.

Set `VASEY_STAGING_BEATSTARS_IMPORT_ENABLED` back to `false` and reload configuration.

## What stays for Sean

- **Rights.** Each track still needs a rights declaration and its verification, which are Sean's evidence. The export's `Rights
  Reference` stays in the snapshot as an operator reference only, not clearance.
- **Offers and prices.** None are created and the export prices are not applied. Licenses, offers and prices are Sean's, in the admin.
- **Publication.** Sean publishes through the ordinary reviewed manifest flow once the blockers clear.
- **Stems association.** Sean confirms, in the admin, that each stems ZIP is the master's recording. The import leaves it unconfirmed.
- **Missing and rejected files.** The report lists each; copy the file, or upload through the admin, and re-run.

## Verification

Focused development checks, not acceptance: `tests/Feature/StagingBeatStarsImportTest.php` (admission refusals, production refusal,
idempotent re-run, `--limit` and `--from`, missing, refused and rejected files, no offers, licenses, rights, quotes or publication,
count-only stdout, attestation), `tests/Unit/Migration/BeatStarsMediaFilesTest.php` (path escape, symlink and extension refusals),
`tests/Feature/StagingEnvironmentAdmissionTest.php` (the policy is a registered admitting file) and
`tests/ops/test_production_runtime_validator.py` (the production hold). They run on SQLite with real ffmpeg derivatives and the
test-only scanner and seller tag; no real export, path or file is read. No hosted staging run, real ClamAV scan or real export import
is recorded.
