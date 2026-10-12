# Development checkpoint — 2026-10-12

Read root `AGENTS.md` first. This checkpoint follows the October 11 handoff; the original importer rejection and failed verification artifacts remain historical evidence, not current work to repeat.

## Merged batches

| PR | Exact reviewed head | Merge | Focused evidence |
| --- | --- | --- | --- |
| #96 importer H1/M1/L1/L2 and all-name shadow check | `cb69203e3c7947366ee8a75758cd2f0f927bcfed` | `92f940cc48d2ea2e02dd66503dcc99f20da51747` | Private MySQL 8.4.11 old-source races 2/18 with 2 intended failures; fixed 11/118 zero skips; whole SQLite 60/336 zero skips; preflight 38316912323 |
| #98 MFA current consumption / condition 1 | `c2be3d3122f59b03ec5927bf24da6ac49cc36975` | `eb6e3741587c43cb8efc256865b45fff70f56019` | Old-source 1/8 intended failure; MySQL 21/381 zero skips; SQLite 83/918 with 11 declared native-only skips; preflight 38318165777 |
| #99 tooling dependencies | `027c674194c1b8b5c5a20231a6d805523413f855` | `0b4a2e76fd2ffc414863a1ba97b9354c9ec4cde8` | Frontend 65 files / 1130 cases, builds/scans, clean install and npm audit; preflight 38319085298 |
| #108 Laravel/Inertia adapters | `c28fb81b0c5fcf6fb0823c51f220ea1e3fac78f6` | `3ab71dd354199e86625ac6878564b9c7334fba43` | Frontend 1130, SQLite 260/3474, MySQL 23/399, stopped-copy 14/14; changed-lock identity probe; preflight 38320089400 |
| #109 C2 durable billing-attempt prerequisite | `f7da37369e821c23650505bedb1a07028142f9c8` | `06684deb1465f0747d67a2e1751e11e9b7317c06` | Final MySQL 20/217 zero skips; SQLite coverage across recorded runs: 198 distinct cases, 193 pass / 5 declared skips, 1070 assertions; preflight 38323937687 |

Counts use tests/assertions unless otherwise labelled; skips are stated separately. Every product batch above has a separate independent approval, carried to its final head. The C2 packet retains the initial SQLite fixture errors, native CHECK normalization setup errors and an interrupted native selection; it does not relabel failed commands as passing. Exact sources, commands and limits live in each `docs/verification/` packet.

Original Dependabot #73/#78/#79 and #74/#75/#80 are closed as superseded. #76 is closed for the Node 26 types / Node 24 runtime mismatch. Stripe 22 #71 and frozen renderer/font #72/#77 remain open and unmerged. Newer dependency versions belong to a later coherent batch. Stripe 21.3.2 and frozen contract identities remain unchanged.

## Next work

The operative C2 award/reserve/consume/release/expire writer still does not exist. This session closes its A1-4 durable first-retrieval prerequisite and adds L2-4's matching-binding invoice-ID regression. Read `docs/verification/membership-c2-20261012/next-writer.md` and the existing implementation plan before editing the writer.

The integrated T23 committed APIs exist. C2 still needs transaction-bound paid-invoice and policy producers, a real provider payment timestamp for late-payment semantics, semantic invoice idempotence, canonical ledger audits, and independent-process duplicate-invoice/last-credit MySQL races. Sean has been asked for credit allowance, license costs, expiry/rollover, late-payment and reservation rules. No answer or commercial defaults are assumed. Continue independent default-off development with explicit synthetic fixtures; do not call it operative commerce before the real producers and owned commit boundary are reviewed.

PR #109 P2 [r4253341984](https://github.com/SeanVasey/VA-Studio/pull/109#discussion_r4253341984) remains open for the next membership batch: reserve retrieval endpoints across attempts and observations, in both insertion directions, with endpoint-combination and native concurrency coverage. One deferral reply was posted. The independent reviewer explicitly retained development APPROVE at the final head, with no P0/P1; current reconciliation allocates fresh intervals through mutually exclusive write paths, and attempts supply no paid authority. This addendum supersedes the earlier no-actionable-findings statement for this P2.

Carried CP-2/CP-3/P1B-1, R-4/R-5/A1-5, L2-1 and other membership activation conditions remain open. Follow the original handoff order after C2: A2, B4/B5, D1 conditions, remaining domain MFA writers, then fixture normalization. Foundation stays manual exact-SHA final verification, only when Sean requests a candidate. No GitLab dispatch was made.

## Environment and boundaries

The local toolchain activation is `/workspace/.va-studio-toolchain/activate.sh`: PHP 8.4.26, Composer 2.8.8, Node 24.19.0. Private Oracle MySQL 8.4.11 used its own container/datadir, loopback port 33396 and socket `/workspace/runtime/mysql-run/mysql.sock`; the container was stopped after local checks and its data retained. It is not a host or provider verification.

The main worktree `/workspace/VA-Studio` was preserved. Current C2 source/evidence worktree is `/workspace/VA-Studio-membership-c2`; isolated SQLite worktree is `/workspace/VA-Studio-membership-c2-sqlite`. Use each worktree's own Composer autoload files. Keep public/build absent during PHP tests. New native schema changes get one setup canary before a larger fail-fast selection, with exact sources and all failed/interrupted outputs retained.

No live credentials, real payments, production deployment, DNS change, customer entitlement import, frozen profile edit or logo recreation occurred. No final Foundation acceptance is claimed for these development merges.
