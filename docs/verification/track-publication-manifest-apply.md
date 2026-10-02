# Reviewed track publication evidence and apply

October 2, 2026. A bounded T12 / WP-02 increment, developed on a separate branch above the participating-writer candidate. This record describes implementation and focused development feedback. It does not accept the writer candidate, this composed increment, track scheduling, the parent work package or production launch.

## Operator and domain contract

`PublishTrack::reviewManifest(Track, User)` captures a ready draft for a server-held confirmation. `publishManifestReviewed(array, User)` applies exactly that confirmation after checking current authority, revisions, status, child evidence and private bytes again. Both require their own transaction, rejecting an ambient transaction before private reads. The existing schema 1 `review` / `publishReviewed` and immediate compatibility APIs remain unchanged in scope; interactive publishing uses the new pair. Unpublishing continues the existing reviewed withdrawal command.

The new confirmation has exactly eight fields: `schema_version` (2), `actor_id`, `track_id`, `intent` (`publish`), `metadata_version`, `publication_version`, `status` (`draft`) and `manifest_hash`. IDs and revisions remain strict integers; the digest is lowercase SHA-256. This is a server-held review identity, not a client authorization token. The digest binds the existing minimized manifest schema 1 payload. No private path, complete license text, source evidence or raw file metadata is exposed in the confirmation.

Review is read-only apart from existing bounded integrity-cache activity. Applying stale metadata/publication revisions, changed offers/media/rights, scope-control changes (including block/unblock ABA), withdrawn authority/MFA, expired licenses or changed physical bytes fails without a publication write or audit. A new explicit review is necessary. Schema 1 is not a fallback. Publication advances the monotonic publication counter while retaining metadata revision, historical media/commercial evidence and the permanent published URL. Its audit includes the exact manifest digest and manifest schema alongside the existing publication revisions, in the same transaction.

The coordinated Filament change clears its old confirmation before mount checks and consumes a submitted confirmation before validation, authority checks or a potentially uncertain result. It catches mount-time readiness failures as `Publication blocked`; failed submit cannot silently recapture current evidence. Cancellation/context replacement and locked-property protections remain in force.

## Locking and time

The strict path uses current locking reads in this order:

1. Persisted actor, catalog Gate and MFA.
2. Track, then active offers in ID order.
3. Current immutable offer revisions under shared locks, discovering schema 2 exclusive scope IDs.
4. Applicable scope rows in ID order.
5. The first ordinary readiness/manifest child-read view.

Supported catalog, rights and media writers participate in the actor/track ordering. Scope controls and exclusive finalization serialize at the scope. Revision locks are shared because the finalizer holds scope locks before inserting revision/license foreign-key references; an exclusive revision lock here could create the opposite wait order. Publication adds no media, rights or license exclusive lock after the track. Published immutable license/media/commercial evidence retains its existing database guards.

`ReadTrackPublicationManifest::captureLocked` is the internal projection reused by the authorized callers; it requires a caller transaction and does not independently replace caller authorization/fences. The original read-only `handle` keeps its existing standalone actor/track contract and point-capture limitations.

After ordinary readiness, `VerifyTrackPublicationFiles` hashes the selected artwork, preview and every frozen deliverable, including a stems binding's master and preview even when they are not licensed deliverables. It opens private local files, checks regular-file/path/descriptor identity, streams a fresh bounded digest without consulting the public-read hash cache, and compares size and identity afterward. It then rechecks all inspected paths to detect a previously hashed path being replaced while a later file was inspected. Missing/unsafe storage failures become a publication blocker.

All licenses are evaluated again at one post-hash decision instant. This uses the existing inclusive start / exclusive end policy and supplies the publication timestamp. Long hashing cannot retain an eligibility decision made before license expiry. The process does not change the application clock. File checks prove the bytes inspected during this operation; SQL locking does not make the filesystem atomic or prevent a privileged external process changing bytes after inspection.

## Executed focused feedback

The final domain source passed the following SQLite command with a synthetic ephemeral testing application key and isolated PHP 8.4 runtime:

```sh
php vendor/bin/phpunit tests/Feature/TrackPublicationApplyTest.php \
  tests/Feature/TrackPublicationManifestTest.php \
  tests/Feature/TrackPublicationGuardTest.php --log-junit publication-apply-sqlite-final.xml
```

Result: **67 executed / 834 assertions / zero failures, errors or skips**, 76.824 seconds. This includes 18 new apply cases, 21 existing manifest cases and 28 existing publication-guard cases. New cases cover exact schema/actor binding, stale and ABA review, commercial and scope successors, current role/email/MFA, ambient transactions, fresh bytes despite a warm positive cache, stems-only bound-master verification, path replacement, post-hash expiry, capacity and atomic audit rollback.

Earlier diagnostic feedback retained one test-only wrong audit-property error (16 reported, 15 passed, 131 assertions). After correcting the test's actual `action` / `context` field names, the earlier 37-case apply+manifest run passed with 430 assertions. These overlap the final 67-case result and must not be summed as distinct coverage.

The coordinated UI development composition separately passed **33 cases / 433 assertions / no skips, failures or errors**, 31.032 seconds: five new mounted editor cases plus the existing 28 guard cases. It used the same three domain file bytes recorded below with the root-owned Filament/test additions. These cases overlap the domain run and are focused feedback, not final composed/native acceptance. The UI exercises schema 2 capture, blocked mount, stale-offer consume/reopen, schema 1 denial and consumption after a simulated uncertain committed outcome.

The new MySQL class has thirteen cases: both lock orders against metadata save, actual offer publication, administrative scope block, actual test-payment finalization, media completion and actor withdrawal, plus two competing applications of one actor's confirmation. Independent processes and `performance_schema` must show the exact requesting/blocking connection IDs and PRIMARY record wait. The finalizer actually inserts its immutable grant and exclusive-sale graph while publication holds shared revision locks; SQLite skips are not concurrency evidence.

The initial MySQL diagnostic executed thirteen cases with nine passes and four harness failures (835 assertions): the offer race initially paused an earlier draft-save transaction, media workers lacked their explicit synthetic scanner, and the authority worker attempted a guarded mass assignment. The corrected harness prepares the draft before review and races actual `PublishOffer`, injects the named test-only scanner only for the media worker, and assigns/saves the guarded role followed by a persisted-revocation assertion. Production source did not change for these repairs. The local media race uses actual decoding/processing with a synthetic scanner and does not prove production malware scanning.

The second MySQL diagnostic reached twelve passes and one worker-output error (878 assertions): successful publication correctly caused the competing media completion to throw `MediaFailure(track_published)`, which the harness had not serialized. The worker now returns that explicit failure code, and the test requires the retained run to be failed with no output IDs. No runtime assertion was relaxed. The final corrected MySQL 8.4.11 run passed **13 executed / 899 assertions / zero failures, errors or skips**, 104.945 seconds, under Repeatable Read with `performance_schema=1`. Original reports are `publication-apply-mysql-verified.xml` and its log. All six PHP source hashes match the frozen source manifest used for independent review. Full source-bound native SQLite/MySQL, both ordinary browsers, genuine scanner/media browser, provenance and database aggregation, independent final-source review and composed candidate acceptance remain required.

Runtime file SHA-256 values:

| File | SHA-256 |
| --- | --- |
| `app/Domain/Catalog/PublishTrack.php` | `d0a8ceb0008e06ae7c7449a230a01c60e98f854a868690c048562e0df4bde305` |
| `app/Domain/Catalog/ReadTrackPublicationManifest.php` | `c80ec55bd0d2f39b94f7ccd66a658ce72cb212ef5e27716a7c2f497d94fc5d0a` |
| `app/Domain/Catalog/VerifyTrackPublicationFiles.php` | `cdecd68f10c478a99e007c3e8b664f9d6e2af09c334e7a84368fb816b9b1a156` |

## Coordinated editor, browser and test routing

The integration includes both Filament files, the five editor cases and five browser fixture/spec/guard files. The default unready-draft WebKit journey passed one case in 20.157 seconds with zero skips, retries or unexpected results. Its earlier local fixture runs retained focus/asset-setup failures; the final run used correctly installed Filament assets. Genuine related fixtures add two independently processed publication tracks while preserving all four original editorial graphs, and verify the exact 71-audit preparation census. The fixture unit suite passed nine cases / 158 assertions, stage safeguards passed six cases, TypeScript passed, and related discovery remains two project cases. Local genuine-scanner execution is blocked by unavailable ClamAV; discovery and source review do not supply that execution.

The two exact MySQL-only methods are registered for SQLite. A fixed `publication` focused suite covers apply, editor and existing manifest/guard cases; the operator suite also includes the editor cases. The original 32-file limit is preserved. Adding all three new classes to operator initially failed that safeguard at 34 files, so a bounded separate suite was introduced instead. Final focused-selector safeguards passed 27 cases and database-receipt safeguards passed 24. No full native gate, timeout, scenario or evidence requirement was removed.

## Remaining dependencies

The integrating owner has registered the two exact MySQL-only method identities (twelve writer cases plus one competing-apply case) and focused routes with this source. This documentation does not waive any gate or turn a skipped method into passing MySQL evidence.

Track scheduling follows this fence and still requires explicit lead time, horizon, precision, grace and pending-schedule disposition choices. Existing site-release scheduling policy is site-specific. Anonymous unlisted review, broader license/bulk operations, granular permissions/recovery, production policies/hosting, content onboarding, historical obligations and full launch acceptance remain separate tracked work.
