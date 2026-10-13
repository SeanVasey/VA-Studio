# C3 retrieval record helpers

Base: 45d1299406821627f4ba02af186e324a70c6ade0. Helper commit a8facf90b1360f5c9b593b4bad40a5962be423b6 was tested independently before schema263 integration. Helper receipts bind the exact eight source/test files, not a claim that the incomplete writer integration ran.

Commands:

```sh
bash /workspace/runtime/billing-retrieval-records-evidence/helpers-initial.sh
bash /workspace/runtime/billing-retrieval-records-evidence/helpers-final.sh
```

Initial helper selection passed 32 tests / 54 assertions, exit0. Final helper selection after adding exact endpoint shape validation and coverage for overridden substr/unknown endpoint columns passed 34 tests / 57 assertions, zero skips, failures or errors, exit0. Exact commands, source hashes and JUnit logs retained; all eight final source files match after execution. Pint passed the eight named helper/test files. git diff --check passed.

SQLite native UTC issuance/bracketing is exercised by an isolated in-memory PDO only. Other cases are pure timestamp/record/interval verification with synthetic retained values and application encryption; no MySQL source behavior, schema migration, provider integration, append writer or host verification claim is made. No hosted CI or native MySQL workload was run.


## Operative writer integration

Schema dependency 57c9bf9fc3dc5e326248abc8baa3e1b14e4a5ece was cherry-picked as 4e4164ed. The exact application, migration, support fixture and owned test hashes for each run are retained in that run's `.sources.json`; corresponding source-check receipts confirm no source changed during execution.

Exact invocations:

```sh
bash /workspace/runtime/billing-retrieval-records-evidence/issuer-canary.sh
bash /workspace/runtime/billing-retrieval-records-evidence/issuer-full.sh
bash /workspace/runtime/billing-retrieval-records-evidence/issuer-final.sh
```

- First actual schema263/native-SQLite positive retrieval canary: 1 test / 14 assertions, zero skips/errors/failures, exit 0.
- Whole initial issuer + unchanged helper selection: 43 tests / 124 assertions, zero skips/errors/failures, exit 0. This binds the writer source before its final scoped current-read fallback.
- Final issuer file after the typed first-identity duplicate fallback was made a current MySQL locking read, plus its native-only deterministic regression: 10 tests / 67 assertions, 9 passed, 1 explicit native-only skip, zero errors/failures, exit 0. The skipped case is `test_native_duplicate_first_identity_uses_a_current_read_after_its_earlier_snapshot`; MySQL execution is pending root's serialized native slot. The unchanged 34 helper cases are carried by source comparison, not rerun after this isolated writer change.

The final feature tests cover copied/foreign issuer values, start replacement before end/append, end replacement before append/attempt, exact end replacement before first invoice identity claim, provider-read completion before database end, unchanged provider paid_at despite an ahead worker, native database freshness bounds, a newer reversal despite a behind worker, and diagnostic retention without invoice identity. Endpoint alteration is an explicit local retained-state simulation that restores the exact update guard before exercising the operation; it is not host failover or restoration evidence.

The first identity operative API is `invoiceForRetrieval(binding, invoiceRef, interval)`: original issuer, PDO/schema, and exact endpoints are locked and validated inside the outer identity transaction; insertion revalidates after encryption and before any identity write. The duplicate-identity path alone uses a current locking read inside that transaction to avoid its earlier REPEATABLE READ snapshot. Ordinary existing `invoice()` identity behavior remains available for legitimate direct fixture/identity uses.

Pre-C3 red proof is retained in `baseline/README.md`: exact 45d12994 source, 4 tests / 53 assertions, 4 intended final assertion failures, 0 errors/skips, exit 1. Both allocation reuse defects and both worker-clock defects are established using the old real APIs, rather than failing due to changed class names/signatures.

No MySQL producer test, race/host/provider condition, deployed system, or hosted CI pass is claimed by this lane yet. Independent review and integrated native checks remain required.

Final runtime producer commit: `7ed9ef471a6a4717636c919833d43296519c39e7`. All tested source hashes remain exact after commit; worktree clean. `issuer-final-commit.json` retains this correspondence.


## Authenticated legacy observation transition

Exact command: `bash /workspace/runtime/billing-retrieval-records-evidence/legacy-transition.sh`.

On integrated source bea13e9c (local merge80c7b10c) plus the single new `BillingLegacyObservationTransitionTest.php`, focused SQLite passed **2 tests / 88 assertions, 0 skips/errors/failures, exit 0**. Source hashes were unchanged during execution; script, JUnit, log and exit receipts are retained.

Each dataset applies the exact migration list through262, creates an encrypted exact v1 or v2 observation envelope and full original row seal before263, with a settled future-created tail and an earlier legacy hint, then applies upgrade263. Assertions authenticate the original legacy payload, preserve every original billing row column/ciphertext/seal, reject legacy current settlement and hint coverage despite its future freshness deadline, and run the actual current reconciliation/typed issuer to append a settled v3 observation. Provider facts including paid_at remain identical, the new native timestamp is earlier than the future legacy timestamp, current settlement/hint coverage succeed only for the v3 interval, and all original rows remain exact.

This is explicitly **synthetic legacy-format evidence**, not an old-writer, host, provider or failover provenance claim. No award or credit row is manufactured. Replay of an original award created before263 through the original captured paid-invoice authority remains untested by this lane: the current consumer requires263 and correctly refuses legacy current freshness, while this fixture's subscription approval is synthetic. Root's current first-award/proof/deadline selection covers its separate current-source behavior. No native run was performed for these two datasets yet.


## Subsequent genuine old-writer award continuity proof

The previously explicit original-award gap is now closed for the separately retained SQLite probe in `award-upgrade/README.md`. Exact old source45d1299406821627f4ba02af186e324a70c6ade0 passed1 test/25 assertions; exact new source5dc1169156287bfa22ae91339aaeccbfe13674f7 passed1 test/103 assertions. Both first executions exited0 with zero failures/errors/skips. `award-upgrade/verification.json` verifies the same persisted database file crossed the process boundary, matching configuration/application-key hashes, unchanged source hashes, exact public receipt replay, and byte-identical original period/event/identity representations.

The old phase used actual policy/subscription approval, loopback identity completion/session authentication, old v2 billing observation writer, real paid/policy producers and public MembershipAwards. The new phase applied only263, freshly authenticated the original buyer, established actual legacy producer refusal, issued a new v3 observation through BillingReconciliation, and replayed the public award without manual award inserts or reconstructed bindings. The original ahead-stamped legacy row stayed untouched. Raw private SQLite files and synthetic row captures remain runtime-only and must not be copied into a repository evidence packet. This is not native concurrency, host, live-provider or restore verification.
