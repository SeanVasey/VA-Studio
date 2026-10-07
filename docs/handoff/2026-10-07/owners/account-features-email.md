# Production customer features: development handoff

The user requested finishing the current development batches, pushing owned commits and handing remaining work to Claude Code. This document covers the 253 consumer child, not overall store completion. Production activation remains disabled. Root owns integration, registration, publication, the overall handoff and all shared files.

## Branch, source and ownership

- Branch: `customer-production-account-features-t32`.
- Local worktree: `/workspace/VA-Studio-production-account-features`.
- Exact borrowed base: `75626795130a4be15d6bd70f71412cbff7411597`.
- First source checkpoint: `b6cf98ed330e1cf4e192d54554468445e21e439d`.
- Final source/test checkpoint: `3cecea697ccaaff74664acf9d48bcaecfa8bf288`; runtime is byte exact `9d0a5a27a20869c8a845e86461e64f34af669c6b`. Evidence head/remote head is supplied with the push receipt in root’s final handoff; `manifest.json` pins the exact source and39 hashes. These remain unreviewed development checkpoints.
- Owned source: 39 new paths under `app/Domain/Customers/ProductionFeatures/`, `config/production-customer-{listening,preferences}.php`, migration253, dedicated tests and `tests/Support/ProductionFeatureFixtures.php`.
- Existing ListeningLibrary/ListeningEvidence/test account authority, old250 consent helpers, old251 suppression and borrowed101 identity/feature bodies remain byte exact. No route, controller, session, current account mount or request grammar is authored here.

The base selectively composes root401 registered identity source, feature adapter d9, committed frame7cc and exact connection-admission repairs cdc/101. See `dependency-composition.json`. The current identity dependency101 was separately approved; the entire new consumer253 still needs independent review of root's exact composition. Do not borrow the unrelated historical receipt child merely to make these consumers work.

## Implemented, held and queued

Implemented in this child: new production-only listening state and public-source closure; explicit empty initialization; favorites, bounded named ordered playlists, notes under a distinct rollout gate, minimized own-feature export and version-fenced clear; original feature ownership binding; new production purpose policy/event/state graph; monotonic explicit withdrawals and retained withdrawal pointer; sealed server-only withdrawal reader; five-table restartable additive installer with before-DDL dependency/namespace/floor admission and final proof; original physical transaction fencing and ordinary/precommit/postcommit authority checks.

Held: independent approval of new253, root production HTTP grammar/controller registration/session capability/mounted product controls, actual composed private HTTP tests and integration review. This branch has no operative production route or enabled identity/purpose/provider. Final SQLite86/766 passes (83pass/3native-onlyskip), final native postcommit withdrawal1/6 passes, and Pint passes. The separate b6 native checkpoint9/109 passes; it is not final-successor nine-case coverage. Native8.0.46 focused development results are not native8.4 or full store certification. Default flags alone do not establish a reviewed production binding.

Queued, deliberately not implemented after the stop instruction:

1. Distinct production suppression254: four new targets/intents/attempts/confirmations tables, references only253 feature binding and production withdrawal lineage, never251 adoption. Default-off unbound provider contract; durable unique attempt before transport; ambiguous outcome inspect-only with no blind resend; positive scoped inspect receipt required for confirmation; no implicit unsuppress on later grant.
2. Production customer/email operations preparation: closed sender/origin/TLS/transactional-content/provider-scope preflight, proof flags/scanner/media paths/privacy/retention/queue/scheduler/DNS facts packet, concrete local rehearsals and read-only manifests. Canonical root identity/SMTP/history composition is `519170f9e5541602577381f17fa39c366b8e608b` in `/workspace/VA-Studio-identity-adapters-registration`. Use its exact configured SMTP factory plus root plain-parent f5 repair; earlier7cc transport bytes are not the final approved provider preparation contract. Historical e8/b24 authenticated-prefix receipts do not grant current account access or generic email outbox authority.

Actual provider credential/binding, mailbox/sender setup, notice content/review, remote mail/API execution, host queues/scheduler/scanner/media permissions, DNS, deployment and live payment activation remain explicit real-environment actions requiring the root user's applicable authorization and actual evidence. This child executes none of them and creates no environment.

## Root API and default configuration

Root calls sealed `ProductionAccountFeatureAccess::forRequest($request, $fixedServerFeature)` using the mandatory production identity session marker. Do not create a test CustomerPrincipal or infer account ownership from email. Server-fixed features are `listening_library` and `consent_preferences` with exact reviewed identity versions.

Listening entrypoints: `ProductionFeatures\Listening\ProductionListeningLibrary::{read,initialize,change,export}`. Export takes the optimistic integer version directly. Preferences entrypoints: `ProductionFeatures\Preferences\ProductionConsentPreferences::{read,initialize,change}`. Each takes the sealed feature identity. The dedicated root initialization POST accepts closed `{}`; do not widen the ordinary command grammar. Clean missing storage GET returns only `initialized:false`, without creating/adopting anything. Same-owner pristine0 initialization repeats safely. Nonpristine repetition or preserved unbound legacy data refuses409. Initialized responses are `initialized:true` plus `library` or `preferences`, with no internal account/origin/principal IDs.

Listening command grammar, version semantics and per-note limits match reviewed notes source. Preserve ordinary V1 writes/noop exact ciphertext and never downgrade V2. Aggregate ciphertext is capped at60000 bytes before save; 25 notes is a count bound, not a promise of25 maximum-sized notes. Keep unavailable placeholders without catalog/private-media metadata. Export contains own IDs, playlist names/order and notes only. Clear retains a monotonically increased empty revision.

Shipped note configuration is exactly `['v2_promotion_enabled'=>false,'v2_rollout_review_reference'=>null]`. Production promotion requires separately reviewed stopped rollout/backup/reference; existing test rollout flags cannot enable it. Existing V2 is not prior-reader rollback compatible.

Shipped purpose configuration is exactly `['grants_enabled'=>false,'email_marketing'=>null]`. A production owner-authored policy needs closed purpose/version/notice/review_reference shape, separate review and affirmative grant against the displayed exact version/hash. Do not copy synthetic fixture text as approved legal/marketing content. Withdrawal remains possible while grants or notice are disabled and increments on every explicit intent. Source first-party capture is not verified historical consent, purchase inference or import promotion.

Root must supply the closed default-off `production-account-features` parent with exact feature versions; this base has no such config file. Feature fixtures explicitly configure synthetic rehearsal authority, and pure policy units provide disabled false/null authority plus a synthetic key. None of this is production application setup.

Catch IdentityException, ListeningException, ConsentException and ProductionFeatureException and return only generic status/reload responses. Unknown postcommit result means the original write may be durable: GET before fresh versioned intent, never blind retry/reset. Root body limits remain listening16KiB, other commands4KiB, and listening read/export3MiB client cap.

## Suppression254 server reader prerequisite

`ProductionConsentWithdrawalReader::read(ProductionFeatureContext,int expectedConsentVersion): ?ProductionConsentWithdrawal` runs only inside the same sealed consent feature operation, original current authority/physical source/deadline. It validates graph adjacency, pointer, original encrypted event/binding, policy and current server recipient. Uninitialized/stale refuses409, unknown or a different recipient returnsnull, and a later grant retains its original matching withdrawal. The sealed result denies JSON/serialization; `serverSnapshot()` is private server transport evidence, never HTTP output. It does not assert provider confirmation or send authority.

254 should use a fresh isolated branch after reviewed253 composition. Retain original deadline and current/original binding proof without authority renewal. All raw config parents/leaves and standard PDO/statement source must be admitted before terminal nested lookup; no Repository/ArrayAccess/Stringable/statement callback follows proof. Root owns provider adapter binding and private routes. Never reinterpret old251 consent/suppression history as production lineage.

## Rehearsal and verification commands

Use the locked toolchain with `source /workspace/.va-studio-toolchain/activate.sh` and run `php vendor/bin/phpunit`; package executable symlinks can point to another worktree's autoloader. The worktree has independent Composer metadata with symlinked locked package directories, avoiding another6GB dependency copy. No dependency/lockfile changes are needed.

SQLite owned selection:

```sh
php vendor/bin/phpunit tests/Feature/ProductionFeatures tests/Unit/ProductionFeatures --log-junit /tmp/production-features-sqlite.xml
php vendor/bin/pint --test app/Domain/Customers/ProductionFeatures config/production-customer-listening.php config/production-customer-preferences.php database/migrations/2026_10_07_253000_production_account_features.php tests/Feature/ProductionFeatures tests/Unit/ProductionFeatures tests/Support/ProductionFeatureFixtures.php
```

These fixtures run migrate:fresh, so native invocation must use a dedicated disposable database, never shared/customer/root review storage. Author's isolated database is `vaseyaudio_feature_consumer` on actual MySQL8.0.46. Native tool calls require explicit network permission, source the private `/workspace/.va-studio-toolchain/mysql/feature_consumer-task.env` and synthetic APP_KEY. Do not print or commit that file. Native environment overrides forcefalse phpunit environment settings. Final exact filters/commands and receipts are in manifest/verification logs. Preserve the original ten-second deadline; native metadata contention/deadline failure is a held development result, not grounds to extend authority or weaken floors.

Migration253 operational down always refuses without deleting tables/history or dropping the migration record. Before installation, validate actual prior users/account/identity/250/242 dependency floor and native dictionary object/derived key namespaces. Only an exact owned global prefix resumes; a complete-unlogged installation can be recorded after exact proof. Missing/foreign/drifted schema requires inspection, never automatic adoption or destructive repair.

## Original defects and honest intermediate results

All original new-child defects are preserved with raw/XML receipts and precursor source snapshots; they are not defects introduced into approved old runtime.

- `ownership-predecessor/`: actual Saved commit/reopen made generic cleanup roll back a foreign transaction; fixed by original outer/module markers and original-only failure cleanup.
- `plain-parent-predecessor/`: actual late ArrayObject app parent ran inside terminal identity proof, withdrew purpose and released stale grant projection; raw standard repository/plain-parent/statement admission now refuses before callback.
- `events-predecessor/`: actual Beginning callback committed/reopened, which old new-child source adopted, and Committing purpose withdrawal returned unknown after a durable grant. Corrected v2 row-first assertion proves the committed event/state count1. Scoped sole connection observer anchors before beginning delegates and proves after committing delegates, preserving standard event/callback order and every other connection/original manager's pending callbacks.
- `scalar-reference-predecessor/`: a plain PHP scalar reference rewrote captured and live grants_enabled together, releasing stale granted/canGrant:true. Detached raw snapshots now preserve historical configuration evidence; rollout/public snapshots receive the same detachment. Laravel environment is read without resolver execution from a scalar instance or its exact standard offsetSet-generated scalar closure; callback-bearing replacements/hooks are refused.
- First final b6 SQLite result:85 cases/747 assertions,79pass/3native-onlyskip/3errors. Two unit harness call sites missed the newly required source argument. The third is a genuine observer postcommit error: a real normal committed callback performs PublishTrack::unpublish transaction, which the observer incorrectly treats as original beginning; new source must stop interception after the original precommit proof, then rely on final read-only closure. Preserve this first final receipt; it is not a green acceptance claim.
- Native previous selection `native-current-ownership-capacity` has7 cases/62 assertions,4pass and3 genuine errors (public ordinary journey, listening initializer in Saved foreign test, capacity export). Original ten-second budget expired under duplicated identity inspection. Held-context refactor removes only NEW module duplicate inspection through sealed Context; typed identity entry/history/current terminal floors remain mandatory and installer complete floor stays unchanged. Final native reruns must be reported per actual result, not replaced by older green subset.
- Earlier missing synthetic key/import/row-order/native fixture assumptions and overstrong raw-environment instance-only admission setup failures are preserved separately. Synthetic notices, addresses, keys and public/media fixtures are test evidence only.

Do not overwrite these receipts, claim full parity/launch, mutate root shared ownership, activate flags, deliver email/suppression, configure credentials, dispatch hosted matrices or push competing main. Finish independent exact-composed review and root integration before moving to254/email operations.

Final completed checks pin source3ce: SQLite86/766,83pass/3native-onlyskip; actual native postcommit publication withdrawal1/6; full owned Pint passes. The separate native9/109b6 checkpoint reruns all three earlier deadline errors successfully with unchanged original10s budget. Native successor’s broader selection remains deferred. The intermediate9d SQLite config-hook teardown recursion fatal512MiB and companion native exit143 are explicitly preserved; the test-only3ce repair writes through its captured repository and restores original callback state. Independent review, root application/private HTTP/product registration,254 and email preparation remain open. Finish source/evidence push and idle; do not start downstream work without new instruction.
