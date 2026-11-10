# Work unblocked by the 2026-11-10 delegated decisions

Prepared 2026-11-10 on `main` at `2a58a50e`. The decisions are in the
[decision register](../../architecture/decision-register.md) ("Register update — 2026-11-10", `DD-xx`); the placeholders
they allow are tracked in the [production cleanup ledger](../../production-cleanup-ledger.md) (`PCL-xx`). This list is a
plan, not evidence: no task here has been started, and no file, class or key named below was changed by this document.

Ids continue the 2026-11-09 audit backlog, which ended at BL-36. Tasks from that backlog that were already ready
(BL-01 to BL-08, BL-12 to BL-21) are not repeated, with three exceptions that these decisions change:

- **BL-12 and BL-13 (collections and sound-kit storefronts) are withdrawn** from the launch backlog (DD-04, DD-05).
- **BL-16 is replaced by BL-42** and **BL-15 by BL-44**, whose scope the decisions widen.
- **BL-29 becomes BL-41**, no longer blocked.

Rules for every task: the lane rules of 2026-11-10 apply. Everything customer-facing ships default-off behind the
existing flag and policy patterns; no price, license term or tax value is invented; a placeholder carries the
`STAGING PLACEHOLDER`, `[SYNTHETIC]` or `STAGING-synthetic-` marker and gets a ledger entry in the same change; payment,
licensing, authorization and migration changes get an independent review of the tested commit.

## Summary

| ID | Task | Size | Area | Depends on | Ready now |
| --- | --- | --- | --- | --- | --- |
| BL-37 | Canonical deliverable wording; remove the "Untagged" label (DD-30) | S | storefront frontend | — | **yes, top** |
| BL-38 | Generate `/legal/licenses` from published license versions (DD-31) | M | site builder, rights read model | — | **yes, top** |
| BL-39 | Staging-placeholder class in the legal guard, and a staging legal release builder (DD-33) | M | site builder | BL-38 (same files) | yes |
| BL-40 | Six-tier synthetic rehearsal catalog with marked test prices (DD-19, DD-20) | M | staging tooling | the synthetic seller tag change | **yes, top** |
| BL-41 | Track scheduled and unlisted publication (FP-032, DD-39) | M | catalog publication | — | **yes, top** |
| BL-42 | Refund and dispute lifecycle on the production chain with the confirmed values (FP-068, DD-16, DD-17) | L | commerce, refunds | — | **yes, top** |
| BL-43 | Conformance fixture for the decided machine-policy values (DD-16, DD-17, DD-42) | S | commerce policy tests | — | yes |
| BL-44 | Data rights and account closure for production customers; classify the last three tables (FP-082, DD-47 to DD-49) | L | customers, data rights | BL-08 (same schedule file) | **yes, top** |
| BL-45 | One production chain: the tax-calculating checkout (DD-14) | M | commerce, production checkout | — | yes |
| BL-46 | Staging specimen signature image generator (DD-27) | S | contracts tooling | — | yes |
| BL-47 | Transactional mail: transport shape and the paid-but-unsent recovery path (FP-075, DD-43) | M | notifications | BL-03 | after BL-03 |
| BL-48 | Runtime validator holds for the families that stay off at v1 (DD-44, DD-50) | S | ops kit | the synthetic seller tag change (same file) | after it |
| BL-49 | Read-only cutover check for the cleanup ledger | M | console, ops | — | **yes, top** |
| BL-50 | Storefront without deferred-family entry points; service requests through contact (DD-02 to DD-05) | M | storefront frontend, site content | BL-37 (same area) | **yes, top** |
| BL-51 | Legacy redirect dispositions for the deferred families (FP-100) | S | migration | — | yes |
| BL-52 | Status documents caught up with the scope decision (DD-64) | S | documentation | — | yes |

**Parallel sets.** Tasks in different areas run at the same time; tasks in the same area run in the order shown.

- Storefront frontend: BL-37, then BL-50.
- Site builder: BL-38, then BL-39.
- Catalog publication: BL-41.
- Commerce: BL-42, BL-43 and BL-45 touch different namespaces and can run together.
- Customers and data rights: BL-44, after the unpaid-snapshot change (BL-08) merges.
- Staging tooling: BL-40 and BL-46 (different commands).
- Ops kit and console: BL-48, then BL-49 (BL-49 can start first if it leaves `validate-runtime.php` alone).
- Notifications: BL-47. Migration: BL-51. Documentation: BL-52.

Every task that adds tests appends to the SQLite and MySQL skip censuses rather than editing shared entries, and every
new migration needs its own timestamp prefix, assigned when the lane is opened.

## The eight most valuable ready-now tasks

### BL-37 — Canonical deliverable wording (S)

**Scope.** The license card's file line calls the roles "Untagged MP3", "WAV master" and "Track stems"
(`resources/js/lib/catalog.ts`), while the generated line under it uses the license vocabulary: "MP3", "WAV master",
"Stems ZIP". DD-30 makes the generated wording canonical. Change the role labels to that vocabulary everywhere the
storefront, order review and order history print a deliverable, and drop "Untagged", which no typed term backs.

**Acceptance.**
- One label map; the card's file line and the generated Deliverables line print the same words for the same role.
- `tests/Feature/PublicLicenseSummaryReconciliationTest.php` gains an assertion that the two lines agree, and it fails when a label is changed back (mutation check).
- Frontend tests updated; no backend term, payload or stored text changes.

**Touches.** `resources/js/lib/catalog.ts`, the components that render deliverables, `tests/frontend`, the reconciliation test, `docs/licensing-and-offers.md`. **Parallel with** everything outside the storefront frontend.

### BL-38 — `/legal/licenses` generated from published license versions (M)

**Scope.** The license comparison page is free text in the site release, so it can drift from what is sold. Generate
it from the license versions that are published and in their effective window: one section per version with its typed
terms rendered by the same generator the track cards use. The site release decides only whether the page is shown and
carries its title and introduction. A release that still holds hand-typed comparison sections is refused at
publication.

**Acceptance.**
- The page shows exactly the published, in-window license versions, in a stable order, and 404s when the release omits it or no version is published.
- A test publishes a successor version and sees the page change with no site release edit.
- The legal publication guard still runs on the title and introduction.
- Schema change is additive and versioned; older releases still load.
- No license text is authored by this task; with no published version the page is absent.

**Touches.** `app/Domain/SiteBuilder/` (`SiteContentSchema`, `SiteContent`, `LegalPublicationGuard`), `app/Http/Controllers/LegalPageController.php`, the rights read model that feeds the track cards, `resources/js` legal page, `docs/site-content-releases.md`, `docs/legal/README.md`. **Parallel with** everything outside the site builder. Independent review (licensing).

### BL-40 — Six-tier synthetic rehearsal catalog (M)

**Scope.** `vasey:staging-synthetic-catalog` creates two synthetic licenses. Extend its definition to one
`[SYNTHETIC] TEST PRICE` license per tier shape of Sean's six templates (deliverables and typed-term shape only; the
text stays "Synthetic rehearsal content — not for sale; staging test only"), priced 1.11, 2.22, 3.33, 4.44, 5.55 and
6.66 USD, each offered on every synthetic track, including an exclusive where the test chain admits one. This gives
staging a contract and a delivery to rehearse for every tier without a real price or term.

**Acceptance.**
- Every created license, offer and track carries the existing synthetic markers; the command still refuses production, a non-synthetic catalog, a missing tag and non-`Synthetic ` operators.
- A test asserts that no license text or cap from `docs/legal/` or the private templates is copied in.
- Rerun is idempotent; teardown instructions in the runbook cover the new records.
- The ledger's PCL-02 and PCL-03 verification queries are stated as runnable commands.

**Touches.** `app/Console/Commands/StagingSyntheticCatalog.php`, `SyntheticRehearsalCatalog`, their tests, `docs/ops/staging-runbook.md` section 5a, `docs/production-cleanup-ledger.md`. **Parallel with** everything but other changes to that command. Depends on the synthetic seller tag change, because the command refuses without a tag.

### BL-41 — Track scheduled and unlisted publication (M)

**Scope.** Tracks have draft and published only. Add the two states DD-39 defines. *Scheduled*: staff set a UTC instant;
a scheduled command, tracked with `OperationalSignals::track`, publishes the track at that instant only if every
publication guard passes then, and otherwise leaves it a draft with the failure recorded, following the site-release
schedule pattern. *Unlisted*: served at its own URL and share link, excluded from catalog, search, sitemaps, feeds and
related-track lists, sent with `noindex`, and purchasable only under the same guards as a published track.

**Acceptance.**
- Database-level state integrity; an audit event per transition; stale-edit protection as on manual publication.
- Tests: a schedule that comes due with a failed guard does not publish; an unlisted track never appears in any listing, sitemap or search result and its page carries `noindex`; a draft is never reachable by guessing the unlisted URL.
- Native MySQL evidence for the schedule claim race.
- The new table is classified in `RetentionSchedule` (not customer data); FP-032's row is updated honestly.

**Touches.** `app/Domain/Catalog/` (publication), a new migration, the Filament track resource, the storefront catalog, track and sitemap controllers, `routes/console.php`, `docs/catalog-administration.md`. **Parallel with** everything outside catalog publication. Independent review (publication).

### BL-42 — Refund and dispute lifecycle on the production chain (L)

**Scope.** The lifecycle merged in PR #172 refuses until four policy values are set and is wired only to the test
chain. DD-16 confirms the values and DD-17 the exclusive values. Wire the lifecycle to the production order and
grant records (tax chain first, per DD-14), reading the values from the validated machine policy, default-off, in
`local` and `testing` only. Still no provider write: refunds are issued by Sean in Stripe and observed.

**Acceptance.**
- With the decided values the lifecycle runs end to end on synthetic production-chain orders: exact line allocation, dispute hold blocks delivery for that order, a lost dispute ends the line's license, a won one restores access, a refunded exclusive goes to operator review and is never relisted automatically.
- Earlier non-exclusive grants are never revoked. Replayed observations change nothing.
- With any value unset it refuses as today (mutation check).
- Native MySQL race evidence for refund-versus-download and refund-versus-exclusive-reopen; independent review of the tested commit.
- Stays out of `staging` and `production` admissions; ledger PCL-16 names where the values are entered.

**Touches.** `app/Domain/Commerce/RefundDispute/`, the production and tax checkout order and paid-grant read models, `config/refund-dispute.php`, `docs/refund-dispute-lifecycle.md`. **Parallel with** BL-43 and BL-45 if the three lanes agree not to edit `MachinePolicyV1`.

### BL-44 — Data rights and account closure for production customers (L)

**Scope.** Production-signed-in customers cannot reach `/account/data-requests` (D-30 step 3b), and nothing closes a
production account. Add the default-off entry point for the production identity path, and make a staff-approved
deletion close the production account: withdraw access, erase the production listening library and the account's
feature bindings, keep the financial and license record. Then apply DD-47 and DD-48 in `RetentionSchedule`:
`production_account_feature_bindings` and `production_listening_libraries` follow the account lifecycle,
`production_buyer_assent_observations` is held indefinitely, and the consent clock starts at deletion approval only for
an account with no retained order.

**Acceptance.**
- The export and deletion routes answer 404 for a production customer until both the data-rights flag and the production identity policy admit them; owner checks use the production origin, never a request identifier.
- Deletion approval of a production account erases exactly the two account tables and keeps every retained family, each listed in the `deletion_completed` event.
- The pinned unclassified set in `CustomerDataRetentionReportTest` becomes empty in the same commit, and no family's class is loosened.
- No purge code path is added. Native MySQL evidence for approval-versus-export races. Independent review (authorization, customer data).

**Touches.** `app/Domain/Customers/DataRights/`, `app/Domain/Customers/ProductionIdentity/`, `routes/customer-data-rights.php`, `tests/Feature/CustomerComms/`, `docs/customer-data-rights.md`. **Parallel with** everything outside customer data rights; start after BL-08 merges.

### BL-49 — Read-only cutover check for the cleanup ledger (M)

**Scope.** The ledger's verifications are prose. Add `vasey:cutover-check`, a read-only command that asserts the
machine-checkable ones and prints one `PASS`/`FAIL` line per ledger id: no track, license, offer, free definition or
staff account carrying a synthetic or staging marker; the tag path and signature path free of staging names; the
published legal pages free of `STAGING`, "set at production" and brackets; every `test_*` table empty; each switch
of ledger section 3 in the state named by an `--expect` profile (`pre-launch` or `v1-launch`). It reads with plain
selects, writes nothing, prints no value of a secret or a customer field, and exits non-zero on any failure.

**Acceptance.**
- One test per ledger id that plants the placeholder and sees `FAIL`, then removes it and sees `PASS`.
- A test that fails when the ledger gains a `PCL-` id the command neither checks nor lists as manual.
- Runs on SQLite and MySQL; no transaction, no lock.
- The ledger and the cutover runbook name the command.

**Touches.** a new command under `app/Console/Commands/`, `app/Support/Diagnostics/`, tests, `docs/production-cleanup-ledger.md`, `docs/migration/cutover_runbook.md`. **Parallel with** everything; it must not edit `ops/production/validate-runtime.php` (BL-48 does).

### BL-50 — Storefront without deferred-family entry points (M)

**Scope.** FP-001 requires "no unavailable product purchase links". With merchandise, services, collections, kits and
memberships out of v1, audit the navigation, home sections, footer, sitemap and site-content schema for links or
calls to action into those families, and make each absent unless its family is enabled. Give the contact form a
"service request" subject so service enquiries have a path (DD-03); it creates an ordinary inquiry and takes no
payment.

**Acceptance.**
- A frontend and a browser-emulation test walk the published navigation and find no link to a deferred family.
- The sitemap and JSON-LD list none.
- The contact subject is validated on the server; unknown subjects are refused.
- No draft data or route of the deferred families is removed.

**Touches.** `resources/js/components/SiteChrome.tsx`, storefront pages, `app/Domain/SiteBuilder/SiteContentSchema.php`, `app/Domain/Inquiries/`, `docs/contact-inquiries.md`. **Parallel with** everything outside the storefront frontend; after BL-37.

## The other tasks

### BL-39 — Staging-placeholder class in the legal guard, and a staging legal release builder (M)

**Scope.** DD-33 lets staging publish legal pages with marked placeholder values. Add a `staging_placeholder` class to
`LegalPublicationGuard` ("STAGING PLACEHOLDER", "set at production") that is admitted only when the environment is
`local`, `testing` or `staging` and refused in production with the existing reason code. Add a console command, refused
in production, that builds a staging draft release from `docs/legal/PRIVACY.md` and `TERMS.md` with the DD-33 values,
the staging banner, and the reviewer appendices and bracket markers removed.
**Acceptance.** Tests for both environments, mutation-checked; the builder never writes a street address, and a test
asserts its output contains none of the drafts' bracketed spans; `docs/legal/*.md` unchanged; ledger PCL-07 updated.
**Touches.** `app/Domain/SiteBuilder/`, a console command, `tests/Feature/CustomerComms/`. After BL-38.

### BL-43 — Conformance fixture for the decided machine-policy values (S)

**Scope.** A test fixture that builds a `MachinePolicyV1` from the decided values (DD-16, DD-17, DD-42, DD-11) and the
approved assent text and seller name, and asserts it validates; a second test that each decided value is the one the
decision register states, so a later edit to either side fails.
**Acceptance.** No production value is committed outside the test fixture; the account id, return origin and prices
stay synthetic. **Touches.** `tests/Unit` or `tests/Feature` for `app/Domain/Commerce/ProductionPolicy/`, the activation
packet's input table.

### BL-45 — One production chain: the tax-calculating checkout (M)

**Scope.** Two production chains exist: one that refuses tax and one that calculates it. DD-14 makes the tax chain the
one v1 sells through. Add the guard that both can never be admitted on one host, and state in the activation packet
that S2 and S3 rehearse the tax chain.
**Acceptance.** A test that admitting both mounts refuses; both mounts stay literal `false`; independent review
(payment). **Touches.** `app/Http/ProductionCheckout/`, `app/Domain/Commerce/ProductionTaxCheckout/`,
`docs/ops/production-activation-packet.md`.

### BL-46 — Staging specimen signature image generator (S)

**Scope.** A console command, refused in production, that draws a PNG reading "STAGING SPECIMEN — NOT A SIGNATURE" with
PHP GD into the private disk and prints its SHA-256 for `VASEY_LICENSE_SIGNATURE_*`. It draws text only; nothing that
resembles a signature or the logo.
**Acceptance.** Deterministic bytes; `vasey:doctor` names a specimen signature as such; tests; ledger PCL-06.
**Touches.** `app/Console/Commands/`, `app/Domain/Contracts/Production/`, `docs/ops/staging-runbook.md`.

### BL-47 — Transactional mail transport shape and recovery path (M)

**Scope.** The former BL-19, now with DD-43: configure the adapter for implicit TLS on port 465 and a sender on a
dedicated subdomain (values per host), and add the recovery path for an order that is paid but whose message failed:
the order page is reachable from the account without the email, and staff can requeue.
**Acceptance.** Tests for failure, requeue and no duplicate send; nothing is sent by default.
**Touches.** `app/Domain/Notifications/Mail/`, `config/transactional-mail.php`, `docs/transactional-mail.md`. After BL-03.

### BL-48 — Runtime validator holds for families that stay off at v1 (S)

**Scope.** `ops/production/validate-runtime.php` holds checkout, contract v2 and four capability flags. Add holds for
what DD-44 and DD-50 keep off through launch: `production-memberships.enabled` and `http_mounted`,
`member-grants.enabled`, and `REPORTING_MARKETING_SEGMENT_ENABLED`.
**Acceptance.** `tests/ops` cases for each hold, mutation-checked; the production kit builds; ledger PCL-23 and PCL-25
name the checks. **Touches.** `ops/production/validate-runtime.php`, `scripts/ops/capability-boundary.php`, `tests/ops`.
After the synthetic seller tag change, which edits the same file.

### BL-51 — Legacy redirect dispositions for the deferred families (S)

**Scope.** Of the 15 unresolved rows in `resources/legacy-redirects/map.json`, decide those that point into a deferred
family (merchandise, services, kits, memberships): each gets a reviewed disposition to the contact page or the catalog,
never to a page that does not exist. Rows that need the crawl or Sean's URL export stay unresolved.
**Acceptance.** The map's pinned hash and row count are updated with the validator passing; the layer stays
literal-false. **Touches.** `resources/legacy-redirects/`, `docs/migration/legacy_route_inventory.csv`.

### BL-52 — Status documents caught up with the scope decision (S)

**Scope.** `docs/remaining-development-plan.md` and `docs/remaining-development-tasks.csv` still count 11 post-launch
rows and list the deferred families' tasks as launch work; CLAUDE.md's Project Notes still head the adaptations
"pending Sean's confirmation" (DD-64). Update the plan, the task register and that Project Notes heading. Sections 1
to 12 of CLAUDE.md are not touched.
**Acceptance.** Counts agree with the parity CSV (checked by a script, not by eye). **Touches.** documentation only.

## Added by Sean's queue decisions (2026-11-10, SQ-xx)

Sean's own decisions supersede several delegated rows (see the reconciliation table in the decision register). These
tasks follow from them. Under the SQ-04 amendment no family is deferred from
development: each is built default-off behind its own server-side flag, and BL-50 makes storefront entry points follow
those flags.

| Task | Size | Source | Notes |
| --- | --- | --- | --- |
| BL-53 Sound kit storefront and entitlement delivery (FP-007) | L | SQ-04 | Needs kit license terms from Sean before a kit can be sold (open question Q-2). Build to default-off. |
| BL-54 Collections/albums as browse groupings (FP-006) | M | SQ-04 | Individually licensed tracks, no bundle price (FP-066 stays deferred) unless Sean says otherwise. |
| BL-55 tc-lib #162/#164 updates with contract profile re-pins | M | SQ-14 | Before contract activation; frozen-bytes and profile `--check` tests re-pinned through review. |
| BL-56 Pint `laravel` preset on changed files in CI | S | SQ-18 | `pint --test --diff=main`; the whole-repo sweep is one PR after the freeze. |
| BL-57 B2/rclone off-host backups, tiered retention, no-delete key | M | SQ-01, SQ-09 | Kit support plus a staging verification run once Sean creates the bucket and age keys. |
| BL-58 Cloudflare R2 private storage adapter and signed delivery | L | SQ-09 (U-03) | New adapter, delivery path and backup change; the private disk stays the tested path until it lands. |
| BL-59 Cookieless analytics (Plausible) | S | SQ-12 | Default-off; PRIVACY must name the processor before it runs. |
| BL-60 Email-verified guest checkout (U-07) | L | SQ-08 | Blocked on the TERMS §3 amendment (open question Q-1). |
| BL-61 Scheduler dead-man's switch (FP-028) | S | SQ-09 | Alert to support@vasey.audio when the heartbeat goes stale. |
| BL-62 Cutover runbook: BeatStars exclusives off 72 h before, RTO 4 h, rollback thresholds | S | SQ-17 | Docs and `vasey:cutover-check` wording. |
| BL-63 Merchandise with Printful fulfillment (FP-010, FP-088) | L | SQ-04 amendment | Catalog, cart line, verified-payment hand-off to Printful, webhook-verified status, default-off; tests use a fake Printful client only. |
| BL-64 Service pages, deposits and milestones (FP-086, FP-087) | L | SQ-04 amendment | Public service catalog and deposit/milestone payments over the verified Stripe path, default-off; inquiry remains the live path. |
| BL-65 Memberships, offers and automatic bundles (FP-009, FP-083, FP-056, FP-066) | L | SQ-04 amendment | Continue the built membership work and add offers and bundles, each default-off; no recurring charge without Sean's authorization. |

BL-41 (track scheduling) adds the `private` state, America/Chicago entry with UTC storage, and "stays scheduled with a
staff alert" on a failed check (SQ-15).

## Still blocked, and on what

| Work | Waits for |
| --- | --- |
| Staging completion, Stripe drills, contract increment 1 and its host golden, importer review on a live target (BL-24 to BL-28) | Sean: reprovision, two operators with TOTP, backup destination, Stripe test credentials (register, "What only Sean can do", items 1 to 3) |
| Legacy history on real data, membership continuity, redirect crawl (BL-34) | Sean: Studio audit and exports (item 4) |
| Publishing the legal pages in production | Counsel's review and Sean's production values (PCL-07) |
| Freeze, Foundation run, security review (BL-35) | Staging drills; the run itself is already authorized (DD-65) |
| Every live activation (BL-22, BL-23, BL-36) | Sean's separate authorizations (DD-67) |
