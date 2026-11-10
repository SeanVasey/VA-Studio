# Production cleanup ledger

Status: **open; nothing here is done.** Started 2026-11-10 with the delegated decisions in the
[decision register](architecture/decision-register.md) ("Register update — 2026-11-10"; `DD-xx` below refers to it).

This is the single list of every staging placeholder, synthetic value and default-off switch that must be replaced,
decided or flipped before production serves a customer. Add an entry in the same change that introduces a placeholder
or a new off switch. Close an entry only with evidence recorded as
[`docs/ops/production-activation-packet.md`](ops/production-activation-packet.md) section 7 requires (command, exit
code, UTC time, SHA, operator, never a secret value), by adding a "Closed" line under the entry. Entries are not deleted.

Rules that hold for every entry:

- A placeholder exists only on `local`, `testing` and the private `staging` host. It is marked `STAGING PLACEHOLDER`,
  `[SYNTHETIC]` / `synthetic-rehearsal`, or named `STAGING-synthetic-…`.
- No secret, street address, real price or license term is written in this file or anywhere in Git. "Supplied by Sean"
  means entered by him on the host or in the admin panel.
- Flipping a switch listed here is never a side effect of closing an entry. Each live switch needs Sean's own
  authorization (AGENTS.md, release boundary; DD-67).

"Supplier" is who provides the production value or decision. "Verified at cutover" is the check that proves the
placeholder is gone or the switch is in its intended state.

## 1. Staging placeholders and synthetic values to replace

| ID | What it is and where it lives | Production value or decision, and supplier | Verified at cutover |
| --- | --- | --- | --- |
| PCL-01 | **Synthetic STAGING seller tag** (DD-40). Tool-generated audio at `private/branding/STAGING-synthetic-seller-tag.wav` on the staging host, marked in its file name and RIFF metadata (the staging step 11 preparation change adds the generator and `docs/ops/staging-placeholders.md`); `MEDIA_TAG_PATH` and `MEDIA_TAG_SHA256` in the staging environment (`config/media.php` `tag_path`, `tag_sha256`). Every preview processed while it is set carries it. | Sean's approved seller tag WAV (S-6), installed by Sean as `docs/ops/staging-runbook.md` section 3 describes, with its own SHA-256. | On the production host `MEDIA_TAG_PATH` does not contain `STAGING-synthetic` (in any letter case) and `MEDIA_TAG_SHA256` equals the `sha256sum` of Sean's file; no `STAGING-synthetic-*` file exists under the private disk; Sean listens to one tagged preview of a real master before the first publication. Any real master processed on staging under the synthetic tag is reprocessed, not copied. |
| PCL-02 | **Synthetic prices** (DD-19). Offer revisions of the synthetic rehearsal catalog: six licenses named `[SYNTHETIC] TEST PRICE tier N shape …`, one per tier shape, at 1.11, 2.22, 3.33, 4.44 and 5.55 USD (published non-exclusive offers) and 6.66 USD (the exclusive shape, an inactive draft offer) (`offers.price_minor`, `offer_revisions.price_minor`; `vasey:staging-synthetic-catalog`, `SyntheticRehearsalCatalog`). | Real USD prices for every tier and offer, entered by Sean in the admin panel as whole cents (S-8). | The production database holds no offer or license whose name contains `[SYNTHETIC]` or `TEST PRICE` and no track with the `synthetic-rehearsal` slug prefix (every `found` of the PCL-02 query below is 0); Sean signs the price list read back from the published offer revisions (the price-list query below). |
| PCL-03 | **Every tier on every synthetic track** (DD-20), and the synthetic catalog itself: six `[SYNTHETIC]` tracks, their artwork, tone masters and generated stems, each offering all six tier shapes, created with `VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED=true` on staging. | Sean's list of which tiers each real track offers (C-4). The synthetic catalog is never created in production: the command refuses `APP_ENV=production`. | `VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED` is `false` or absent in the production environment (the PCL-03 configuration commands below); the production database was built from migrations and the reviewed import, never from a staging dump; every `found` of the PCL-03 query below is 0. |
| PCL-04 | **Publishing details and per-track exclusive disclosures** (DD-23). Absent on staging; contracts are rehearsed without them. | Sean's PRO, IPI and publishing designee, given to licensees on request, and any earlier release of a track offered exclusively, entered per track by Sean. | Each exclusive offer Sean publishes has its disclosure reviewed by him; none of these details appears in Git. |
| PCL-05 | **Free-license text** (DD-24). Staging free definitions carry the `docs/legal/LICENSE-TIERS.md` section 5.1 draft marked `STAGING PLACEHOLDER`. | The free-license text Sean approves, recorded as the operative free terms (`docs/free-grants.md`, "Admission and identity"). | No free definition in production contains `STAGING PLACEHOLDER` or `[SYNTHETIC]`; the approved terms hash recorded for production matches the text Sean approved. |
| PCL-06 | **Specimen signature image** (DD-27). A staging image reading `STAGING SPECIMEN — NOT A SIGNATURE` at `VASEY_LICENSE_SIGNATURE_PATH`, pinned by `VASEY_LICENSE_SIGNATURE_SHA256` (`config/contracts.php` `vasey_license_v1`). | Sean's signature PNG, installed by Sean on the production host's private disk. It is never committed. It must not change while any v2 origin is unrendered. | The production `VASEY_LICENSE_SIGNATURE_SHA256` equals the hash of the file Sean installed and differs from the staging specimen's; one rendered golden contract on the production host shows his signature and passes the profile check. |
| PCL-07 | **Legal page placeholders** (DD-33, DD-36, DD-37). Staging legal pages open with "STAGING PLACEHOLDER — not in effect" and read "set at production" for the effective date, postal address, venue county, backup provider and email provider. The contact-form notice (`CONTACT_INQUIRIES_PRIVACY_NOTICE`) carries the same marker. The drafts in `docs/legal/` keep their square-bracket placeholders. | From Sean: postal address, Iowa venue county, effective date, the provider names once chosen (PCL-10, PCL-17). From counsel: review of TERMS sections 15 to 17 and the no-samples warranty. Decided already: minimum age, no DMCA agent, deliverable wording (DD-30, DD-34, DD-35). | The production site release publishes through `LegalPublicationGuard` with no refusal; the published `/legal/privacy`, `/legal/terms` and `/legal/licenses` contain neither `STAGING` nor "set at production" nor a square bracket; the release's approval reference names Sean's approval and counsel's review. |
| PCL-08 | **Tax configuration** (DD-14). None on staging beyond Stripe test mode: `config/production-tax-checkout.php` has `enabled`, `http_mounted` literal `false` and `provider`, `funds_mode`, `account_id`, `return_origin` `null`. | Sean, with his accountant: tax registrations, Stripe Tax settings and the product tax category. Then the reviewed change that binds the provider and merchant facts. | Stripe's tax settings show each registration Sean names; one test-mode rehearsal order on the production chain shows tax calculated for a registered jurisdiction and none for an unregistered one. |
| PCL-09 | **Logo and icons** (DD-41). The PNG icons under `public/brand/`; no SVG source and no full icon suite. Nothing is recreated. | Sean's official SVG mark. The icon suite is generated from it. | `assets/` holds Sean's SVG; every app icon is a full-bleed opaque tile (alpha channel asserted); a physical install on iOS and Android shows the mark. |
| PCL-10 | **Mail** (DD-43). `MAIL_MAILER=log` on staging: nothing is sent. `VASEY_PRODUCTION_REAL_MAIL_ADMITTED=false`; `config/production-identity-smtp.php` settings `null` and its `$enabled` literal off; `VASEY_TRANSACTIONAL_MAIL_ENABLED=false`. | Sean: the provider account, its credentials on the host, the sender subdomain and its SPF, DKIM and DMARC records. Then the reviewed code change that flips the identity SMTP literal. | `ops/production/validate-runtime.php` passes with real mail admitted; SPF, DKIM and DMARC resolve for the sender; one enrollment code and one order-ready message reach a mailbox Sean controls and pass DMARC. |
| PCL-11 | **Staff accounts** (DD-52). Staging operators, possibly both held by Sean, and the `Synthetic …` accounts the rehearsal catalog uses. | Sean: the production operator accounts, each with TOTP enrolled, and who the second reviewer is. | `vasey:doctor` passes `operator` on production; no user whose name starts with `Synthetic ` exists; every admin has an enrolled authenticator; role changes are in the audit log. |


### PCL-02 and PCL-03 verification commands

Run these on the production host as the application user, from the release directory (`art` is `php artisan`, as in the
runbooks). Each heredoc is one read-only query, piped to the `mysql` client with the same root-only option file the
backup restore proof uses (`docs/ops/backup-restore-proof.md`; it holds the database credentials and never enters Git).
`art db` is not used: it opens an interactive TTY session and ignores standard input. The queries use only
portable SQL, and `tests/Feature/StagingSyntheticCatalogTest.php` runs this exact text against a database before and after a
synthetic run. Record each command, its output, the UTC time and the release SHA as the entry's "Closed" evidence.

PCL-02, synthetic licenses and prices. Every `found` must be 0:

```sh
mysql --defaults-extra-file=<OPTION_FILE> <DATABASE> <<'SQL'
SELECT 'license templates' AS check_name, COUNT(*) AS found FROM license_templates WHERE name LIKE '%[SYNTHETIC]%' OR name LIKE '%TEST PRICE%' OR slug LIKE 'synthetic-rehearsal-%'
UNION ALL SELECT 'license versions', COUNT(*) FROM license_versions WHERE approval_reference = 'SYNTHETIC-REHEARSAL-NOT-FOR-SALE' OR authored_source LIKE '%SYNTHETIC REHEARSAL LICENSE%'
UNION ALL SELECT 'offers on synthetic licenses', COUNT(*) FROM offers JOIN license_versions ON license_versions.id = offers.license_version_id JOIN license_templates ON license_templates.id = license_versions.license_template_id WHERE license_templates.name LIKE '%[SYNTHETIC]%' OR license_templates.name LIKE '%TEST PRICE%'
UNION ALL SELECT 'offer revisions', COUNT(*) FROM offer_revisions WHERE CAST(snapshot AS CHAR) LIKE '%[SYNTHETIC]%' OR CAST(snapshot AS CHAR) LIKE '%TEST PRICE%'
UNION ALL SELECT 'synthetic tracks', COUNT(*) FROM tracks WHERE slug LIKE 'synthetic-rehearsal-%';
SQL
```

PCL-02, the price list Sean reads back and signs (one row per active offer):

```sh
mysql --defaults-extra-file=<OPTION_FILE> <DATABASE> <<'SQL'
SELECT tracks.slug AS track, license_templates.name AS license, license_templates.type AS license_type, offer_revisions.price_minor, offer_revisions.currency FROM offers JOIN offer_revisions ON offer_revisions.id = offers.current_revision_id JOIN tracks ON tracks.id = offers.track_id JOIN license_versions ON license_versions.id = offers.license_version_id JOIN license_templates ON license_templates.id = license_versions.license_template_id WHERE offers.is_active = 1 ORDER BY tracks.slug, offer_revisions.price_minor;
SQL
```

PCL-03, the switch. The first prints `false` or nothing for `enabled`; the second prints `0`:

```sh
art config:show staging-synthetic-catalog
grep -c '^VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED=true' .env
```

PCL-03, the synthetic catalog. Every `found` must be 0:

```sh
mysql --defaults-extra-file=<OPTION_FILE> <DATABASE> <<'SQL'
SELECT 'synthetic tracks' AS check_name, COUNT(*) AS found FROM tracks WHERE slug LIKE 'synthetic-rehearsal-%' OR artist = 'Synthetic Test Content'
UNION ALL SELECT 'synthetic rights declarations', COUNT(*) FROM rights_declarations WHERE provenance_reference LIKE 'SYNTHETIC-REHEARSAL-%'
UNION ALL SELECT 'synthetic media', COUNT(*) FROM media_assets WHERE original_name LIKE 'synthetic-rehearsal-%'
UNION ALL SELECT 'synthetic stems associations', COUNT(*) FROM stems_recordings WHERE verification_reference LIKE 'SYNTHETIC-REHEARSAL-%';
SQL
```

## 2. Credentials and provider modes

| ID | What it is and where it lives | Production value or decision, and supplier | Verified at cutover |
| --- | --- | --- | --- |
| PCL-12 | **Stripe test mode.** `STRIPE_MODE=test`, `STRIPE_TEST_SECRET_KEY` (`sk_test_`), `STRIPE_WEBHOOK_SECRET` of a test endpoint, `VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED=false`. The webhook receiver accepts test-mode events only. | Sean: the live key as `PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY` only, `STRIPE_MODE=live` and the admission flag, at stage S3 and by his own authorization. Open design decision first: how live events are received (activation packet, section 8). | `validate-runtime.php` passes with live Stripe admitted; no `sk_test_` value and no `STRIPE_TEST_*` flag is set on production; no `sk_live_`, `rk_live_` or `pk_live_` value exists on staging or in Git (the bundle and repository scans pass); the first live charge is Sean's own, reconciled and refunded by him. |
| PCL-13 | **Test-commerce chain flags.** `STRIPE_TEST_CHECKOUT_ENABLED`, `STRIPE_TEST_PAYMENT_PROCESSING_ENABLED`, `STRIPE_TEST_FINALIZATION_ENABLED`, `VASEY_TEST_CONTRACT_ISSUANCE_ENABLED` and the `VASEY_TEST_*` capabilities in `scripts/ops/capability-boundary.php`; on for staging rehearsals. | Off in production, always. `TestEnvironment` refuses them there as well. No supplier. | The production environment file sets none of them `true`; the ten `test_*` tables are empty in production (the retention report lists them `not_applicable`). |
| PCL-14 | **Staging basic auth.** nginx `auth_basic` with `/etc/nginx/vasey-staging.htpasswd`, exempting only `POST /webhooks/stripe`; staging `robots.txt` disallows everything. | Production is public: the derived production kit has no basic auth and no htpasswd. Staging keeps its basic auth after launch. No supplier. | An unauthenticated `GET /` on production answers 200 and on staging 401; production `robots.txt` is the intended public one; the staging credentials were never reused for a production account. |
| PCL-15 | **Off-host backup destination.** Staging `VASEY_BACKUP_DEST` in `/etc/vasey-staging/staging.conf` and its SSH key (DD-53). | Sean: a separate production destination and key, not the staging one. | A production nightly run ends "shipped"; one set fetched back from the destination passes `restore-check`; the restore drill is recorded with `--kind=restore-drill` and shows on the dashboard. |
| PCL-16 | **Per-host policy values.** The retention settings (`VASEY_CUSTOMER_DATA_RETENTION_*`, seven periods and the reference), the refund, dispute and exclusive values of DD-16 and DD-17, and the identity mode of DD-42. Unset in every committed template. | Decided (2026-11-07, 2026-11-09, 2026-11-10); entered on the production host by the operator and confirmed by Sean. | `vasey:retention-report` runs without `refused=retention_policy_unset`; the machine policy validates and its hash is the one Sean approved; the values match the decision register line for line. |
| PCL-17 | **Production host facts** (DD-55, DD-58): region, provider and storage, which the PRIVACY processors table must name. | Sean creates the server; the facts are read from it. | PRIVACY section 5 and section 9 name the real provider and region of the production server. |

## 3. Default-off switches to decide or flip, one at a time

Each stays as shipped until Sean authorizes that activation. "Stays off at v1" means the 2026-11-10 scope decision keeps
it off through launch, and cutover verifies that it is still off.

| ID | What it is and where it lives | Production value or decision, and supplier | Verified at cutover |
| --- | --- | --- | --- |
| PCL-18 | **Production checkout.** `config/production_checkout.php`: `mounted` literal `false`, and the `PRODUCTION_CHECKOUT_*` switches (`HTTP_ENABLED`, `ENABLED`, `RECONCILIATION_ENABLED`, `OPERATOR_RECONCILIATION_ENABLED`, `PROVIDER_IO_ENABLED`, `EXEMPTION_AUTHORING_ENABLED`, `COMMITTED_READ_RECEIPTS_ENABLED`). | Flipped at S2 (test funds) and S3 (live), one at a time, each a reviewed change with Sean's authorization. | The S2 rehearsal evidence exists for the exact SHA; `validate-runtime.php` passes for the stage; each flip has its own record. |
| PCL-19 | **Tax checkout.** `config/production-tax-checkout.php`: `enabled`, `http_mounted`, `committed_read_receipts_enabled` literal `false`. This is the chain v1 sells through (DD-14). | Flipped after PCL-08, by a reviewed change and Sean's authorization. | As PCL-18, plus the tax evidence of PCL-08. |
| PCL-20 | **Contract v2 gates.** `contracts.vasey_license_v2_issuance`, `production_checkout.contract_identity_v2` and `production-tax-checkout.contract_identity_v2`, all literal `false` and held false by `validate-runtime.php`. | Flipped together at D-30 activation (step 8), after the six license versions are authored and reviewed, the host golden passes and every open provider session of a name-only order is expired and reconciled. The provider write needs Sean's authorization. | The activation runbook's record shows zero open name-only provider sessions; a golden contract of each of the six templates renders on the production host and matches the pinned profile; the first paid order issues a v2 contract. |
| PCL-21 | **Pre-launch capability holds** in `ops/production/validate-runtime.php` (`PRE_LAUNCH_HOLDS`, approved 2026-10-18): `VASEY_OPERATIVE_FREE_GRANTS_ENABLED`, `CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED`, `DISCOVERY_SITEMAP_ENABLED`, `VASEY_LISTENING_V2_PROMOTION_ENABLED`. | Each lifted by a reviewed change to that file with Sean's authorization: free grants once PCL-05 closes; operator notifications once mail works (PCL-10); the sitemap at public launch; listening promotion only if wanted (it is a one-way data change). | The validator passes with exactly the intended holds lifted; the sitemap lists only published tracks; a free grant issues under the approved text. |
| PCL-22 | **Production free grants.** `config/production-free-grants.php`: `enabled`, `http_mounted` literal `false`, `provenance` and `storage_root` `null`. | Flipped with the free-grant hold of PCL-21. | As PCL-21. |
| PCL-23 | **Memberships.** `MembershipHttpMount` (admits only a literal `true` at `production-memberships.http_mounted`, in `local` or `testing`); `config/production-memberships.php` and `config/member-grants.php`: `enabled`, `authoring_enabled` `false`, every `approved_*_hash` `null`. | **Stays off at v1** (DD-50). A later membership launch needs Sean's plans, prices and wording and its own review. | All of these still read `false` or `null` on the production SHA; the `/production-memberships/…` routes answer 404; BeatStars retirement is blocked while the membership inventory shows an active member. |
| PCL-24 | **Legacy redirects.** `config/legacy-redirects.php` `enabled` literal `false`; `resources/legacy-redirects/map.json` has 5 retained and 15 unresolved rows. | Flipped at the DNS switch, after the map is reviewed against a crawl and Sean's URL export. Sean authorizes with the DNS change. | Every reviewed legacy URL answers its mapped target on the production host; no unresolved row remains unreviewed. |
| PCL-25 | **Customer-facing default-off features.** `VASEY_CUSTOMER_DATA_RIGHTS_ENABLED`, `VASEY_TRANSACTIONAL_MAIL_ENABLED`, `CONTACT_INQUIRIES_ENABLED`, the production customer identity path (`config/production-customer-identity.php`) and the `REPORTING_CUSTOMER_SEGMENTS_ENABLED`, `REPORTING_CUSTOMER_EXPORT_ENABLED` and `REPORTING_MARKETING_SEGMENT_ENABLED` flags. | Identity, transactional mail, contact intake and data rights are wanted at v1 and are enabled per the activation packet, each after its prerequisite (PCL-10, D-30 step 3b). The marketing segment **stays off at v1** (DD-44). | A real account enrolls, recovers, receives an order-ready message and files a data request on the production host; the marketing segment flag is still `false`. |
| PCL-26 | **Sweeps and purges that are off or unscheduled.** `media.upload_session_sweep.scheduled` literal `false` (the hourly `vasey:upload-sessions-sweep --execute` is registered but gated); `vasey:media-unattached-report` and `vasey:retention-report` are manual dry runs; no media delete and no retention purge exists. | The upload-session sweep is scheduled by a reviewed change once a staging dry run and one executed run are recorded. Any delete of completed media and any retention purge is built, reviewed and separately authorized by Sean (DD-67). | The dashboard shows the sweep's last outcome OK if scheduled; no command with a delete or purge mode has run on production without a recorded authorization. |
| PCL-27 | **Operational alerting.** Dashboard widgets alert nobody; the outbound heartbeat ping does not exist yet; `REPORTING_*_STALE_*` thresholds are defaults. | Sean chooses where alerts go (an address or a monitor) once the ping exists; thresholds confirmed against DD-59. | Stopping the scheduler on staging raises an alert within the threshold; the backup and restore-drill signals are green on production before launch. |

## 4. Items that are not placeholders but must be true at cutover

| ID | What it is | Supplier | Verified at cutover |
| --- | --- | --- | --- |
| PCL-28 | The BeatStars Studio audit and the remaining exports (DD-66): until they exist, active obligations in deferred families (memberships, merchandise, services, kits) are unknown. | Sean | The audit checklist has no unperformed item; each deferred family shows no active obligation, or a recorded way it is honored before retirement. |
| PCL-29 | The four Dependabot alerts from archived evidence lockfiles (DD-63). | Sean | The alert list shows only alerts from the shipped lockfiles; `composer audit` and `npm audit --audit-level=high` pass on the candidate SHA. |
| PCL-30 | The held tc-lib updates (DD-26) stay unmerged until after activation. | Development lead | `resources/contracts/*/profile-assets.json` pins match the installed packages on the candidate SHA. |
