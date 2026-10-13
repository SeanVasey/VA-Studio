# C4b source and invocation map

Renderer final source is `483793065c3f7a66057907a777f938f608bf7036`. Its final four-file run passes **76 tests / 243 assertions**, with zero errors, failures or skips and exit 0. This is a renderer component execution; later composed operation and integrated unit selections are recorded below as separate invocations. The evidence worktree begins at root `3b23aa3d4edfc2bbbd0891d9272a9fc3c0ae51e8`, not at that component commit.

## Renderer results

Counts overlap; they must not be summed as one suite. `unknown` means the original exit receipt was not released, even where XML records a successful run. Exact original file-owner command exits and the final whole-lane exit are explicitly recorded by the owner.

| Invocation | Tests / assertions | Errors | Failures | Skips | Exit | Source boundary |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| input-text-initial | 23 / 37 | 0 | 0 | 0 | unknown | Intermediate bytes; no final-head claim |
| input-text-complete | 23 / 37 | 0 | 0 | 0 | unknown | Intermediate bytes; no final-head claim |
| reference-alias-old-source | 1 / 1 | 0 | 1 | 0 | unknown | E1 old input at `1d88e9f0`; retrospective mapping |
| reference-alias-fixed-source | 1 / 3 | 0 | 0 | 0 | unknown | E1 correction at `2333b9da`; retrospective mapping |
| renderer-initial | 9 / 40 | 0 | 3 | 0 | unknown | Complete XML; asynchronous exit not captured |
| renderer-corrected | 9 / 43 | 0 | 1 | 0 | unknown | Specific I/O refusal expectation still wrong |
| renderer-input-interim | 33 / 86 | 0 | 0 | 0 | unknown | Superseded transport/timeout tests |
| renderer-corrected-barrier | 34 / 89 | 0 | 0 | 0 | unknown | Intermediate corrected barrier dependency |
| transport-cr-old-source | 2 / 1 | 1 | 1 | 0 | unknown | E6/E8 old classes at `10b8cb25`; red test bytes incomplete |
| transport-cr-fixed-source | 12 / 46 | 0 | 0 | 0 | unknown | Renderer correction at `cd6aefe9` |
| renderer-e6-e8-complete | 36 / 86 | 0 | 0 | 0 | unknown | Corrected renderer/input/text selection |
| files-e7-red | 3 / 7 | 0 | 3 | 0 | 1 | E7 old Files class, new tests |
| files-e7-green | 3 / 15 | 0 | 0 | 0 | 0 | E7 corrected Files class |
| files-workspace-red | 2 / 3 | 0 | 2 | 0 | 1 | E7 old Workspace class, new tests |
| files-workspace-green | 2 / 10 | 0 | 0 | 0 | 0 | E7 corrected Workspace class |
| files-full-interim | 38 / 147 | 0 | 0 | 0 | unknown | Before final two workspace cases |
| files-full-final | 40 / 157 | 0 | 0 | 0 | 0 | Final file/workspace source at `4837930` |
| lane-combined-final | 76 / 243 | 0 | 0 | 0 | 0 | Exact final renderer head `4837930` |

[Runs](evidence/runs.json) links every invocation to its XML/JSON log, exit provenance, command narrative and source boundary. [Test summary](evidence/test-summary.json) independently reconciles the recorded totals and retains concrete case names where JUnit exists. File-only green logs retain aggregate totals; the final whole-lane XML supplies named case coverage.

## Exact comparisons and historical limits

The renderer owner inventory records all 32 changed implementation, fixture, test and imported dependency entries from baseline `7edd614497b930c5bc02141537cc7e24dce6fb27`. [Source verification](evidence/source-verification.json) compares each hash with actual Git blob bytes at the final renderer commit. It is a complete baseline-diff inventory, not a contemporaneous full-tree before/after capture. The owner's clean final worktree and execution statement remain separately identified in the released README.

Original author commit labels remain unchanged throughout the packet. For public-checkout portability, [the explicit fallback map](evidence/portable-git-map.json) resolves equivalent path bytes from root history reachable at `ec90cc1a097db8925d87689d0ac62fdff0165c5a`. Canonical fingerprints cover all 73,998 requested path/blob pairs across 27 original refs. Thirty-five per-path overrides and 48 original absences preserve the original comparison results exactly, including the 14 partial historical comparisons. This is selected-path equivalence, not whole-tree equivalence or a reassignment of old runs to root source. The verifier does not require an unpublished author branch.

Nine additional comparisons verify retrospective E1, E6/E8 and E7 old/fixed implementation/test capsule hashes against their declared Git references. They do not reconstruct every historical running tree. E6/E8 red XML identifies test methods at lines 205/223, while the retained corrective test file identifies them at 198/216; therefore that corrective file does not establish the exact red-run test bytes. Original E1/E6/E8 full shell invocations were not retained; the README explicitly supplies reproduction commands instead. E1 and E7 failure locations are consistent with their named source mappings, but that consistency is not an independent historical environment capture.

The five pre-existing frozen manifests and their 38 member entries match baseline and renderer-final Git bytes. The new member manifest is separately identified by SHA-256 `caa3218c162ef3c9192785858e6cd9fad89ab828a345466652dda21e74a1c852`. Its technical descriptor hashes are owner-reported released API results, not production approvals: synthetic `1c518ec10f5dce9cf538b48de4e1034cd1fe1574fe21c9f0819d0638972e434e`, verified `d9047a26ebdc6b2bbfc44d00f3fbd93bfc1504e61948bf9666e7acadde712cbd`.

## Facts component and schema-only native results

The first facts component release belongs to `bd3661ec02e3004fef3ae3d8a990e44ef5c6ce08`. It is historical review evidence: the separately retained identity-config/actor callback correction advances source to `5d6c58da50267a636e393ce3754ef473516923aa`. Passing earlier authoring/facts components below are not acceptance of the superseded source. Every retained exit is an actual `.exit` file.

| Invocation | Driver | Tests / assertions | Errors | Failures | Skips | Exit |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| documents-records-first | none | 28 / 64 | 0 | 1 | 0 | 1 |
| documents-records-fixed | none | 28 / 64 | 0 | 0 | 0 | 0 |
| documents-records-final | none | 28 / 64 | 0 | 0 | 0 | 0 |
| document-cap-old | none | 1 / 1 | 0 | 1 | 0 | 1 |
| documents-records-cap-final | none | 30 / 66 | 0 | 0 | 0 | 0 |
| schema/pure-first | none | 21 / 44 | 0 | 0 | 0 | 0 |
| schema/pure-final | none | 21 / 44 | 0 | 0 | 0 | 0 |
| schema/pure-detach | none | 24 / 49 | 0 | 0 | 0 | 0 |
| sqlite-schema-authoring-canary | sqlite | 7 / 17 | 1 | 0 | 0 | 2 |
| authoring-diagnostic | sqlite | 1 / 0 | 1 | 0 | 0 | 2 |
| authoring-committed-diagnostic | sqlite | 1 / 0 | 1 | 0 | 0 | 2 |
| authoring-canary-fixed | sqlite | 1 / 9 | 0 | 0 | 0 | 0 |
| sqlite-authoring-facts-first | sqlite | 9 / 39 | 1 | 0 | 0 | 2 |
| sqlite-facts-fixed | sqlite | 4 / 17 | 0 | 0 | 0 | 0 |
| schema/native-old-01 | mysql | 2 / 10 | 0 | 2 | 0 | 1 |
| schema/native-fixed-01 | mysql | 4 / 19 | 0 | 0 | 0 | 0 |
| facts-review/old | sqlite | 6 / 18 | 0 | 6 | 0 | 1 |
| facts-review/fixed | sqlite | 10 / 35 | 0 | 0 | 0 | 0 |
| timestamp-compatibility/old | none | 1 / 3 | 0 | 1 | 0 | 1 |
| timestamp-compatibility/fixed | none | 51 / 95 | 0 | 0 | 0 | 0 |

The complete SQLite schema component is 6 / 17 inside the errored seven-case canary. The whole authoring component is 8 / 34 inside the errored nine-case authoring/facts run. Their class-specific JUnit leaves are independently extracted in `test-summary.json`; both parent invocations retain exit 2 and their real errors. The initial authoring error requested a locking staff lookup in a Laravel-level-zero committed frame. Diagnostic attempts and the corrected 1 / 9 positive remain distinct. The facts teardown error exposed a WeakMap value retaining its own key; the real issuer lifetime correction produces the complete 4 / 17 facts pass. No teardown suppression is claimed.

The first document failure lacks a complete contemporaneous source inventory and remains diagnostic history. Later whole document cases validate the explicit 65,536-byte boundary; the 65,537-byte old-source case remains one real failure. Schema source inventories bind eight owned files exactly to `6b76eea8` and `3561eee8`.

The final historical facts inventory has all 11,503 tracked paths matching actual `bd3661ec` Git bytes. Earlier full canary and authoring/facts captures differ from it in 12 and four paths respectively; every difference is retained. Sixteen separately retained untracked application/test capsules match their corresponding captured hashes, and the two-file lifetime correction exactly matches the later commit. These are explicit overlays, not execution of a clean later tree.

Native old source is exact `15a65e0761382a9ad50c4b279605a644a4eeb285` with the released external existing-API probe. All 10,633 tracked hashes and external runner/test bytes are identical before/after. Fixed source is exact `bd3661ec`, with all 11,503 tracked hashes and the same external program identities stable. Old PHPUnit exit 1 is the intended pair of incorrect-admission failures; the verification runner exits successfully after confirming that outcome. Fixed native source passes 4 / 19. This establishes actual current table definitions and known required guard checks under the tested old repeatable-read dictionary snapshot. Unknown extra-trigger inventory, native facts/authoring and complete preparation remain outside this schema selection.

E9 changes three mutable inputs during the actual retained-profile decrypt callback: identity configuration, a caller-referenced actor ID, and actor.exists. The six current/committed-phase cases fail on clean `bd3661ec` with 6 / 18 and no setup errors. Fixed production bytes at `5d6c58da` pass the same external six cases plus the unchanged four-case facts file, 10 / 35. All 11,504 captured final tracked hashes match the fixed commit. The executed external regression file is retained verbatim; its repository version differs by the recorded Pint/import-formatting diff, so the repository test is not substituted for the exact external executed bytes. This is SQLite-only source closure; the reviewer report is not a final DECISION.

The later pure catalog timestamp correction uses the actual `PreparationSelection` formatter and complete member definition reader. Old `5d6c58da` refuses valid six-digit UTC timestamps: 1 / 3, one intended assertion failure. Fixed `a7265e8c5365421f2110623f685cbc67d21a99b0` preserves exact input strings and passes 21 timestamp cases plus the previous 30 document/record cases, 51 / 95. All 11,505 tracked final hashes match that source. Five old dependencies and retained old/fixed document source capsules are independently checked. This is pure compatibility evidence; the initial composed canary's separate setup error is not relabelled as this expected red regression.

## Operation dependency and issuer checkpoint

| Invocation | Driver | Tests / assertions | Errors | Failures | Skips | Exit |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| budget-io-initial | sqlite/pure | 12 / 45 | 0 | 0 | 0 | 0 |
| io-review-red | sqlite | 4 / 8 | 0 | 4 | 0 | 1 |
| budget-io-reviewed | sqlite/pure | 16 / 61 | 0 | 0 | 0 | 0 |
| budget-io-final | sqlite/pure | 18 / 65 | 0 | 0 | 0 | 0 |
| native-io-final | mysql | 3 / 24 | 0 | 0 | 0 | 0 |
| source-values-initial | none | 5 / 15 | 0 | 1 | 0 | 1 |
| source-values-final | none | 5 / 20 | 0 | 0 | 0 | 0 |
| finish-boundary-initial | sqlite | 4 / 17 | 0 | 0 | 0 | 0 |
| finish-boundary-final | sqlite | 5 / 21 | 0 | 0 | 0 | 0 |
| finish-boundary-native-statements | sqlite | 5 / 21 | 0 | 0 | 0 | 0 |
| composition-issuer-initial | sqlite configured | no tests executed | — | — | — | 255 |
| composition-issuer-fixed | sqlite | 4 / 15 | 0 | 0 | 0 | 0 |
| composition-frame-issuer-final | sqlite | 10 / 45 | 0 | 0 | 0 | 0 |
| composition-frame-issuer-5bbebd77 | sqlite | 10 / 45 | 0 | 0 | 0 | 0 |

The initial issuer invocation used a fixture class as a trait and died before tests; its empty XML and original failing parser runner remain archived. It contributes no PHPUnit error or passing case. Source-value initial failure was an insertion-order expectation error; corrected tests compare canonical object bytes and preserve list order. Original I/O red tests fail four admission assertions against old `c7310cda`; exact old implementation and regression test capsules match captured hashes. Their final four owned inputs bind to `d3aafa1b`. Native I/O's five selected inputs bind to `98ca4f0a`; full 1,297-path inventory also includes 11 unrelated draft differences, which are not promoted into committed-source claims.

The first budget/I/O full map matches all 1,942 captured inputs at `c7310cda`. Committed issuer/frame source `5bbebd776ca6cce2be4fd0d427f3baa926c2676d` matches all 2,003 captured inputs. Twelve other full checkpoint comparisons remain partial because of draft composition files or intervening implementation/test changes. The five narrow owner-selected binding receipts match every named file at their exact dependency commits. All retained operation runner bytes independently match captured hashes, and completed invocations retain owner unchanged-source sidecars; those are not described as independent full before/after maps.

Eleven migration-backed composition cases were unexecuted at that checkpoint. The later frozen release below supplies actual migration-backed execution after E10/E11 and observer corrections. Checkpoint pending language remains historical; no earlier invocation is relabelled as a later rerun.

## Root exact unit selection

Root source `4962943d366b54c9e3b85e16ef1f747bf6eb09c0` passes nine changed unit files: **107 tests / 220 assertions**, zero errors/failures/skips, exit 0. All 1,396 captured source hashes independently match that reachable root commit. The retained runner hash matches, the source receipt records clean status and an all-true Git comparison, and the result reports no changed sources. This is exact integrated unit execution, not a composed preparation or native result. Later CI selection changes are not reruns of these unit cases. Native execution and final source comparison are recorded below. Policy/discovery completion is recorded separately below.

## Complete SQLite composition

The frozen [operation command narrative](evidence/operation/commands.md) and source/result/exit/XML receipts retain all eight later invocations. They use SQLite; the portable clock selection excludes its native-only sibling without counting an unselected case as a skip.

| Invocation | Tests / assertions | Errors | Failures | Skips | Exit | Source boundary |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| composition-canary-initial | 1 / 16 | 1 | 0 | 0 | 2 | Exact `9d8abd45`; timestamp setup error before operation, not expected red |
| reservation-wrappers-initial | 17 / 345 | 0 | 0 | 0 | 0 | Exact `9d8abd45`; all 2,006 inputs match |
| composition-canary-timestamp-fixed | 1 / 48 | 0 | 0 | 0 | 0 | Exact `43e58632`; all 2,034 inputs match |
| retained-deadline-old-bridge-red | 2 / 43 | 0 | 2 | 0 | 1 | Supporting `a082d3b3` plus exact old `5bbebd77` Inspection preload |
| composition-fifteen-initial | 9 / 269 | 0 | 1 | 0 | 1 | Exact `a082d3b3`; fail-fast, inert Carbon test hook |
| composition-facts-callback-corrected | 1 / 25 | 0 | 0 | 0 | 0 | One test-file precommit overlay over `a082d3b3`; all bytes match `2cfd9a0d` |
| composition-fifteen-final | 15 / 432 | 0 | 0 | 0 | 0 | Exact `2cfd9a0d`; all 2,034 inputs match |
| reservation-clock-wrapper-final | 1 / 18 | 0 | 0 | 0 | 0 | Same exact `2cfd9a0d`; all 2,034 inputs match |

Final author source is `2cfd9a0db46a806c0a0bd0c83839b5b584fc3627`. The complete selection comprises real candidate/retry integration 4 / 148, composed boundaries 9 / 228 and retained deadlines 2 / 56. Recorded wall durations are 314.484 seconds for that selection and 13.044 seconds for the separate clock case. Runner hashes and unchanged-source result sidecars independently reconcile; unlisted runtime dependencies are not supplied by the Git comparison.

[Operation relations](evidence/operation-relations.json) independently compares the earlier 17 wrapper cases with final captured bytes: 2,003 of 2,006 captured inputs remain identical. The timestamp validator and two operation test files differ; every changed path/hash remains visible. All seven named reservation implementation/wrapper/fixture files remain identical. This supports a bounded source carry, not a 17-case rerun on final source or one combined 33-case run.

The retained-deadline red executes only the exact old Inspection class, SHA-256 `9e3190d4f4c93e23d2995d4ad58ea4ecfaaffb8c5a2acd991fe9dda1a64ea89f`, over the current supporting tree. Its two test inputs are byte-identical to the final fixed tests. The same cases move from 2 / 43 with two failures to 2 / 56 passing within the whole 15-case run. These narrowed private-cap fixtures establish original honor/invoice-window preservation; they do not claim measured physical elapsed time or native MySQL behavior. The archived overlay plan's “prepared, not executed” label is its historical preparation status; actual source/result/exit receipts establish the subsequent execution.

The first 15-case attempt stopped after nine cases because a proposed Carbon callback was never invoked, so its facts mutation never happened. The correction uses the real retained-record decrypt callback, asserts refusal and rollback, and changes only the test. The initial eight passing leaves remain inside the failed invocation rather than becoming a separate green run.

## Root policy, style and discovery

At exact root `78ef16be7626c81de49d103d581551b707201eda`, four policy self-test suites pass 52, 61, 32 and 14 tests respectively, totaling 159. Pint passes all 78 operative changed PHP paths. The 32 historical archived PHP capsules are deliberately excluded from formatting, preserving their original bytes. All six commands exit 0 and all 2,254 captured source hashes match Git and before/after receipts. [Quality verification](evidence/quality-verification.json) independently reconciles those results and retained runner identities.

Native selection discovery lists 2,143 selected cases in 210 files from 8,475 discovered cases in 625 files, across 24 nonempty shards. The 47 untimed-file warning remains visible; fallback weights are not measured durations. Discovery runs no application/database bodies, and selection-policy skips are not runtime skips. Pure SQLite schema cases remain unselected on MySQL while their real native counterparts are selected. The 50 exact generated listings/configurations/manifest are archived and hash-verified; the owner records exact cleanup with no unrelated deletion. [Quality commands](evidence/quality/commands.md) supplies the original full argv and environment context.

## Initial root native composition failure — retained history

The first actual root native invocation at `f4732dbe21b9e40eb386b6115be1283ff563f511` executes **1 test / 16 assertions**, with **1 error, 0 failures, 0 skips and exit 2**. The logged error is `MemberGrantException: Member original unavailable` at `MemberOriginalSourceCapture.php:208`, during the real authored-source candidate/retry case. The original release did not identify the inner cause; the subsequent diagnostic below identifies exhaustion of the original frame. This is not an intended regression red or a passing native check. Wall duration is 272.394 seconds (JUnit 272.099544).

The exact command selects the candidate/retry case and source-withdrawal/materialization case with `--stop-on-defect`. The second selected case never executes and is neither a pass nor a skip. All 1,388 captured inputs independently match the clean root Git source, the runner hash matches, and the owner result reports no changed inputs. The [initial native release](evidence/root/native-initial-f4732dbe/public-release-native-initial.json) preserves the actual log/XML/exit/source/result plus exact test and setup runners.

The setup receipt identifies private MySQL 8.4.11 and synthetic schema `vaseyaudio_member_originals_root`. It records local server identity and successful schema creation, not successful composed behavior. No private configuration, database contents or generated PDF is included. The corrected native positive and separate withdrawal later pass; this initial error remains intact as historical evidence.

## Native frame diagnosis and narrow quality follow-up

The [native diagnostic commands](evidence/operation/native-preparation-diagnostic/commands.md) preserve two more actual failures on supporting author `2cfd9a0d` with four explicitly retained timing-only PHP overlays. The first attempt errors before source capture because its owned TMPDIR is absent: 1 / 0, one error, exit 2, 134.486 seconds. The directory creation and mode receipt is retained; application and diagnostic bytes remain unchanged for the second invocation.

The repeated diagnostic executes 1 / 16 with one error, zero failures/skips and exit 2, 243.299 seconds. Commit completes at 59.141574 seconds with 858.425 milliseconds remaining; committed facts finish at 59.734641 seconds; the committed BillingEvidence frame assertion refuses at 60.366539 seconds. The inner reason is `IdentityException::committed_frame_required`. These observations establish exhaustion of the original 60-second cap in that invocation. The diagnostic overlays preserve original deadline expressions and ordering; they provide phase/exception metadata, not accepted production source or a performance guarantee.

Both 1,324-input source inventories bind to actual supporting Git bytes. All four preloaded files match captured hashes and unchanged-overlay sidecars. An additional 985-path map independently binds original application, selected fixture/test and PDF-child bytes to failed root `f4732dbe`. The overlays remain disclosed separately; neither invocation is described as an uninstrumented rerun.

The narrow correction advances root source to `32f083d28db0609a52cade793849a8767a855ae3`. Its separate [follow-up quality release](evidence/quality/native-budget-followup/commands.md) passes Pint on four selected PHP files and native discovery, both exit 0. Discovery now lists 8,478 source cases / 625 files and 2,145 selected cases / 210 files in 24 nonempty shards, preserving 47 untimed-file warnings. Two new Composition cases enter native selection; one new IssuerOwnership case remains in the source census but is not selected natively. No application or database test body ran in this quality selection.

All 2,360 source hashes and seven local runner/autoload hashes remain unchanged. The prior 159 policy self-tests were not rerun: 31 script/policy hashes remain identical, and 74 of the prior 78 Pint paths are unchanged. Four PHP changes and two earlier workflow census-comment changes explain the prior inventory delta; non-comment workflow lines are identical. The initial receipt-sealing assertion omitted those two comment files and failed. Its exact script and error are retained; Pint/discovery passed on their original invocations and were not rerun. All 50 exact generated files are archived and the owner records exact cleanup. The subsequent behavioral correction release is recorded separately below.

## Corrected SQLite and actual native positive

The [separate correction release](evidence/operation/native-preparation-correction/commands.md) binds exact author source `9399dd998cc3d972b94f6753e576e2c756438657` to integrated root `32f083d28db0609a52cade793849a8767a855ae3` for all captured application/test/resource inputs. The private initialization correction removes repeated constructor proofs only from the issuer-registered member capture. Public inspect and writer paths retain their full proof; no deadline is extended or renewed. Implementation review remains the independent reviewer's responsibility.

| Invocation | Driver | Tests / assertions | Errors | Failures | Skips | Exit | Wall seconds |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| sqlite-owned-composition | SQLite | 22 / 509 | 0 | 0 | 0 | 0 | 311.722 |
| sqlite-public-reservation-proof | SQLite | 1 / 20 | 0 | 0 | 0 | 0 | 16.148 |
| native-positive-final | MySQL 8.4.11 | 1 / 48 | 0 | 0 | 0 | 0 | 421.294 |

The 22-case selection includes the two new real-decrypt withdrawal cases and the caller-shaped pending-capture refusal, alongside the original composed integration and retained deadline cases. The separate existing ordinary-public reservation proof case directly exercises the preserved public path. It does not turn the earlier 17-case wrapper run into a new whole-file result.

The actual native positive/exact-repeat case runs without diagnostic class overlays, with original clocks and caps. Its same test and fixture bytes were used in the earlier instrumented refusal. The complete 1,324 native input maps differ only in the two corrected application files. Each SQLite run captures 2,034 inputs. Exact original commands, runner hashes, unchanged-source sidecars, private MySQL setup and final four-file Pint/diff receipts are retained. Total invocation duration includes fixture setup and repeated preparations; it is not an individual frame timing measurement, performance guarantee or exclusive CPU claim.

The initial precommit correction Pint failure concerned import ordering and is owner-reported from its tool transcript; no standalone initial log was retained. Final Pint and diff logs are archived. The parent's separate native source-withdrawal case now passes at ec90cc1a, as recorded below.

## Frozen integrated source and root native withdrawal

Frozen application/test source is `ec90cc1a097db8925d87689d0ac62fdff0165c5a`. The root's actual native source-withdrawal case passes **1 / 30**, zero errors, failures or skips, exit 0, wall 286.271 seconds. All 1,388 captured inputs match that exact Git source and remain unchanged according to the result sidecar. Its [release](evidence/root/native-withdrawal-ec90cc1a/public-release-native-withdrawal.json) contains the exact command, runner, source, result, XML, log, exit and reused private setup receipt. This is a distinct MySQL invocation from the author positive/exact-repeat case.

[Final source comparison](evidence/final-source-verification.json) compares 18 named selections with the frozen target, preserving every difference from older captured source. The three corrected author selections and the root native withdrawal match all their selected captured inputs. The complete named runtime/test/dependency trees at integrated correction32f083d2 and targetec90cc1a are identical. The later workflow census comments remain explicit metadata differences in quality comparison; they are not a new validation execution.

Older broad inventories can contain application and test inputs that changed later. Their differences remain visible even when the tested component's narrower owned files match. Earlier wrapper17 / 345 and clock1 / 18 results retain their original sources after the private constructor correction; only the one ordinary public proof case was rerun. Captured-path equality does not supply unlisted runtime dependencies, reconstruct historical environments or create an additional suite run. Independent DECISION, final preflight, full Foundation verification and production/host/provider acceptance are outside this packet author's completed evidence assembly.


The final comparison matrix contains seven exact captured selections and eleven with differences. Renderer-final differs on the later document value reader and document test. The root pure inventory differs on two subsequently corrected application inputs and two CI selection policies; its nine executed unit-file bytes remain recorded at4962943d. Historical wrapper and clock captures differ on six and four inputs respectively. Initial quality differs on four PHP paths and two workflow census comments; follow-up quality differs only on those two later comment updates. The machine map lists every path and both hashes rather than silently dropping those deltas.
