# C4b source and invocation map

Renderer final source is `483793065c3f7a66057907a777f938f608bf7036`. Its final four-file run passes **76 tests / 243 assertions**, with zero errors, failures or skips and exit 0. This is a renderer component execution; an integrated C4b suite is pending. The evidence worktree begins at root `3b23aa3d4edfc2bbbd0891d9272a9fc3c0ae51e8`, not at that component commit.

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

Nine additional comparisons verify retrospective E1, E6/E8 and E7 old/fixed implementation/test capsule hashes against their declared Git references. They do not reconstruct every historical running tree. E6/E8 red XML identifies test methods at lines 205/223, while the retained corrective test file identifies them at 198/216; therefore that corrective file does not establish the exact red-run test bytes. Original E1/E6/E8 full shell invocations were not retained; the README explicitly supplies reproduction commands instead. E1 and E7 failure locations are consistent with their named source mappings, but that consistency is not an independent historical environment capture.

The five pre-existing frozen manifests and their 38 member entries match baseline and renderer-final Git bytes. The new member manifest is separately identified by SHA-256 `caa3218c162ef3c9192785858e6cd9fad89ab828a345466652dda21e74a1c852`. Its technical descriptor hashes are owner-reported released API results, not production approvals: synthetic `1c518ec10f5dce9cf538b48de4e1034cd1fe1574fe21c9f0819d0638972e434e`, verified `d9047a26ebdc6b2bbfc44d00f3fbd93bfc1504e61948bf9666e7acadde712cbd`.

## Facts component and schema-only native results

The facts component release belongs to `bd3661ec02e3004fef3ae3d8a990e44ef5c6ce08`. It is historical review evidence: subsequent identity-config/actor callback findings require their own correction receipts. Passing authoring/facts components below are not acceptance of that superseded source. Every retained exit is an actual `.exit` file.

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

The complete SQLite schema component is 6 / 17 inside the errored seven-case canary. The whole authoring component is 8 / 34 inside the errored nine-case authoring/facts run. Their class-specific JUnit leaves are independently extracted in `test-summary.json`; both parent invocations retain exit 2 and their real errors. The initial authoring error requested a locking staff lookup in a Laravel-level-zero committed frame. Diagnostic attempts and the corrected 1 / 9 positive remain distinct. The facts teardown error exposed a WeakMap value retaining its own key; the real issuer lifetime correction produces the complete 4 / 17 facts pass. No teardown suppression is claimed.

The first document failure lacks a complete contemporaneous source inventory and remains diagnostic history. Later whole document cases validate the explicit 65,536-byte boundary; the 65,537-byte old-source case remains one real failure. Schema source inventories bind eight owned files exactly to `6b76eea8` and `3561eee8`.

The final historical facts inventory has all 11,503 tracked paths matching actual `bd3661ec` Git bytes. Earlier full canary and authoring/facts captures differ from it in 12 and four paths respectively; every difference is retained. Sixteen separately retained untracked application/test capsules match their corresponding captured hashes, and the two-file lifetime correction exactly matches the later commit. These are explicit overlays, not execution of a clean later tree.

Native old source is exact `15a65e0761382a9ad50c4b279605a644a4eeb285` with the released external existing-API probe. All 10,633 tracked hashes and external runner/test bytes are identical before/after. Fixed source is exact `bd3661ec`, with all 11,503 tracked hashes and the same external program identities stable. Old PHPUnit exit 1 is the intended pair of incorrect-admission failures; the verification runner exits successfully after confirming that outcome. Fixed native source passes 4 / 19. This establishes actual current table definitions and known required guard checks under the tested old repeatable-read dictionary snapshot. Unknown extra-trigger inventory, native facts/authoring and complete preparation remain outside this schema selection.

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

Eleven migration-backed composition cases and the final integrated candidate were not executed by this checkpoint release. Later E10/E11/observer review corrections are pending. Root source will advance during integration; component passes will be compared against the final candidate without being relabelled as reruns. CI policy/discovery, integrated native execution, independent review and exact-head preflight remain separate records.
