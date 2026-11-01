# Independent PCC final evidence and carry review — 2026-11-01

Reviewed candidate: `9c68984de27dc2c077e226653f63ee4f842ec33e`.
Tested executable source: `db5e4b9342f08ede28d35c49524990d7777bfbcc`.
Main baseline: `2ab0c41e946e30cc402afc8ec107a3670e399abd`.
Reviewer: separate non-author agent `/root/pcc_finish_sol61/pcc_final_carry_review`.

## Decision

**APPROVE the final evidence classification and documentation carry for a default-off development merge**, subject to the outstanding remote preflight, required Codex code/security and Bugbot reviews, resolution of any blocking findings, and exact expected-head merge. No new P0–P2 finding was identified in this evidence/carry review. The requested original sequential four-shard execution has completed; its result remains failed. This approval does not relabel that result as passing.

Under AGENTS.md's October 6 CI cost policy, a focused, independently reviewed development candidate may merge without full Foundation CI when exact source, failures and untested conditions are recorded. The completed failed sweep, supported runtime diagnosis and focused correction checks, unchanged-main test-only failure reproduction, and preserved source review satisfy the local evidence condition for that development boundary. A full green final integrated acceptance receipt remains outstanding. Production activation and release acceptance are not approved.

## Source equivalence and review attribution

I verified the candidate worktree is clean at the stated SHA. The entire `db5e4b93` → candidate diff changes four documentation files only: the PCC README, sanitized evidence JSON, imported independent source decision, and deferred-work ledger. No application, configuration, test, workflow, dependency manifest or lockfile changed. The tested executable source therefore carries unchanged to this candidate.

The original independent payment/auth decision is byte-identical to `/workspace/pcc-evidence/independent-pcc-review-db5e4b93-DECISION.md`. That decision belongs to `/root/pcc_payment_auth_review`; I do not attribute its source review or its independently executed 59/829 proxy/mount checks to myself. I read it in full and checked the unchanged literal mount/SMTP defaults and runtime holds. Its named production-mount and SMTP configuration conflicts remain explicitly accepted for default-off development. Its P3-1 and P3-2 coverage follow-ups are recorded in the deferred ledger; P3-3 is corrected to SMTPS/465 in the current packet while the historical decision remains immutable.

## Evidence independently inspected

I read AGENTS.md, repository README, the complete PCC packet (including historical failure/probe records), the sanitized resumption JSON, the independent source decision, the new deferred-ledger entries, the original runner/inspection/verifier scripts, renderer class runner and vendor materialization script. I inspected the actual logs/JUnit and used lightweight read-only Python checks, not another application suite.

- All original four-shard log/JUnit SHA-256 hashes in the public JSON match the retained artifacts. All original case identities, case counts, assertion totals and exact committed SQLite skip-census identities match the generated inventories. The repository's unmodified standard receipt verifier refuses shards 1, 2 and 4 with `JUnit records a failure or error` and accepts shard 3. Totals remain 10042 cases, 105104 assertions, 732 skips, 3 failures and 2 errors, exits 1/2/0/1.
- All published focused renderer and baseline cache log/JUnit hashes match retained files; exit and test/assertion counts match their artifacts. Correct renderer class results total 74 tests/597 assertions with no skips. The earlier empty-filter selections remain separately recorded as no-tests exit 1, and are not counted as passing checks.
- The original restricted-autoload log explicitly refuses `/workspace/VA-Studio/vendor/symfony/polyfill-mbstring/bootstrap.php` outside `/workspace/VA-Studio-pcc`. The contained probe loads successfully; its retained nonfatal agent-detector warning is visible. The materialization script refuses to run before all four original exit files exist and preserves the original vendor tree. I independently compared current package files against the retained pre-copy hash map: no package-byte differences; current vendor has zero symlinks and zero paths resolving outside the project. Regenerated Composer metadata is distinct from unchanged package bytes. No renderer isolation limit or source was weakened.
- The two original renderer errors and two ready-versus-retry failures all arise in actual isolated-renderer cases. The explicit restricted-autoload failure, contained-byte repair, four exact-case passes and four complete-class passes support the documented environment classification. These focused passes do not establish that a new full sweep would pass.
- I inspected the cache test's raw ordering followed by prefix removal and the identical reversed-key failures in both focused PCC and exact-main logs (each 1 test/3 assertions/exit 1). Both `PruneExpiredCacheRowsTest.php` and `PruneExpiredCacheRows.php` are byte-identical to the exact main baseline; the baseline worktree HEAD is `2ab0c41e946e30cc402afc8ec107a3670e399abd`. The observed retained key set is correct, but the order-sensitive test assertion fails. Deferring a precise prefix-independent test repair to DEF-OPS is reasonable for this development merge. The later held-lock denial/release assertions did not execute in these failing invocations and remain unclaimed.
- Equipped ops output records 334 tests/OK with ClamAV 1.5.4, and the equipped Composer audit records no advisories. The packet preserves initial missing-tool/account, unsupported ClamAV, CA-chain and Pint limitations rather than claiming they passed. Historical native and mutation results stay attributed to their historical source; no current native execution is inferred.

## Remaining gates and risk limits

The original full-sweep failure is permanent evidence, not superseded by focused results. The baseline cache assertion still needs its queued repair and validation; a final green integrated suite is not established here. The changed proxy trust behavior requires real topology validation before an explicit proxy/CDN configuration. P3 operator-withdrawal and composed SMTP behavioral coverage remain follow-ups, not evidence already executed.

No heavy database suite, native/MySQL test, browser test, Foundation/GitLab run, provider call, deployment, live credential installation, DNS change or customer import was performed by this reviewer. No production config-cache/route-cache host proof, concurrency or latency acceptance is supplied. Checkout stays literal-mounted false with runtime capability holds; identity SMTP stays literal disabled. A5 production-ledger and Paid252/Tax production mounts remain separate work.

This immutable record may be imported byte-for-byte in a documentation-only carry commit. Such an import preserves this reviewed executable source; the resulting exact PR head must still satisfy the required remote review and merge gates. Any executable-source change requires renewed relevant verification and review.
