# R4 member policy admission regression — red/green proof

The **same unchanged probe** fails on clean exact old source **`0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`** and passes on clean exact fixed source **`a4e37cdc222ccea13ae65fb983c6d5fd89a25e29`**. The old policy admitted the mixed-provenance SQLite configuration and invoked all three authority factories. The fixed policy refused with **`provenance_driver` before any factory callback**. No database connection, application bootstrap, provider call, migration, or CI dispatch occurred.

| Source | Tests / assertions | Failures / errors / skips | Exit | Observed decision | Factory calls | XML duration |
| --- | --- | --- | --- | --- | --- | --- |
| `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371` | 1 / 10 | 1 intended / 0 / 0 | 1 | `admitted` | 3 | 0.006061 s |
| `a4e37cdc222ccea13ae65fb983c6d5fd89a25e29` | 1 / 10 | 0 / 0 / 0 | 0 | `provenance_driver` | 0 | 0.005285 s |

Each source was executed exactly once; there were no initial setup errors or discarded retries. PHP **8.4.26** in both runs. Runtime reporters rounded durations to **7 ms** (baseline) and **6 ms** (fixed). All **10,632 baseline** and **10,633 fixed** tracked file hashes, plus external probe/runner hashes, were unchanged within their respective executions. Both worktrees stayed clean. The probe and runner hashes also match across the two runs. Full output, JUnit XML, real exit status, observed decision/factory trace, exact policy SHA-256, and source receipts are retained separately under `fail-0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371/` and `pass-a4e37cdc222ccea13ae65fb983c6d5fd89a25e29/`. `comparison.json` binds and summarizes the red/green pair.

The probe calls the existing `App\Domain\Grants\Member\MemberGrantPolicy::current()` API with an isolated real `Illuminate\Container\Container` and `Illuminate\Config\Repository`. It configures verified-provenance 258, rehearsal-provenance 257, and SQLite in the raw database configuration. The container has no database manager. Three typed, nonoperative authority doubles are exposed by counting factory closures; all their authority methods are forbidden.

The desired assertion is `provenance_driver` with **zero factory callbacks**. The old implementation actually admitted the configuration and invoked all three factory callbacks. The probe records that result before the assertion, and the runner verified precisely that admission/failure pattern. A missing method, exception of another type, or setup error cannot count as the intended red result. The exact same probe and existing `current()` API produced the fixed-source pass.

Executed old-source path: `/workspace/VA-Studio-billing-retrieval`, at exact `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371` before and after the authorized run. That path belongs to the root lane and may move. Further execution requires coordination; the runner refuses a dirty checkout or a different HEAD before invoking PHP. No new worktree was created or existing checkout moved.

Exact executed baseline command, from `/workspace/VA-Studio-billing-retrieval`:

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_HOST=127.0.0.1 DB_PORT=1 bash /workspace/runtime/member-policy-admission-evidence/baseline/run.sh /workspace/VA-Studio-billing-retrieval 0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371 fail
```

Exact executed fixed-source command, from `/workspace/VA-Studio-member-policy-admission`:

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_HOST=127.0.0.1 DB_PORT=1 bash /workspace/runtime/member-policy-admission-evidence/baseline/run.sh /workspace/VA-Studio-member-policy-admission a4e37cdc222ccea13ae65fb983c6d5fd89a25e29 pass
```

The runner uses `--no-configuration --bootstrap <selected-source>/vendor/autoload.php`; the probe extends PHPUnit's `Framework\TestCase`, not Laravel's test case. It needs private Composer metadata that resolves application classes to the selected checkout. It records reflection-verified policy path, exact policy SHA-256, all tracked source hashes before/after, external probe/runner hashes, command log, JUnit XML, exit code, observed decision/factory trace, and a result verifier. It preserves separate `fail-<sha>` and `pass-<sha>` directories and refuses to overwrite prior evidence. Its final exit remains the real PHPUnit exit: expected baseline **1**, expected fixed **0**.

This is a **synthetic configuration-only proof**. The provenance strings and authority doubles provide no verified provider, host, membership, license, artifact, entitlement, or grant evidence. It exercises policy admission only and opens no database.
