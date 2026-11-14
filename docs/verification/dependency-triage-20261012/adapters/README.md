# Laravel / Inertia dependency batch

Tested dependency source: `30c4f7caeac91ad198e33e3088d46c6ff376155f`.
Main integration `88b2b91769c93af48349033f357fa0ebd1e1da84` adds only the prior tooling review receipt;
the application, tests and installed dependency graph are unchanged. PHP **8.4.26**, Composer **2.8.8**,
Node **24.19.0**, npm **11.9.0**, private Oracle MySQL **8.4.11**.

## Change and compatibility

This combines the inspected targets of Dependabot #74, #75 and #80. Composer changes exactly three of 158
package records: Laravel 13.34.0 → 13.35.0, inertia-laravel 3.3.4 → 3.5.1, and prompts 0.3.24 → 0.3.25.
There are no package additions/removals or composer.json changes. The lock's plugin-api-version changes
2.9.0 → 2.6.0 as producer metadata; no Composer plugin is added. npm changes the root constraint and three
packages: Inertia React/core 3.7.1 → 3.8.0 and laravel-precognition 2.0.0 → 2.1.0. No other npm record changes.
See the two graph-delta JSON files and installed-version receipts beside this file.

The entire Stripe 21.3.2 Composer record and all fourteen test-v2 renderer package version/reference pins
remain unchanged. All frozen contract assets, application code and test files are unchanged. #71's Stripe
22 proposal and the PDF/font proposals #72/#77 remain unmerged.

Reviewed upstream notes from the three bot PRs:
[Laravel 13.35.0](https://github.com/laravel/framework/releases/tag/v13.35.0),
[inertia-laravel 3.5.1](https://github.com/inertiajs/inertia-laravel/releases/tag/v3.5.1) and preceding 3.4/3.5 notes,
and [Inertia 3.8.0](https://github.com/inertiajs/inertia/releases/tag/v3.8.0).
The framework changes include database/relationship casts and queries, route caching, scheduler interruption
and filesystem fixes. The paired Inertia releases add opt-in BigInt behavior, deferred/redirect handling,
client history asset-version revalidation and response/prefetch fixes. This app does not opt into BigInt or
previous-URL session storage and has no direct Precognition/Form usage. This is not an exhaustive upstream audit.

## Recorded verification

| Check | Actual result |
| --- | --- |
| Fresh Composer and npm installations | Exit 0; installed identities match the selected locks |
| Composer strict validate and platform requirements | Both exit 0 |
| Full frontend | 65 files / **1,130 tests**, no failures/errors/skips; exit 0 |
| Focused SQLite, 15 complete files | **260 tests / 3,474 assertions**, zero failures/errors/skips; exit 0 |
| Private MySQL, exact filtered selection | **23 tests / 399 assertions**, zero failures/errors/skips; exit 0 |
| Production/preview builds including TypeScript | Both exit 0; production >500 kB chunk warning retained |
| Production/preview bundle scans | Six/four files, no hits; both exit 0 |
| Composer audit before/after; npm audit after | Zero advisories; exit 0; no abandoned Composer packages |
| Composer outdated before/after | Exit 0; available versions are present in the reports |
| npm outdated after | Exit 1 because updates remain available |
| Actual stopped-copy upgrade suite | **14 tests passed**, zero failures/cancellations/skips/todos; exit 0 |
| Changed-lock identity probe | Exit 0; prior/candidate lock bytes alone change schema identity and refuse the initializing workspace without changing retained identity/database bytes |

The native selection contains the two complete staff attestation files, the committed-consumption marker
under an older outer transaction snapshot, and both importer engine changes during a blocked table open
(unlisted table and epoch). It uses the existing private server on its own datadir, loopback port 33396 and
socket, not a shared or hosted server. The fifteen SQLite files cover staff authority/MFA, readiness, importer,
catalog/metadata HTTP, embed route caching, scheduling and frozen contract rendering/profile consumers.

Exact commands and paths are in [commands.md](commands.md). Output and JUnit are retained with only worktree
paths, ANSI colour and trailing whitespace normalized. No expected assertion, admission rule or audit threshold
was changed. Newer available versions remain for a later coherent batch.

## Limits and recovery

No full PHP/MySQL matrix, browser, live provider/account, host, Foundation or GitLab result is claimed. Browser
asset-version mismatch/back-forward restoration and logout → Back are not exercised by this selection. The
frontend tests use jsdom. The real stopped-copy tests use synthetic old/new checkouts derived from this source
and its installed graph; they cannot establish interoperability with an actual completed installation of the
previous Laravel graph. Frozen rendering identities are preserved, not reapproved for a new profile.

Rollback requires restoring both prior dependency manifests/locks and reinstalling their graph; no application
schema migration is added here. Review the actual stopped installation before any host upgrade. Source-bound
independent review and exact-head cheap preflight remain merge gates. The changed-lock probe and its output are
retained beside this file; it exercises Node identity checks only, not PHP or a completed prior-runtime install.
