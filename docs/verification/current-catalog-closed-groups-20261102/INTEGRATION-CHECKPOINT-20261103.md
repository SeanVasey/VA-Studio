# Account integration development checkpoint — November 3

These are exact-source component results. They do not establish complete account, history, F9, target-host or launch acceptance. MembershipHttpMount remains literal-false; nothing is deployed.

Application source: `a9b48dafb64dbf34e3ca0397d1b5b17d56bb5bb0`. The physical case uses that application plus Feature/helper source `02f18b391e74fd78626d22e7821192c8198e1320`; its SAFE receipt explicitly identifies both.

| Exact selection label | Tests / assertions | Seconds | SAFE outer SHA256 |
| --- | --- | --- | --- |
| `native-a9b48daf-product-typed-01` | 1 / 1081 | 237.319400 | `cd4838c483fd73994294a04fbae2a44d6159ac49dba490df24430c00d7e8ea9f` |
| `native-02f18b39-product-physical-01` | 1 / 880 | 160.549081 | `c0e9104324a308fd72403c6d74392ef64f5496b4b02514c7aefe500acd2eb8e8` |
| `native-a9b48daf-product-cost110-01` | 1 / 8 | 95.241927 | `58942032742703ba840cf0c4127d0f403b2b08661475dbfb7fa3e0ac20ea68cf` |
| `native-a9b48daf-product-memo15-01` | 1 / 8 | 176.661504 | `397d9b6cc099760ec8bd35d694bdebdab93de14b500bf5b0a87cf8c5b3b5a9ba` |
| `native-a9b48daf-product-upgrade_rows-01` | 1 / 15 | 208.879475 | `a5dfe88fd386bebe8e4ecaa943854485885008daafcc12be483f2c1b907979df` |
| `native-a9b48daf-product-overlap_resume-01` | 1 / 12 | 266.945708 | `117504288b161c0b407bdaf210a01325867639408188803ad97ac5dcf428cb36` |
| `native-a9b48daf-product-lease_release-01` | 1 / 6 | 196.287693 | `b6007e53c5315ddfa34911d2c7c559a5af98576ee58189b2a46e02d4a2a02ef6` |
| `native-a9b48daf-product-early_rr-01` | 1 / 4 | 199.393063 | `fc3aedf8091acbb5b6211b4b02f4b88ea504fe346e72b3c28de17f7d8bf5970f` |
| `native-a9b48daf-product-implicit_ddl-01` | 1 / 5 | 161.613113 | `b441bb3c54d5bb10accb642743460c7277e9fdfca9e550d11a7ee0d0e1a21fad` |
| `native-a9b48daf-product-sql_accounting-01` | 1 / 3 | 120.279582 | `9988f21b218338d856a618c9c66ac63b02dcaf30715ac370bf60a2c0565bab6c` |
| `native-a9b48daf-product-primary_attrs-01` | 1 / 7 | 128.213877 | `ecb9ee5610526b26956fb95f3b1a2d94030baec520b0310d767af2aef1d979b0` |
| `native-a9b48daf-product-failed_memo-01` | 1 / 5 | 256.053461 | `0f70961607770f526df65681c7776dd95df68d4ea2e77fb775178be4a877bd9d` |
| `native-a9b48daf-product-unframed_rr-01` | 1 / 4 | 95.625001 | `65cf82a79d3298291a27765a764189a06c4fe952a0797b21703159fb9690c6eb` |
| `native-a9b48daf-product-config_callbacks-01` | 1 / 4 | 105.349560 | `f008a1c46231d0939f80c812584e6284f4826588d6d581dd82f3c1c5a1592dd7` |
| `native-a9b48daf-product-foreign_guards-01` | 1 / 48 | 114.398579 | `dd74dd27ae3500f8059cb5f26112dce597e6f7e20458da374ca1ef17f941bb2e` |

The fixed eleven-method acceptance menu completed as eleven separate native processes: populated up/down15, interrupted overlap12, nested session release6, and eight queue cases totaling80 assertions. Every selected case had exact one-method positive JUnit, no failure/error/skip/warning, complete bounded EOF/reap/private persistence and all six source/state/profile/resource/PHP/memory cleanup checks. The fixture returned healthy after each case. Do not call this a combined full suite.

The complete inspection measured108 total SQL (90 primary+18 companion) against the unchanged110 bound. Memo reuse measured first31/hit14 against15 and strict half-ratio, with two full callbacks. Timing observations are not target-host headroom. The narrow corrected physical oracle passed1/880; its pure unit selection passed16/163. The old-helper/new-counterexample throws the expected anonymous-shape exception; it is not positive-pass or mutant-kill credit.

Original expected3/actual2, historical cost133/110, f378 physical1/104/one-failure, all timeout/UNKNOWN and original failed fixture receipts remain preserved. Source corrections do not rewrite those results.

## Actual isolated fixture readiness

Owned private Oracle MySQL8.4.11 fixture04 has its own datadir and loopback port33107. Immutable admission SHA256: `33e1b2de6bdde9060491aae4ce2757eb318c69ac96fd97b618024990b58fa894`. The separate observer qualification added only SELECT on performance_schema.threads. A later separately reviewed qualification retained those original rights and added exactly two table-level PFS SELECT privileges plus the minimum escaped exact peer-schema privileges; complete before/after grant sets stayed in RAM and matched only that delta. No global/PROCESS/grant-option privilege, credential change, production setting or peer DDL was introduced.

Readiness source manifest: `5f96c69024ddbd7ba5423f4a9e53ff8e8f3c92a71d3b52077c297a1b837f7f78`; actual SAFE receipt: `691ef29a3b350e856567edefdaf04c9825ba42479d59608cafc7aab692d95cdf`. Same-app count-only reads, identity/UUID/database, EOF/cursors/PDO destruction and all six cleanup checks passed. Effective max_allowed_packet67108864 supports the original8388665-byte oversized statement. Peer schema remained absent. These are qualifications, not product test passes.

The original public certificate failed chain/purpose/admitted-IP verification. TLS remains BLOCKED_TLS_CERT_OR_IP, with no selected CA export or native TLS acceptance. The endpoint method on this profile must retain its original UNEXECUTED_NO_PRIMARY_TLS_PROFILE classification; endpoint-only checks are not verified TLS coverage. Runtime Python3.13.5 and localhost socket bind/listen/close passed; original SMTP-consuming cases own their localhost sink lifecycles.

## Integration and CI evidence

The integration composes a9b, main `04e45a0ace13b8f58bde93581c88f2d3489a409c`, both complete A2 commits and the c962 N7 merge receipt. At integration source `1671e808b690da524faf5fe8abac658acdb8580b`, App/database changes from a9b are exactly the two already-merged N7 HTTP files; the physical Feature/helper/unit blobs equal tested02. No production environment key was added.

Source-only runtime discovery, with actual candidate classmap/bootstrap binding, proved2706 selected native cases in269 files of10166 discovered cases in762 files across eight nonempty shards. It ran zero product cases.106 untimed files use fallback weights; no coverage is dropped. Both workflow count comments carry those observed counts. The SQLite census preserves the complete311-method main prefix and first150 pinned entries before22 additions (333 total). The separate MySQL census is sorted and retains its25 original identities plus the exact SQLite-only missing-guard down-noop; the method still must execute on SQLite. The native selection retains whole files and every case, including that expected driver-specific skip. NativeExplainNoteTransport is registered as a driver-branching helper. No test/audit was weakened.

The first source inventory attempts exposed a missing bin shortcut, dependency mount aliases, a missing explicit Composer loader and omitted sparse public scripts. They were diagnosed with read-only closed observations, fixed in test tooling/source materialization, and retained as failed attempts. Borrowed dependencies were never changed. Actual candidate App/Tests/Database mapping, strict application origins, private per-case runtime paths and the original PHP/capture/deadline limits remain required. No native component was restarted for these listing fixes.

## Remaining finite development-PR gates

Integrated product execution and final source approval remain pending at this checkpoint:23 existing single native cases (11 composed consumers+12 defensive catalog/migration methods), focused unit checks, and the separate SQLite down-noop. Use the finite selector map in README.md. Preserve every original diagnostic grammar and assertion. Independent source review has found no validated P0-P2 defect so far; its final verdict must name the frozen tested SHA and actual integrated receipts. Cheap preflight, Codex code/security, Bugbot and expected-head merge remain required; no Foundation dispatch occurs here.

Four-provider real ledger interruption/restart adaptation, native races/account-PK foreign-owner progress, verified TLS, Format F/preauthenticated maintenance custody, F9 full100/recovery pools and target-host/shared-cache evidence are explicit later gates. They are not silently claimed by this development boundary. No B-complete, mount activation, deploy or launch claim follows.
