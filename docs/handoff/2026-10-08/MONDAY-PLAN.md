# Monday staging plan and tracker (from 2026-10-08)

This file is the live tracker for the Monday target. Update the **Status** columns at every checkpoint. The longer plan
and the merge ledger remain in `../2026-10-07/CLAUDE-PLAN.md`.

Sources:
- `main` at `89e3e61f`, read by two read-only audits on 2026-10-08:
  - the code audit (what an operator and a customer can do today);
  - the plan audit (re-score of the 40 register groups and 103 parity rows).
- Sean's instructions of 2026-10-08:
  - All development is approved to proceed.
  - Anything that needs his input, accounts, credentials or uploads is asked for.
  - The staging host is **Laravel Forge + a VPS**.

## 1. What Monday can and cannot be

**Monday target: a private staging site in Stripe TEST mode.** On it, Sean can:

1. sign in to `/admin` with his own staff account and TOTP MFA;
2. upload and publish 3–10 real tracks (WAV, artwork, optional stems) with tagged previews, rights, a reviewed license and USD non-exclusive offers;
3. browse the storefront, track pages and player;
4. buy as a guest with a Stripe **test** card and receive the test contract PDF and the exact purchased files;
5. see a BeatStars **dry-run** report for his catalog export, with nothing applied.

**Not reachable by Monday.** Each of these is a real gap in the code, not a choice:
- live payments;
- production checkout and tax routes (built, not registered);
- the live webhook receiver;
- refunds and disputes (T21);
- production customer identity and email;
- importing BeatStars customers or entitlements, for which no code exists;
- bulk catalog import on MySQL (the draft importer is SQLite-only and metadata-only);
- DNS cutover.

Migrating the live store is a later milestone. The repo's own estimate for production-capable purchase and delivery is 28–55 person-days.

**Register re-score at `89e3e61f`:**

| Status | Groups |
| --- | --- |
| Accepted | 6 of 40 (T03–T08) |
| Built but off by default or test-only | about 7 |
| Partial | 19 |
| Not started | 4 |
| Blocked mainly on Sean | 4 |

Merges since the handoff are reviewed development merges with activation conditions; none closes a register group.

## 2. Decisions

| Date | Decision | By |
| --- | --- | --- |
| 2026-10-08 | Staging host: Laravel Forge managing a VPS | Sean |
| 2026-10-09 | Reconfirmed Laravel Forge + VPS after considering a plain VPS; DigitalOcean was selected earlier in the same session. Access is expected tomorrow (2026-10-10); no host/account/SSH access supplied yet. | Sean |
| 2026-10-09 | Codex finishes #63 safely, then hands the remaining ordered development queue to Claude Code to save Codex usage; detailed handoff and prompt on `harness/claude-handoff-20261009`. | Sean |
| 2026-10-08 | Conditions C11–C13 for paid downloads: runtime requirements doc, per-buyer heavy-work lock, page-driven continuation | Claude (delegated by Sean) |
| 2026-10-08 | Environment, interim: staging runs `APP_ENV=local`, `APP_DEBUG=false`, private access only (basic auth or allowlist; the Stripe webhook stays signature-checked). Every test-commerce policy admits only `local`/`testing` today. | Claude; **confirmed by Sean** (S-1) |
| 2026-10-08 | Access: staging subdomain with TLS and basic auth (the Stripe webhook route is signature-checked and exempt from basic auth) | Sean |
| 2026-10-08 | Environment, target: a reviewed `staging` environment that admits test commerce, requires staff MFA and refuses live mode (lane B2). Staging switches to it once merged. | Claude |

## 3. Work items

The lanes are separate worktrees with no overlapping files. "Review" means an independent reviewer agent writes a decision for the exact commit before merge.

| ID | Item | Lane / worktree | Size | Depends on | Review | Status |
| --- | --- | --- | --- | --- | --- | --- |
| M-01 | Provision the VPS through Forge (PHP 8.4, MySQL 8.4, nginx, ClamAV, ffmpeg, prlimit, qpdf/poppler, Node 24) | A: Sean in Forge + `ops/staging/provision.sh` | L | S-2 | light (security) | kit merged #63 at `49489697`; actual Forge provisioning blocked on S-2, access expected2026-10-10 |
| M-02 | Staging kit: nginx with basic auth, FPM pools per `docs/ops/paid-delivery-runtime.md`, queue workers and scheduler, Forge deploy script, env template, non-root MySQL trigger privilege proof, backups, runbook | A `VA-Studio-ops` / `harness/staging-ops` | M | — | light | reviewed kit merged #63 at `49489697`; source69a ops74/runtime13-63, independent APPROVE/preflight; real services/mount/host pending |
| M-03 | Test-commerce policy profile and validator | B `VA-Studio-commerce` / `harness/staging-test-commerce` | M | — (values: S-4) | **payment** | preparation merged #62 at `0d864771`; final SQLite 76/813, template 36 checks, independent APPROVE; actual host values/probe await S-2–S-4 |
| M-04 | Pipeline runner (receipts → reconcile → finalize → contracts → activation, draining cursors) | B | S–M | — | **payment** | merged #62: durable checked cursors, one-minute minimum, late observations remain exceptions; native journeys 9/249; systemd/host execution pending |
| M-05 | Stripe event delivery (`stripe listen` unit or a test dashboard endpoint) plus the read-only preflight probe | B | S | M-02, S-4 | payment | reviewed templates/walkthrough merged #62; actual account GET, Dashboard/CLI event and purchase untested, blocked on S-2–S-4 |
| M-06 | `staging` environment admission plus MFA, live mode refused | B2 `VA-Studio-stagingenv` / `harness/staging-env-admission` | L | — | **auth/payment** | **Merged** #68 at `d8aba146`: `APP_ENV=staging` admits test commerce, requires staff TOTP, refuses live mode and the Paid252 operative lane; independent auth/payment review and delta approved; C2 domain MFA follow-up; host switch via activation or `ctl refresh` then `route:list` check |
| M-07 | Synthetic end-to-end run on staging, plus drills (declined card, expired session, duplicate webhook, reconcile without webhook) | integrator | L | M-02–M-05, host up | payment evidence | waiting |
| M-08 | Fix buffer for first-real-interop defects (Stripe test, media, renderer), each fix with a regression test | integrator + reviewer | L | M-07 | **payment/licensing** | waiting |
| M-09 | BeatStars export normalizer to `vasey-private-catalog-drafts-v1` | C | M | S-9 (export) | **migration** | waiting on export |
| M-10 | BeatStars dry-run report (no apply) | C | S | M-09 | migration (read-only) | waiting |
| M-11 | Operator onboarding: two staff accounts, MFA, seller tag WAV and its hash, first-pack checklist, upload session support | integrator + Sean | S + Sean | M-02, S-5–S-8 | auth | waiting |
| M-12 | Storefront and player check with real content (keyboard, desktop, mobile) | D | M | M-11 | — | waiting |
| M-13 | Nightly backup plus one restore proof | A | S–M | M-01, S-10 | — | preparation merged #63; credential authentication proved on disposable MySQL, real backup/restore/shipping blocked on S-2/S-10 |
| M-14 | PR #56 paid downloads: independent addendum15 for `55976099..27a151b6`, integrate current main, Codex/preflight, merge (default-off, unmounted) | E `VA-Studio-paid252` | M | — | **payment/licensing** | merged #56 at `86e22f1a`; addenda 15-18 APPROVE WITH CONDITIONS; unmounted, C2/C4-C8/C11 incl. (k) open |
| M-15 | Staging commit gate: affected checks and preflight on each deployed commit | integrator | S | each deploy | — | ongoing |
| M-16 | FPM render risk: renderers spawn `PHP_BINARY` inside web requests, which may be the FPM binary under FPM. Lane A reports the exact fix, then a separate fix and review. | A then fix lane | S | — | licensing | **Merged** #67 at `b9cb7b67`: validated `VASEY_PHP_CLI_BINARY` under FPM, genuine FPM-to-CLI byte-identical renders, independent licensing review approved; frozen V1 files untouched; family 256 binding added at its composition; host check in staging runbook §7 |
| M-17 | Census normalization (SQLite skip list) | integrator | S | census run | — | #60 merged at `3b3a3654` (217 pairs); embed leak fix #66 proven red/green, merging next |
| M-18 | Doc reconciliation: CLAUDE-PLAN status column, Free256 README status, Project Notes contradictions (MySQL version, shard count) | D | S | — | — | done in the follow-up tracker PR (CLAUDE.md shard count and native MySQL availability, CLAUDE-PLAN C1b/D1/A2 cells; C2 kept outstanding, Free256 status line) |
| M-19 | D1 operator command: register rights scopes, link current offer revisions and list unlinked offers | `harness/rights-scope-command` | S | publication and explicit rights references | **rights/auth** | merged #65 at `c8d51000`; SQLite/MySQL command suites each 11/236, independent APPROVE; operators still need to link their real offers |

**Critical path, after Sean grants host access (H0):**

| Step | Hours | Cumulative |
| --- | --- | --- |
| Provision | 6 | 6 |
| Deploy with the pre-written kit | 2 | 8 |
| Configure test keys and policies | 1.5 | 9.5 |
| Synthetic end-to-end | 6 | 15.5 |
| Fix buffer and review | 11 | 26.5 |
| Sean's real-content purchase and evidence | 3 | **≈30** |

The best case, with no interop defects, is about 19 hours. If H0 is Friday 12:00 UTC, the expected finish is Saturday evening, leaving Sunday as slack. If H0 slips past Saturday noon, the cuts in §5 apply.

## 4. Inputs only Sean can supply (in the order needed)

| ID | Input | Unblocks | Status |
| --- | --- | --- | --- |
| S-1 | Accept the interim `APP_ENV=local` staging (private access only) until the `staging` environment lands | M-01–M-07 | **done: accepted** |
| S-2 | Forge account and DigitalOcean VPS (at least 4 GB RAM), and SSH access for deploys | M-01 | **blocking:** Forge + VPS reconfirmed; Sean expects access tomorrow, 2026-10-10 |
| S-3 | Access path: **chosen: a staging subdomain with TLS plus basic auth**. Still needed: the exact hostname and one DNS A record pointing at the VPS, added or authorized by Sean. | M-02, M-05 | blocking: exact hostname unanswered; DNS awaits VPS IP and Sean's action/authorization |
| S-4 | Stripe **test** account ID, `sk_test_` key, and the webhook secret (`whsec_`) or a Stripe CLI login on the host, through a secret channel and never in chat or the repo | M-03–M-05 | blocking: TEST account/config or secure host CLI login not supplied; secrets never in chat/repo |
| S-5 | A second staff person or account: license approval must come from a different staff account than the contributor | M-11 | required for rehearsal; not supplied at checkpoint |
| S-6 | Seller preview tag WAV; nothing can publish without it | M-11 | required for publication; not supplied at checkpoint |
| S-7 | 3–10 tracks: WAV masters (up to 200 MiB each), artwork (up to **20 MiB** each), optional stems as one ZIP per track (up to 200 MiB), plus metadata and rights references. Limits from `app/Application/Media/IngestMediaUpload.php:63`. | M-11 | required real content/rights; not supplied at checkpoint |
| S-8 | License tiers, terms text and USD prices, plus the seller legal name and buyer assent text for the test order policy | M-03, M-11 | required authored terms/prices/seller/assent; templates do not establish approval |
| S-9 | BeatStars catalog export (whatever BeatStars provides), placed in private storage, never in the repo | M-09, M-10 | required private export; not supplied, normalizer/dry run not done in this checkpoint |
| S-10 | Encrypted off-host backup destination (bucket or another server) | M-13 | blocking: encrypted off-host destination not supplied; host backup contains environment/key |
| S-11 | Optional: site copy and the official logo SVG (the logo is not recreated) | M-12 | optional |

## 5. Risks and scope cuts

- **First real Stripe test transaction.** Every acceptance so far used fixtures. If the chain still fails at Sunday noon, demo up to "payment verified" and label it plainly.
- **Host delay.** Use an SSH tunnel with `http://localhost` as the origin; the checkout policy allows it, so no DNS or TLS is needed.
- **Real media or the tag WAV is late.** Start with 3 tracks.
- **No BeatStars export.** Run the dry run on a hand-made metadata sheet.
- **Customer emails.** None are sent on staging (captured on the host), so test as a guest.
- **MySQL triggers as a non-root user.** Proven in lane A before deploy.

## 6. After Monday (ordered by the register's dependencies)

1. Merge the `staging` environment (if not already in) and move staging onto it.
2. Phase A remainder:
   - register and mount production checkout and Tax255;
   - the live webhook receiver;
   - production reconcile;
   - refunds and disputes (T21);
   - mount paid downloads (needs C11 verified on the host).
3. Phase B: root mount of account features, production identity, SMTP and sender (needs Sean's domain and SMTP).
4. Migration: a MySQL-capable draft importer, then the BeatStars converter applied, then the customer/entitlement import design. Applying the import needs Sean's authorization.
5. Phase C: membership writer, member originals, renewals. Phase E product groups. Phase F: Foundation CI on the final commit, cutover rehearsal, launch (Sean's decision).

## 7. Status log

| Time (UTC) | Update |
| --- | --- |
| 2026-10-10 08:15 | Foundation `37967128232` on `f57e7256` failed (SQLite 2/2 at the 60-min limit, WebKit D, MySQL 1 of 8 shards accepted, 1 with 8 inquiry-test errors, 6 cancelled at 210 min: about 68 s per schema-rebuilding case). [VA-Studio #84](https://github.com/SeanVasey/VA-Studio/pull/84) merged at `bd84978e`, expected `185e20d3`: WebKit fixes (test-only, reproduced on WebKit 2359), SQLite 100 min, four native-only migration-test fixes with MySQL red/green, MySQL in 24 shards with measured timings. Reviews approved with conditions applied; preflight success; Codex no findings. Foundation run 3 dispatched on `bd84978e`. Sean approved continued development; four lanes (BeatStars normalizer, MySQL importer, domain MFA C2, launch documents) running under a workflow with independent review. S-2-S-10 unchanged. |
| 2026-10-09 17:27 | [VA-Studio #82](https://github.com/SeanVasey/VA-Studio/pull/82) merged at `59bd6046`, expected `5b424983`: Foundation MySQL runs only the reviewed native selection (163 files / 1,765 cases; MySQL skip census 58), free-grant and attachment tests on the job's disposable database, drift guards, GitHub 210 min, GitLab 8 shards at 175 min. Independent reviews approved (conditions applied); preflight success; Codex P2 fixed. Not yet run on hosted MySQL. Residual: 47 SQLite-only driver-branching files plus 26 helper-reached. Next: one Foundation on the integrated exact SHA; WebKit D still open. S-2-S-10 unchanged. |
| 2026-10-09 15:52 | Foundation `37921309772` on `0f9b39ce` failed (SQLite OOM, two browser fixture/spec faults, WebKit D, MySQL over budget). #70 merged at `13409a29`, expected `e04a050d`: migration recompilation leak fixed (full local SQLite shards 7,634/0 failures at 512M), receipt verifier accepts setUp assertions on reviewed skips, license-draft fixture and Chromium inquiry spec fixed (focused Chromium clean). Independent reviews approved with conditions applied; preflight success; Codex no findings. Open: WebKit D; MySQL native selection (Sean's option 1) in progress. S-2-S-10 unchanged. |
| 2026-10-09 10:58 | #68 merged at `d8aba146`, expected `5513e3ae`: B2 `APP_ENV=staging` admits Stripe-TEST commerce with required staff TOTP and refuses live mode/keys, production-checkout funds, production identity, verified membership provenance and the Paid252 operative lane; `ctl refresh` rebuilds the route cache (C1); repaired the `tests/ops` regression from #67. Final selection 1,965/0 failures; ops 74 + 20 OK; preflight success; Codex no findings; independent review and delta approved (C2 follow-up). Queue complete; next final manual Foundation on the exact integrated SHA. S-2-S-10 unchanged. |
| 2026-10-09 10:24 | #67 merged at `b9cb7b67`, expected `e07da62f`: M-16 renderer children run a probe-validated CLI PHP under PHP-FPM; genuine FPM smoke byte-identical to CLI for free/paid/generic/composed production-free, unset fails closed; suites 1,165/0 failures at `214256a4`; two Codex P2s (probe and generic child loader path) fixed red first; preflight 37916677344 success; Codex no major issues; independent licensing review, delta and re-check approved. Repaired main's Free256 guard (red since #56). Next B2 staging env admission (independent auth/payment review APPROVE WITH CONDITIONS at `56efbb7`, C1 route-cache fix done), then final Foundation. S-2-S-10 unchanged. |
| 2026-10-09 08:56 | #66 merged at `e5e500ca`, expected `93c5ace1`: Livewire asset-injection flush in the test base; ordered red 4 / green 42-1456, regression test red/green; preflight success, Codex no findings. M-16 found main red since #56 on a Free256 guard (`app/Domain/Grants/Paid` must not exist); repaired in #67. Next #67 (M-16), B2. |
| 2026-10-09 08:49 | #60 merged at `3b3a3654`, expected `bcbf72d9`: SQLite skip census union 217 pairs (main 184 incl. #56 + 33), self-test 34 OK, preflight 37906764900 success, Codex no major issues. Next #66 embed leak (ordered red 4 failures / green 42/1456 proven), then M-16, B2. |
| 2026-10-09 08:44 | #56 merged at `86e22f1a`, expected `6dca2379`, last product `fe8cdcb0`: Paid252 default-off/unmounted; integrated SQLite family 136/4954 (1 native-only skip); independent Addenda 15-18 APPROVE WITH CONDITIONS; Codex rounds 23-25 fixed red first, final Codex review no major issues; preflight 37906390268 success. C2, C4-C8 and C11 incl. new (k) remain pre-mount conditions; deliberate C11 Codex thread open. Next #60 union (217 pairs), #66 embed, M-16, B2. S-2-S-10 unchanged. |
| 2026-10-09 06:27 | #63 merged at `49489697`, expected `78b989f6`, functional69a; ops74/runtime13-63, independent runtime 6 methods/18 states PASS, prior admin4 methods/25 states PASS, prior app1 method/5 states plus genuine MySQL app auth3 states/ordinary cleanup PASS, earlier reviewed evidence preserved. Preflight 37892786276 success; exact-head code review complete, all16 threads repaired/resolved. Actual Forge/Stripe purchase/backup unexecuted; S-2–S-10 required. Sean's selected checkpoint: handoff to Claude, next #56 addendum15 then #60 union, ordered embed proof, M-16 and B2. Ledger/status/handoff branch preserve this merge. |
| 2026-10-09 01:41 | #62 merged at `0d864771`, expected head `4902c45e`; SQLite 76/813, template 36, native journeys 9/249, independent APPROVE, preflight 37870562968 success, five Codex findings repaired/resolved and final code review complete. Active host/events/real purchase remain untested. #63 integration checks pass; its final review/merge is next. Forge/DigitalOcean access expected tomorrow; S-2–S-10 still required. |
| 2026-10-09 00:16 | #65 merged at `c8d51000`, expected head `8453909e`: D1 operator command, current SQLite/MySQL 8.4.11 suites each 11/236, independent APPROVE, preflight success, Codex P2 fixed/resolved. Forge + VPS reconfirmed; access tomorrow. Next #62/#63 reviews. S-2–S-4 still block host/Stripe rehearsal; S-5–S-10 still needed for content, export and backup. |
| 2026-10-08 23:30 | Claude handoff to Codex: see `CODEX-HANDOFF.md`. Open: #56 (addendum 15), #60, #62, #63; WIP branches for D1, M-16, B2 and the embed fix. |
| 2026-10-08 23:00 | PR #44 merged (`672f5826`). PR #56 round 22 pushed, addendum 14 APPROVE WITH CONDITIONS. Census run complete: PR #60 opened. M-18 done. |
| 2026-10-08 22:45 | Sean confirmed S-1 (interim local mode, locked down) and chose a staging subdomain for S-3. |
| 2026-10-08 22:40 | Audits complete. Lanes A, B and B2 started. PR #56 at `94d32518` with addendum 13 running. Census run on `d3e1c39a` in progress. Inputs S-1 to S-11 listed. |
