# DEF-OPS deferred operations and CI findings batch, 2026-10-24

Lane DEF-OPS (branch `harness/deferred-ops-20261024`, base `707649a6`). Scope: the deferred findings that
`docs/handoff/2026-10-24/COMPLETION-PLAN.md` groups as DEF-OPS, from `docs/handoff/2026-10-07/codex-deferred-20261011.md`.
Ops scripts, CI scripts, command code and documentation only. No schema migration, no live host, DNS, credential or payment,
and no production hold is lifted. Files owned by in-flight lanes (C4e routes and membership, A5 refund and operations, MIG
migration domain) are not touched.

## Work package (written before the code; the results section below is filled in at the end)

Each finding is read in full in the ledger and in its review record before it is changed. A finding that is invalid, already
fixed, or not worth its code is recorded as such with the reason instead of being built.

| Finding | Planned disposition |
| --- | --- |
| O1 P3s (ledger entry of 2026-10-18; closed by O2 except O2 P3-1) | Verify the O2 closures on main; fix O2 P3-1 (shipped record without a size) in `ops/staging/backup.sh` with a red/green test |
| O3 R6, N1 | R6: production hold reads its config keys from `CapabilityBoundary`, docblock corrected, consumer pinned by a test. N1: rewrap the two long lines |
| O4 P3-1, P3-3, P3-4, P3-5 | P3-1: test the configured `connection` and `lock_connection`. P3-3: detect non-default cache stores that reopen the backlog, or document. P3-4: NTP note. P3-5: refresh the stale scheduler descriptions (mind `build-kit.py --check`) |
| K3 P3-1, P3-2 | Interactive-console wording or `stream_isatty` across the three commands; refusal tests for policy version, challenge completed between `message()` and `relay()`, and the `lstat`/inode checks |
| PR #149 P2 r4305529286 | Bound `vasey:test-identity-links list` to the newest N captures by mtime, report how many older were not examined, batch lookups |
| K4 P3-1, P3-2, P3-3 | Read-only transaction and `REPEATABLE READ`, explicit `CheckoutIntent` columns, `OUTPUT_RAW` and `OutputFormatter::escape()` in `vasey:test-commerce-evidence` |
| C1 P2 r4305244895, r4305244899, P3-A to P3-D | Resume refusals when staff edited an interrupted draft (rights evidence, media hash), generated-media brand check, wording, docblock |
| #141 Dependabot P2 r4305283401 | Exclude the two testing-library packages from `npm-minor-patch` |
| M2 R3 (R4 note) | Widen the Composer exclusion to `tecnickcom/tc-lib-*` |
| `scripts/ci/test-related-browser-stage.py` | Find and fix the root cause of the failures on main without skipping or weakening the safeguard |
