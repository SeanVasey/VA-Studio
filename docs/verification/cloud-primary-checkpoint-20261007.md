# Cloud primary-development recovery checkpoint

Recorded October 7, 2026, America/Chicago. Integration owner: `/root` in the
selected published cloud workspace `/workspace/VA-Studio`. No second environment
or Mac execution was used. The current branch is
`codex/cloud-primary-development-20261007`.

## Verified starting source and recovery limits

- Clean restored source and freshly read remote main:
  `717f0866444701637e7c396b4376891aa5dd995d`, through PR24.
- All published origin branch refs fetched without pruning. Existing source,
  branches, local database, key, private files, dependencies and locks preserved.
- Prior development chat `01a11333-125e-74e8-bff7-d4d81a5f70f1` is idle;
  its last turn is interrupted. The handoff review and environment setup chats
  are idle/completed. One primary owner integrates current work.
- A compact predecessor status is accessible; the detailed development turn
  currently returns `connection closed before server request was answered`.
  The handoff review explicitly reports unpublished PR25 repairs, private-sitemap
  worker output and earlier uncommitted work as unrecovered. They are not claimed
  recovered or silently discarded. New replacements must identify that boundary.
- PR25 remains open at `f713ce641cb043c9171c4a3b35c2e666d2bf146e` with the
  partial MySQL DDL finding `discussion_r4203470722` unresolved. PR26 remains
  open at `10a6dabe6dd4938680656acf08be8d1aa2a98085`; its actual cheap
  preflight `37578619154` passed. No other open PR appeared in the fresh list.
- Branch-protection read returned GitHub HTTP403 `Resource not accessible by
  integration`. No settings were changed; merge enforcement remains authoritative.

## Restoration and network evidence

The retained signed rootless Debian toolchain activates with
`source /workspace/.va-studio-toolchain/activate.sh`. Actual PHP is 8.4.26;
runtime preflight, `composer validate --strict`, and platform requirements pass.
`composer audit --locked --no-interaction` now passes with no advisories, using
the cloud shell's explicit network permission. Neither auditing nor dependencies
were weakened. Initial default-sandbox GitHub requests failed at proxy port8080;
the permitted network call successfully read/fetched all origin refs and PR state.

The restored SQLite migration repository contains all baseline migrations through
`2026_10_07_239000_production_buyer_assent_observations`. A cloud loopback Laravel
server starts with `--no-reload` when network permission is supplied. Fresh smoke
and native MySQL restoration evidence are recorded separately as they complete;
prior setup receipts are historical and do not certify changed source.

## Current owned work

| Owner | Isolated branch/worktree or scope | Deliverable |
| --- | --- | --- |
| `/root` | `codex/cloud-primary-development-20261007` | One integration/publication path, shared registrations, checkpoints and final source-bound acceptance |
| `pr25_recovery` | `codex/pr25-discovery-epoch-recovery-new-20261007` | New repair against published PR25; exact owned partial-DDL recovery and failure/retry/bookkeeping/data-preservation proof |
| `pr26_review` | Exact PR26 head | Independent retained-amount/authority/evidence review before expected-head development merge |
| `crawler_routes` | `crawler-route-correction-pr23`, then separate listening branch | Actual inquiry-route crawler correction; next private customer favorites/playlists journey |
| `completion_audit` | `codex/cloud-completion-audit-20261007` | Source-backed all-scope completion/dependency checklist |
| `recovery_inventory` | Separate recovery-inventory branch | Published-source census and missing-work reconciliation |
| `native_mysql` | Workspace-owned disposable toolchain | Native MySQL migration proof prerequisites; no production credentials or hosted matrix |

Crawler source `99478464f9b8dd89c5595ab15b75466f90ea6555` and evidence
`5ba37d2defbf56cea8b1f4e3eebcf10fcc8957e6` correct `/inquiries` to
`/contact/inquiries`. The author reports actual 185 cases /4218 assertions with
no failures/errors/skips, covering all eight registered private inquiry routes
and existing privacy/ownership checks. Independent integration review follows.

## Integration order and remaining boundary

1. Independently reconcile exact PR26 source/evidence and merge its reviewed head
   using expected SHA and existing protections.
2. Finish native PR25 failure/retry proof; independently review exact repair;
   integrate the crawler correction and coherent recovery batch with cheap preflight.
3. Continue dependency-ready personal-store journeys using the source-backed audit.
   Existing production commerce, product fulfillment, memberships and migration
   gaps remain implementation work, not a preview-readiness claim.
4. Keep focused checks source-bound and reuse unchanged valid evidence. Reserve
   complete Foundation/native/browser verification for the final integrated exact
   reviewed SHA, with additional full runs only for actual failures/new source.

No live payment activation, real transaction, production credentials, services
purchase, budget/protection change, DNS cutover, active entitlement import or
source retirement is authorized by this checkpoint. Content, actual provider/legal
facts and hosting acceptance retain their concrete preparation/launch gates.
