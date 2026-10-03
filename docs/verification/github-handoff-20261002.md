# GitHub development handoff — October 2, 2026

## Authority and destination

Sean directed completion of the GitLab-to-GitHub switch. The active development repository is [SeanVasey/VA-Studio](https://github.com/SeanVasey/VA-Studio), private. The integrating branch is `codex/github-handoff-20261002`; its GitHub pull request carries live run and review results. GitLab [MR !1](https://gitlab.com/vaseydev/va-studio/-/merge_requests/1) is predecessor evidence, not a separate candidate to merge.

## Preserved source and composition

GitHub and GitLab main both resolve to `cae053efbc2019c849269605df030a36a620484e` at handoff. These exact imported tips were independently checked:

| Increment | Commit | Tree |
| --- | --- | --- |
| Shared writer/configuration foundation | `cd0cf7d3a908c5c4fb21d571088af4bdfe6082f9` | `fc61aabc0e5bc8c9903ef98f8398d91bddab272a` |
| Browser, PCNTL and measured teardown repairs | `b5c0a40700777e0fdb608697dc4ddad10b3fbdb2` | `9871757607f340f7a2d1a2a80ef21be80f83a60d` |
| Publication compare/apply | `d58250a2c28a75a9b1a2e5be74f87947f0c930f2` | `5bb7a043ab2b6fcc2b7d0390464c09cc2988563b` |
| Payment-exception operations | `82552f05a47eedea624d99694fec7365d005aef3` | `1462c4696c6ab028fe284cb3eccf9c9f74dbebc6` |
| Launch configuration and latest queue | `67a75de75d422032e7fab30a87dd41fa8dee156f` | `49e090d5c10b4a3277ec44164ef7612bdd7f8f44` |

All four follow-on heads descend from the shared foundation. A full audit additionally found the newer compatibility source `3e61cf5ed428877f89eca88da5ab02d60dec5f7b` missing from the initial transfer. Its 10 files were verified against GitLab blob IDs and reconstructed as exact tree `5b6f3962240b0280e6441b95649455559e3b273c`; GitHub preserves that identical tree and original parent under native commit `2faf027977e858ae91b1a4d9ac7e8b9269c7cfb9` on `codex/github-ci-compatibility-20261002`. Commit metadata differs; this mapping is deliberate and is not an exact-SHA transfer. All other 102 GitLab branches matched named GitHub refs exactly; neither source nor destination had tags. The recovered 512M runtime preflight and safeguards are retained in this candidate. The integration preserves their parent history and applies their exact changed blobs, with two explicit shared-file reconciliations:

- `scripts/ci/focused-tests.py`: retain publication's new selection and commerce's two operational test files. Existing operator selection remains within its 32-file bound.
- `scripts/ci/database-sqlite-skips.json`: exact union of the reviewed MySQL-only methods, 95 base methods plus two publication and one commerce methods, totaling 98. No base method is removed and no physical-write skip is added. Dataset counts remain distinct from method counts.

The browser repair boots the Laravel console kernel before standalone image generation. The teardown repair retains full setup migrations and wipes disposable test tables during teardown; its lifecycle guards remain part of the full suite. Imported feature behavior, test registration and previous component evidence are described in [publication verification](track-publication-manifest-apply.md), [payment operations](../test-payment-exception-operations.md) and [the original correction record](gitlab-acceptance-followup.md).

## GitHub portability and verification

The prior GitHub database collector was bound to `VASEYDEV/VASEYAUDIO` and that repository's workflow ID. The handoff binds repository identity to `SeanVasey/VA-Studio` (ID `1402461806`) and resolves the active Foundation CI workflow through GitHub's authenticated API, requiring its exact path and name before comparing the run's workflow ID. This preserves repository, workflow, commit/tree, run/attempt, job, artifact, census and exact skip checks. Receipt reuse remains disabled.

PHP setup explicitly includes PCNTL for the physical short-write tests. The runtime proof must reject missing native capability; widening SQLite skip policy is not an alternative. Full SQLite, four MySQL shards, both ordinary browser engines, genuine-scanner related browser, frontend, quality/audits and final aggregate remain required as configured.

Local handoff checks cover the Python selectors, collector and migration-specific safeguards only. The current shell has no PHP/Composer, and direct CLI clone lacks an authenticated credential; source reads and commits use the authorized GitHub connection. This does not prevent hosted CI. Previous component executions are historical, not executions in this session.

The source PR records exact test commands/counts, independent integration review and hosted run disposition. Do not infer acceptance from this document or a successful source transfer. Do not create status-only source commits while CI is running; update the PR evidence instead.

## Continuing work

1. Complete one fresh full GitHub acceptance run on the composed candidate and resolve actual failures.
2. Bind independent review to the final candidate SHA/tree. Merge only the expected head after required checks and review; verify main separately.
3. Continue dependency-ready work from [the queue](../development-agent-queue.md) and [40-group register](../remaining-development-tasks.csv). Six parent groups remain accepted and 34 retain criteria; this handoff changes no completion count.
4. Preserve historical source links and GitLab evidence. Production activation, DNS/cutover, real content/customer import and actual hosting are not performed by this development-host transition.

The old `contract-profile.yml` branch-specific workflow is a preserved one-off diagnostic, not a required handoff gate. Its historical repository guard is intentionally retained. No live payment or production credentials are introduced.

## First native execution and reviewed recovery batch

Inspection before the first full GitHub execution, [37086861886](https://github.com/SeanVasey/VA-Studio/actions/runs/37086861886), established that Actions had been disabled. The approved repository policy now permits account-owned actions and the four exact external action pins used by the workflows; default tokens remain read-only and fork workflows remain disabled. The run on `6c55b463` exposed a stale operator browser expectation: the unready-track guard rejects the action before opening confirmation. Both SQLite shards also exposed seven legacy migration tests whose manual rollback/restore chains omitted migration 36, leaving triggers that referenced tables already removed by those tests. The repair changes test dependency order and empty-table assertions only; runtime rollback guards and existing data/evidence assertions remain intact.

| Isolated source | Added boundary | Acceptance required |
| --- | --- | --- |
| `33c8a2802b86f0f88c263eb601e01493da5feef5` | Correct the older operator journey to assert rejected mount, absent confirmation, cleared review, retained draft and public 404 | Independent patch review, focused browser proof and current full suite |
| `7a0d11e456e185a4dc2c7a94532d9a319585893e` (includes contract `17471045`) | Source-backed content/publication journey plus truthful uncertain publish/unpublish recovery; authorization and known validation stay distinct | Independent source review, focused publication on SQLite/MySQL and current full suite |
| `da712f632810f9ea49b71a42ba65ab57997a0cfd` | Retain quarantine bytes across uncertain commits, failed rollback and nested concurrency outcomes; keep confirmed precommit cleanup | Independent source review, six regression definitions, focused media on SQLite/MySQL and current full suite |
| `f04d910928a47e6b39226c59921f7f6e9ec01a35` | Correct seven manual migration roundtrip chains and add them to the existing focused commerce selection | Independent source review, focused commerce on SQLite/MySQL and current full suite |

The recovery lanes modify sixteen distinct files relative to the first integration candidate. Composition retains their parent histories and exact lane blobs, alongside the lead's four task/assignment records. The one shared-file reconciliation in this batch is `scripts/ci/focused-tests.py`: preserve the media recovery registration and all seven commerce migration registrations, with all prior suite membership and the existing 32-file ceiling unchanged. The PR records the final composed SHA, tree, review and actual run outcomes; the source record does not claim its own later CI result. No prior receipt is reused as acceptance of the composition. No parent task, production activation or historical-data migration is completed merely by these repairs.
