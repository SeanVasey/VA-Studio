# VASEY.AUDIO completion plan: from current main to an authorized launch

Prepared 2026-10-24 (read-only planning; no repository edit, test, CI run or provider call was made).
Source: `origin/main` at `786fb7c7` (PR #167 merge). Every claim cites a file read for this plan; statements marked
**(inference)** are my judgement, not a recorded fact.

---

## 0. Owner inputs received after this plan was drafted (2026-10-24)

- **Merchant of record (#6, U-04 in part):** Vasey Multimedia dba VASEY.AUDIO. **Seller and licensor (#7, U-05 in part):** Sean Vasey.
  Currency, tax registrations and payout reconciliation remain open.
- **Governing law (#16):** Iowa, USA. The postal address was supplied (street line only, city and ZIP still needed). It is
  kept out of this public repository and inserted when the legal pages are published through the site content system.
- **Drafting assigned to the development lead (#7, #16, part of CX):** Sean asked the lead to draft the privacy policy, the
  terms and the license tier matrix with per-tier terms. The drafts carry proposed numeric caps for Sean to confirm and leave
  prices to Sean; his approval of the final text and the prices is still required before any publication or approved hash.
- **Stripe (#2):** Sean reports production Stripe access. The claude.ai Stripe connector is installed but needs reconnecting,
  so it is not available to the agents yet. Order of work once connected: test mode first (restricted keys, webhook, business
  details, staging drills); live webhooks only once the production host exists; live charging only after a passing drill and
  Sean's explicit go-ahead (AGENTS.md release boundary). API keys are created in the Stripe dashboard and entered into the
  host environment, never into chat or Git.
- **Lanes started early:** SEO (legacy redirects and JSON-LD, Wave 1) and the legal drafting (part of CX's text) are running.

## 1. Honest status

### 1.1 Launch-scope parity numbers

`docs/remaining-parity-coverage.csv` has 103 rows. 11 are `postlaunch_by_design` (FP-026, 048, 057, 059, 070–072,
092–094, 096). `docs/remaining-development-plan.md` (section "Verified starting point") counts the other **92 rows as
launch-required**. The status column is a source reading dated 2026-10-17, "not test acceptance, not release acceptance and
not a completion percentage" (same file, "Parity status").

| Launch rows (92) | Count | Share | Rows |
| --- | --- | --- | --- |
| implemented_evidenced | 43 | 47% | storefront, player, admin/media, licensing core, test checkout chain, test delivery, credits, inquiries |
| implemented_partial | 31 | 34% | FP-005–010, 013–015, 017, 023, 025, 032, 034, 035, 037, 040, 041, 055, 061, 067, 068, 075–077, 081, 082, 087, 091, 098, 102 |
| not_implemented | 13 | 14% | FP-028, 056, 058, 062 (conditional on PayPal use), 066, 069, 088, 089, 090, 099, 100, 101, 103 |
| blocked_on_sean | 5 | 5% | FP-063 (U-04), 083 (C5/U-10), 085 (U-09/U-10), 095 (T09 audit), 097 (S-9) |

What "evidenced" still lacks: most rows have no real-provider, real-host or physical-device evidence. Examples are FP-018 to
FP-022 ("Physical-device acceptance is pending"), FP-053 (MySQL races proven "in native tests, not on a" host) and FP-047 ("A
deployed worker is not demonstrated"). In the task register (`docs/remaining-development-tasks.csv`) **6 of 40 groups are
Accepted (T03–T08)**. FP-103 (security, accessibility and launch evidence for an exact candidate) is `not_implemented`.
So the formal launch acceptance count is zero. The last complete Foundation pass was run `38077166247` on `0f23e63f`
(2026-10-10) (`CLAUDE-PLAN.md` ledger, 2026-10-10 rows). That was about 80 merges ago.

### 1.2 What merged after the 2026-10-17 snapshot, and the row changes it causes

`git log --merges` lists PRs #139–#167 since 2026-10-17. Ledger: `docs/handoff/2026-10-07/CLAUDE-PLAN.md` §5 and
`docs/handoff/2026-10-17/RESUMPTION.md`. **No row changes category.** The gaps narrow as follows:

| Row | Status | What changed / what remains |
| --- | --- | --- |
| FP-055 free grants | partial (narrower) | PR #165 merged the D1 commit observer, first-byte proof and default-off HTTP. `ProductionFreeHttpMount` admits only the literal `true` in `local`/`testing` (`routes/web.php` lines 87–90; `config/production-free-grants.php` `http_mounted => false`). **Still open:** the real bounded source adapter, production-environment admission, Sean's free-grant terms, renderer r2 or hash-checked fonts (CLAUDE-PLAN D1 row), and lifting the `VASEY_OPERATIVE_FREE_GRANTS_ENABLED` pre-launch hold (`ops/production/README.md` item 6). D1 P3 F3 (accept-then-close) is also deferred. |
| FP-009 membership pages | partial (narrower) | PR #159 merged the C4d member domain: index, pre-bytes, commit witness, and `explicit_owner_confirmation_v1` recorded (`decision-register.md`, 2026-10-18). **Still open:** C4e member HTTP (in flight), F3 fence narrowing, native evidence on an exact SHA, a public plan page, and the paid lifecycle (U-10/C5). |
| FP-084 credit ledger | evidenced, **regression open** | G1 is a release blocker. On MySQL 8.4.11 a reserve takes 60–66 s and about 90k statements, overrunning its fixed 60 s budget on main. About 95% of the statements are repeated `IdentityMigrationOwnership::inspect` (CLAUDE-PLAN, 2026-10-19 row). |
| FP-061 / FP-064 Stripe | partial / evidenced (test) | PR #150 added K4 drills: declined, expired, duplicate webhook, reconcile-without-webhook, and a native race. They were proven only with synthetic fixtures (`docs/ops/stripe-test-drills.md`: "not yet run against Stripe"). |
| FP-067 tax | partial | PR #147 added the Tax history index. There is still no real-provider evidence. |
| FP-102 backups/rollback | partial | PRs #148, #152 and #166 added the production ops kit, converging immutable encrypted off-host backups and expired cache pruning. "Nothing was run on a host" (`ops/production/README.md`, last section). |
| FP-103 launch evidence | not implemented | Prerequisites only. S1 ran every PHP test file once locally, with the failures repaired in PRs #151 and #157. A full SQLite suite passed on the D1 tested source: 9,495 tests, 0 failures (CLAUDE-PLAN, 2026-10-24 row). |
| Other | — | PR #140 added the synthetic staging catalog (rehearsal content, not import, FP-098). PR #149 added the staging test-identity relay. PR #153 added the production capability hold. PRs #154, #158 and #167 updated dependencies (Laravel 13.38, Filament 5.10.2, Livewire 4.4.8 advisory fix via #165). |

**In flight (from the brief, not yet on main):** G1, the membership writer latency fix (release blocker, PR imminent), and C4e
(default-off member HTTP). C4e phase A is done and under re-review. Phase B, the native MySQL evidence, comes after G1. No
other lanes are open.

### 1.3 What "done" means

From the `AGENTS.md` release boundary and `docs/migration/cutover_runbook.md` §8:

1. **Feature parity for existing obligations.** All 92 launch rows are accepted or explicitly dispositioned by Sean with a
   continuity plan. Which obligations are active is unknown until the authenticated BeatStars audit (T09). All 14 items in
   `authenticated_studio_audit_checklist.csv` are `not_performed`, and all 27 in `known_unknowns_and_validation.csv` are open.
2. **Live payments authorized.** The production commerce chain must be composed, not just configured. At `786fb7c7`:
   - `ProductionCheckoutServiceProvider` is not in `bootstrap/providers.php`.
   - `routes/production-checkout.php` is not required by `routes/web.php`.
   - There is no production reconciliation console command (only `ReconcileTestPayments` and `ReconcileTestCheckout`).
   - `VerifyStripeWebhook` refuses non-test events.
   - The preflight production profile forces `MAIL_MAILER=log` and `STRIPE_MODE=test` (`ops/production/README.md`, "preflight note").
   - Sean's merchant, tax, terms and price inputs are also required.
3. **DNS/cutover authorized.** A rehearsed runbook, one checkout/exclusive authority, a reconciled delta and a named incident
   owner (runbook §§7–10).
4. **Final integrated Foundation CI on an exact SHA.** One manual dispatch with `expected_sha` (AGENTS.md CI cost policy).
5. **Security and accessibility launch evidence** on that SHA (FP-103 / T38).

### 1.4 Critical path

The longest chain runs through Sean's inputs, not through agent engineering **(inference from the dependency edges in
`remaining-development-tasks.csv` and the activation packet §1)**:

```
Sean: staging host + Stripe TEST keys (S-2/S-3/S-4) ─► hosted Stripe-test drills ─► interop fixes (M-08) ─┐
Sean: BeatStars audit + exports (T09/S-9/U-09) ─► scope disposition ─► product/migration lanes ───────────┤
Sean: merchant/tax/terms/prices (U-04/U-05/S-8) + identity/mail (U-07, sender) ─► production composition ┤
Agent: G1 ─► C4e ─► A5 ─► production composition ─► final-candidate prep ─────────────────────────────────┴─► freeze SHA
  ─► one Foundation run + security/a11y review ─► real-export dry run + restore + cutover rehearsal ─► Sean's launch/DNS go
```

### 1.5 Who can move what

| Bucket | Items |
| --- | --- |
| **(a) Agents, now** | G1; C4e; A5 refund/dispute mechanism (policy values left unset); production commerce composition (default-off); collections/kits storefront and delivery; legacy redirect engine and JSON-LD; historical-order/contract archive importer and delta tooling (synthetic); reporting and dashboards; media orphan tooling; legal-page publication mechanism; transactional mail adapter (default-off); automated a11y audit; deferred-finding batches; final-candidate F2/census normalization |
| **(b) Blocked on Sean's decisions or inputs** | Scope disposition after the audit; U-04/U-05/S-8 terms and prices; U-07; U-08; refund/dispute policy (A5/T21); C5/U-10 membership policy and member display/terms hashes; U-12 services/merch; U-11 e-sign/offers/PayPal; U-13 retention/consent/processors; free-grant terms; track-scheduling timing rules; legal governing law/liability; logo SVG (S-11); named device tester and deployment operator |
| **(c) Blocked on external setup** | Staging VPS/hostname/DNS record (S-2/S-3; not evidenced as provisioned anywhere after the 2026-10-09 MONDAY-PLAN table **(inference: Sean to confirm)**); Stripe test account/keys/webhook secret (S-4); second staff account (S-5); seller tag WAV (S-6); real tracks (S-7); off-host backup destination (S-10); production host region/account (rest of U-02) and storage (U-03); SMTP/ESP sender with SPF/DKIM/DMARC; real BeatStars exports (S-9); DNS zone export (U-018) |
| **(d) Final acceptance gates** | One Foundation pass on the frozen SHA; independent security review of that SHA; a11y and physical-device evidence; staging commerce drills; restore rehearsal; real-export dry run and reconciliation; cutover/rollback rehearsal; Sean's per-operation authorizations (live keys, first real charge, import into active entitlements, DNS) |

---

## 2. Owner-decision list (earliest needed first)

These questions are for Sean. Legal and financial items stay questions: no price, term or policy is proposed here. The
"Default" column gives a process or technical recommendation only.

| # | Question for Sean | Default recommendation | Unblocks | Needed by |
| --- | --- | --- | --- | --- |
| 1 | **Is the Forge + DigitalOcean staging VPS up? What is the staging hostname?** Please add its A/AAAA record (never the apex or `www`). (S-2/S-3) | Size per `ops/staging/README.md` step 1: 4 vCPU / 8 GB / 160 GB | Every hosted rehearsal; FP-061/067/102 evidence | **Now** (Wave 0) |
| 2 | **Can you enter the Stripe TEST account ID, `sk_test_` key and `whsec_` secret in Forge/host only?** (S-4) | Test mode only, host secret store, never chat or repo (`docs/ops/staging-provisioning-checklist.md` §4) | K4 drills against real Stripe; Tax255 automatic-tax evidence; interop findings before freeze | **Now** |
| 3 | **Do you approve a staging-only synthetic preview tag now, or will you supply the real seller tag WAV?** (S-6). **Who is the second staff account?** (S-5) | Approve the staging-only tag for rehearsal; supply the real tag before real content | C1 synthetic catalog on staging (RESUMPTION C1 row); license-approval separation | Now |
| 4 | **Will you do the read-only BeatStars Studio walkthrough (A-001..A-014) and place the official exports in private storage?** (T09/S-9/U-09) | You perform it or export, with the agent's checklist. No credentials are shared with agents. | FP-095/097/098 real run/099/101; PayPal (FP-062), memberships (FP-085), services/merch obligations | **Wave 1**. This is the biggest scope lever. |
| 5 | **Which product families and integrations launch with v1, and which are retired or deferred with a continuity plan?** Candidates: merch, service deposits, offers/negotiation, bundles, PayPal, e-sign, collaborator revenue accounting. | Decide from the audit. Anything with active obligations stays (D-07: narrowing needs an explicit decision preserving obligations). | Whether lanes PF-2/REP-b/OFF exist at all | After #4, before Wave 2 |
| 6 | **What is the merchant legal name and address? Currency? Tax registrations and strategy (Stripe automatic tax or declared exemptions)?** (U-04/S-8) | Keep as a question; qualified accounting/legal input | `MachinePolicyV1` seller/currency/tax; FP-063; production composition activation | Before Wave 3 freeze (code-pinned hashes) |
| 7 | **What are the license tiers, terms text, USD prices and buyer assent text? Who is the qualified reviewer?** (S-8/U-05) | Keep as a question; no invented prices or terms | FP-005, live contracts, final policy hashes | Before freeze |
| 8 | **For production, guest purchasing or account-first? Which recovery design?** (U-07) | Account-first with explicit per-order guest claim, as implemented for test (FP-078 evidence) | Production identity activation; production composition | Wave 2 |
| 9 | **Which sender domain and SMTP/ESP provider for receipts and identity mail? Can you add SPF/DKIM/DMARC records?** | One transactional provider. Marketing ESP is a separate U-13 choice. | FP-075, FP-013 outbound, production identity mail (`config/production-identity-smtp.php`, a code change) | Wave 2 |
| 10 | **Refund window, partial refunds, dispute/chargeback consequences for downloads, rights and exclusives?** (A5/T21) | Keep as a question. Agents build the mechanism with policy values unset (refuse when unset). | FP-068 activation | Values before freeze |
| 11 | **Exclusive reservation TTL, late-payment policy, pending non-exclusive cutoff, availability after refund?** (U-08) | Keep as a question | Exclusive live checkout | Before freeze |
| 12 | **Production host region/provider account, sizing, secrets custody, off-host backup target, incident owner?** (rest of U-02, S-10). **Storage: local private disk on the VPS, R2 or S3?** (U-03) | Second Forge VPS like staging; age-encrypted rclone off-host copy (`docs/ops/backup-offhost-hardening.md`); local private disk at launch **(inference: the kit assumes a local bind-mounted private root)** | Restore rehearsal; production provisioning; FP-102 | Wave 3 |
| 13 | **If memberships are in scope:** plans, prices, allowances, rollover, renewal-failure/dunning, cancellation, rejoin, legacy grandfathering, member display wording, consumption/retention terms and the approved hashes (C5/U-10) | Keep as a question; nothing recurring exists (FP-083) | FP-009 public page, FP-083, FP-085, member flags | Wave 2–3, only if audit shows active members or you want memberships at launch |
| 14 | **What are the free-grant terms text? Do you authorize lifting the free-grant hold at launch?** | Keep as a question | FP-055 activation | Before freeze |
| 15 | **Retention schedule, consent purposes, analytics/email processors, deletion exemptions?** (U-13) | Transactional-only at launch; marketing postlaunch **(inference)** | FP-076, 081, 082, 091 | Wave 2 for export/deletion |
| 16 | **Governing law, venue, liability, age terms? Approve publishing legal pages as a new site-content page type?** (`docs/legal/README.md`) | Agents build the page type; reviewer finalizes text | FP-005 | Wave 2 build, text before freeze |
| 17 | **Track scheduling rules (lead time, precision, grace) and unlisted-track semantics?** (decision-register T12 entry) | Mirror the site-release scheduler (D-24) only if you say so | FP-032 | Wave 3 |
| 18 | **Services deposits/booking terms; merch provider/shipping/returns?** (U-12) **E-sign, offers, PayPal, collaborator payouts?** (U-11) | Depends on #5 | FP-008/087/010/088/056/058/062/069 | After #5 |
| 19 | **Production contract renderer acceptance** (U-06): adopt the pinned offline profile with the r2 PHP-FPM binary injection (C4d packet)? | Yes, technical; legal archival review separately | Production contracts | Wave 2 |
| 20 | **Who are the physical-device tester and the final deployment operator? Can you supply the official logo SVG/icon source?** (S-11) | Name both; the logo must not be recreated (AGENTS.md) | FP-015/017/023/041 device evidence; cutover roles | Wave 3 |
| 21 | **Who controls DNS? Will BeatStars Marketplace keep selling (single exclusive authority)? What are the RTO/RPO and rollback thresholds?** (U-018, U-020, U-027) | One authority; freeze BeatStars exclusives at cutover | Cutover rehearsal | Wave 4 |
| 22 | Housekeeping: may we delete the stale `codex/stems-preview-native-proof-20261003` branch (CI cost trigger)? Do you approve the cost of the one final Foundation run? | Yes / yes, once, at freeze | CI cost | Any time / Wave 4 |

---

## 3. Engineering lanes

Assumptions:
- At most 3–4 concurrent heavy agents, with independent reviewers counted against the cap (RESUMPTION concurrency note).
- Every lane is default-off.
- Census files are append-only and union-merged by the F2 owner.
- One **mount owner** per wave holds `routes/web.php`, `bootstrap/app.php`, `bootstrap/providers.php`, `AppServiceProvider`
  and the skip censuses, because C4 and D1 had to serialize on exactly these (RESUMPTION, lane P1).

| ID | Lane (one PR-sized batch) | Rows / tasks closed or advanced | Depends on | Likely files / conflict risk | Review? | Native MySQL? | Size |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **G1** | Membership writer latency: prove schema ownership once per owned frame and at terminal phases (in flight) | Protects FP-084; T30 release blocker | — | `IdentityMigrationOwnership`, `MembershipRows`, `BillingSchema`, `ProductionCustomerAccess`; conflicts with C4e | Yes (PR #115 currentness) | Yes, plus latency measurement | M |
| **C4e** | Member HTTP over C4d (in flight). Phase B: F3 fence narrowing, render profile r2, one not-found, native evidence on exact SHA, index latency at about 100 periods | FP-009 (HTTP part); T30 | G1 | Mount owner files plus census; member controllers/pages | Yes | Yes | L |
| **A5** | Refund/dispute lifecycle: authenticated provider retrieval, durable staff/MFA observation ledger, full/partial refund and dispute state machine with access/rights/inventory effects, and staff customer/order administration. Policy values are config and refuse when unset. | FP-068, FP-040; T19/T21 | Merged PRs #125/#126 | `app/Domain/Commerce/RefundResolution`, `Operations`, Filament resources; census | Yes (money/rights) | Yes (races) | L |
| **PCC** | Production commerce composition, default-off: register `ProductionCheckoutServiceProvider` behind a false flag; mount `routes/production-checkout.php`; add a production reconciliation operator command; implement the webhook strategy (retrieval-only is current; receiver only if chosen); reviewed preflight-profile parametrization for real mail and live mode behind flags; `trustProxies`; Paid252/Tax mounts admitted for production behind flags; production identity SMTP settings | FP-060/061/063/064/067/073/074/078 production successors; T22/T23/T24; packet §1 gates | C4e (mount files); staging interop findings | Mount owner files, `scripts/ops/private-server-preflight.php`, `ops/production/validate-runtime.php`, `config/production_checkout.php` | Yes (payment/auth) | Yes | L (may split PCC-a ops/preflight and PCC-b mounts) |
| **PF1** | Collections/albums and sound kits: public storefront, licensing, cart lines, checkout, entitlement delivery; bundle pricing for collections; range/resume policy for large archives | FP-006, 007, 037, 066, 077; T26/T27 | PCC mounts (public routes), Sean scope (#5) | `app/Domain/Catalog/*`, pages, pricing; public route file | Yes (licensing/delivery) | Yes (inventory) | L |
| **PF2** | Services public catalog plus deposit/milestone payments; merch only if in scope | FP-008, 087, (010, 088) | #5, U-12 | Services domain, checkout lines | Yes | Yes | M–L, **decision-gated** |
| **CX** | Customer communications and data rights: legal page type (site-content schema v4); default-off transactional mail adapter for order-ready intents and contact operator notification; customer data export and deletion with retention parameters unset; ESP suppression adapter only if U-13 chooses one | FP-005, 013, 075, 076, 081, 082, 091; T15/T32 | #9, #15, #16 for activation only | `SiteContentSchema.php` and release schema; mail/notifications; customer routes | Yes (privacy/identity) | Light | L |
| **SEO** | Legacy redirect engine (reviewed mapping table, one-hop 301, loop/draft-privacy guards, admin import from crawl), JSON-LD structured data, sitemap continuity, prep for the `DISCOVERY_SITEMAP_ENABLED` hold lift | FP-014, 100; T36 | URL list from audit (engine first) | Public discovery routes, middleware (mount owner) | Light | No | M |
| **MIG** | Migration completion on synthetic fixtures: historical orders/contracts as `archive_only`/`verified_grant` (never synthesized), delta capture and exclusivity-authority freeze tooling, product-family import adapters, reconciliation report per runbook §5 cohorts | FP-098, 099, 101; T34/T35/T39 | Real run needs S-9 | `app/Domain/Migration/*`, importer; low conflict | Yes (migration/irreversible) | Yes | L |
| **CAT** | Catalog admin parity: media orphan/quota/delete tooling; collaborator split totals; track scheduling plus private/unlisted state (timing rules needed) | FP-034, 035, 032; T12/T13 | #17 for scheduling | Catalog/media admin, Filament | Yes (publication) | Some | M |
| **REP** | Reporting and operations views: admin dashboard and operational-health widgets (queue age, failed jobs, payment exceptions), sales/conversion reporting, first-party segmentation/export, collaborator revenue accounting (if in scope) | FP-028, 089, 090, 069; T32 | A5 (financial truth) for money reports | Filament widgets, read models; low conflict | Light (read-only); yes for money totals | No | M |
| **UXA** | Experience acceptance prep: automated axe/keyboard/reflow/forced-colors/reduced-motion sweep of every page; fix findings; physical-device and screen-reader protocol for the named tester; PWA icon wiring once the official SVG exists | FP-015, 017, 023, 025, 041, plus device sign-off of FP-018–022; T33 | S-11 logo; tester (#20) | `resources/js`, CSS; broad but shallow | Light | No | M |
| **DEF** | Deferred-finding batches (freeze-and-batch, `codex-deferred-20261011.md`): **DEF-OPS**, **DEF-ID** and **DEF-LANE**, detailed below the table | Hardens FP-102/078/055/067 | Owning lanes | Ops scripts (OPS); identity (ID) | ID yes; OPS light | ID yes | S + M |
| **FND** | Final-candidate preparation: remaining F2 (rows 18/23 peer counterparts, P3-1..P3-6), census normalization after the new families, shard rebalance, held dependency majors decided (Stripe 22 vs pinned 21.3.2, PHPUnit 13, Playwright 1.64.1), full local SQLite plus native-selection sweep on the frozen SHA, README/parity CSV refresh | T01/T02/T38 prerequisites | All launch lanes merged | Census files, CI scripts, docs | Yes (CI gate) | Yes | M |

The three DEF batches:
- **DEF-OPS:** O1 P3s, O3 R6/N1, O4 P3-1/3/4/5, K3 P3s, #149 P2, K4 P3s, C1 P2s r4305244895/r4305244899 plus P3-A..D, #141 Dependabot P2, M2 R3.
- **DEF-ID:** the long-frozen "unwrapped V1/generic/IdentityOriginalCommitWitness metadata" P2s and identity key-rotation F1/F3 (CLAUDE-PLAN D1 row).
- **DEF-LANE:** Tax P3s, member-grant P3s, D1 P3s F3/F6/F8/F9, C4d P3s. Each rides in its owning lane's next batch.

Which deferred items gate launch **(inference from their text)**:
- **DEF-ID** gates production identity activation.
- **D1 F3** gates free-grant activation.
- **C1 P2s** gate a resumed staging rehearsal after staff edit an interrupted draft.
- **O4 P3-3** is a production configuration-drift risk.
- The rest are hardening.

### Waves (at most 3–4 concurrent; the mount owner is named in bold)

| Wave | Concurrent lanes | Gate to exit |
| --- | --- | --- |
| **0 (now)** | **G1** → C4e-A re-review; MIG (synthetic, no mount files); DEF-OPS (ops scripts only) | G1 merged with independent approval and native latency under budget |
| **1** | **C4e-B** (mount owner); A5; MIG (continues); SEO engine (redirect table and JSON-LD in its own files; one mount line queued for C4e's owner) | C4e merged; A5 merged; staging drills started if S-2/S-4 have landed |
| **2** | **PCC** (mount owner) with fixes from the hosted Stripe-test drills folded in; CX; DEF-ID; REP | PCC merged default-off; production chain composable end to end in staging test mode |
| **3** | **PF1** (mount owner, public routes); CAT; UXA; PF2 *only if scope #5 keeps it* | All in-scope rows implemented; DEF-LANE batches drained |
| **4** | **FND** alone, then freeze; the independent security reviewer and one Foundation run in parallel on the same SHA | Exact-SHA Foundation pass; security review with no open P0/P1; a11y/device evidence recorded |

Sequencing rationale:
- G1 must precede C4e (C4d gate).
- C4e, PCC and PF1 each need the mount files, so they take turns as mount owner, one per wave.
- A5 precedes money reports (REP) and refund activation.
- PCC benefits from real staging interop findings, so staging should start in Wave 1.
- FND must be last because new families change the census.

---

## 4. Pre-launch hardening and acceptance

| Step | Owner | Prerequisite | Evidence |
| --- | --- | --- | --- |
| Provision staging (`ops/staging/README.md` steps 1–11), operators with TOTP, seller tag, `vasey:doctor`, backup destination | Sean (Forge/DNS), agent guides | S-2/S-3/S-6/S-10 | Doctor pass, deploy log |
| Synthetic catalog on staging (`vasey:staging-synthetic-catalog`) | Agent with Sean's operator | Tag (#3), DEF-OPS C1 P2s | Command output |
| Stripe TEST drills M-07 (declined, expired, duplicate webhook, reconcile-without-webhook) per `docs/ops/stripe-test-drills.md`; record findings for M-08 | Agent drives, Sean holds keys | S-4 | `vasey:test-commerce-evidence` output |
| Production-chain rehearsal in staging test mode (S2 of the activation packet), after PCC | Agent + Sean | PCC merged; S-4; decision on S2a vs S2b (`ExecutionContextV1` still requires `local`/`testing` for production-checkout test funds) | Packet §7 evidence |
| Restore rehearsal (`docs/ops/backup-restore-proof.md` MySQL steps 1–4, off-host copy read-back) | Operator (Sean or named) | S-10, U-02 | `RESTORE_CHECK result=RESTORE_VERIFIED`, measured restore time |
| Independent security review of the frozen SHA (authz, secrets in bundle, private-asset exposure, webhook, identity) | Separate reviewer agent | Wave 4 freeze | DECISION.md bound to SHA |
| Accessibility and device evidence (screen reader, physical iOS Safari and Android playback/media session/install, mobile admin) | Named tester + agent protocol | UXA, #20 | Recorded results on exact candidate (FP-015/023/041, U-026) |
| **Single Foundation CI** (`final-verification.yml`, `expected_sha`) | Agent dispatches with Sean's go-ahead | FND green locally; no lane open | Run conclusion on that SHA only |
| Real BeatStars export dry run, reconciliation (runbook §§3–5), exceptions signed off | Agent + Sean | S-9, MIG | Private counts/hashes; zero unexplained deltas |
| Legacy redirects populated from crawl and URL export (URL-001..020 are "Recommendation" only today) | Agent | Audit/crawl, SEO | Crawl report, one-hop checks |
| Production provisioning (`ops/production/README.md`) on a non-apex rehearsal hostname | Sean (Forge) + script | U-02/U-03, authorization | Deploy log; validator pass with holds |
| Cutover and rollback rehearsal (freeze/delta, in-flight webhooks, single exclusive authority, rollback preserving new orders) | Operator + Sean | Above plus U-018/U-020/U-027 | Concrete packet with outcomes (T39) |
| Live activation: one flag at a time per packet §6 (provider I/O → reconciliation → receipts → HTTP → new orders), canary purchase/refund, then DNS | **Sean authorizes each**; operator executes | All gates in runbook §8 | Observed live checks; post-switch reconciliation (T40) |

---

## 5. Timeline in waves (not calendar promises)

Observed throughput, from `git log` and the ledger: the 2026-10-17/18 burst merged about 15 PRs with about 4 lanes, mostly
S/M batches. The L-size sensitive D1 lane took 2026-10-18 → 2026-10-24 including two reviews. G1 has been in progress since
2026-10-19. **Inference:** budget roughly one week per wave for L lanes with review. If inputs arrive on time, Waves 0–4 are
about 5 back-to-back weeks of agent work. This is an order-of-magnitude estimate, not a commitment.

- **Back-to-back without Sean:** Waves 0–1 entirely (G1, C4e, A5, MIG synthetic, SEO engine, DEF-OPS), and most of Wave 2
  (PCC/CX/REP built default-off with policy values unset).
- **Where the plan waits on Sean:**
  - Staging inputs #1–#3. Without them, PCC is built blind to Stripe interop and the risk moves to the end.
  - Audit #4 and scope #5, before Wave 3 product lanes.
  - Terms/merchant/tax #6, #7, #10, #11 and #14 before the Wave 4 freeze, because the policy hashes are code.
  - Host #12 before restore and cutover rehearsal.
  - Every activation and DNS step.
- **Minimum Sean input set that unblocks the most:**
  1. Staging VPS + hostname/DNS record (#1).
  2. Stripe TEST keys entered on the host (#2).
  3. The BeatStars audit and exports (#4), then the scope disposition (#5).
  4. Merchant identity/currency/tax and the license tiers/terms/prices/assent with a named reviewer (#6/#7).
  5. Sender domain + SMTP provider (#9).

  With these five, every engineering lane can finish and the only open items are acceptance runs and authorizations.

---

## 6. Risks and recommendations

**Risks**
1. **Unknown active obligations** (high). The 14 audit items have not been performed. A late discovery, such as active
   members, open service jobs or PayPal subscriptions, adds lanes after freeze. *Do T09 in Wave 1.*
2. **Late performance defects like G1** (high). The membership writer overran its budget on main undetected until C4d.
   *Require a native latency measurement in every sensitive lane's evidence*, not just correctness.
3. **Interop surprises with the real Stripe account** (medium–high). The SDK is pinned at 21.3.2 / API `2026-08-26.dahlia`;
   Stripe 22 is held (RESUMPTION). Drills have only run on synthetic fixtures. *Start hosted drills before PCC freezes.*
4. **Shared-file contention** (medium): routes/bootstrap/providers/census. *One mount owner per wave; census append-only
   union; FND owns final normalization.*
5. **Usage-window exhaustion** (medium). 7–8 agents paused everything (RESUMPTION). *Cap at 3–4 including reviewers;
   reviewers start only on a frozen SHA.*
6. **Foundation failing late on stale tests** (medium). S1 found 19 stale failures after earlier merges. *Repeat an S1-style
   full local sweep on the frozen SHA before the single Foundation dispatch, and diagnose any failure from logs before a
   second run.*
7. **Documentation drift** (low–medium): README "Current state" is dated 2026-10-17; the parity status column predates
   PRs #139–#167. *Refresh both in FND (allowlisted docs path, `scripts/ci/ci-scope.py`) and update the parity CSV in each
   lane's merge.*
8. **Default-off code mistaken for launch capability** (medium). *Each family's activation is a single short reviewed
   "activation PR"*: flag flips, approved hashes, lifted holds. It is opened only with Sean's named authorization and SHA.

**Recommendations for efficiency and accuracy**
- Send Sean the five-item input checklist (§5) as one message now. Most waiting time is input latency, not code.
- Batch by surface, not by finding. Deferred P2s/P3s ride in the owning lane's next PR (freeze-and-batch rule). There is
  one DEF-OPS batch now and DEF-ID before production identity activation.
- Keep every lane default-off, with policy values left unset in config so the code refuses until Sean's inputs arrive.
  Mechanisms can merge before decisions; activation cannot.
- Each PR gets one cheap preflight, opened only for a final candidate (RESUMPTION lane protocol). Native MySQL runs on each
  lane's private datadir. There are no Focused-feedback dispatches while a local native server is available.
- **Run the single Foundation pass only when all of these hold:**
  - every in-scope lane is merged;
  - the hosted staging interop fixes are merged;
  - census and shards are normalized;
  - the dependency majors are decided;
  - the local full sweep on the frozen SHA is green;
  - the security reviewer has the same SHA.

  Any later source change voids it (AGENTS.md).
- Re-measure parity after each wave using the same `development_status` vocabulary. Report "launch rows accepted"
  separately from "implemented". Never present a percentage of the 40 task groups as completion
  (`remaining-development-plan.md`).
