# Private-server migration and hosting preparation

This package prepares VA-Studio for Sean's possible private server during migration before launch. Sean authorized configuration, updates and planning on 2026-10-02. The server, operating system, network, credentials and deployment target have not been inspected. The package creates no accounts, services, database, public endpoint or live payment path.

The source basis is candidate `bd1fd374b4c284d172e4f10e2c2921f81726f54f`. The [preparation environment template](../ops/private-server/env.example) deliberately leaves secrets and site-specific settings blank. It is a configuration review input; copying it onto a host is not a passing readiness check.

## Decide the actual host topology

| Route | Fits when | Evidence required before choosing |
| --- | --- | --- |
| Managed processes on Sean's Linux server | The host can isolate application identities, PHP runtime, database and workers and provide durable local storage | Exact OS/support status, resource capacity, service management, filesystem permissions, patching, network/firewall/TLS, backups and operator access |
| Isolated Linux deployment on Sean's virtualization/container host | The host can supply persistent volumes, resource controls and production service supervision | Reviewed images/build, UID mapping, volume locality, database/worker compatibility, ingress/TLS, backup/restore and restart behavior |

Neither route has been selected or executed. A container declaration would not demonstrate that Linux resource limits, scanner controls, persistent storage or restore work. A server behind residential NAT also needs an explicit private/staging-access or public-ingress decision; no port forwarding or DNS changes are included.

Before migration, record host CPU/architecture, RAM, disk capacity, OS/version, filesystem type, network/ingress, available process supervisor, storage mount layout, backup destination, operator identity and recovery access. Record provider/utility costs and budgets from actual choices. Do not infer that a Mac development machine is a Linux media worker.

## Required application and worker contract

| Component | Required setup | Readiness evidence |
| --- | --- | --- |
| Application | Laravel 13/PHP 8.4, locked Composer packages, compiled Inertia/React assets; web document root `public/` | Runtime versions/extensions, exact candidate/build, boot and supported HTTP flows |
| Build | Node `>=24.15.0 <25`, locked npm packages | TypeScript/frontend tests/build and dependency audit on the candidate |
| Database | MySQL 8.4; dedicated database and restricted runtime identity; separate controlled migration authority | Real schema/trigger guards, transaction/race results, migration preflight and restore |
| Private files | Application and workers see the same durable `storage/app/private` filesystem; private roots are not web served | Owner/mode/UID mapping, source/output hashes, traversal/symlink refusal and joint DB/file restore |
| Queues | Database connection is supported; separate supervised `media`, `payments`, `contracts` consumers | Retry ordering, jobs survive restart, failed-job/recovery visibility and retained evidence |
| Scheduler | Host invokes `php artisan schedule:run` once per minute from the selected release | Scheduler heartbeat and observed due site-release behavior; named operator for overdue/expired work |
| Media | Linux `prlimit`, patched FFmpeg/ffprobe, ClamAV and approved seller tag | Actual clean/hostile/oversize/archive tests, derivative integrity, profile/hash and scanner freshness |
| Documents | Pinned offline PHP PDF renderer and fonts; qpdf/Poppler verification tools | Frozen input → retained exact original document; replays do not replace historical documents |
| Sessions/mail | Same-origin HTTPS cookies and actual selected transactional transport | CSRF/ownership/session expiry, sender authentication and observed mail delivery/recovery |
| Operations | Protected logs, alerts, backups, replay/reconciliation and rollback runbooks | Named owner, observed signals, tested restore and synthetic failure drills |

Required PHP extensions include `pdo_mysql`, `pdo_sqlite`, `mbstring`, `intl`, `bcmath`, `gd`, `fileinfo`, `zip`, `curl` and `posix`. A Redis choice adds an actual Redis service/client dependency; `predis/predis` is not currently locked. The baseline database queue avoids requiring Redis for initial development.

The generic S3 stanza in `config/filesystems.php` is not an implemented cloud-media migration. The current private media adapter explicitly uses the local disk, and `league/flysystem-aws-s3-v3` is not installed. Object storage needs its own adapter/dependency and acceptance work. Web and worker hosts with different unshared local disks cannot satisfy current storage identity simply by setting `FILESYSTEM_DISK`.

See [Mac development](development-macos.md) for dependency setup and the Mac/Linux boundary. Mac frontend/catalog development can proceed independently; current media processing must retain Linux limits and private-file protections.

## Review the environment before any installation

Use [ops/private-server/env.example](../ops/private-server/env.example) to inventory required settings. It includes production application mode, debug off, MySQL, encrypted database sessions, secure/HttpOnly/SameSite cookies and an asynchronous database queue. These are intended controls, not observed deployed controls.

The [read-only configuration preflight](private-server-preflight.md) validates that template separately from an actual protected installation file and can inspect local Linux/PHP/filesystem prerequisites. A blank installation file is blocked. Even a passing file check explicitly reports `deployment_ready: false`: it does not inspect effective cached/process configuration, connect to services or prove an operational deployment.

Supply the real HTTPS `APP_URL`, retained `APP_KEY`, dedicated DB connection, media executable paths, private approved tag path/hash and selected mail transport through the host's protected configuration mechanism. Never put values into Git, chat, CI artifacts or general diagnostic transcripts. Choose trusted proxy handling from the actual ingress; do not blindly trust all forwarded headers. Verify private responses, CSRF and cookie security through the actual proxy.

An existing installation keeps its encryption key. Back up the key with encrypted database/private-file backups; if rotation is chosen, rehearse `APP_PREVIOUS_KEYS` access before changing it. Cached configuration takes precedence over an edited template: rebuild it through the controlled release and restart workers. Do not clear a live installation's configuration to experiment.

The template keeps mail at `log`, inquiries disabled and every test commerce flag false. It omits fabricated license prices, currency/tax rules and live-payment credentials. The blank mail fields are not a configured SMTP service. Protected log collection/rotation/retention must be selected for `stderr`; do not expose stderr to public responses.

## Private-media and scanner preflight

Follow [Media processing](media-processing.md) as the worker identity. PHP ZIP/posix, Linux `prlimit`, supported encoder identifiers and approved tag bytes are prerequisites. The private tag is a regular WAV file within the supported limits, referenced relative to the private disk and bound to its exact SHA-256; no synthetic fallback exists.

The media job has a 900-second timeout and 960-second claim lease. The template's 1200-second queue retry is deliberately longer than both. Split slow media from the 90-second payment/contract workers and verify the supervisor's termination/restart behavior. Network isolation for media subprocesses must be supplied and observed at the host boundary; a PHP argument-array wrapper alone does not establish it.

Choose `clamscan` or the documented production `clamdscan` route. Do not claim signatures from an installed binary. For `clamd`, apply and verify the documented socket/group permissions, size/time limits, `AlertExceedsMax`, archive scanning, UTC daemon time and refresh behavior. The daemon canary needs a worker file-size hard limit of at least 4 GiB plus sparse-file behavior. The current default signature-age ceiling is 48 hours; alert before that ceiling.

Run `vasey:doctor` as the identity that actually owns private storage. Its scanner check writes then removes a bounded private canary, and can log a warning; it is not a completely write-free inspection. A passing doctor check still needs real upload → quarantine → scan → derivatives → rights/license review → publication evidence. Do not onboard the only copy of a master: preserve the independent original and source hash.

## Safe migration and deployment sequence

1. Fix the exact candidate/build and inspect local changes; complete its native CI and independent reviews. Preserve the existing selling system and its source evidence.
2. Inventory the host and approve the chosen isolated topology. Allocate protected development/staging data, a dedicated DB, shared private filesystem and secret mechanism before supplying credentials.
3. Install/build the selected locked candidate through a reviewed release process. Do not use `composer setup`: its migration/key-generation behavior is unsuitable for retained data. Keep schema changes a separate controlled step.
4. Snapshot and back up the database, private assets/contracts/site images and encryption keys before migrations. Preflight owned trigger/schema guards and choose forward repair instead of destructive rollback against paid history.
5. Restore a backup into an isolated location. Validate table counts, retained IDs/hashes, original contract bytes, asset manifests, key decryption and application reads. Record observed restore time and data loss against the owner-selected RPO/RTO.
6. Configure runtime identity, private filesystem, queues, scanner and scheduler. Verify HTTP/session/proxy protections and supervised worker behavior on synthetic fixtures first.
7. Provision the operator only through the trusted interactive `vasey:create-admin` boundary; no default credentials. Verify persisted authority, production MFA enrollment/recovery, revocation and audited operations.
8. Rehearse a small content batch with source hashes and independently retained originals. Finish current rights/publication/scheduling work before claiming automated catalog onboarding.
9. Inventory BeatStars source exports and real historic orders/contracts/consent/membership obligations. Dry-run imports with reconciliation; do not convert imported customers to active entitlements by configuration.
10. Complete production commerce/customer/operations code and reviewed policies. Then run real Stripe **test-account** interoperability and synthetic order-to-contract/delivery drills before requesting any live-selling cutover.

The current scheduler registers scheduled **site-release** publication. It does not automatically schedule payment receipt scanning/reconciliation. Bounded commands exist for receipt recovery, test-payment reconciliation and test finalization; future operator scheduling must preserve cursor progress, exact account scope, idempotency and durable evidence. Do not enable a cron loop that repeatedly handles only the first page and assumes a whole backlog was reconciled.

## Health, recovery and rollback

Record safe health signals for web errors/latency, scheduler heartbeat, queue age/terminal failures, signature age, private disk capacity/integrity, DB/backup health, document/delivery failures and paid-to-fulfilled delay. Set actual alert destinations, thresholds and budgets with the responsible operator; no threshold/uptime/cost is established by this document.

Reports retain candidate/build, tool versions, IDs, counts, hashes and safe reason codes. Exclude secrets, full buyer identities, raw webhook bodies, signed URLs, delivery tokens, private filenames/paths and original customer contracts from general CI/monitoring output. Keep necessary private evidence in access-controlled storage with the selected retention policy.

Rehearse worker interruption, missed dispatch, poison media, missing webhook, expired lease, restart and restore. Recovery commands must preserve immutable originals and use retained evidence; do not reset consumed download attempts, fabricate payment confirmation or rerender missing original contracts as a repair.

Before sales, a code/configuration reversal can use the tested previous release while retaining schema/data. After payments begin, stop new checkout first, preserve inbox/reconciliation and paid/grant history, and resolve in-flight obligations/exclusive availability. Never enable simultaneous independent exclusive-selling paths on BeatStars and the new store. DNS reversal alone cannot reconcile paid orders.

## First sale and cutover remain separate gates

Current checkout, finalization, issuance, activation and delivery policies, and the webhook, permit test-only `local`/`testing`/`staging` operation through one environment helper (`App\Support\Environment\TestEnvironment`). Staging requires staff MFA and refuses live mode and production identity. Production selling requires reviewed production policies/adapters and complete customer/operations workflows; filling this template or setting `STRIPE_MODE=live` does not supply them.

Before live sale, resolve actual seller/legal identity and license terms, prices/currency/tax/capture rules, exclusive timing, refund/dispute policy, buyer account/guest recovery, privacy/retention, support/transactional email and actual source obligations. No value or approval is invented here. Host configuration, source migration, private storage readiness, real payment-account interoperability and complete customer delivery each need their own evidence.

Prepared configuration and reversible development are within the current authorization. This package authorizes no irreversible DNS/cutover, retirement of BeatStars, active customer entitlement import, live payment collection or production content activation. The [security/operating contract](architecture/security-operations.md), [WP-13](work-packages/WP-13-security-reliability-and-release-validation.md) and [WP-14](work-packages/WP-14-cutover-and-postlaunch-reconciliation.md) define the remaining reviewable release evidence and separate action gates.

## Package verification boundary

The installed `vlucas/phpdotenv` parser accepted all 72 template keys. Validation passed for production/debug/session controls, blank credential/policy fields and all eight disabled activation flags, including contact inquiries. All seven relative source links resolve in this checkout, and the template has no duplicate keys. This is file-level preparation; no host runtime, packages, services, ports, credentials, scanner, backup, restore or deployment was executed.
