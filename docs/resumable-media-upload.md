# Resumable private media upload

This child of T13 adds a usable operator flow alongside the existing whole-file upload. It does not complete object storage, production worker/media acceptance, large customer downloads or the remaining T13 criteria.

Open **Media assets → Resumable upload**, select a track and role, and choose a file. The browser computes the whole file's SHA-256 before starting. WAV masters and WAV-only stems ZIPs remain limited to 200 MiB; PNG/JPEG artwork remains limited to 20 MiB. Browser hashing needs HTTPS or localhost and enough memory for the bounded source file; the whole-file upload remains available if that browser cannot hash it.

The authenticated service retains the actor, track, role, size and hash for 24 hours. It accepts sequential 8 MiB parts, except the final shorter part. Four uncleared sessions per operator bound retained transport. Retrying an identical active start recovers its existing session, including an expired session; cancel an expired upload explicitly before restarting. No caller-supplied filesystem path is accepted.

The upload ID is displayed and retained in this tab's session storage under the operator's numeric identity. No source bytes, names, hashes, credentials or private storage paths are stored there. Copy the ID if recovery outside that tab is required. Inspect the ID, reselect the same size/hash file and choose **Continue upload**. A paused or failed request may already have committed: the next inspection determines the actual offset. A failed final response may likewise have saved the asset. Inspect before retrying; completion is idempotent. Pending terminal transport cleanup can be retried with **Finish upload** or **Cancel upload**, as indicated.

**Finish upload** verifies and saves an immutable private quarantine source. Processing, recording association, licensing and publication remain separate existing actions. Completed media is not served by the public media endpoint. Foreign sessions, withdrawn authority and required MFA failures cannot resume, complete or cancel another operator's upload.

## Request limits

Each multipart request carries at most 8 MiB of file bytes plus at most 64 KiB of envelope overhead. Configure the reverse proxy/body limit and PHP `post_max_size`/`upload_max_filesize` to admit at least 9 MiB requests before using this route. Application checks independently reject excess lengths, oversized parts and extra fields. PHP parses multipart bodies before Laravel runs, so application checks do not replace proxy/PHP resource limits. The browser test server explicitly uses `upload_max_filesize=9M` and `post_max_size=9M`; this does not change production server configuration or increase the 200 MiB source bound.

Endpoints are under the authenticated Filament panel at `/admin/resumable-uploads`, with same-origin/CSRF checks, current persisted Gate/MFA checks, per-route throttles, owner-bound service admission, minimized responses and no-store/private error handling. Creation uses JSON; part requests contain exactly one `offset` field and one `chunk` file; completion/cancellation use an empty JSON object. Unexpected results instruct inspection rather than claim rollback or success.

## Verification scope

`ResumableMediaUploadHttpTest` exercises the real domain through HTTP and Livewire, including private responses, authority/MFA withdrawal, CSRF, caller-path rejection, full-size part admission and preserved whole-file creation. Frontend tests cover sequential receipt-driven offsets, interrupted and stale responses, exact-file resume, expiry, denied access and uncertain completion. The native browser scenario sends a real full-size multipart request, loses its response after server commit, reloads, resumes and verifies a quarantined asset. Executed results belong to the integrating candidate; a test definition is not browser or production acceptance.

Local verification of this HTTP/UI child composed with domain checkpoint `bd1e7caef4f03bf7084e73afeb9ed2d6496bbc6b`:

- `php vendor/bin/phpunit tests/Feature/ResumableMediaUploadHttpTest.php`: SQLite **12 tests / 389 assertions**, passed. The same class passed on isolated native MySQL **8.4.11**, **12 tests / 389 assertions**, no skips. The full-part in-process test configures Laravel's request-size middleware with the documented 9 MiB host limit; it does not exercise PHP's native multipart parser.
- `npm run test -- tests/frontend/resumable-media-upload.test.ts`: **10 tests**, passed, including terminal cleanup recovery and clearing an inspected session when its displayed ID changes.
- `npm run build`: TypeScript and production Vite build passed. Targeted PHP formatting and `git diff --check` passed.
- `npm run test:browser -- --project=chromium-desktop tests/browser/resumable-media-upload.spec.ts`: fresh fixture migrations, operator creation and installation diagnostics passed, but browser launch failed because the pinned `chromium_headless_shell-1243` executable was absent. No browser interaction, screenshot, native multipart-parser, mobile or keyboard acceptance is claimed from that attempt. The integrating candidate must execute the Chromium and WebKit scenarios.
