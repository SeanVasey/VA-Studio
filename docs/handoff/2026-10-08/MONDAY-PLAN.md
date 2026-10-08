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
| 2026-10-08 | Conditions C11–C13 for paid downloads: runtime requirements doc, per-buyer heavy-work lock, page-driven continuation | Claude (delegated by Sean) |
| 2026-10-08 | Environment, interim: staging runs `APP_ENV=local`, `APP_DEBUG=false`, private access only (basic auth or allowlist; the Stripe webhook stays signature-checked). Every test-commerce policy admits only `local`/`testing` today. | Claude; **confirmed by Sean** (S-1) |
| 2026-10-08 | Access: staging subdomain with TLS and basic auth (the Stripe webhook route is signature-checked and exempt from basic auth) | Sean |
| 2026-10-08 | Environment, target: a reviewed `staging` environment that admits test commerce, requires staff MFA and refuses live mode (lane B2). Staging switches to it once merged. | Claude |

## 3. Work items

The lanes are separate worktrees with no overlapping files. "Review" means an independent reviewer agent writes a decision for the exact commit before merge.

| ID | Item | Lane / worktree | Size | Depends on | Review | Status |
| --- | --- | --- | --- | --- | --- | --- |
| M-01 | Provision the VPS through Forge (PHP 8.4, MySQL 8.4, nginx, ClamAV, ffmpeg, prlimit, qpdf/poppler, Node 24) | A: Sean in Forge + `ops/staging/provision.sh` | L | S-2 | light (security) | kit being written |
| M-02 | Staging kit: nginx with basic auth, FPM pools per `docs/ops/paid-delivery-runtime.md`, queue workers and scheduler, Forge deploy script, env template, non-root MySQL trigger privilege proof, backups, runbook | A `VA-Studio-ops` / `harness/staging-ops` | M | — | light | in progress |
| M-03 | Test-commerce policy profile and validator | B `VA-Studio-commerce` / `harness/staging-test-commerce` | M | — (values: S-4) | **payment** | in progress |
| M-04 | Pipeline runner (receipts → reconcile → finalize → contracts → activation, draining cursors) | B | S–M | — | **payment** | in progress |
| M-05 | Stripe event delivery (`stripe listen` unit or a test dashboard endpoint) plus the read-only preflight probe | B | S | M-02, S-4 | payment | in progress |
| M-06 | `staging` environment admission plus MFA, live mode refused | B2 `VA-Studio-stagingenv` / `harness/staging-env-admission` | L | — | **auth/payment** | in progress |
| M-07 | Synthetic end-to-end run on staging, plus drills (declined card, expired session, duplicate webhook, reconcile without webhook) | integrator | L | M-02–M-05, host up | payment evidence | waiting |
| M-08 | Fix buffer for first-real-interop defects (Stripe test, media, renderer), each fix with a regression test | integrator + reviewer | L | M-07 | **payment/licensing** | waiting |
| M-09 | BeatStars export normalizer to `vasey-private-catalog-drafts-v1` | C | M | S-9 (export) | **migration** | waiting on export |
| M-10 | BeatStars dry-run report (no apply) | C | S | M-09 | migration (read-only) | waiting |
| M-11 | Operator onboarding: two staff accounts, MFA, seller tag WAV and its hash, first-pack checklist, upload session support | integrator + Sean | S + Sean | M-02, S-5–S-8 | auth | waiting |
| M-12 | Storefront and player check with real content (keyboard, desktop, mobile) | D | M | M-11 | — | waiting |
| M-13 | Nightly backup plus one restore proof | A | S–M | M-01, S-10 | — | in kit |
| M-14 | PR #56 paid downloads: review addendum 13, Codex, merge (default-off, unmounted) | E `VA-Studio-paid252` | M | — | **payment/licensing** | addendum 13 running |
| M-15 | Staging commit gate: affected checks and preflight on each deployed commit | integrator | S | each deploy | — | ongoing |
| M-16 | FPM render risk: renderers spawn `PHP_BINARY` inside web requests, which may be the FPM binary under FPM. Lane A reports the exact fix, then a separate fix and review. | A then fix lane | S | — | licensing | investigating |
| M-17 | Census normalization (SQLite skip list) | integrator | S | census run | — | running |
| M-18 | Doc reconciliation: CLAUDE-PLAN status column, Free256 README status, Project Notes contradictions (MySQL version, shard count) | D | S | — | — | open |

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
| S-2 | Forge account and VPS (Hetzner or DigitalOcean, at least 4 GB RAM), and SSH access for deploys | M-01 | needed Friday |
| S-3 | Access path: **chosen: a staging subdomain with TLS plus basic auth**. Still needed: the exact hostname and one DNS A record pointing at the VPS, added or authorized by Sean. | M-02, M-05 | choice done; DNS record needed Friday |
| S-4 | Stripe **test** account ID, `sk_test_` key, and the webhook secret (`whsec_`) or a Stripe CLI login on the host, through a secret channel and never in chat or the repo | M-03–M-05 | needed Friday |
| S-5 | A second staff person or account: license approval must come from a different staff account than the contributor | M-11 | needed Saturday |
| S-6 | Seller preview tag WAV; nothing can publish without it | M-11 | needed Saturday |
| S-7 | 3–10 tracks: WAV masters (up to 200 MiB each), artwork (up to **20 MiB** each), optional stems as one ZIP per track (up to 200 MiB), plus metadata and rights references. Limits from `app/Application/Media/IngestMediaUpload.php:63`. | M-11 | needed Saturday |
| S-8 | License tiers, terms text and USD prices, plus the seller legal name and buyer assent text for the test order policy | M-03, M-11 | needed Saturday |
| S-9 | BeatStars catalog export (whatever BeatStars provides), placed in private storage, never in the repo | M-09, M-10 | needed Saturday |
| S-10 | Backup destination (bucket or another server) | M-13 | by Sunday |
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
| 2026-10-08 22:45 | Sean confirmed S-1 (interim local mode, locked down) and chose a staging subdomain for S-3. |
| 2026-10-08 22:40 | Audits complete. Lanes A, B and B2 started. PR #56 at `94d32518` with addendum 13 running. Census run on `d3e1c39a` in progress. Inputs S-1 to S-11 listed. |
