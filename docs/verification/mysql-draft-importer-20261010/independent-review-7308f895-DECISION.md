# Independent final-head review: PR #96 importer H1 correction

- Review date: 2026-10-12.
- Reviewer: separate Codex final-delta review session; no implementation, test or repository edits.
- Exact reviewed candidate: `7308f895619c7c5612905675e784b2f3602bd7b1`.
- Previously approved application/test source: `a4bc2bbc5ad260084028fe671790197a2c767bd5`.
- Reviewed delta: `a4bc2bbc5ad260084028fe671790197a2c767bd5..7308f895619c7c5612905675e784b2f3602bd7b1`, including evidence commit `66b64331ff1ed559b5f77893b7c1779ae006e764`, ledger commit `f6b8ffe2939e8552ab272b9300d083bb19e9f972`, and the final historical-version wording correction.

## DECISION: APPROVE

Approve this exact candidate for the authorized development merge. The previous independent review's conditions to archive the completed evidence, correct discovery-count comments, and obtain a final committed-head delta assessment are satisfied. No open blocking finding or new actionable product/test finding was identified in this delta. This decision does not itself establish a passing exact-head PR preflight, override repository protections, or authorize production activation.

## Findings and source continuity

1. **Application and test source is unchanged.** I inspected the complete changed-path inventory and the actual documentation/workflow deltas. Only evidence/documentation, status ledgers and two CI comments changed. `git diff --exit-code a4bc2bbc5ad260084028fe671790197a2c767bd5 7308f895619c7c5612905675e784b2f3602bd7b1 -- app tests` passed; the wider application/configuration/migration/runtime-script/dependency paths also have no delta. The final candidate retains the prior review's substantive application/test assessment.
2. **Red control identity is supported by Git objects.** `git diff --exit-code 2aba5bede538aacd8493b56585f0fbd3bcd20d0d d55d023ac8fada1fd0b6aad811c421addbb0d3d9 -- app` passed. The only changed path between those commits is `tests/Feature/PersistentCatalogDraftImportTest.php`; that file is identical in the red control, approved source and final candidate. I recomputed its SHA-256 as `dd5678246c8355275fb1d58df71c6dce57a234c3f27bc3e1e2a10b4f2d5d6395`. The evidence class hashes match the packet: rejected/control `f1b1f589aa317389e9e13eff70a958d070c24047745750a55b1482dbb905d468`; approved/final `ce408e6a40f1dea59ca0f53ccaa3fadf922fe7191362942d4bb4171d13fca383`.
3. **The retained red failures exercise the intended window.** Both named datasets fail at the final `catalog_target_invalid` assertion with “An engine change racing the pin was admitted.” Inspection of the unchanged test confirms that the exact blocked `SELECT 1 ... LIMIT 0`, actual MyISAM change and timeout restoration assertions precede that failure. The red log/JUnit record two failures, not setup errors or skips.
4. **CI execution behavior is unchanged.** The GitHub comment now states 1,825 of 7,800 cases in 164 selected files; the GitLab comment states 1,825 selected cases. These agree with `native-selection-corrected.txt`: 1,825 selected cases in 164 files, out of 7,800 discovered cases in 562 files. Eight-shard discovery did not execute that selection or change the hosted 24-shard configuration.
5. **Status and limitations are accurate.** The ledger records verification with merge pending, and preserves activation boundaries. I identified stale statements that MySQL 8.4 was unavailable; commit `7308f895` explicitly dates the original 8.0 environment and distinguishes current focused 8.4.11 evidence from the unexecuted complete file/hosted matrix. That documentation finding is closed.

## Saved evidence independently inspected

I read the root `AGENTS.md`, the prior `independent-review-a4bc2bbc-DECISION.md`, current evidence/readme records, ledgers, raw logs and JUnit. I parsed individual JUnit cases and reconciled their assertions and outcomes, rather than adding nested suite totals. All paths below are under `docs/verification/mysql-draft-importer-20261010/local-evidence/h1-20261012/`.

| Saved record | Tests | Assertions | Recorded outcome |
| --- | ---: | ---: | --- |
| `mysql-races-red.*` | 2 | 18 | 2 intended failures; no errors/skips |
| `mysql-new-cases-green.*` | 4 | 32 | No failures/errors/skips |
| `mysql-prior-cases-green.*` | 4 | 63 | No failures/errors/skips |
| `mysql-schema-drift-green.*` | 3 | 23 | No failures/errors/skips |
| `reviewer-mysql-drift-green.*` | 3 | 23 | Same datasets #10–12; overlapping coverage |
| `sqlite-final.*` | 60 | 336 | No failures/errors/skips |

The distinct focused MySQL total is **11 tests / 118 assertions**, not 14 tests. The implementer's schema-drift shell exit status is explicitly unavailable; its complete log/JUnit remain and the prior independent reviewer records a separate successful repeat with exit 0. The archived reviewer probe records **12/12 checks**, which are separate from PHPUnit cases. Policy logs record **50** focused-policy and **52** sharder self-tests passing; the Pint record says passed.

The archive retains the two initial SQLite runs with 56 source-admission errors each, the workspace-fixture run with two such errors, the no-tests filter attempt, corrected two-case check, and initial failed discovery. They are not counted as passing runs. The final SQLite JUnit is a complete 60-case file with zero errors or skips. The corrected native discovery is count/selection evidence only.

During this review I executed only Git/read-only source comparisons, hashing and evidence parsing. `git diff --check` passed for the reviewed delta; the checkout was clean at inspection. I did **not** rerun PHPUnit, policy tests, Pint, the probe, discovery or any database process. Database test execution and probe execution are attributed to the archived implementer/prior-reviewer records, not to this final-delta reviewer.

## Remaining merge conditions and limits

- Merge only after the ordinary required exact-head preflight and repository protections are satisfied, using the expected candidate SHA. No additional database rerun is required by this unchanged application/test delta. Any subsequent product/test change needs independent assessment; an archival review-receipt-only commit needs its final delta confirmed.
- The pin's five-second bound applies to each metadata-lock wait, not total segment duration. Queued DDL can still stall ordinary queries; connection loss can prevent session restoration. The archive does not claim those conditions are eliminated.
- Triggers on pinned tables outside the owned-object comparison, or routines they call, may reach an unpinned table created after the final listing or a nontransactional table in another schema. A later refusal cannot undo that side effect and does not guarantee cross-schema detection. Reviewed trigger/routine dependency enforcement remains the documented owner-decision residual.
- No complete MySQL file, repository-wide SQLite suite, hosted Foundation/browser matrix, actual TLS/production-host transport, least-privilege deployment, alternate-server routing/failover, `lower_case_table_names=1/2`, production import or real-provider-data acceptance is established here. SQLite cases do not establish native concurrency. Existing production activation and cutover gates remain in force.
