# Current preview at the recording track fence

This bounded WP-03 repair addresses [PR #4 review finding 4171519063](https://github.com/SeanVasey/VA-Studio/pull/4#discussion_r4171519063), reported against `a000a663c77fda2b017478f850277aec46dc089f`. A recording writer could open a MySQL Repeatable Read snapshot before waiting for the track lock. After a processor committed replacement outputs, the writer's ordinary preview lookup could still accept the old selected preview and create an immutable obsolete association.

## Reproduction and correction

The test-only source `20b4d62f56efe6556554a2d8d99a32a52599e87f`, composed with reviewed focused selection and exact SQLite skip registration at `51a2b88202579fe6e350853fd55bdb1b50533e85`, leaves the application runtime identical to the reported candidate. Its four MySQL datasets exercise real media processing and independent database sessions. The diagnostic source is separate from the runtime correction so native results can distinguish the defect from the fix.

Native [diagnostic run 37095186436](https://github.com/SeanVasey/VA-Studio/actions/runs/37095186436), on `9b2f7cff7aad39ef3ced246ab57944b1f9bea662` (tree `15139eb25744ea61657d0f3ac9f28d2d7882dff6`), reproduced the defect on MySQL 8.4.11 and PHP 8.4.26. The focused suite executed 50 cases and 1,301 assertions with exactly two failures and no errors or skips: both obsolete-preview datasets returned `saved` with the old preview instead of `rejected`. The outer worker also reported the same old preview before and after the service and retained caller transaction level one. The other 48 cases passed, including both quarantined-source submissions. This source adds only a temporary isolated CI trigger to the test-only composition; its application runtime is still the reported candidate.

`BindStemsToRecording` now selects the latest ready preview through a current locking read after acquiring the track lock. On MySQL, it forces the existing `media_assets_track_id_role_status_index`, with equality on track, role and status, to bound the scan to ready previews. SQLite retains its previous planner behavior. The submitted master lookup is scoped to the fenced track; stems track identity is checked again. Submitted stems and master IDs do not gain new row locks.

Actor authorization still precedes the track fence. The processor's existing source-before-track order is unchanged. No transaction is restarted or isolation level lowered, and current-binding inspection, immutable evidence validation, audit attribution and private-byte checks remain in place. An obsolete preview is rejected before downstream association verification can accept evidence from an old caller snapshot.

## Proof boundaries

`StemsRecordingPreviewConcurrencyTest` covers root and caller-owned transactions. A real processor holds the track lock before writing its replacement. A separate authorized recording writer then waits on that exact primary-key record; the independent observer verifies both connection IDs and the wait before releasing the processor. The test requires completed replacement processing, rejection of the old preview, no recording row or association audit, and unchanged original media rows and bytes. The outer-transaction dataset also requires the caller to retain its transaction and its ordinary preview snapshot to remain old despite the current selection rejecting it.

Two negative datasets hold the binder's track while the processor holds its exact quarantined source record and waits on that track. Submitting the source as stems or master must execute preview selection and reject while those locks remain held. Releasing the caller transaction must then let processing complete. This checks the new scan does not introduce a track-to-quarantined-source wait. It does not establish general deadlock freedom, single-record-only locking, or resolve every processed-source regeneration/foreign-key interaction.

The change makes the deciding preview selection current. Other immutable media, processing and association relation reads retain their existing semantics; it does not promise that every newly committed asset is visible through an older caller snapshot. Existing authorization, integrity, idempotency and binding race tests remain required alongside the four new datasets.

## Acceptance evidence

The two new MySQL-only method identities are registered explicitly for SQLite; CI derives all four dataset skips from the actual inventory. No broad skip exemption is introduced. PHP and Composer are unavailable in the editing environment. Source review and local whitespace checks are not runtime acceptance. Native red/green results and the final full acceptance source belong in the integrating PR; no status-only source commit is required to record later green runs.
