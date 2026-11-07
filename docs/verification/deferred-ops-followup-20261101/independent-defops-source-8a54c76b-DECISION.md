# Independent DEF-OPS source review — 2026-11-01

Reviewer: separate non-author Sol 6.1 agent.
Candidate: `8a54c76b05a734a1d41af490ec86abd92ce67023`.
Executable-source commit: `f6783d2a4903d88e14f66d7742439370ba3b91b2`.
Base: `2ab0c41e946e30cc402afc8ec107a3670e399abd`.
Worktree: `/workspace/VA-Studio-defops-followup`; candidate HEAD and clean status observed.

**Decision: SOURCE-ONLY CONDITIONAL ACCEPTANCE. No P0, P1 or P2 identified. This is not final approval of an actually tested authorization change.** All three deferred findings remain pending execution receipts and final review.

## Scope and evidence

Read `/workspace/VA-Studio/AGENTS.md`, candidate packet and original deferred ledger's faceb5f8 P3-1/P3-2/P3-3. Reviewed exact base-to-candidate six-file diff, complete relay and evidence command, affected tests, backup shipping/record functions and disposable fixture infrastructure. Read Laravel pluck implementation and SQLite UNION wrapping references to assess query construction. Review executed no tests, application/DB bootstrap, operations fixtures, native database, build/browser process, mutation, CI or live action. No repository source edits or PCC runtime/run changes.

## Source conclusions

### P3-1: staff recipient exclusion

Each UNION ALL arm retains the former `LOWER(email) = ?` database predicate and `is_admin = true` filter. A bound projection returns the exact requested recipient, eliminating the PHP ASCII case-folded stored-address key that lost accent/space collation matches. MySQL's column/expression collation has precedence over the bound comparison string; the separate projection does not change that comparison. Actual Oracle MySQL semantics must still be established by the queued test.

All recipient values are bound twice, never interpolated. Laravel retains the explicit select expression during pluck, so it returns `relay_recipient`, not stored email. UNION bindings preserve each arm's projected value and comparison. Duplicate matching rows or repeated captures set the same associative key and do not shift an index-to-recipient mapping; challenge IDs still map independently. Captures cannot supply numeric-only array keys because normalized email includes its domain. Listing admission caps examined captures at 200 and print supplies one, so the reachable batch is at most 200 UNION arms / 600 parameters, below ordinary SQLite 500-arm and 999-variable limits. A listing still issues one staff query; no per-capture query loop was introduced. SQL exceptions cannot produce an admitted staff capture through a fallback.

Reserved-address admission, exact normalized recipient matching, challenge/proof/private-file verification, fresh operator/MFA authority, audit-before-print, production refusal and console escaping remain unchanged. New fixture tests genuine domain capture creation, confirms the database itself matches each staff fixture, asserts list and print withhold private information, and includes a non-admin positive. MySQL explicitly pins accent-insensitive PAD SPACE collation and exercises accent and trailing-space staff variants; SQLite only tests case behavior. Native execution is essential, not implied by this source assessment.

### P3-2: SQL statement guard

Strict rejection of `/*`, `--` and `#` closes comment splitting and MySQL executable comments before the existing locking/INTO checks. It also rejects optimizer comments and markers inside quoted literals or quoted identifiers. That is conservative refusal, not a parser bypass. Current snapshot callers use generated Eloquent/aggregate SQL and bound values, with no necessary comment markers or SQL literal data interpolation. No acceptance broadening is present.

Existing plain SELECT prefix/locking/INTO checks, MySQL READ ONLY and REPEATABLE READ framing, active switch/finally cleanup, single WeakMap callback per connection, output minimization and escapes remain intact. Both pure predicate assertions and actual command callback regression cover split FOR SHARE/UPDATE and executable comments. Existing framing, valid-query, callback lifetime and post-command-write tests are retained. The reported 13-assertion pure pass and old boolean reproduction are author evidence only; this reviewer ran neither. The initial old PHPUnit formatting failure is honestly distinguished in the packet.

### P3-3: backup guard tests

Production backup code is unchanged. New equal-malformed-size tests supply identical malformed local-record and remote-list values, so equality alone would wrongly admit them; nonzero refusal, absence of 'already shipped' and unchanged remote digests are meaningful assertions. Failed/empty/malformed ciphertext stat cases override the existing test stat shim only for ciphertext-size lookup, execute the real sliced shipping function through verified transfer, and assert warning, no shipped/temporary record, remote ciphertext present and retry cleanup. Success exit with a warning matches the existing verified-transfer behavior when only its local shipped record cannot be persisted.

The existing harness uses isolated temporary directories, local rclone remote and disposable generated encryption fixtures, with no database/network or real host configuration. Runtime tool prerequisites/skips and mutation outcomes must be recorded, not inferred from source.

## Conditions before final approval

1. Respect PCC's exclusive slot and frozen `db5e4b93` sweep; no duplicate/restart or runtime interference.
2. After release, execute both complete focused PHP command files on the documented SQLite CI environment. Confirm real list/print/privacy tests, current valid evidence SQL, refusal paths and guard lifetime. Preserve exact tested SHA, commands, counts and failures.
3. Execute both focused files separately on an isolated Oracle MySQL **8.4.11** instance, confirming pinned accent/PAD SPACE fixtures and READ ONLY framing. Replacing DB matching with prior PHP re-keying must fail the native accent/space regression. SQLite proves neither MySQL collation nor concurrency.
4. Run focused off-host backup tests with required tools available; explicitly report skipped tests. Execute documented digit/stat guard mutations and comment-rejection mutation, preserve actual failures, restore each mutation, and explain any equivalent surviving single-operand digit mutation.
5. An independent authorization reviewer must assess actual execution receipts and exact tested SHA, including any repairs. Static lint/Pint, pure guard assertions and this source decision do not constitute final merge approval. Recalculate native discovery/counts at integration; if a native-only method is later added, append its census entry without altering frozen entries. No shared-file changes are requested in this review.

No unrelated refactor, new commercial policy, live action, or scope expansion is required by this decision.
