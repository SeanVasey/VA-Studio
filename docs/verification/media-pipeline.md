# Private media pipeline verification

This is the first WAV/artwork processing increment for [WP-03](../work-packages/WP-03-private-media-ingestion.md), delivered in [PR #21](https://github.com/VASEYDEV/VASEYAUDIO/pull/21). It does not establish full BeatStars parity or production readiness.

## Scope exercised

- Actual operator upload objects, server MIME/size/hash capture, private quarantine, forged-path/type rejection and authorization.
- Real FFmpeg WAV processing, preserved master bytes, untagged delivery MP3, periodic seller-tag mixing, full-length preview duration and measured waveform peaks.
- Real raster decode/re-encode; immutable output/provenance records and source/profile idempotence.
- Missing/stale/error scanner evidence, missing/mismatched/silent tag, malformed media, size/resource limits, symlinks, changed bytes, partial copies, retries and processing claims.
- Publication readiness, matching preview/deliverable source revision, safe public DTOs, draft operator review, production panel MFA policy, old-preview withdrawal and bounded digest verification.

Fixtures are synthesized at test time. The scanner double is explicitly accepted only under the testing environment. A fake executable exercises the scanner response contract and stale-signature rejection; no real ClamAV installation or malware-detection result is claimed.

## Local tools and commands

Local verification uses PHP 8.4.1, FFmpeg/ffprobe 6.1.1, Linux prlimit, Node 24.19.0 and npm 11.9. Production must use supported patched runtimes; these local versions are evidence of the test environment, not a deployment recommendation.

```sh
php artisan test
npm test
npm run build
php vendor/bin/pint --dirty
git diff --check
```

The first integrated candidate, local `6b935d9` / remote `cbe75e332a107c05e4634ae2ce7ae86b2fe5b660`, had identical source tree `cb52d2f0bb2d780660b83e5ad944c215c3479409`. Its local full run passed 53 PHP tests and 270 assertions; 14 frontend tests and the TypeScript/Vite build also passed. Independent rerunning exposed an intermittent scratch-directory cleanup assertion. A passing single run therefore did not establish that candidate as verified. The investigation found an empty workspace from a preceding PHP process reappearing in the shared fake-disk root after that process recorded successful removal. The current attempt removed its own distinct workspace. Tests therefore receive uniquely named private fake disks, retaining every cleanup assertion. The runtime denied syscall tracing, so the external recreation mechanism is not attributed more precisely. This is test isolation; no production cleanup assertion or validation was weakened. The independently reviewed correction is commit `4361967de7a2bbdc2f283cdc88922243f30721b9`, source tree `98eebb72030820d1f96f9fe46231995d5d4f7291` (integrated locally as `fe7ba70` with the same tree). Independent PHP verification passed **54 tests / 274 assertions**. The formerly intermittent WAV case also passed in 12 separate sequential PHPUnit processes; the storage-isolation regression passed. The helper creates a uniquely named fake disk, aliases it as the application's local disk, and removes only its own test root at teardown. No temporary diagnostics or production workaround remain.

Independent review found no known unresolved high-severity defect within the supported paths examined. Review covered upload trust, path and symlink rejection, media derivation, partial-copy/collision handling, immutable promotion, retries/claims, readiness, private derivative access and production MFA enrollment policy. It is not production release approval. Subsequent verification-document changes do not alter the reviewed application or tests.

## Candidate CI

GitHub verified candidate `cbe75e332a107c05e4634ae2ce7ae86b2fe5b660` in [PR run 33915719752](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/33915719752) and [push run 33915715670](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/33915715670): both workflows passed. Each backend job ran 53 tests / 270 assertions on MySQL 8.4 and then SQLite; frontend jobs passed 14 tests, TypeScript/build and production audits. Those clean-runner results are separate from the local cross-run interference described above.

## CI repairs

The foundation workflow could not parse its unquoted SQLite `:memory:` command. After fixing that YAML, the first real backend job exposed an absent `tests/Unit` directory referenced by PHPUnit. The empty suite declaration was removed, and FFmpeg/util-linux installation was added for actual media tests. The repaired baseline passed [GitHub CI](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/33915025552), including MySQL and SQLite. That baseline result is separate from this media increment's checks.

## Remaining acceptance boundaries

- MySQL functional tests and database triggers do not prove multi-worker race correctness under production concurrency. Real queue retries, worker termination, restore and orphan reconciliation need deployment exercises.
- Production ClamAV installation, clean/detection/error samples, signature updates and resource capacity remain unverified.
- The approved seller tag and actual catalog have not been supplied to this pipeline; synthetic test media is not saleable catalog content.
- Public digest successes cache for at most 60 seconds without renewal; privileged same-size/same-stat changes may wait that long for detection. Cold large-catalog hashing, pagination and realistic load are documented scale gates.
- Bounded subprocesses do not constitute an OS/network sandbox. Restricted worker permissions, network denial and secrets isolation are deployment work.
- Real browser/device playback, seeking, artwork appearance and audible seller approval remain pending. HTTP and decoded-audio tests are not visual or listening approval.
- Stems/ZIP extraction, resumable uploads, managed object storage, checkout, payment verification, contracts, entitlements, purchased delivery and migration remain later work. No live site, DNS or sales provider was changed.
