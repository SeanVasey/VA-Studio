# Independent terminal receipt recovery addendum — 3543b71f

Reviewer: `/root/activation_final_evidence_review`, separate non-author reviewer. This bounded read-only review supplements `member-activation-final-evidence-review-interim-3543b71f-20261014.md`; no source/DB/test work or hosted action was performed. Only this new runtime note was written.

**The interrupted terminal run remains Partial. Its two actual recovered transcripts strengthen direct behavioral evidence, but do not establish the original runner's process exit.** The lead's fresh17:36UTC reconciliation accurately preserves `process_exit:null` and `original_runner_verifier_exit:null`, and separately records fresh metadata verifier0. It does not invent successful original runner completion.

Correction to the interim note: the default source directory lookup used `rg --files` in the exact activation worktree. That command respects Git ignore rules and omitted generated transcripts; the interim wording that they were unavailable in that directory was too strong. Both original transcripts existed, and explicit durable copies are now in the named interrupted receipt. This correction does not affect the actual missing original process/final verifier receipts.

Read both complete copied JSON documents and independently matched these bytes/hashes:

| Scheduled writer | Filename suffix | Bytes | SHA-256 |
| --- | --- | ---: | --- |
| activation | `fc6bbe9b-829a-44d7-8581-2feb23360992.json` |14626|`167f37ac4cebdfaa42b6c852b9c5a091a1565db7f574595efb3139aa890ac7a8`|
| release | `b3a3c8b0-14c8-4688-bf8c-68a71a6de86b.json` |14047|`83a70add421c0f28d0a9cb9a4b38cba926c3328c301f1ffa0a5ffc813dd81f65`|

The prefix is `parent-test_actual_consume_and_release_serialize_at_the_authored_honor_boundary-`, under `/workspace/runtime/member-activation-root-evidence/mysql-activation-consume-release-shared-coordination/`.

In activation-wins order, parent/session59 observes release/session61's exact USER LEVEL LOCK wait on activation/session60, while the latter actually holds a normal committing transaction (`transaction_level:1`, native PDO true). Activation returns its real receipt with empty errors; release returns null with actual `reservation_terminal`. Award/reserve/consume and one origin/artifact/activation are retained, terminal balances are available2/reserved0/consumed1/expired0, and the complete terminal/conservation marker follows all authenticated graph/original/readback/audit assertions. Synthetic activation wall12:00 and release wall12:05 are explicitly distinct.

In release-wins order, parent/session62 observes activation/session64's exact USER LEVEL LOCK wait on release/session63 and its real owned normal committing hold. Release returns its real released receipt with empty errors; activation returns null with actual `reservation_terminal` in the retained-history probe. Exactly one release terminal and zero origin/artifact/activation rows/original paths are verified; balances are available3/reserved0/consumed0/expired0. Both operation walls are exactly12:05, after actual confirmation at12:00. This is terminal-first refusal at equality; the independent portable no-terminal case remains the separate equality proof.

All four actual worker exits are0, stderr is empty, teardown did not stop them, and native/framework idle is reported and asserted. Parent has native/framework idle at the graph tail. The passing2/129 JUnit testcases match these completed test-body assertions. The original parent process exit and uninterrupted runner source-after observation remain unobserved despite those worker exits and testcase results.

The fresh reconciliation's digest matches the earlier before digest and captured bundle, all2320 source inputs/recorded Git, clean3543 HEAD/status, autoload and runner are reported equal. This reviewer inspected the reconciliation and bounded public artifacts; it did not execute the full fresh metadata comparison itself. A unique focused provider2 rerun recording actual parent exit and final source/loader/runner gates remains the narrow acceptance direction. No new P0/P1 source finding was established; final C4c decision, fresh regression receipts, portable evidence and exact-candidate cheap preflight remain outstanding.
