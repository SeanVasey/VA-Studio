# Claude Code development handoff

Sean requested completion and publication of the current development batches,
followed by a detailed repository handoff for another harness. This supersedes
the earlier instruction to keep implementing the entire remaining scope within
this chat. Remaining work is preserved for continuation; completion, launch or
full final verification is not implied.

## Start here

The [ready-to-use continuation prompt](CLAUDE-RESUME.md) can be pasted into Claude
Code. The [branch inventory](BRANCHES.md) and [machine ledger](remote-branches.json)
pin the published source. The owner packets below are byte-identical snapshots;
their [source map](owners/source-map.json) records original commits and hashes.

- [Checkout, source receipts and tax/payment preparation](owners/checkout.md)
- [Paid delivery, native operations and reconciliation](owners/paid-delivery-native-operations.md)
- [Account features, production suppression and email](owners/account-features-email.md)
- [Memberships, member originals, billing and host operations](owners/memberships-operations.md)
- [Free identity, free256 and content migration](owners/free-content.md)
- [Independent source-review decisions](owners/independent-review.md)

Read the latest central branch/review ledger for later approvals; original owner
packets retain their point-in-time wording. In particular, the Source2ec contract
has independent approvalc0cc, while fresh checkoutc6 and paid252 do not. The late
identity-frame cleanup correction5053 is a separately reviewed derivative of
main38. Preserve it when composing older dependency snapshots.

1. Read root `AGENTS.md`, this handoff and its final branch inventory. Fetch
   origin without pruning, inspect local changes and reconcile active owners
   before editing. Preserve unrelated files and historical branches.
2. Use one integration owner and isolated branches/worktrees. Published
   development branches contain both accepted components and unfinished source;
   verify their exact commit and evidence before selectively composing them.
3. Follow `docs/development-agent-queue.md`,
   `docs/live-payment-and-production-preparation-queue.md` and the owner packets
   listed in the inventory. Old dated owner tables are historical.
4. Read `docs/remaining-development-tasks.csv`,
   `docs/remaining-parity-coverage.csv`, `docs/completion-execution.md` and
   `docs/verification/cloud-completion-audit-20261007.md`. Keep the full personal
   BeatStars replacement scope. Six of forty groups are accepted and thirty-four
   remain open; the 103 parity rows are not an implementation percentage.

The repository is `https://github.com/SeanVasey/VA-Studio`. Development in this
chat used the selected cloud workspace `/workspace/VA-Studio`, never Sean's Mac
or a newly launched environment. The prior unpublished PR25 repairs, private
sitemap output and uncommitted predecessor work remain unrecovered. Replacement
work is explicitly labelled new. The restored source was
`717f0866444701637e7c396b4376891aa5dd995d` through PR24.

## Publication and evidence

The final publication ledger and branch inventory accompany this document.
They record exact merged heads, unmerged source and remote push receipts. Read
the newest checkpoint in `docs/verification/cloud-primary-checkpoint-20261007.md`
before using older dated status. PRs25–36 were merged before this handoff batch;
bounded track discovery, the inquiry crawler prefix, PWA fallback, listening,
inquiry notices, service authority, note capacity, consent, production identity,
fixture free grants and sitemap response/crawl corrections are on that source.

Current support attachment, identity/SMTP/historical receipt and suppression
batches use focused development checks, independent sensitive review and cheap
preflight. A final ledger records their actual publication disposition. Other
current branches stay explicitly unmerged where consumer acceptance or review
is incomplete. A successful push preserves work; it does not certify it.

Original raw failures, JUnit, canary source, interrupted runs and harness errors
remain in `docs/verification/`. Source/artifact manifests bind a result to its
actual executable commit. Do not replace old failures with green output or carry
evidence across changed runtime by assumption. For unchanged source, verify hashes
and reuse its valid evidence instead of repeating unrelated matrices.

## Current development dependencies

| Boundary | What the next owner must establish |
| --- | --- |
| Checkout246 physical writes | Independently review the new command frame/write-admission/sole committing dispatcher. Preserve exact original principal, physical transaction marker, deadline and late fresh-policy proof; distinguish direct privileged commit/reopen limitations from normal delegated commit protection. Replay/read/reconciliation must remain unaffected. |
| Paid Source90/2ec | Use the whole-contract independent review, not just a parent-configuration repair review. Preserve one original reader/deadline, historical identity seal, one-use sibling receipts, finite internal PDO/default statement admission and the single producer observer. Read-only receipts can omit consumer admission; new paid writes require complete typed consumer authority. |
| Paid252 | Finish consumer/HTTP/whole-order delivery and recovery proof on approved producer source. Retain the actual native409/expired-authorization410 diagnosis and original results. Reacquire authorization deliberately after recovery; do not extend its lifetime. Preserve original PDF/master bytes and current-owner denial. |
| Account features253 | Finish review of the new account-feature scope, original frame before Beginning delegates, committing proof after ordinary delegates, detached scalar-reference policy snapshots, postcommit delegates and mandatory current identity-floor proof. Native deadline errors and successor fixes remain distinct. |
| Suppression254 | Build from authentic253 withdrawal lineage and its server-only current-recipient reader. Keep this production family distinct from legacy251/Test fixtures. Commit the attempt before I/O, preserve unknown outcomes without resend, and confirm only exact positive evidence. Never unsuppress from a consent grant. |
| Automatic tax255 | Implement authoritative current provider tax facts and exact approved account/mode/API capability. Client totals, prior arithmetic checks and tax scaffolds do not establish payable tax. |
| Free256 | Implement the distinct production free grant family: approved definitions/terms/template/profile/assets, independent admin approval, current customer assent, immutable original rendering/recovery, library and private PDF/master/MP3/stems delivery. Free identity preparation ff0 is a reviewed component, not operative256. |
| Membership257 | Preparation16e is independently reviewed. A later finite PDO/default statement repair has separate scope and review status. Implement authentic paid invoice/period/award/reserve operations, actual last-credit and duplicate-invoice native races, and policy-derived costs/expiry. No synthetic invoice can authorize a production award. |
| Member258 | Review the distinct member-origin preparation and finish current invoice, eligibility, retained redemption and immutable artifact/activation/HTTP fulfilment. Consume only after verified original readiness in the owned transaction; pending/unknown reservations are not automatically reversed. |
| Billing259 | Server-owned approved subscription scope and authoritative bounded current Stripe invoice/payment/charge/balance-transaction evidence are required. Invoice paid flags and deduplicated events alone do not award credits. Renewals, cancellation, dunning, refunds/disputes and missing provider facts remain explicit. |

Do not put two independent financial observers on the same transaction frame.
Consumer commit admission uses the producer's typed seam and sole observer. A
Laravel listener exception may leave framework depth zero while physical PDO
remains open; cleanup needs proof of ownership of the original frame and must
preserve caller, replacement or reopened transactions.

Other agreed catalog/content, collection/album/kit, service/merchandise,
exclusive-sale, promotion, refund/dispute, administration, migration, media and
hosting requirements remain governed by the full register. Current track-store
components do not close those requirements.

## Reproducing focused checks

This workspace has PHP8.4.26, Composer2.8.8, Node24.19 and the unchanged locked
dependency graph. Composer strict validation, platform checks and locked security
audit passed with permitted network access; no lockfiles or auditing were changed.
The local rootless native daemon is MySQL8.0.46. Its evidence is development
evidence; final required MySQL8.4/browser verification remains outstanding.

For this cloud workspace, activate the existing toolchain before PHP commands:

```bash
source /workspace/.va-studio-toolchain/activate.sh
export APP_KEY="$(php -r 'echo "base64:".base64_encode(str_repeat("I",32));')"
php vendor/bin/phpunit tests/Feature/SELECTED_TEST.php --log-junit /tmp/selected.xml
```

Replace the selected path using the actual owner's packet. The displayed key is
synthetic test input. Do not copy application keys or private database environment
files into Git. This toolchain path is cloud-specific and is not part of the repo;
another harness must supply compatible runtimes and install the unchanged locks.
Use direct PHPUnit in isolated worktrees with their own Composer autoload; the
restored `artisan test` command had a Collision/autoload failure. Runtime receipts
record that failure separately.

Use positional Vitest file paths; the rejected `--files` invocation was a harness
error. Use existing frontend/Pint/type/build checks appropriate to changed source.
Do not interpret environment setup failures or skipped cases as functional passes.

Native fixtures need an explicitly selected dedicated testing schema with
appropriate grants and lifecycle cleanup. Some older tests still enforce author
database names; normalize those fixtures before final CI rather than silently
skipping them. Shared fixture/account ownership must be coordinated before a
native run. The actual temporary foreign-schema membership fixture and its grants
were cleaned up; no production schema or external service was used.

Local SMTP and native MySQL checks in this managed workspace required explicit
network permission, including loopback. Initial network rejection and missing
library errors are preserved separately from the genuine probes. Another harness
should diagnose its own permissions without weakening assertions.

## Final verification work still required

Foundation CI remains manual, with the exact reviewed forty-character
`expected_sha`. Do not restore `ci.yml`/`focused.yml`, add automatic native/browser
matrices, increase budgets/timeouts, weaken receipt/provenance checks or change
repository protection settings. Focused checks and inexpensive preflight suffice
for reviewed development merges. Run one consolidated Foundation verification
when the complete integrated candidate is ready; retry only for actual failures
or changed source.

Before that final run, finish the test fixture/engine census. Native MySQL requires
zero skipped cases. SQLite exclusions must be the exact reviewed native-only
methods, without broad file exclusions or hidden guards. Known normalization work:

- Listening freshness/migration SQLite-only temporary-table and metadata cases
  need real native counterparts.
- Discovery namespace SQLite inline CHECK/FK versus global-index cases need
  corresponding native dictionary/table-local-index assertions.
- Free grant recovery's composite dependency-key case needs native coverage;
  free recovery/concurrency hardcoded author schema checks need standard dedicated
  testing-schema admission.
- Suppression migration has SQLite-only engine cases needing native counterparts.
- Audit all newly composed concurrency/identity/membership/paid fixtures for author
  database names and workspace-only snapshots. Normalize current fixtures while
  keeping archived original probe files byte-identical.
- Regenerate and independently inspect the exact method census after the final
  new families are integrated, using existing CI partition/receipt safeguards.

No final Foundation run was dispatched for the handoff. Complete native8.4,
SQLite, rendered browser, storage/restore and provider interoperability acceptance
remain future checks on the exact eventual candidate.

## Content, payment and hosting preparation

The six-agent preparation queue records concrete downstream deliverables:
checkout/tax/Stripe readiness; membership billing and private server operations;
customer identity/email/suppression; reconciliation/whole-order restoration;
content/license migration; independent release review. Their handoff packets
distinguish developed code from work that was only assigned or planned.

Reuse existing `scripts/ops/private-server-preflight.php`, its test harness,
`ops/private-server/` templates and persistent content-upgrade tools. The current
preflight inspects files only and explicitly does not claim deployment readiness.
Actual server TLS/proxy/private storage, scanner/signatures, media processing,
supervised queue/scheduler, mail delivery and backup/restore need separate proof.

Retain source IDs/hashes, original contracts/files, license terms, approved prices,
rights evidence and customer obligations. Do not commit masters, stems, private
exports or secrets. Do not fabricate legal, merchant, tax, provider or policy facts.
The pinned Stripe SDK/API contract used in current planning is21.3.2 /
2026-08-26.dahlia; verify exact source and account capability before configuring it.

## Authorization and next operator actions

Sean authorized current development commits/pushes and reviewed development
merges. He explicitly approved any membership test setup. Necessary synthetic
membership tests and reversible code/preparation can proceed. Another harness
must still respect its own filesystem/network permission mechanism.

Real credential configuration, live payment activation, real-money transactions,
paid services, budget/protection changes, production deployment and DNS/cutover
require separate authorization. Prepare a concrete reviewable action packet with
source, configuration inputs, expected outcomes, test/rollback steps and retained
evidence before seeking that authorization. BeatStars remains authoritative for
live sales and existing obligations until the actual migration/cutover gates pass.
