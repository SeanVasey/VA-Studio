# Cloud continuation source recovery inventory — October 7, 2026

The fetched repository preserves the latest customer/product and repaired
catalog-authoring work. PR #25 and PR #26 contain the newest unmerged children.
There is also useful older, published source that is **absent from current main**:
inquiry-specific alert intents, a protected operator sharing dialog, a private
checkout-return shell, and public app installation metadata/guidance. These are
recoverable backlog, not proof that inaccessible cloud-session work was recovered.

This inventory is frozen against `origin/main`
`717f0866444701637e7c396b4376891aa5dd995d`, tree
`1c43bb29aefb56f3698ba734e756d685fd018245` (the JSON records the authoritative
readback). Root owns subsequent integration. No remote branch was deleted,
rewritten or pruned by this lane; no CI, provider, credential, repository setting,
merge or deployment action was performed.

## Census and method

[The machine inventory](cloud-recovery-inventory-20261007.json) records all 142
fetched `origin` branch tips with full SHA/tree/merge-base identities, all 144
commits reachable from those refs but outside main, each commit's ordered parents,
504 deduplicated executable source differences, and the original GitLab mapping.
The fetched histories contain 798 unique commits; main contains 654. Of the 142
remote tips, 73 are ancestors of baseline main and 69 are not.

Executable paths here means all tracked paths except `docs/`, Markdown/CSV and
root policy/readme/license files. It includes tests, build inputs, workflows and
public assets. For each branch, comparison covers paths changed from its merge
base with main to its tip. For each non-main commit, comparison covers its own
first-parent change, including a merge's first-parent delta. Root commits use an
empty baseline. Full retained ancestry remains available in Git.

Changed blobs are compared with the same current main path and all objects
reachable from main, including historical blobs. Exact current equality,
main-history retention, absent current paths and divergent versions are distinct.
**A tip outside main ancestry is not automatically missing work; an absent old
blob is not automatically a needed feature.** Historical retention proves bytes
are preserved, not that current behavior is identical or independently approved.
A divergent path can be an obsolete predecessor of today's implementation.

The complete ref list and raw blob IDs are in the JSON rather than a selectively
chosen branch list. Its non-main commit census also preserves intermediate source
and one-off diagnostic commits that are no longer the best candidate tips.

Read-only checks used normal `git for-each-ref`, `ls-tree`, `merge-base`,
`rev-list --objects`, first-parent tree comparison, `cat-file` and targeted diffs.
`git fsck --full --no-reflogs --unreachable` reported no unreachable objects at
inspection. Accessible reflogs record the current clone/fetch and this session's
worktrees; they do not expose the inaccessible predecessor's dirty filesystem.
No original historical CI archive was downloaded or its recorded test rerun by
this inventory.

## Incorporated source and deliberate successors

| Retained source | Reconciliation with baseline main |
| --- | --- |
| Customer/product completion `da56e26e`, successor `f9596354`, completion batch `a302bf19` | All exact tips are ancestors of main. Their collection/album administration, customer history/recovery and composed children should not be reimplemented. |
| Collection refresh `14b1fdc2`, original order items `ec4766b3`, order reference `f008ea96` | All exact tips are ancestors of main. Later customer claim/refund/recovery tips also survive in main ancestry. |
| Bulk license/private onboarding `97e952b9`, foundations `ea722029`, production preparation `b1dd0dae` | All exact tips are ancestors of main. Their publication maps and exact-tree local/native identities are preserved in main's verification records. |
| Private consumers `60ba52e3`, dependency compatibility `b4cbc483`, retained profile `d74e5607` | Exact tips retained in ancestry; do not revive older locks or registry variants. |
| Pages sitemap `1c6b4469`, index/robots `09b48f9f`, PDF seam `4cbb5a7d`, atomic adoption `0b0db4e1` | Exact tips retained in main ancestry. The separate current crawler correction is owned by another lane. |
| Catalog repair/review `0c7dbd8c` (two refs) | Tip is outside main ancestry, but all 36 branch-changed executable paths are preserved: 14 exactly at current paths, 22 as main-history blobs. Current publication/rights/private-review successors are already present. |
| Metadata presets `903d89fa` | Tip is outside main ancestry, but all 22 changed executable paths are retained: 11 current, 11 historical. No missing new path. |
| Authoring checkpoints `09d93bf6`, `a4f8996c`, `7637b207` | Earlier private-review/authoring variants are superseded by repaired `0c7dbd8c`. Inspection of the later repair shows the additional publication version/manifest/guard and privacy/test corrections now retained. Eight divergent checkpoint-3 paths are old shared files, not absent modules. |
| Single-seller experience `53226865`, PR104 ledger `5a5ee9cf`, CI policy `09baea3e` | Tips outside ancestry nevertheless retain every changed executable blob as current or historical main source. Their ancestry alone would give a false lost-source alarm. |
| Claude image stack `487f0b6c` | Main retains the private image library, site-release image slots and public image serving through later accepted successors. Six divergent paths are older versions of shared files; zero new current-path absence. |

Original GitLab inventory contains 102 named branches. **101 still have exactly
the recorded tip at the same fetched GitHub branch name.** The sole changed name
is main, which advanced from `cae053ef` to baseline `717f086`; its original tip
remains in history. This corroborates source preservation without relying on
commit ancestry alone. It does not re-query live GitLab branch state today.

The handoff's compatibility exception is explicit: original GitLab
`3e61cf5ed428877f89eca88da5ab02d60dec5f7b` was reconstructed on GitHub as
`2faf027977e858ae91b1a4d9ac7e8b9269c7cfb9`, preserving tree
`5b6f3962240b0280e6441b95649455559e3b273c` and parent history rather than the original
commit metadata. Native `2faf0279` is in current main ancestry. That mapping is
retained in `github-handoff-20261002.md`; this lane does not claim a new exact-SHA
transfer or live GitLab blob verification.

## Current open children

| Source | Present only on the published branch at baseline | Owner / next boundary |
| --- | --- | --- |
| PR #25, `f713ce641cb043c9171c4a3b35c2e666d2bf146e` | Eight absent executable paths: bounded eligible-track snapshots, epoch service/migration and focused/race tests. Six existing executable paths changed. | PR25 recovery lane must correct/review/test its actual successor before root integrates. Prior unpublished repairs are not available through this fetched tip. |
| PR #26, `10a6dabe6dd4938680656acf08be8d1aa2a98085` | Eight absent executable paths: read-only production amount requirements/consistency and access/unit tests. | Separate exact-source review lane. Unknown tax/total and false buyer/payable/provider/purchase authority remain deliberate. |

Both remain separate from source already incorporated in main. This inventory
supplies preservation/comparison evidence, not approval to merge either child or
accept its past results for a changed source.

## Recoverable older backlog

The newest preserved combined checkpoint is
`origin/codex/public-installation-checkpoint-20261001`,
`ae8ca1d8a4c61f0edd207cdb63ae828c128d9e45`. Its 32 absent executable paths divide
into four bounded children. The JSON groups every absent path with its original
Git blob ID, mode, byte count and SHA-256. Thirty-one additional paths diverge
from main; these include feature integration seams and older versions of shared
source/registrations. Recompose only needed feature changes against current main;
do not wholesale merge this pre-handoff tree or import old automatic workflows,
dependency locks, timing rows, native skip allowances or obsolete base changes.

| Child | Existing main behavior and retained missing increment | Historical source/evidence and recomposition seams |
| --- | --- | --- |
| Inquiry alert intents — T15 | Main saves private inquiries. Current generic notification foundation supports only `test_order_ready`, with no producer wiring. The absent inquiry-specific child atomically retains a minimal original-operator intent, fenced worker/command, bounded definite retry and unknown-outcome refusal. Transport remains default-off/unbound. | Original source `f0d37831`; use final retained files at `ae8ca1d8` and `docs/verification/inquiry-notification-intents.md`. Reconcile `SubmitInquiry.php`, inquiry config/env, current admin reads/locks, additive migration ownership and current tests/registration. Historical 115-case focused proof passed both engines; a later 158-case SQLite proof had 147 executions/11 skips followed by Pint failure, and its MySQL run was cancelled. Separate style and 34-case native migration proofs are recorded; none is current composition acceptance. |
| Protected operator sharing dialog — T17 | Main already offers a Share action opening the public track in a new tab, public metadata and embeds. The retained child supplies a fresh persisted staff/MFA/public-eligibility descriptor, canonical link/iframe dialog, clipboard success only after fulfillment, manual selection and late-callback cleanup. | Source `94b3d9a8`, final retained files at `ae8ca1d8`; `operator-track-sharing.md`. Reconcile current `TrackResource.php`, CSS and related-browser registration. Historical 21 cases/122 assertions passed per database, frontend/build and two genuine native journeys passed; separate test-only style bridge retained. |
| Private checkout-return shell — T11 | Main's owner-checked return remains a bare status page. The retained child adds existing header/footer/audio owner and fixed private head without public canonical/social/order metadata, with typed CMS-unavailable fallback. | Corrected branch `1939e0de`, native `e811b7d9`, final files at `ae8ca1d8`; `checkout-return-shell.md`. Reconcile current `TestCheckoutController.php`, `CheckoutReturn.tsx`, Blade fallback, metadata and checkout tests. Historical 67-case database selection and 340 frontend cases passed before a separate formatting bridge; all six native identities passed. It preserves payment truth and performs no automatic provider action. |
| Public app installation — T33 / FP-017 | Main's bounded offline recovery exists; it does not supply a manifest, installation disclosure or the retained public/private head lifecycle. The recoverable child has explicit user-controlled browser installation guidance, fixed public metadata and whole-approved-logo icon derivatives. | Permanent `c456a69e` plus fixture/query successors `0751e963`/`36ab8871`; final files at `ae8ca1d8`; `public-app-installation.md` and `docs/brand/asset-manifest.json`. Reconcile current public Storefront/Editorial/SiteChrome/MetadataHead, Blade and CSS. Historical six cases/160 assertions passed per engine, 361 frontend cases passed; corrected native-only `52bfd403` / run 36870313663 passed both projects after two genuine failed predecessors. Real device/OS installation, launcher appearance and full final acceptance remain unverified. |

The four historical verification files above are preserved on the remote
checkpoint, not currently under main's verification directory. Read them with
`git show ae8ca1d8:<path>` before editing. The recorded previous CI results are
historical attribution from those records, not new execution or independent
artifact verification here. Their original failed/cancelled boundaries must
remain visible when publishing a current-main successor.

One-off workflow, MySQL diagnostic, developer PHP packaging and contract-child
probe branches are intentionally retained evidence/tooling. Their absent paths
are not product source to restore automatically. Old Dependabot candidates contain
retired workflow names/older dependency variants; current reviewed dependency
successors supersede them. The machine inventory leaves every unmatched version
visible rather than certifying these old blobs as needed or already accepted.

## Accessible and inaccessible prior work

Accessible evidence comprises fetched Git objects/refs, in-tree handoff and
publication maps, original source inventories and feature verification files,
plus the current cloud clone's reflogs/worktrees. Workspace attachment discovery
found the current pasted request; it did not reveal the previous private worker
output or dirty checkout. No customer exports, media masters or credentials were
read, copied or committed by this inventory.

The integrating coordinator's handoff reports the earlier development chat
`01a11333-125e-74e8-bff7-d4d81a5f70f1` as idle/interrupted, with `read_thread`
returning connection closed. The completed read-only handoff did **not** recover
unpublished PR25 repairs, private sitemap worker output or previous uncommitted
source. That remains a precise limitation. This lane did not independently reopen
that chat or treat a handoff as filesystem recovery.

Recovery avenues are the retained remote refs and commits listed here; a future
working read/handoff from the earlier chat or its original filesystem; preserved
original evidence/download references if their hosting account exposes them; and
feature recomposition from reviewed published source. None authorizes a claim
that inaccessible cloud work has been recovered. Main incorporates later repair
and source-map equivalents, while absent published children remain a concrete
backlog that can now be assigned without duplicate implementation.

## Inventory validation

The final frozen-source verifier passed: all 142 ref names and frozen source
identities, 798 reachable/654 main/144 non-main commit census, every recorded
commit tree, all 504 source object references, and all 32 recovered-path byte
counts and SHA-256 hashes agree with Git. All 102 original mapping rows retain
the historical tip, including 101 exact current-tip mappings. `git diff --cached
--check` passed. The first live-ref comparison correctly refused a concurrently
advanced `origin/main`; the corrected verifier uses the recorded frozen heads and
keeps that subsequent integration outside this inventory's claims. No application
or historical CI tests were executed for this documentation-only change.
