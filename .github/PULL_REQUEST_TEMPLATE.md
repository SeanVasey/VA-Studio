<!-- Keep this concise. Replace prompts; delete inapplicable risk bullets.
Link detailed evidence instead of pasting logs. Never prefill successful results.
CLI/API agents: use this structure in the supplied PR body; GitHub does not add it to an explicit body. -->

## Why and what changes

<!-- State the concrete problem, why it matters, and the resulting customer/operator behavior.
For docs/tooling, describe the contributor benefit. Identify remaining scaffolds or fixtures. -->

## Scope

- Work package / issue: <!-- WP-xx; use "Advances #..." for partial work; "Closes #..." only when all acceptance criteria are met. -->
- Changed surfaces: <!-- Domain commands, storefront, Filament, media, workers, contracts or docs. -->
- Remaining work / next dependency:

## Verification

- Tested commit: <!-- SHA; identify any later documentation-only change. -->

| Check / environment | Actual result or reason not run | Evidence |
| --- | --- | --- |
| <!-- Command, database/version, browser/device as applicable --> | <!-- Passed / failed / skipped counts; never treat a skip as a pass --> | <!-- CI run, report or screenshot link --> |

<!-- Choose checks for the change from README.md and .github/workflows/ci.yml:
PHP/MySQL and SQLite; frontend tests and TypeScript/build; production dependency audits.
For concurrency claims, include independent-process MySQL races. SQLite alone is insufficient.
For UI changes, record mobile/desktop, keyboard/focus, playback and reduced-motion checks as relevant.
For docs-only changes, check paths, links and consistency; no application test claim is needed. -->

- Untested conditions / known limitations:
- Independent review: <!-- Required by AGENTS.md for payment, licensing, authorization, migration or irreversible data changes. Name actual reviewer/evidence, reviewed SHA and unresolved findings; otherwise N/A. -->

## Risks and recovery

<!-- Keep only affected areas; a short "No runtime/data change; revert this commit" is enough for docs-only work. -->
- Commerce / rights: <!-- Server money/tax, exact license/asset snapshots, retries, reservations, payment verification and grants affected. State whether checkout availability changes. -->
- Access / media: <!-- Server authorization, draft privacy, private masters, customer data and consent affected. -->
- Schema / operations: <!-- Forward migration, provider/config requirements, rollout and rollback/forward repair; preserve immutable evidence. -->
- Brand / UI: <!-- Approved active-theme tokens and original identity assets; link visual evidence when changed. -->

## Handoff

- Updated work package / docs / decision evidence:
- Follow-up issue or next bounded step:

<!-- A merged increment is not complete BeatStars parity or launch approval.
Keep secrets, private audio, customer exports and real contract data out of PRs. -->
