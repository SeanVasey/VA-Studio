# WP-03 stems recording association verification

Scope: explicit recording association and frozen offer evidence on merged PR #30 / main `a58f0d36dbbc00a6c43b287e1cc0ecc5b09041bc`. Issue #3 remains open. This increment does not infer musical correspondence, approve actual terms or complete delivery.

## Regression coverage

- `StemsRecordingTest`: Filament confirmation, canonical/private audit evidence, preserved processing parents/bytes, stems-only offer/quote evidence, public response minimization, fresh master digests despite cached normal reads, direct and mounted authorization, stale previews, wrong roles/tracks/quarantine/publication state, idempotent repeats, immutable model/SQL evidence, tag regeneration, new recording rejection and audit rollback. Existing non-stems snapshot shape is preserved.
- `StemsRecordingConcurrencyTest` and its separate-process worker: MySQL observer verifies the exact track row wait and winning/losing connections before releasing the winner. Conflicting confirmations must produce one attestation and one audit event. SQLite intentionally skips this concurrency proof.
- `StemsArchivePolicyTest`: separately persisted historical v1 runs/assets remain verifiable under v2 and changed configuration; the v2 duration limit is fingerprinted, queued drift requires a new run, actual member validation uses the frozen limit, and unknown/mismatched versions or invalid duration evidence are rejected.

## Review findings addressed

PR #30's automated review identified [historical archive version invalidation](https://github.com/VASEYDEV/VASEYAUDIO/pull/30#discussion_r3970465878) and [an unfingerprinted duration ceiling](https://github.com/VASEYDEV/VASEYAUDIO/pull/30#discussion_r3970465886). Explicit v1/v2 validators and the v2 frozen ceiling address those findings. These prior findings are independent input, not an independent pass on this new implementation.

## Execution record

PHP and Composer are unavailable in the editing workspace. The integrating PR must record the actual tested remote commit, MySQL/SQLite counts (including intentional race skips), frontend/build/audit and Chromium/WebKit CI links. Test definitions alone are not successful execution evidence. `git diff --check`, local frontend and build results are recorded in the PR after execution.

Independent authorization/media/migration review remains required. Real ClamAV detection, physical-device association/upload interaction, seller export alignment, full catalog performance, isolated production workers, recovery and object-store acceptance remain unperformed. Synthetic fixtures contain generated tones and a testing-only scanner.

## Operations and next step

Apply the additive migration without backfill. Preserve populated association rows, all referenced media, historical archive validators and frozen offers when rolling back application code; do not run the destructive migration `down()` on retained production evidence. Corrections require a new stems revision and explicit confirmation.

After this increment is verified and accepted, proceed to WP-04 typed rights/caps/variables and consistency fixtures in the existing [ordered plan](../development-order.md). Retain broader WP-03 production and storage gates.
