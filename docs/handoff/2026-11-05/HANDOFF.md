# Claude harness checkpoint — 2026-11-05

Entry point for the next session (Claude's usage resets Saturday). It supersedes the Codex checkpoint of 2026-11-04 for
current state. That checkpoint's archive, bundle and recorded results remain history. `AGENTS.md` governs. Sean's instructions
override older documents.

## Where things stand

- **Main:** `f766e354` (PR #191 merged 2026-11-05). B is done. Publication order continues **C → D → E → F**.
- **PR #191's security gate.** The Codex security review failed on the payer's usage limit. Sean approved merging on an
  independent Claude security review of the exact head `a58fa2db`: APPROVE WITH CONDITIONS, no P0–P2 findings
  ([record](../../verification/member-mount-gates-20261101/PR191-SECURITY-a58fa2db-INDEPENDENT-20261104.md);
  [PR comment](https://github.com/SeanVasey/VA-Studio/pull/191#issuecomment-6646460004)). Its P3s are mount gates and are in
  the deferred ledger. **Expect the same Codex quota failure on every new PR's security review** until the payer's limit resets.
- **Nothing is live.** Mounts stay false, and no keys, payments, deploys or customer data are involved.

## Lane branches (all pushed; none has a PR yet)

Each branch's packet records its exact commands, results, review record and deferred items. In the "Native" column, "with ACC-IPV6"
means the run used the branch head plus the cherry-picked fix `a2144d4a`, which this harness's kernel needs (see Environment).

| Lane | Branch @ head | Review | SQLite | Native MySQL 8.4.11 |
| --- | --- | --- | --- | --- |
| ACC-IPV6 (fix on main's account guard) | `claude/catalog-ipv6-absent-20261104` @ `8236f570` | APPROVE; P3-2/3 fixed | unit 75/1653 | `CurrentCatalogAdmissionTest` 7/42 on a no-IPv6 host |
| REP indexes | `claude/rep-resume-20261104` @ `ec8a0578` | repair review: conditions fixed | 8/408; affected 56/1222; post-fix 2/113 | 8/293; post-fix 2/113 |
| G1 N-1 | `claude/g1-token-20261104` @ `7ddf2657` | conditions fixed | — | original STOP kept; revised 1/15; token mutant survives (no kill claimed) |
| CX recovery cursor | `claude/cx-recovery-review-20261104` @ `e37caa35` | REQUEST CHANGES, then re-review conditions fixed | 46/499 | 11/162 (real concurrent workers) |
| CAT inventory | `claude/cat-inventory-20261104` @ `2fc74539` | APPROVE; F1/F3/F5 fixed | 11/59 | 11/59 |
| DEF-ID metadata | `claude/def-id-20261104` @ `9278d79c` | conditions met | 53/169 (40/53 fail on unmodified main); affected 98/665 | all 76 selected |
| UXA public a11y | `claude/uxa-20261104` @ `614b9d5d` | — | npm audit 0, typecheck, vitest 1177, build | browser spec blocked here (no ClamAV signatures) |

The Codex prep branches these build on are also on origin under their original names (pushed 2026-11-05):
`codex/mig-final-integration-20261103` (`6f441ac7`), `codex/a5-final-integration-20261103` (`bfbe6a28`),
`codex/defops-final-integration-20261103` (`b5d1467b`), `codex/c4e-integration-census-prep-20261103` (`6d96042f`),
`codex/c4e-test-gaps-prep-20261103` (`abbd8a9b`), `codex/t36-cache-backoff-prep-20261103` (`e0a07604`),
`codex/pf1-a-technical-revision-prep-20261103` (`f4f03a84`), `codex/account-ledger-ci-portability-20261103` (`ebc472d3`) and
`codex/cx-mail-followup-prep-20261103` (`d8984c27`). Codex's mutation-experiment branches were deliberately not pushed; they
remain only in its bundle.

## Next work, in order (smallest first; no rework is known to be needed)

1. **Publish C**: MIG → A5 → DEF-OPS, each merged with the new main (all three trial-merged cleanly onto `a58fa2db`). Then the
   C4 census and the deferred batches: REP, G1, CX, ACC-IPV6. At integration:
   - regenerate the native selection and both count comments (CX on the C4 census proved 2777 of 10237 in 275 files);
   - union the SQLite census main-first;
   - mark FUB-1 F3 addressed (CX);
   - add the lane packets' deferred items to the ledger.

   ACC-IPV6 can also go first as its own small PR: it changes no behaviour where IPv6 exists.
2. **Publish D**: CAT, DEF-ID, UXA, PF1, then FND final census normalization.
3. **E** needs a staging host (protected-configuration rerun, browser, scanner and build evidence). **F** is the Dependabot batch.
4. Still open, design only: C4e N4 idle-PDO, the REP EXPLAIN diagnostics and UI companion (their packets stayed in Codex's
   workspace), the account-ledger CI hosted checks.

## Decisions for Sean (unchanged unless noted)

- **Exact PHP pin (new).** `CatalogSocketBinding::attest` accepts only PHP 8.4.27. CI (`setup-php` '8.4') and Forge install the
  latest 8.4 patch, so identity migrations refuse everywhere once 8.4.28 ships. Choose a policy (an allowlist or a minimum
  version). The code was deliberately not changed.
- **CI collation (new, unverified).** Hosted MySQL shards use `mysql:8.4` with its default `utf8mb4_0900_ai_ci`. The account guard
  checks `utf8mb4_unicode_ci`. The first Foundation run will show whether the shards refuse.
- The handoff's existing "Waiting on Sean" list (staging hostname, Stripe test keys, exports, prices and tax, legal inputs).

## Environment recipe for native runs in a cloud container (from this session)

- PHP **exactly 8.4.27**. The Ondřej PPA offers it: `apt-get update`, then upgrade all `php8.4-*` packages.
- A private Oracle MySQL 8.4.11 on its own datadir and port, started with `--skip-name-resolve`, plus a `root@'127.0.0.1'`
  account. Create the test database with `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`.
- If the kernel has no IPv6 (`/proc/self/net/tcp6` missing), cherry-pick `a2144d4a` onto the source under test until ACC-IPV6
  merges, and say so in the evidence.
- Some identity-heavy native files take more than 60 minutes. Run them alone, with a longer limit.
- A test that creates a MySQL VIEW must drop it: `migrate:fresh` does not drop views by default.
