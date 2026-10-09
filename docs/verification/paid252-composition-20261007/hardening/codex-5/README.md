# Paid252 Codex round 5: spool reservation and the render profile release rule

Development evidence from the Claude Code harness for PR #56, code commit `32440644`. Not Foundation or final acceptance.
No schema, migration, guard, receipt, deadline or authorization lifetime changed, and no file pinned in
`resources/contracts/*/profile-assets.json` was touched (`paid-v1/profile-assets.json` sha256 is still `4a2221b6…3940`).

## Finding 1: spool space was checked per slot (`PaidGrantPrepareStream.php:60`, fixed)

Each of the three spool slots compared `disk_free_space()` with its own size alone, so concurrent snapshots that fit
individually could together fill the filesystem. The fix ports the reviewed Free256 design (`ProductionFreeGrantSpool`,
`ProductionFreeGrantSpoolReservationTest`):

- **Admission:** slot selection and the free-space check run under one short exclusive `flock` on
  `paid-spool/admission.lock`.
- **Subtraction:** free space must cover this size plus the 16 MiB reserve, after subtracting the bytes reserved by every
  other held slot. A slot is held if its lock cannot be taken with `LOCK_NB`, and its reservation is read from its
  fixed 20-byte `slot-N.reserve` sidecar.
- **Release:** the reservation is written while both locks are held, set to zero once the snapshot is complete and
  unlinked (the disk then reflects it), and set to zero in `finally` on any failure.
- **No repair, kept from the paid adapter:** a slot whose snapshot exists, or whose sidecar is not a regular single-link
  0600 20-byte file owned by this user, is skipped and never deleted or rewritten. A stale but well-formed sidecar of an
  unheld slot is ignored by others and overwritten by that slot's next holder.

| Run | Source | Result |
| --- | --- | --- |
| Red: new `PaidGrantSpoolReservationTest`, original adapter | `cde97f0a` + test | 3 failures, rc 1 (`backend-red.txt`): two snapshots that only fit alone were both admitted; no sidecar exists |
| Green: `PaidGrantSpoolReservationTest` | `32440644` | 3 tests, 11 assertions, rc 0 (`backend-green-file.txt`) |
| Green: paid family, `--filter PaidGrant tests/Feature`, SQLite | `32440644` | 78 tests (75 + 3), 3,750 assertions, 1 skipped, rc 0 (`family-green.txt`) |
| Pint `--test` on the changed PHP files | `32440644` | passed (`pint.txt`) |

The tests nest a second preparation inside the first one's copy, so concurrency is exercised in one process through the
real locks and sidecars. No multi-process or native filesystem-full run was made.

## Finding 2: a profile advance strands unfinished documents (`PaidGrantRenderProfile.php:35`, documented, not built)

Verified: rendering compares the order's retained profile with `current()`. A deploy that changes a pinned file,
`MANIFEST_HASH` or the base would make every order whose document is unfinished fail with `profile_changed` until its
five attempts are spent. Completed documents are delivered from stored bytes and are unaffected.

Today there is exactly one released paid profile, so there is no historical implementation to keep. The failure needs a
future profile change shipped without versioning. The fix Codex proposes, keeping historical renderer implementations
and resolving by the retained version, is new renderer architecture (a second pinned manifest directory, a retained
renderer entry point and process script), and it is only needed when a second profile exists. Free256 has the same
render-side rule (`requireCurrent`). This round therefore records the release rule in the `PaidGrantRenderProfile`
docblock: before any such change ships, drain unfinished paid documents, or add the new profile as a separate version
that keeps this one's files and resolves the renderer by the retained version. The decision is put to Sean.
