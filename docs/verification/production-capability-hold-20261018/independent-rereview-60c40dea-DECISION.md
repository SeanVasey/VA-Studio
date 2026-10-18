# Independent re-review — O3 delta dccc5852..60c40dea

- **Reviewed commit:** `60c40deacad7e444c29192fb78efdf2c47e1ccd1` (parent `dccc585251873fa21051f73f3cba033ae30ffd6e`), worktree `/home/user/wt/o3`.
- **Scope:** `git diff dccc5852..60c40dea` only. That is 5 files, +32/−16: `CHANGELOG.md`, `docs/ops/production-activation-packet.md`, `ops/production/README.md`, `ops/production/validate-runtime.php` (docblock only) and `tests/ops/test_production_runtime_validator.py`.
- **Prior record:** `o3-review-dccc5852.md` (APPROVE; R1 P2, R2–R6 P3).
- **Decision: APPROVE.** R1–R5 are fixed correctly and completely, and the fixes add no false claim. R6 stays deferred, as agreed. No new P0, P1 or P2.

## Fix-by-fix

| Prior | Fix at 60c40dea | Verdict |
| --- | --- | --- |
| R1 (P2): rationale overstated V2 listening exposure | **Validator docblock.** It now says three flags have no environment gate. It says V2 listening is reached only through a customer session that the test customer access path creates (local, testing, staging), that the production lane does not read the flag, and that the flag is held as defence in depth. **Packet.** The intro paragraph and the V2 table row are rewritten the same way. **Production README item 6** and the **CHANGELOG** entry likewise. | Correct. It matches the earlier trace: `CustomerAccessPolicy` → `TestEnvironment::TEST_COMMERCE` = local, testing, staging; `CustomerSessionController:38,68` is the only writer of `_customer_access`; `ProductionListeningRollout` reads `production-customer-listening` (literal false). The "only guard" claim now covers just the three flags it is true for. |
| R2 (P3): false test comment; no behavioural test of the effective layer | **Comment.** The false sentence is removed. **New test.** `test_the_effective_configuration_refuses_what_the_file_check_admits` sets `PATH=false` and then `<flag>=${PATH}` for each of the four flags. It asserts exit 1 and that stderr is exactly `FAIL holds.<flag>`. | Correct, and it reaches only the effective layer. **Effective layer mutated** (`=== false` → `true`, loop removed, `!== true`): 5 failures, the new test for all four flags plus the regex test. **File loop removed instead:** the new test still passes, so the file check admits the input and only the configuration check refuses it. The exact-stderr assertion also shows that the preflight ran and passed. One caveat: `== false` survives the new test, because the PATH string is truthy. The retained regex test still kills it, so the coverage is adequate. |
| R3 (P3): lifting procedure incomplete | **Packet.** It adds the two-step lift. Deploy the validator change with the flag still false, then set the flag and let the deploy refresh the environment. It also notes that the test's `PRE_LAUNCH_HOLDS` map changes with the validator. | Correct. A non-first deploy calls `runtime_gate "$previous_release"` before it builds. The refresh path calls `runtime_gate "$REL"`, which validates with the served (new) release's validator, then `ctl refresh` → `cmd_configure`. |
| R4 (P3): "literal false" imprecise | **Packet and CHANGELOG** now say "`false` or `(false)`, in any letter case and quoted or not". The packet also says a key with no `=` is refused although the runtime would read it as unset. | Correct. The tests cover the accepted forms and the no-`=` refusal. The earlier mutation M1 showed the no-`=` form passing the effective layer, which confirms the runtime reads it as unset. |
| R5 (P3): CHANGELOG under Added | The entry moved to the first bullet of the existing `### Security` section in `[Unreleased]` (line 185). | Correct. There is one `### Security` heading in Unreleased and no duplicate. "Added" still opens with a blank line and then the M-07 bullet. The move removes exactly one bullet and one blank line, and adds one bullet and one blank line. `git diff --check` is clean. |
| R6 (P3) | Deferred | Agreed; it can stay deferred. |

## Findings

| ID | Sev | File:line | Finding | Suggested fix |
| --- | --- | --- | --- | --- |
| N1 | P3 (nit, optional) | `docs/ops/production-activation-packet.md:41`; `ops/production/README.md:147` | Two rewrapped prose lines run past the surrounding wrap width; packet line 41 is 214 characters. This is cosmetic only, and the packet's tables already exceed it. | Rewrap when next touched. |

No other findings. Every new claim checks out against the code.

## Commands and results (in /home/user/wt/o3)

| Command | Result |
| --- | --- |
| `git rev-parse HEAD` (start and end) | `60c40deacad7e444c29192fb78efdf2c47e1ccd1`, unchanged |
| `git status --short` (end) | clean |
| `git diff --check dccc5852..60c40dea` | clean, exit 0 |
| `php -l ops/production/validate-runtime.php` | No syntax errors |
| `python3 -I ops/production/build-kit.py --check` | `build-kit: ok, 13 files` |
| `python3 -m unittest tests.ops.test_production_runtime_validator` | Ran 27, OK |
| `python3 -m unittest discover -s tests/ops -p 'test_*.py'` | Ran 322, OK |
| `grep -n '^## \|^### ' CHANGELOG.md` | One `[Unreleased]` with Added (11), Changed (61), Fixed (112) and Security (185); no duplicate heading |

**Mutations** (temporary edits to `validate-runtime.php`, reverted; tree verified clean afterwards):

| Mutation | Result |
| --- | --- |
| E1: `config($key) === false` → `true` | 5 failures: the regex test plus the new test × 4 flags |
| E2: effective loop removed | 5 failures (same tests) |
| E3: `!== true` | 5 failures (same tests) |
| E4: `== false` | 1 failure (the regex test) |
| F1: file-level loop removed | 2 failures (`test_a_value_only_flag_with_no_equals_sign_is_refused`, `test_the_hold_is_checked_before_the_preflight_child_runs`). The new test passes, which shows it exercises only the effective layer. |

## Untested conditions

These are unchanged from the prior record. Nothing ran on a real host; the rollback path does not re-run the current validator; and there is a composer-hook window before the first validator run on a first install.
