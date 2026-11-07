# Tier usage limits (license terms schema v5)

Status: implemented on branch `claude/license-terms-v5-20261107` (base `origin/main` f578e10c). It needs independent review
(licensing model, review-evidence guard migration) before increment 1 of the
[production contract profile](production-contract-profile.md) authors Sean's six licenses. Fixtures are synthetic and nonbinding.

## Why

Sean's tiers limit audio streams, monetized video streams, free downloads, and for-profit and not-for-profit performances
separately. The v2–v4 usage vocabulary has one combined streams pair and one `live_performances` slot, so those limits
could only be written as literal prose that the generated statement schedule would contradict (gap G-1). Sean chose a new
schema on 2026-11-07. Each limit is now entered once and reaches the authored source, the statement schedule, the storefront
features and the contract text identically.

## What changed

Schema 5 is schema 4 with a different `usage` object. Every other root (`required_asset_roles`, `permissions`, `credit`,
`territory`, `duration`, `ownership`, `publishing_income`, `recording_royalty`, `policies`) keeps its exact v4 shape, rules
and statement text. It is validated by the unchanged v4 validator.

| Usage key | Statement label | Source variable |
| --- | --- | --- |
| `audio_releases` | Audio releases | `{{usage.audio_releases}}` |
| `copies_downloads` | Copies / downloads | `{{usage.copies_downloads}}` |
| `audio_streams` | Audio streams | `{{usage.audio_streams}}` |
| `monetized_video_streams` | Monetized video streams | `{{usage.monetized_video_streams}}` |
| `non_monetized_video_streams` | Non-monetized video streams | `{{usage.non_monetized_video_streams}}` |
| `music_videos` | Monetized music videos | `{{usage.music_videos}}` |
| `radio_stations` | Radio stations | `{{usage.radio_stations}}` |
| `free_downloads` | Free downloads | `{{usage.free_downloads}}` |
| `for_profit_performances` | For-profit performances | `{{usage.for_profit_performances}}` |
| `not_for_profit_performances` | Not-for-profit performances | `{{usage.not_for_profit_performances}}` |

Each key needs `mode`: `prohibited`, `unlimited`, or `limited` with an integer `limit` from 1 to 2,147,483,647. There are no
defaults, and the v4 keys `monetized_streams`, `non_monetized_streams` and `live_performances` are refused. Statements list
the ten usage lines first, then the v4 statements, so a v5 version has 24 statements. The source must contain every statement
variable and `{{policy_texts}}` exactly once, and unknown or retired variables are refused.

Code: `App\Domain\Rights\TieredLicenseTerms` (validation, statements, source values) and `TieredLicensePreview`
(`vasey-license-review-html-v5`). `LicenseTerms::SCHEMA_VERSION` is 5, so new admin drafts author v5. Consumers updated:
`LicenseTerms` dispatch, `LicenseSourceVariables`, `LicenseContent`, `LicenseVersion::features()`, `LicensePreview`,
`MemberGrantDocumentValues` (retained member terms) and the Filament usage, scope and economic sections. Catalog publication,
manifests, offer and quote snapshots, public disclosure and the React storefront read the generated `features` and rendered
text, so they needed no change.

**Define tier usage limits** (shown on v4 versions only) creates a linked v5 successor. It copies the source, availability
dates and every non-usage declaration, plus `audio_releases`, `copies_downloads` and `radio_stations`. The other seven usage
choices start blank. That includes `music_videos`, because v5 narrows it to monetized music videos, so a v4 value is not
the same declaration. The author also replaces the retired source variables.

## Compatibility

- Versions on schemas 1–4 keep their validators, statements, source values, previews and review hashes. Golden bytes from the
  base commit pin v4 (`tests/Fixtures/license-terms-v4.json`, `tests/Fixtures/license-review-v4.html`), next to the existing
  v1–v3 goldens.
- Storage is unchanged (`structured_terms` is JSON). One migration is required anyway: the review-state trigger only admits
  pinned schema/renderer pairs. `2026_11_07_270100_license_tier_usage_terms` installs `license_review_state_guard_v5`, which
  adds `(5, vasey-license-review-html-v5)`, before dropping `license_review_state_guard_v4`. `down()` reinstalls the
  byte-identical v4 trigger, which leaves retained v5 rows immutable and refuses new v5 submissions.

## Verification

SQLite, CI environment (`/home/user/notes/pu.sh`, `public/build` absent). Native MySQL was not run here, so the MySQL form of
the v5 trigger is untested until a MySQL run migrates it. The migration test is in the reviewed MySQL selection, but like the
v4 one it uses its own in-memory SQLite connection.

- New tests: `PU_TIMEOUT=3000 /home/user/notes/pu.sh <file>` for `tests/Unit/TieredLicenseTermsTest.php` (34 tests),
  `tests/Feature/TieredLicenseTest.php` (8), `tests/Feature/TieredLicenseMigrationTest.php` (1) and
  `tests/Unit/ProductionMemberOriginals/MemberGrantTieredTermsTest.php` (11): all pass. `PublicLicenseDisclosureTest` gains a v5 case.
- Affected suites, one run per file over 62 files (license, rights, quote, catalog publication and manifest, exclusive offer,
  production preparation packet, member grant and member original tests): 985 tests pass, and 106 MySQL-only concurrency
  tests skip on SQLite by design. No failures.
- Updated existing tests: three assertions of the new-draft default (4 → 5), and the two trigger names a packet-guard test
  drops (`license_review_state_guard_v4` → `_v5`).
- `python3 scripts/ci/test-*.py`: all pass except `test-related-browser-stage.py`, which fails identically on the base commit
  (no `node_modules`/`public/build`/ClamAV signatures in this container). `scripts/ci/phpunit-shards.py --shards=4` accepts the
  partition. `npm test`: 70 files, 1177 tests pass; `npm run typecheck` passes (no TypeScript changed).
- `vendor/bin/pint --test` on the changed PHP files passes, except `LicenseVersion.php` and `PublicLicenseDisclosureTest.php`,
  which report the same fixers on the base commit and were not reformatted.

## Operating notes (from the independent review, 2026-11-07)

- **Rollback is forward-only once a v5 version is published.** `down()` restores the exact v4 guard. After that, v5 rows in
  `legal_review` or `approved` can no longer advance; the trigger refuses them, so nothing is corrupted. Published v5 rows stay
  readable and immutable. Rolling the code back as well would make published v5 rows fail validation and break their offers.
  Do not roll back after the first v5 publication.
- **The "Define tier usage limits" action copies `copies_downloads` from v4.** v5 adds a separate `free_downloads` slot, so the
  author must confirm that the v4 copies limit did not include free downloads before submitting the successor for review.
- **Native MySQL (private MySQL 8.4.11, PHP 8.4.27).** A full `migrate` installed `license_review_state_guard_v5`. A targeted
  rollback restored `license_review_state_guard_v4`, and `migrate` reinstalled v5. The v5 trigger body admits the renderers
  `vasey-license-review-html-v1` through `-v5`. `TieredLicenseMigrationTest`: OK (1 test, 25 assertions).
  On the same server, `TieredLicenseTest::test_database_rejects_every_cross_version_v5_renderer_pair` and
  `test_review_binds_every_tier_limit_to_the_v5_preview_and_publication_evidence`: OK (2 tests, 56 assertions).
- **Independent review (2026-11-07): APPROVE WITH CONDITIONS, no P0 or P1.** Condition (P2, native MySQL evidence) met above;
  the three P3s are recorded in these notes and in `production-contract-profile.md`.

