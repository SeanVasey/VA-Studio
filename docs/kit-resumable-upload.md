# Private resumable WAV kit upload

The **Resumable kit upload** action opens a dedicated upload page from a sound-kit draft. It sends a WAV sample ZIP to that exact kit and mounted draft version. It does not reuse track IDs, media roles or stems associations. The existing **Upload kit ZIP** action remains available for small files within the host's whole-request limit.

The application permits up to 200 MiB per source, sent as sequential 8 MiB multipart chunks. Sessions last 24 hours and retain at most four uncleared sessions per operator. These are application bounds, not evidence that a production proxy/storage deployment accepts them.

## Operator recovery

Start verifies the selected source SHA-256 before creating or recovering a matching session. The browser remembers only the session ID in per-operator, per-kit session storage. Inspect uses server state. Continue requires the original filename, size and SHA-256 and sends from the server's retained offset. A lost acknowledgment, pause, access failure or uncertain completion requires inspection; attempted bytes are never presumed retained. Another kit's session cannot replace the mounted kit context.

Finish explicitly retains a new private archive revision and requests technical verification. Its success message identifies the retained revision and directs the operator to **Inspect revision** for the processing result. Retention does not establish verification, rights approval, publication, checkout or delivery. A changed draft version or archive profile prevents completion; inspect and cancel remain available for recovery. Reopen the current draft after cancelling an obsolete upload. Terminal transport cleanup can be retried without another source/revision.

## HTTP boundary

Authenticated Filament routes under `/admin/sound-kit-uploads` require current catalog authority, current required MFA and CSRF. JSON commands accept only exact typed fields; chunks accept only decimal offset and one valid uploaded file. Admission rejects foreign origins, cross-site requests, method overrides, query strings, range requests, unsupported content types/encodings and oversized bodies. The multipart envelope is bounded to 8 MiB plus 64 KiB; actual file bytes are independently limited to 8 MiB.

Success projects only `id`, `kitId`, `expectedVersion`, `originalName`, `sizeBytes`, `sha256`, `receivedBytes`, `chunkBytes`, `status`, `expiresAt`, `revisionId` and `cleanupPending`. Models, storage paths and part descriptors are never serialized. Admission, authorization and debug failures use generic private responses with no-store/noindex/no-referrer protection. Signed kit-page Livewire requests retain that protection even when CSRF rejects before component boot. The kit ID and mounted draft version are locked Livewire properties.

The track uploader remains a separate client and controller. Its existing behavior is unchanged; the shared privacy middleware recognizes additional kit endpoints and signed kit-page updates.

## Verification on 2026-10-06 UTC

The following focused checks ran with the companion kit domain/migration present in the integration worktree:

- `php vendor/bin/phpunit tests/Feature/SoundKitUploadHttpTest.php tests/Feature/SoundKitDraftAdminTest.php tests/Feature/ResumableMediaUploadHttpTest.php --stop-on-failure`: **36 tests, 1,094 assertions passed** on PHP 8.4.26/SQLite. This covers real controller/domain round trips, retries, stale versions, exact projection, foreign/revoked authority, required MFA withdrawal, CSRF, strict transport rejection, 8 MiB part admission and actual signed Livewire early-failure privacy.
- `npm test -- tests/frontend/resumable-kit-upload.test.ts tests/frontend/resumable-media-upload.test.ts`: **26 tests passed**, including exact-file recovery, uncertain completion, pause/late receipts, native fetch receiver, kit-context refusal and unchanged track-client regressions.
- `npm run build`: TypeScript and production Vite build passed.
- Targeted Pint and `git diff --check`: passed.
- `npm run test:browser -- tests/browser/resumable-kit-upload.spec.ts --list`: disposable SQLite fixture bootstrap passed; Chromium desktop and WebKit mobile tests were both discovered.

The native browser test is implemented but **not accepted as executed** in this environment. Its attempted run stopped before navigation because the pinned executables were absent: `chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell` and `webkit-2359/pw_run.sh`. Browser installation returned invalid/truncated archive responses and failed. No passing native upload, screenshot or production-size HTTP claim follows from test discovery.

The pending native journey creates a kit through its real Filament form, uploads a valid stored WAV ZIP larger than the PHP server's 9 MiB request ceiling, sends an 8 MiB part plus remainder, deliberately withholds the first response after actual native fetch completes, reloads/inspects, rejects changed file bytes, resumes and finishes, then inspects the retained revision. It preserves the browser's exact FormData and multipart boundary rather than replaying requests through Playwright's API client. It must pass in both pinned browser projects before native transport acceptance. MySQL concurrency, production ingress limits and real malware-scanner acceptance remain separately recorded domain/deployment checks.
