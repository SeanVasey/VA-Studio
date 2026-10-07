# Finish-out plan and session protocol — Claude Code, from 2026-10-07

Read this file first in every session. It is the single entry point; the six
owner packets, `README.md`, `BRANCHES.md` and `remote-branches.json` beside it
are reference material, not the plan. Update the **State** table at the end of
every batch so the next session is never working blind.

## 1. Where the project actually is

- Reviewed `main` at handoff: `523267bb` (PR #40). Six of forty register groups
  (`docs/remaining-development-tasks.csv`) accepted, thirty-four open. The 103
  parity rows (`docs/remaining-parity-coverage.csv`) are an acceptance map, not a
  progress percentage.
- Everything commerce-, identity- or delivery-related runs behind default-off
  flags. Nothing is live. BeatStars remains the live store.
- Toolchain reproduced in this environment: PHP 8.4.26, PHPUnit 12.5.34, locked
  Composer graph, Node 24.21 / npm 11.19, native **MySQL 8.4.11** (CI's image
  line; the Codex workspace only had 8.0.46). Setup commands are in CLAUDE.md
  Project Notes; MySQL: `mysqld --user=mysql --daemonize`, root password
  `ci-only-password` (CI literal, synthetic), database `vaseyaudio_test`.
- Integration branch: `claude/stoic-archimedes-xza96q` → PR #41. All work lands
  through PRs onto `main` from this branch or reviewed lane branches.

## 2. Lane protocol (how to work fast without breaking provenance)

1. **Worktrees, never branch-switching the integrator.** Each lane:
   `scripts/dev/mkworktree.sh /home/user/VA-Studio-<lane> <base-sha>`
   (symlinked locked `vendor/`, own autoload, own `.env`), then
   `git checkout -b harness/<lane>`. One agent per lane; the integrator never
   edits a lane's files.
2. **One native schema per lane** on the local MySQL (`vaseyaudio_<lane>`).
   Native tests run full migrations per case (≈1–3 min each); run only
   `--filter` selections natively. SQLite is the phpunit.xml default and is
   where the broad selection runs.
3. **Composing a frozen Codex branch**: take only the lane's *owned* paths with
   `git checkout <exact-runtime-sha> -- <paths>`; never its borrowed
   identity/checkout snapshots; never merge the whole author tree. Record a
   `source-map.json` (path → blob) under `docs/verification/<lane>-composition-20261007/`.
4. **Verification per batch** (CI cost policy, AGENTS.md): archived canaries
   byte-identical to recorded SHA256 → run; affected selection on SQLite; the
   native-only cases of that selection on MySQL 8.4; Pint on changed files.
   Full family / Foundation CI only for the final integrated exact SHA.
5. **Sensitive changes** (payment, licensing, authorization, migration,
   irreversible data) get an **independent reviewer agent** (opus) that writes
   `DECISION.md` for the exact SHA; the integrator does not self-approve.
6. **Evidence is append-only.** Red runs, harness errors and interrupted runs are
   kept and labelled. Never overwrite an archived canary or receipt.
7. **PR body** uses `.github/PULL_REQUEST_TEMPLATE.md`: tested SHA, table of
   actual counts (skips are not passes), independent reviewer + SHA.
8. Commit trailer: `Co-Authored-By` + `Claude-Session` lines per the session
   reminder. No model IDs in repo artifacts.

## 3. Phased plan to all features working

Ordering follows the register's `depends_on` chain. "Done" for every phase means:
composed on `main`, focused SQLite + native evidence, independent decision where
sensitive, flags still default-off unless Sean activates.

### Phase A — Commerce core (T22, WP06/07/08)
| Step | Deliverable | Status |
| --- | --- | --- |
| A1 | Checkout physical-write c6 + receipt 2ec composed, observer guard, refused-frame record fix, config int fix | **merged** PR #41 → `37653bf1`; release blocker A1b below |
| A1b | Frame admission for intent/basis/authority writes + initiate() re-proof (Codex P1/P2 ×2) | lane `harness/checkout-write-admission-all` running; RELEASE BLOCKER |
| A2 | Paid252 consumer on composed producer; native 409 root cause; whole-order delivery; root route mount | composed + diagnosed on `harness/paid252-composition` (pushed): root cause is identity `IdentityMigrationOwnership::inspect()` cost (~470 metadata statements per identity assertion on MySQL) exhausting the 60 s authorization budget; fix routed to `harness/native-schema-isolation`; HTTP whole-order and root mount still open |
| A3 | Tax255: `ProductionTaxCheckout`, Stripe Checkout `automatic_tax`, SourceV2 + V2 consumer adapter, migration 255000, default off | queued after A1 |
| A4 | Live-payment preparation packet: SDK/API/mode preflight, webhook signature+dedup checks, unknown-outcome reconciliation, dry-run commands, activation checklist with Sean's inputs | built on `harness/production-preparation-1` → PR #46 draft; independent review running. Gaps found: no live webhook receiver; test-mode payments need local/testing APP_ENV (staging path = Sean's decision); no operator reconciliation command |
| A5 | Refund/dispute lifecycle (T21) on top of A2 provider-authoritative state | after A4 |

### Phase B — Customer identity, features, mail (T23/T32)
| Step | Deliverable | Status |
| --- | --- | --- |
| B1 | Account features 253 reviewed + composed + canonical default-off `production-account-features` config | **merged** PR #45 → `1462e822` (dev merge; C1 activation blocker → `harness/native-schema-isolation`; C2/C3 → 254 lane) |
| B2 | PR39 late P2 findings: SuppressionDelivery old-recipient target; IdentityCommittedFrame single-close restoration | identity fix APPROVED; suppression fix APPROVE WITH CONDITION C-A1 (HMAC-mismatched older target must 503) being fixed on `harness/pr39-late-findings` |
| B3 | Suppression254 (distinct family from 251; built on 253 withdrawal reader; provider contract default-off) | after B1 |
| B4 | Root HTTP mount for 253 (session-bound routes, privacy middleware, body caps) + frontend | after B1 |
| B5 | Email operations preflight: sender/origin/TLS/provider scope, queue/scheduler/retention facts | after B3 |

### Phase C — Memberships and member originals (T30/T31, WP11)
| Step | Deliverable | Status |
| --- | --- | --- |
| C1 | 257 (incl. 3cd Rows guard) + 258 preparation reviewed and composed; implementation plan file | **merged** PR #42 → `3716324a`; findings F1/F2 → C1b |
| C1b | F1, F2 and Billing259 read-only evidence adapter | built on `harness/membership-operative-1` (pushed); SQLite green (40/24/88); native evidence in progress; policy env defect (fails closed) being fixed |
| C2 | Operative 257 writer: reserve/lock/proveCurrent, one period per invoice, award/reserve/consume/release/expire, duplicate-invoice + last-credit independent-process MySQL races | after C1 |
| C3 | Billing259: Stripe subscription/invoice/payment/charge/balance-transaction evidence adapter, default-off, pinned SDK 21.3.2 / API 2026-08-26.dahlia | after C2 |
| C4 | 258 member-original renderer/storage/readiness; activation + credit consumption in one owned transaction; HTTP | after C2 |
| C5 | Renewal/cancellation/dunning/legacy continuity (T31) | after C3 |

### Phase D — Free family and content (T18, T34/T35)
| Step | Deliverable | Status |
| --- | --- | --- |
| D1 | Free256 operative family: definitions/terms/template/profile/assets, admin approval, customer assent, immutable rendering, library, private PDF/master/MP3/stems delivery; ff0 identity component composed on canonical 519 | after A2 (reuses paid renderer/transfer by hash) |
| D2 | Content/license migration dry-run packet with immutable originals, source IDs/hashes | after D1; needs Sean's source exports |

### Phase E — Remaining product groups (T26–T29, T33, T36)
Collections/albums, kits, services milestones, merchandise fulfilment, player/PWA
experience acceptance, SEO/route continuity. Each is a lane with the same
protocol, started as its dependencies in A–D close; parity rows in
`remaining-parity-coverage.csv` with `primary_task` = that T-id are the
acceptance list.

### Phase F — Hosting, final verification, cutover (T37–T40)
| Step | Deliverable |
| --- | --- |
| F1 | Private-server preflight extended with real host facts; backup/restore; worker/scanner/queue/scheduler manifests; rollout/rollback packet |
| F2 | Fixture normalization: dedicated testing-schema admission everywhere, native counterparts for every SQLite-only case, exact native-only method census regenerated |
| F3 | One manual Foundation CI dispatch on the final integrated 40-char `expected_sha`; MySQL 8.4 / SQLite / browser, zero skips on native |
| F4 | Cutover rehearsal and authorized launch — Sean's decision |

## 4. Inputs only Sean can supply (block activation, not development)

Merchant/Stripe account + mode + tax registration; approved plan prices,
currency, allowances, rollover/cancellation/dunning policy; license terms and
free-grant terms text; legal pages; production host/storage choice (U-02);
sender domain/DNS/SMTP provider; BeatStars exports for migration; logo source
geometry (not to be recreated). Each is listed again in the activation packets
(A4, C3, D2, F1) with exactly what it unblocks.

## 5. State ledger (update every batch)

| Date (UTC) | Batch | SHA / PR | Evidence | Decision |
| --- | --- | --- | --- | --- |
| 2026-10-07 | A1 compose c6+2ec+guard | `d20d4394`, PR #41 draft | `docs/verification/checkout-composition-20261007/` — SQLite 170/162/8 native-only skips (pre-guard), red regression retained, canaries 5/5, affected 38/37/1 skip, native 8.4.11: exclusion 2/2, rows native 1/1, reviewer receipt pair 2/2, c6 physical canary 1/1 (0 fail/err/skip) | independent review pending; preflight run on d20d4394 was cancelled by the concurrency group (superseded push), live run on aa514349 pending |
| 2026-10-07 | A1 fix: refused-frame transaction record (reviewer Medium) | `e86381bf`, PR #41 | regression red on 4a8cec40 retained; affected SQLite 37/37; canaries 1/1; native 8.4 regression 1/1; preflight run 42 success on 9d70b4ac | independent APPROVE for e86381bf (addendum in `independent-review/DECISION.md`); carried forward: consumers wrapping a receipt in their own transaction must clear the manager record after abort with a probe-B regression on both drivers |
| 2026-10-07 | A1 merged | PR #41 merged at `37653bf1` (head `ece5a9ee`, code head `a97937ad`) | preflight run 47 success; Codex security review no findings; Codex code review P1 r4208264406 + P2 r4208264416 + P2 r4208109348 answered and deferred with reviewer agreement | independent APPROVE (addenda 1–3); open RELEASE BLOCKERS: frame admission for intent/basis/authority writes on `harness/checkout-write-admission-all`; conditions: no route/provider registration, no provider_io_enabled until closed |
| 2026-10-07 | C1 merged | PR #42 merged at `3716324a` (head `de6ae38a`) | preflight run 48 success; Codex code+security no findings | independent decision: composition APPROVED, Rows 3cd narrow APPROVE (F1 open), 258 join APPROVED (F2 open before activation) |
| 2026-10-07 | docs ledger | PR #43 merged at `03c58d87` | preflight run 50 success | docs-only |
| 2026-10-07 | B1 merged | PR #45 merged at `1462e822` (head `8773b720`) | preflight run 56 success; Codex security no findings; Codex P1 = C1 answered | independent APPROVE WITH CONDITIONS (dev merge only); ACTIVATION BLOCKER C1: 253 admission + identity inspect() cost exceed the 10 s deadline natively |
