# Dependabot triage — October 12, 2026

The ten October 9 PRs were inspected against `c2be3d31` (the tested MFA candidate). MFA then merged as PR #98 at `eb6e3741587c43cb8efc256865b45fff70f56019`; this branch integrates that merge without changing the tested dependency graph. Their
individual branches have old bases; compatible changes are carried into bounded batches with current workflow
policy, focused local checks and exact-head cheap preflight. No Foundation or GitLab dispatch is authorized here.

| PR | Proposed change | Disposition |
| --- | --- | --- |
| #71 | stripe/stripe-php 21.3.2 → 22.0.0 | Deferred to a dedicated SDK/API compatibility change. Do not merge as-is. `BillingProviderPin` and `StripeCapabilityPreflight` require SDK 21.3.2; the reviewed API is 2026-08-26.dahlia. |
| #72 | tc-lib-pdf 8.76.3 → 8.78.2 | Deferred to a separately reviewed successor rendering profile. Current frozen test-v2 identities require the existing package versions and references. |
| #73 | Vite 8.3.0 → 8.3.3 | Merged via #99; stale bot PR closed. |
| #74 | Laravel 13.34.0 → 13.35.0 | Merged via #108; bot PR closed. |
| #75 | inertia-laravel 3.3.4 → 3.5.1 | Merged via #108 with its Laravel/prompts change; bot PR closed. |
| #76 | @types/node 24.13.3 → 26.6.4 | Reject this major for the Node >=24.15 <25 runtime. The tooling batch ignores automated types-major updates; a future runtime-major change must review that rule. Bot PR closed after #99 merged. |
| #77 | tc-lib-pdf-font 4.4.1 → 4.5.1 | Deferred with #72; it also changes the frozen renderer identity. |
| #78 | Vitest 5.0.1 → 5.0.3 | Merged via #99, preserving the exact root pin; bot PR closed. |
| #79 | @vitejs/plugin-react 6.1.1 → 6.1.2 | Merged via #99; bot PR closed. |
| #80 | @inertiajs/react 3.7.1 → 3.8.0 | Merged via #108 with the Laravel adapter; bot PR closed. |

No frozen `resources/contracts/*/profile-assets.json` member is edited or re-pinned. The PDF/font and Stripe bot
PRs remain unmerged. This triage does not authorize a new rendering profile, provider version change, live account,
host, payment or deployment. Genuine dependency audits and repository protections remain in force.

## Tooling batch

Executable dependency source: `361607b2ad2e4ca4aa3e944a857d72e1acb498a8`. Node **24.19.0**, npm **11.9.0**.
The root changes are Vite 8.3.3, React plugin 6.1.2 and exactly Vitest 5.0.3. The resolver changes 24 package records
and removes two obsolete transitive packages (`siginfo`, `stackback`), plus root metadata. No production npm
package, Composer lock or frozen contract file changes. Installed @types/node remains 24.13.3; Inertia React
remains 3.7.1 until its separate batch. Exact graph identities are retained in `tooling/graph-delta.json`.

Reviewed upstream notes: [Vite 8.3.3](https://github.com/vitejs/vite/releases/tag/v8.3.3),
[Vitest 5.0.3](https://github.com/vitest-dev/vitest/releases/tag/v5.0.3), and
[React plugin 6.1.2](https://github.com/vitejs/vite-plugin-react/releases/tag/plugin-react%406.1.2), as linked by
the three Dependabot PRs. Vite includes URL/HTML/build fixes and compatible Rolldown updates. Vitest fixes result
isolation, retry/cache/VM behavior and pins why-is-node-running 3.2.1 (a transitive major replacing 2.3.0). The React
plugin fixes compound-component refresh; its changed compiler diagnostics option is not configured in this repo.

The post-install outdated report also lists newer Vite/Vitest patches, Inertia 3.9.1, Node-24 types and other
available updates. Those are recorded for the next coherent dependency batch; this batch is the three inspected
October 9 PR targets. The earlier report ran before installation and has no current installed versions, so it is
not evidence of the installed graph. Outdated's exit 1 means available updates, not a passing all-current claim.

| Check | Actual result |
| --- | --- |
| Clean `npm ci --include=dev` | Exit 0; installed versions agree with the lock |
| Full frontend suite | **65 files / 1,130 tests pass**, zero failures, errors or skips; exit 0 |
| Production build (includes TypeScript) | Exit 0; existing >500 kB chunk warning retained |
| Production bundle scan | 6 files, 9 secret names plus Stripe key prefixes; no hits, exit 0 |
| Preview build (includes TypeScript) | Exit 0 |
| Preview bundle scan | 4 files; no hits, exit 0 |
| npm audit before and after | Zero advisories of any severity; both exit 0 |
| npm outdated before and after | Both exit 1 because updates are available; retained reports, not a failure-free freshness claim |

Exact commands and source identity: [tooling/commands.md](tooling/commands.md). Console output and JUnit are retained,
with only worktree paths, ANSI colour and trailing whitespace normalized. Independent review APPROVE was carried to exact receipt-only head `027c674194c1b8b5c5a20231a6d805523413f855`.
PR #99 merged at `0b4a2e76fd2ffc414863a1ba97b9354c9ec4cde8` after preflight `38319085298` passed. No browser, PHP, native MySQL or full
Foundation execution is claimed for this npm-only batch; the PHP graph and frozen assets are unchanged.

## Laravel/Inertia batch

The paired #74/#75/#80 targets are verified at dependency source `30c4f7ca`:
[graph, exact commands, results and limits](adapters/README.md). Frontend 1,130; SQLite 260/3,474; MySQL 23/399;
actual stopped-copy 14/14, builds/scans and audits passed. Stripe and frozen renderer identities stay unchanged.
Independent APPROVE carried to exact head `c28fb81b`; preflight `38320089400` passed. PR #108 merged at
`3ab71dd354199e86625ac6878564b9c7334fba43`; #74/#75/#80 are closed as superseded. Continue C2, beginning
with its durable first-attempt billing evidence prerequisite.
