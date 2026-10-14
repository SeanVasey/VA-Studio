# C4b renderer and private candidate storage evidence

The renderer lane is complete at `483793065c3f7a66057907a777f938f608bf7036` in `/workspace/VA-Studio-member-original-renderer`. The final focused run passed **76 tests / 243 assertions**, with **zero skips, failures or errors**. The worktree was clean after the run and final formatting/diff checks. This is lane evidence, not approval of the composed C4b application or a production/host verification.

Baseline: `7edd614497b930c5bc02141537cc7e24dce6fb27`, containing the actual C4a merge and reviewed shared contract. PHP: **8.4.26 CLI** through `/workspace/runtime/bin/php`. `inventory.json` binds every changed source/dependency and every retained evidence artifact by SHA-256; it distinguishes lane commits from dependencies.

## Implemented scope

The member-only input retains an explicitly detached, bounded scalar/array value and exact authored text. The pure released descriptor supports facts validation without filesystem I/O. The actual isolated child uses the existing retained fonts and PDF packages with a new member implementation manifest, fixed document/page metadata, and exact profile/text/output verification. The process is constructed and controlled by the renderer; its optional no-argument observation hook cannot provide a substitute transport. Admission, current runtime bytes, the original monotonic budget and the corrected native I/O frame are checked around the physical boundaries. The child rejects an inherited application environment.

Private storage derives its path from the source, publishes with atomic no-overwrite linking, and requires independently rendered expected bytes to adopt an existing candidate. Observations bind path, physical identity, bytes, profile, storage policy and the original observation interval. Native plain-file stream checks precede and follow candidate/path observations. Workspace cleanup is bounded and owns only its invocation's directory. Expired/changed admission leaves an orphan instead of deleting unrelated files. A crash before unlinking the publication temporary leaves two links and is refused; a completed one-link publication can be adopted only after exact expected-byte comparison.

These values and files carry no grant, activation or consume authority. No origin/artifact records, active entitlements, provider effects, public routes, production configuration or commercial defaults were added in this lane.

## Final commands

Executed from `/workspace/VA-Studio-member-original-renderer` on the exact final head:

```sh
/workspace/runtime/bin/php vendor/bin/phpunit \
  tests/Unit/MemberOriginalRenderInputTest.php \
  tests/Unit/MemberOriginalTextTest.php \
  tests/Feature/ProductionMemberOriginals/MemberOriginalRendererTest.php \
  tests/Feature/ProductionMemberOriginals/MemberOriginalFilesTest.php \
  --log-junit /workspace/runtime/member-original-renderer-evidence/lane-combined-final.xml
```

Exit **0**; 76 tests / 243 assertions / zero skips, failures or errors. The file contains the exact test names and per-case results: input 15/20, text 9/20, real renderer 12/46, private files/workspace 40/157. Coverage includes independent real child determinism for single and automatic multiple pages, CRLF/bare-CR preservation, a hook-returned substitute process being ignored, initial/late environment stripping, original-budget and native-transaction refusals, two actual competing storage workers, and actual worker death before/after the publication unlink boundary.

```sh
/workspace/runtime/bin/php vendor/bin/pint --test \
  app/Domain/Grants/Member/MemberGrantRenderProfile.php \
  app/Domain/Grants/Member/MemberGrantRenderProfileRegistry.php \
  app/Domain/Grants/Member/MemberOriginalFileObservation.php \
  app/Domain/Grants/Member/MemberOriginalFiles.php \
  app/Domain/Grants/Member/MemberOriginalPdfRenderer.php \
  app/Domain/Grants/Member/MemberOriginalRenderInput.php \
  app/Domain/Grants/Member/MemberOriginalRendererProcess.php \
  app/Domain/Grants/Member/MemberOriginalText.php \
  app/Domain/Grants/Member/MemberOriginalWorkspace.php \
  scripts/member-original-renderer-autoload.php \
  scripts/render-member-original.php \
  tests/Feature/ProductionMemberOriginals/MemberOriginalFilesTest.php \
  tests/Feature/ProductionMemberOriginals/MemberOriginalRendererTest.php \
  tests/Support/MemberOriginalRendererFixtures.php \
  tests/Unit/MemberOriginalRenderInputTest.php \
  tests/Unit/MemberOriginalTextTest.php \
  > /workspace/runtime/member-original-renderer-evidence/pint-final.log 2>&1
git diff --check 7edd614497b930c5bc02141537cc7e24dce6fb27 HEAD
git status --short
```

All exit **0**. Pint reported passed; diff/status produced no output. The test runner's final tool receipt reported 5914 ms; JUnit's summed suite time is 5.904993 seconds.

## Retained development failures and corrections

These are historical results, not additional current failures. XML/log files are retained without replacement. The old-source cases used new regression tests with the specified old implementation bytes; they were not claims that the old commits already contained those tests.

| Artifact | Tests / assertions | Failures / errors / skips | Meaning |
| --- | ---: | ---: | --- |
| `reference-alias-old-source.xml` | 1 / 1 | 1 / 0 / 0 | E1: exact input source from `1d88e9f0` retained caller-held nested PHP references. |
| `reference-alias-fixed-source.xml` | 1 / 3 | 0 / 0 / 0 | Explicit bounded scalar detachment prevents caller and returned-value aliases. |
| `renderer-initial.xml` | 9 / 40 | 3 / 0 / 0 | One insertion-order assertion plus cleanup masking two intended admission errors. The asynchronous initial command's final exit receipt was not captured; the complete JUnit result was. |
| `renderer-corrected.xml` | 9 / 43 | 1 / 0 / 0 | Test still expected generic `io_frame` instead of the actual specific `io_transaction`; expectation corrected. |
| `transport-cr-old-source.xml` | 2 / 1 | 1 / 1 / 0 | E6 accepted a substitute PDF-looking transport; E8 rejected CR as a missing glyph. Exact old renderer/PDF implementation from `10b8cb25`. |
| `transport-cr-fixed-source.xml` | 12 / 46 | 0 / 0 / 0 | Whole renderer file passed after renderer-owned transport and CR formatting correction. |
| `member-original-files-e7-red-20261013.log` | 3 / 7 | 3 / 0 / 0 | E7: old file adapter accepted user-space substituted streams/stat observations. |
| `member-original-files-e7-green-20261013.log` | 3 / 15 | 0 / 0 / 0 | Same three cases reject before trusting substituted observations. |
| `member-original-files-workspace-red-20261013.log` | 2 / 3 | 2 / 0 / 0 | Old workspace cleanup trusted a substituted filesystem. |
| `member-original-files-workspace-green-20261013.log` | 2 / 10 | 0 / 0 / 0 | Same two cases preserve owned/unrelated contents and refuse substitution. |
| `member-original-files-full-final-20261013.log` | 40 / 157 | 0 / 0 / 0 | Complete final private-file/workspace suite. |
| `lane-combined-final.xml` | 76 / 243 | 0 / 0 / 0 | Exact final lane head, all four owned suites together. |

Other retained green intermediate XML files are listed with their counts in `inventory.json`; they apply only to their intermediate candidate bytes. Tests referring to the former process factory or factory timeout inspection are superseded by the final owned-process/no-argument-hook tests. The old input SHA-256 is `9da97ef10c5d62ffc78a123b18f5658d13f3cecca1cf9a3261e0fe99850d6ec0`. E7 old Git blobs are `64fcc6553922a1113449cde171da817946dcece5` (Files) and `e200dcc42d194845ed31bd39802a66582e540d4a` (Workspace).

The native I/O dependency is the corrected operation implementation from `d3aafa1b1563353fe56a1eef54c4f477a50f0d61`, integrated here as `02ae6bedf7db922b22a7853b4c645a02ff6d0cf9`. Its operation-owner E2/E3 old/fixed evidence is separate. This packet does not claim to have executed that owner's MySQL checks.

The file-lane owner supplied these original command receipts. Each old-source command exited **1**; each fixed/final command exited **0**. The six original file logs were copied byte-for-byte into this packet; their original paths are retained in the allowlist metadata.

```sh
/workspace/runtime/bin/php vendor/bin/phpunit tests/Feature/ProductionMemberOriginals/MemberOriginalFilesTest.php --filter 'user_space_file_stream|one_shot_wrapper' > /workspace/runtime/member-original-files-e7-red-20261013.log 2>&1
/workspace/runtime/bin/php vendor/bin/phpunit tests/Feature/ProductionMemberOriginals/MemberOriginalFilesTest.php --filter 'user_space_file_stream|one_shot_wrapper' > /workspace/runtime/member-original-files-e7-green-20261013.log 2>&1
/workspace/runtime/bin/php vendor/bin/phpunit tests/Feature/ProductionMemberOriginals/MemberOriginalFilesTest.php --filter workspace_cleanup_refuses_substituted > /workspace/runtime/member-original-files-workspace-red-20261013.log 2>&1
/workspace/runtime/bin/php vendor/bin/phpunit tests/Feature/ProductionMemberOriginals/MemberOriginalFilesTest.php --filter workspace_cleanup_refuses_substituted > /workspace/runtime/member-original-files-workspace-green-20261013.log 2>&1
/workspace/runtime/bin/php vendor/bin/phpunit tests/Feature/ProductionMemberOriginals/MemberOriginalFilesTest.php > /workspace/runtime/member-original-files-full-final-20261013.log 2>&1
```

The other old/fixed regression XML files retain exact selected testcase names and source bindings below. Their original full shell invocations are not retained in this packet; the following are **reproduction commands**, not invented original command receipts. Each command must be run with its stated implementation variant and the corrective regression test present:

The E6/E8 `regression_test` capsule identifies the retained corrective test file at `cd6aefe9`, not byte-for-byte contemporaneous red-run whole-file source. The red XML locates those methods at lines 205/223 and the assertion at 218; the retained corrective file locates them at 198/216 and 211. The selected test names, old implementation Git identities and observed red outcomes are preserved, but this packet cannot assert the exact historical red whole-test-file hash. The final combined green run is bound to the complete exact final source inventory.

```sh
/workspace/runtime/bin/php vendor/bin/phpunit tests/Unit/MemberOriginalRenderInputTest.php --filter test_caller_held_nested_references_cannot_change_validated_values_or_returned_projections
/workspace/runtime/bin/php vendor/bin/phpunit tests/Feature/ProductionMemberOriginals/MemberOriginalRendererTest.php --filter 'test_observation_hook_cannot_substitute_a_pdf_looking_transport_result|test_real_child_preserves_crlf_and_bare_cr_source_with_exact_text_digest'
```

## Exact new profile identity and historical preservation

The new, unpublished member candidate's `resources/contracts/production-member-v1/profile-assets.json` SHA-256 is:

`caa3218c162ef3c9192785858e6cd9fad89ab828a345466652dda21e74a1c852`

Its complete descriptor hashes, recomputed through the actual pure released API, are:

- `synthetic_rehearsal`: `1c518ec10f5dce9cf538b48de4e1034cd1fe1574fe21c9f0819d0638972e434e`
- `verified_production`: `d9047a26ebdc6b2bbfc44d00f3fbd93bfc1504e61948bf9666e7acadde712cbd`

These are technical identities, not production enablement or legal approval. Only this previously unpublished member candidate was re-pinned during implementation corrections; affected real-child checks were repeated. The six pinned members are the input, text, PDF renderer, process wrapper, child autoload and child script. The E7 file/workspace adapter correction did not change those PDF members or descriptor bytes.

`frozen-profiles-inventory.json` records all five existing profile manifests and 38 member entries matching baseline Git bytes and their declared hashes. No existing manifest, font, package, helper or other frozen member was edited or re-pinned. The runtime updater in this folder targets only the new unpublished member manifest; it is development tooling, not a production regeneration path.

## Limits and remaining integration

No native MySQL, database migrations, composed authoring/capture/readiness workflow, production host/filesystem, live provider/account/payment or hosted CI was exercised by this lane. The focused feature tests use disposable idle native SQLite handles and deliberately opened raw transactions for I/O refusal; they do not establish MySQL concurrency. Synthetic legal/profile/input fixtures remain synthetic. The two-worker test establishes local candidate publication behavior, not application grant serialization.

Database facts can change during detached rendering. Fresh source/readiness refusal and candidate-orphan behavior belong to the operation/integration lane. File observations are bounded local observations; database and filesystem effects are not atomic, and out-of-band changes after the last observation remain a host/storage boundary. A two-link interrupted publication requires separately authorized exact repair; this implementation does not introduce automatic orphan cleanup.

The facts owner is separately tightening new member authored-source admission to the renderer's existing 65536-byte bound; that later dependency does not change the pinned renderer source. The root must integrate all lanes, obtain the separate review of the actual integrated head, and run its focused composed checks/preflight before publishing or merging. Independent early review source-closed E1/E6/E8; the final E7 correction has been sent for source review. This evidence file is not an independent DECISION.
