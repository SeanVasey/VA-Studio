# License economics verification

Date: 2026-09-12. Integrates from merged PR #33 / remote main `47018b18ee7ba199661604fae90982538ea5ee9d`. Local baseline `c8117ae29075915f25354f4ba3bedf3459d5d403` has the same tree `c795fe23e4ed20625efd401112a7612ea6961b3f`.

## Scope and acceptance

The primary agent owns economic domain validation/source/rendering, admin fields/mapping, the additive lifecycle guard, isolated fixtures/unit regressions and docs. An independent agent owns the feature/upgrade/history regressions in a separate worktree. The bounded assumption is that actual policy remains an explicit author/reviewer decision under U-05. No source policy or production rates are supplied.

Acceptance covers strict economic shape; separate recording/composition ownership policy declarations; exact integer rates and roles/bases; complete referenced text and server-derived hashes; one bounded policy-bundle substitution; blank new admin decisions; separate-author review; immutable offer/quote evidence; and v1–v3 compatibility.

## Commands and evidence

| Environment / command | Actual result |
| --- | --- |
| Local `npm test -- --maxWorkers=1` | 39 tests passed across 6 files. |
| Local `npm run build` | TypeScript check and production Vite build passed. |
| Local PHP / Composer | Executables unavailable. No local backend execution is claimed. |
| GitHub PHP/MySQL 8.4 and SQLite suites | Results, exact tested commit/tree and logs are recorded in the integrating PR after CI executes. |
| GitHub frontend/build, dependency audits, Chromium/WebKit operator workflows | Results are recorded in the integrating PR. The generic operator workflow does not constitute device/browser acceptance for every new economic field. |

Review the integrating PR for actual final-head CI results and the independent code-review outcome. A review is distinct from executed tests or a GitHub human approval. No unexecuted check is counted as passing here.

## Focused regressions

- Unit contract cases reject missing/unknown/stale fields, noninteger or unbounded rates, wrong subjects/bases, dangling/duplicate/orphan policies, unsupported keys and template/control/UTF-8 violations. Boundary cases cover precise percentages, six shared/distinct references, total text limits, stable output without stored-order mutation and narrowly contractual `none` modes.
- Source coverage requires every new variable and exactly one full policy bundle while retaining historical repetition behavior. Complete source values remain separate from compact card features.
- Feature tests exercise deterministic escaping/hashes, separate-review publication, policy-text changes, SQL immutability, exact schema/renderer pairs, mounted admin authorization, visible domain errors on hydrated UUID policy rows and explicit v3-to-v4 mapping without defaults.
- Frozen v1/v2 fixtures remain unchanged. A static v3 expected preview guards pre-v4 rendering bytes; the migration regression publishes v1/v2/v3 evidence before applying the new guard, then exercises v4 publication without rewriting older evidence.
- Offer/quote regressions retain policy text and economic choices from the selected immutable revision when successor licenses and offers are published.

## Remaining acceptance

No real production policy consistency, qualified legal approval, chain-of-title verification, global policy catalog, calculated ownership allocations, collaborator payouts, royalty accounting, buyer assent, executed contract/PDF, grant/delivery or provider effects are claimed. WP-05 must expose full frozen policy text in buyer detail/disclosure; WP-08 must reproduce it in contracts. Real device/editor accessibility and production infrastructure remain in their original work packages.

## Independent review correction

The initial static review of local `4ce80d942e2db01688dbd9c76bb2a3a92dc70054` (tree `b32481c452a264747a0c4bbd7dce18986f651649`) found one P2 admin issue: numeric domain policy indices did not identify the UUID-keyed mounted repeater inputs. The error adapter now maps indices back to actual row keys, with an independent regression requiring a visible row-level error and an unchanged draft. Final-candidate review and CI evidence are recorded in the integrating PR.

The first complete MySQL run on remote `52eb527e45cd5f6d90721d71682a6750cb38d15a` passed 413 tests and failed the new modal-message assertion. The actual UUID field-error assertion passed; plain Livewire `assertSee()` inspects parent HTML while Filament action modals render in a partial. The test now uses pinned Filament's `assertMountedActionModalSee()` to check actual modal HTML, retaining the field-path and unchanged-draft assertions. This is an assertion-surface correction, not a removal of visible-error coverage. The final candidate must rerun both database suites; the [integrating PR](https://github.com/VASEYDEV/VASEYAUDIO/pull/39) records those results.
