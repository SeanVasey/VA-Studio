# G1 N-1 result charset token and R-5 deadline wording — source preparation

Base b5d1467bbe00743d0d7298dc2a6d9687c9fec388, sparse branch codex/g1-token-runbook-prep-20261103. All runtime/lint/style/native/mutation checks UNRUN. Scope is one new native test in the existing MembershipSchemaProofFrameTest, one append-only SQLite skip pair and one operator sentence qualification. No App, guard, deadline, isolation, migration, dependency, role, grant, public flag or policy change. Old branches/evidence remain unchanged. Independent exact-source review and ROOT execution later; this source prep does not close native G1 N-1.

## Governing scope and observation

AGENTS local bounded-work/focused-test requirements, docs/handoff/2026-10-07/codex-deferred-20261011.md G1 entry and external REMAINING-C-QUEUE-20261103 identify narrow N-1 and R-5 only. Existing schema-guard-once-per-frame packet documents original guard/token and review; its historical results are not current evidence. Foundation CI scope documentation is not an exemption for this test/scripts change. No board/scope-register status edit or new host/policy deadline is introduced.

SchemaProofFrame::prove consults the owner's exact real PDO/frame. consultCurrent obtains its token (owner witness included), checks remembered token, and only on a miss pins real tables and calls the supplied complete proof; only successful proof with unchanged post-token is remembered. MySQL hit still calls IdentityMigrationOwnership::assertNoDependents. R-7 READ COMMITTED dependent rescans, terminal proof and ownership checks remain byte-identical.

New selection: Tests\Feature\ProductionMembershipBilling\MembershipSchemaProofFrameTest::test_native_result_charset_alone_invalidates_a_remembered_complete_proof. No provider or child process. Existing reservation fixture/owned MembershipAwardTransaction/real PDO are used; no fake PDO, private property mirror or admission bypass. Public SchemaProofFrame::prove callback runs BOTH existing full guards before incrementing its observable completion counter. First complete call1, unchanged hit1; change only SESSION character_set_results between original value and ASCII-compatible latin1 (utf8mb4 if original is latin1): complete call2 then hit2; restore original value: complete call3 then hit3. Real frame admit/transaction checks persist; finally restores original charset and rolls back, then asserts PDO/connection idle. Failure cannot be advertised as successful complete proof.

Independent query samples every OTHER token leg exactly: CONNECTION_ID, DATABASE, foreign_key_checks, sql_mode, sql_select_limit, Com_create_table and Com_drop_table. Before/changed/restored samples must match exactly; original and changed result charset reads must differ as selected. This is an observable public memo behavior test, not an assertion against a reflected token implementation. Removing ONLY character_set_results from token SELECT leaves the ASCII other fields/counters unchanged, so the first changed consult wrongly hits: callback remains1, assertion expecting2 fails. Native mutation is NOT executed here and must be done only on a separate reviewed disposable source by ROOT if needed, never this frozen branch.

Source assumption for execution: latin1 preserves canonical ASCII schema identifiers/DDL/metadata field values in the fixture, unlike the old utf32 re-encoding refusal case. Full guard source compares actual stored schema/trigger creation charset/collation names (ASCII strings), not a requirement that current result charset equal utf8mb4; SET result charset does not rewrite stored schema or client charset. Actual full proof under changed state is still required. If this real proof refuses or any other-leg invariant fails, execution is STOP/UNPROVEN; do not treat a failed proof as remembered, relax assertions, or claim token-only isolation. No proof claim rests on presumed Oracle behavior alone.

## Six actual owned-frame deadline/wait callsites at base

| Owner operation | Original budget / transaction path | Blocking/cleanup qualification |
| --- | --- | --- |
| MembershipAwards::award | deadline at39; begin71, commit87, abort96 | Original sampled60s includes callbacks/waits/commit/receipt; source comment explicitly disclaims interrupting blocking waits. |
| MembershipReservationOperations::run | deadline83; begin143, commit163, abort174 | Same original60s sampled deadline; lock acquisition/SQL and failure abort/finish remain ordinary synchronous work. |
| MemberGrantDefinitions | deadline28; begin48, commit109, abort117 | Original60s; admission/context and pre/committed checks sample the same deadline; no SQL cancellation mechanism. |
| MemberOriginalSourceCapture original reservation | min(workflow budget, now+60s)277; begin297, commit323, abort327 | Existing workflow may be shorter; SQL/catalog/row locks/rollback are not interrupted by an elapsed PHP sample. |
| MemberOriginalSourceCapture activation | min(workflow budget, now+60s)374; begin396, commit449, abort458 | Same sampled budget and synchronous transaction/terminal proofs/cleanup. |
| MemberActivationReads inspection | min(workflow budget, now+60s)127; begin148, commit169, abort173 | Same sampled deadline; committed reader and lock finish use retained budget, no new timeout. |

Each observer creates the existing MembershipAwardTransaction on TransactionBeginning, before delegates; owned frame shared metadata locks end at actual transaction completion. SchemaProofFrame::pin does ordinary SELECT LIMIT0; full guards perform ordinary metadata queries; domain current rows take synchronous native locks. Application hrtime checks are not database statement cancellation. Abort/protectFailure rollback and boundary lock finish also require actual completion. Existing original60s/domain and host/native lock-wait settings are unchanged. Runbook therefore removes only “at most its60s deadline”, states sampled deadline cannot interrupt blocking SQL or bound rollback/cleanup, and keeps quiesced migrations/short lock_wait_timeout advice. No immediate global DDL detection or dictionary-snapshot consistency promise is added.

## Census and finite later verification

Append exactly one pair to existing methods in scripts/ci/database-sqlite-skips.json, preserving all336 prior rows/order and pinned150 d93da4e7a13a8c6aff8f97f363eb6d93dde884bad53c9ae50eeb0ed32514c71c. Native test explicitly skips SQLite and is classified, not mislabeled SQLite proof. Existing MySQL include_files already selects MembershipSchemaProofFrameTest.php; no native policy expansion/strict guard change. Enumeration/count comments wait actual future integrated discovery; no counts guessed. Do not replay unrelated full classes/matrices or claim original native evidence current.

ROOT later: actual PHP syntax/Pint check of this one changed test; exact selected native discovery then one original test on private Oracle8.4.11 under existing qualified source/vendor/admission/all6/capture/EOF/reap/cleanup protocol. Preserve normal native600/655/512MiB/64KiB/cleanup30, exact one-method JUnit positive assertions/noFESW/genuine App/source origins. Native/mutation UNRUN; no PHP/Docker/SQL/fixture/vendor operation by source author. Independent source review required before merge; no push/PR/merge/activation here.

## Claude harness execution (2026-11-04)

Codex's sealed v2 execution packet and runtime did not transfer; these runs are new. Native runs use this harness's private
Oracle MySQL 8.4.11 and include the cherry-picked identity fix `a2144d4a` (lane ACC-IPV6), which this kernel needs.

| Run | Source | Result |
| --- | --- | --- |
| Original positive | `7f467682` (284abdc4 + a2144d4a) | **STOP**: 7 assertions, then `Current private catalog admission required.` at the first consult after changing `character_set_results` ([log](original-failure-native-positive-284abdc4-ipv6.log)) |
| Revised positive | `eb1cd7b7` (9a99e6b9 + a2144d4a) | OK, 1 test, 14 assertions |
| Token-only mutant (2ba71e16's one-line change) on the revised test | `eb1cd7b7` + mutant, restored after | survives: OK, 1 test, 14 assertions |

Cause of the STOP: every framed consult opens `CatalogLease`, and `CurrentCatalog::verifyLive()` re-reads the session context
(`CONTEXT` includes `@@SESSION.character_set_results`) and refuses when it differs from the captured identity. That check
arrived with the account guard (PR #190), after this test was designed, so its premise (the complete proof reruns and passes
under a changed result charset) no longer holds. The revised test asserts the behaviour that does hold: the changed charset is
never answered from the remembered proof (callback count stays 1), the frame closes, and after restoring the charset the next
consult reruns the real complete proof.

N-1 is therefore closed by the lease's context check, not by the token leg. Removing the token's result-charset leg is not
observable in a framed consult (the mutant survives), so that leg is defence in depth only. No mutation kill is claimed.
Independent review of `9a99e6b9` is still required before merge.
