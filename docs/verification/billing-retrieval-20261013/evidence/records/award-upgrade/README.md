# Original old-writer award replay after retrieval identity migration

**Pass:** an award created through the actual old public writer was replayed by the actual new public writer after migration 263 and a fresh reconciliation. The public receipt and all original period/event values, ciphertexts, and seals are byte-identical. The original identity records and original billing columns are also unchanged.

| Phase | Exact source | PHPUnit result | XML duration |
| --- | --- | --- | --- |
| Old writer | `45d1299406821627f4ba02af186e324a70c6ade0` | 1 test, 25 assertions, 0 failures/errors/skips, exit 0 | 8.578075 s |
| New writer | `5dc1169156287bfa22ae91339aaeccbfe13674f7` | 1 test, 103 assertions, 0 failures/errors/skips, exit 0 | 4.768387 s |

Both phases passed on their first execution. There were no discarded earlier failures or reruns. Total: **2 tests, 128 assertions**. Runtime: **PHP 8.4.26**, **SQLite 3.46.1**. These focused local tests do not establish MySQL concurrency, real provider behavior, production host verification, backup/restore behavior, or release readiness.

Exact commands, in order:

```sh
bash /workspace/runtime/billing-retrieval-records-evidence/award-upgrade/run-phase.sh old
bash /workspace/runtime/billing-retrieval-records-evidence/award-upgrade/run-phase.sh new
```

`run-phase.sh` retains the complete command/environment setup. It activates `/workspace/.va-studio-toolchain/activate.sh`, selects the corresponding exact-source worktree, sets `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=/workspace/runtime/billing-retrieval-records-evidence/award-upgrade/award.sqlite`, an empty `DB_URL`, and the corresponding `APP_BASE_PATH`. The test command is:

```sh
php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --colors=never --log-junit <phase>-phpunit.xml <phase-specific-external-test.php>
```

The old phase uses `/workspace/VA-Studio-billing-c3-baseline` and `OldWriterAwardTest.php`; the new phase uses `/workspace/VA-Studio-billing-award-upgrade` and `NewWriterAwardReplayTest.php`. Absolute output and test paths are retained in the script. Both worktrees have private Composer metadata and shared installed dependency directories. Reflection output in `old-runtime.txt` and `new-runtime.txt` verifies the loaded `BillingLedger`, `MembershipAwards`, fixture, and `ProductionCustomerSessions` paths. No dependency installation, product edit, tracked test edit, provider network call, MySQL workload, or CI dispatch occurred.

The dedicated SQLite database and row receipts are retained privately under this runtime directory. `old-database-after.sha256` equals `new-database-before.sha256`, proving that the new phase began with the exact persisted file left by the old phase. `old-after.sqlite` and `new-after.sqlite` retain the two closed-process snapshots. The tests deliberately do not use `FinalizationDatabaseMigrations` teardown, so the original award survives the process boundary.

The old phase alone invokes `migrate:fresh --force`. It then uses the existing `MembershipAwardFixtures` real workflow:

1. MFA-backed staff policy creation and `BillingSubscriptionApprovals` approval.
2. Actual loopback SMTP DATA delivery, mailbox proof completion through `CompleteIdentity`, and customer authentication through `ProductionCustomerSessions`.
3. The original `BillingLedger` and `BillingSettlement` write a genuine v2 observation from the in-process synthetic graph.
4. `MembershipAwards::award()` invokes the real paid-invoice and policy producers and returns the first public receipt.

The fixture registers the real `SealedMembershipPaidInvoice` and `SealedMembershipPolicyFacts` sources. The standard fixture's eligible-license and grant capabilities are deliberately unusable mocks, with their methods forbidden; neither is used by this first-award operation. This evidence establishes the real paid producer and public first-award/replay path, not redemption or grant readiness. No fabricated principal, copied authority token, reconstructed invoice binding, or manually inserted award was used.

The new phase restores settings with `awardSetup()` and the retained approved plan policy hash. Application key, actual encrypter key, and membership/billing/identity configurations match by hashes only; no key or plaintext credential is written to evidence receipts or printed. The original buyer is freshly authenticated through `ProductionCustomerSessions::authenticate()` using the existing synthetic fixture credential.

Only migration `2026_10_13_263000_membership_billing_retrieval_identity` is applied in the new phase. It adds NULL retrieval evidence to old rows while preserving every original column. The retained v2 observation remains readable history, but `currentSettled()` returns NULL and the real `SealedMembershipPaidInvoice::lock()` refuses with **`legacy_or_changed_evidence`**.

The new phase then performs actual `BillingReconciliation::retrieve()` with the unchanged existing `RehearsalBillingGateway` graph. It appends **settled sequence 2, envelope v3**, with database-issued `retrieved_at` **2026-10-13 06:01:36 UTC**, inside the recorded database bracket **06:01:35–06:01:36 UTC**. The provider fact hash is unchanged, including provider `paid_at` **2026-10-07 00:00:00 UTC**. The old fixture's worker timestamp was **2026-10-13 12:00:00 UTC**, so the successful append also exercises the explicit legacy-to-database-time transition from an ahead-stamped old row.

`MembershipAwards::award()` then returns the exact original receipt, with exactly one period and one award event still present. Equality compares canonical UTF-8 row representations including every retained value, ciphertext and seal; it does not claim that the entire upgraded SQLite file is byte-identical.

| Preserved artifact | Bytes | SHA-256, equal before/after |
| --- | ---: | --- |
| Public award receipt | 272 | `041333699c841125696be159acab5c6ca4f6f2dd45d5e68d5bfb4972cd68d075` |
| Full period rows | 3325 | `ce0f9ac00275bf479ebaf0b82f316fd41b2c3cf9b2b95c948f248e9c99b14ab4` |
| Full credit event rows | 850 | `390e621df3f46de73017211a2fcc8d6661300b0af97e3133e3702b0be94ec8fc` |
| Identity tables plus synthetic buyer/staff/account rows | 5826 | `1e9a2ebf67d703f133caa6ed6e467d28811e290daf1ccc567e557aa0b8271fdc` |

All **10,288 old-source** and **10,631 new-source** tracked file hashes stayed unchanged during their respective executions. Both Git worktrees remained clean. External probe/helper/command hashes were also unchanged within each run. `old-source-before.json`, `old-source-after.json`, `new-source-before.json`, and `new-source-after.json` retain those bindings. `verification.json` summarizes independently rechecked XML counts, database continuity, and byte equality; `artifacts.json` hashes the complete retained evidence set. Raw synthetic row/database artifacts stay in this private runtime directory.
