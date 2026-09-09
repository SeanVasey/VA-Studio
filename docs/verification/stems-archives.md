# WP-03 private stems archive verification

Scope: bounded WAV-only ZIP ingestion on top of merged PR #29 (`b2da258a7689c5d5783f92cff29d2772a450b7f5`). This is archive processing, not recording association or entitlement delivery. Issue #3 remains open.

## Acceptance surface

- Synthetic stored/deflated members and safe folders pass real libzip, member scanning, RIFF/audio probing and full FFmpeg decode, then produce one rebuilt immutable private archive with exact WAV hashes and canonical member evidence.
- Traversal, absolute/drive/backslash paths, hidden/reserved/ambiguous names, excessive depth, case collisions, file/directory conflicts, links/special files, encryption, unsupported compression/nested files, corrupt CRC/lengths and non-audio members fail without ready assets or leftover scratch files.
- Entry/member/total/ratio bounds reject before expanded-member scanning. An elapsed-budget fixture rejects a delayed member scan. These small synthetic fixtures verify enforcement without creating real decompression bombs.
- Member/output scan failures preserve the source and permit a same-profile retry without duplicate output. Changed archive policy creates a new revision; missing or mismatched member/output evidence makes a revision unavailable.
- Filament ZIP intake ignores forged readiness fields. Unauthorized intake/queue requests and both public/operator-preview archive access are denied. Stems do not generate previews or alter measured track duration.
- Offer publication retains its exact recording-revision blocker for a technically processed stems archive. The next increment must establish that association explicitly.

## Execution evidence

Run `php artisan test` against MySQL 8.4 and `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test`, plus the existing frontend/build/audit and operator-browser CI gates. The PHP ZIP extension and real FFmpeg binaries are installed by the existing CI job. No workflow or dependency lockfile change is required.

PHP and Composer are unavailable in the editing workspace. [PR #30](https://github.com/VASEYDEV/VASEYAUDIO/pull/30) records the actual tested remote commit, CI run links, counts and any failures/corrections; this document does not claim an unexecuted test passed. Review the final PR verification table before acceptance.

Initial CI at `ee23d19be7d91093f37cfd43be237f2f5fc6129f` passed 242 MySQL tests with one test-fixture failure: the simulated published track omitted its mandatory permanent slug. The corrected fixture supplies that reservation; the production URL trigger is preserved. Both initial frontend jobs and Chromium/WebKit operator jobs passed. Local `npm test -- --maxWorkers=1` passed all 39 tests and `npm run build` passed after the default parallel run hit one existing 5-second storefront timeout. CI keeps the default test settings. Final backend outcomes and candidate identity are recorded in PR #30.

Fixtures are generated at runtime and contain synthetic tones only. The scanner is an explicit testing-only double. Tests do not establish real ClamAV detection, physical-device upload behavior, full seller-catalog processing, actual stems alignment, production resource isolation, crash recovery, object-store privacy or launch readiness. Independent security/media review remains required; self-review is not independent review.

## Reference and recovery

Implementation uses PHP's [indexed read streams](https://www.php.net/manual/en/ziparchive.getstreamindex.php), [entry statistics](https://www.php.net/manual/en/ziparchive.statindex.php) and [external attributes](https://www.php.net/manual/en/ziparchive.getexternalattributesindex.php). It never calls `extractTo` with uploaded paths and never copies the source archive as the ready deliverable.

No database migration is introduced. Stop intake/processing to roll back code, preserve all original and promoted revisions, and reprocess under a new policy when necessary. Earlier application code will not recognize stems revisions as verified; no stems offer or purchased entitlement should depend on this ingestion-only increment. See [operations](../media-processing.md) and [ordered handoff](../development-order.md).
