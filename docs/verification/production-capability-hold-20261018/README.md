# Production pre-launch capability hold: evidence packet (lane O3, 2026-10-18)

Branch `harness/production-capability-hold-20261018`, based on `origin/main` `9225df8c` and brought up to `6c28d3a3` (PR #152)
by a merge before review. Sean approved the hold on 2026-10-18. Nothing ran on a real host; no application code, route,
config, census file or workflow changed.

## What changed

`ops/production/validate-runtime.php` refuses four flags unless they are false (`PRE_LAUNCH_HOLDS`):

| Flag | Config key | Identifier on refusal |
| --- | --- | --- |
| `VASEY_OPERATIVE_FREE_GRANTS_ENABLED` | `free-grants.operative_enabled` | `holds.vasey_operative_free_grants_enabled` |
| `CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED` | `inquiries.operator_notifications_enabled` | `holds.contact_inquiry_operator_notifications_enabled` |
| `DISCOVERY_SITEMAP_ENABLED` | `discovery-sitemap.enabled` | `holds.discovery_sitemap_enabled` |
| `VASEY_LISTENING_V2_PROMOTION_ENABLED` | `customer-listening.v2_promotion_enabled` | `holds.vasey_listening_v2_promotion_enabled` |

- File level, before the preflight child runs: a flag present in the candidate file passes only as `false` or `(false)` (any
  letter case, quoted or not); a blank, any other value, or a key with no `=` is refused. Unset is allowed.
- Effective configuration, in the isolated real bootstrap: `config(<key>) === false`, so a renamed or removed key fails closed.
- The first three have no environment gate in the application, so on production the validator is their only guard. V2
  listening promotion is reached only through a test-only customer session; it is held as defence in depth because promotion
  is one-way.
- Lifting one is a reviewed change to the validator (and the test map) with Sean's authorization for that activation, in two
  deploy steps (`docs/ops/production-activation-packet.md`, section 1).
- Docs: the activation packet section, the production README item 6, comments on the four template lines, and a CHANGELOG
  Security entry.

## Commits

| Commit | What |
| --- | --- |
| `ff707cad` feat(ops): hold four non-environment-gated capabilities in the production validator (O3) | validator, tests, template comments, packet section, README, CHANGELOG |
| `c7b09ed4` merge `origin/main` (PR #152) | no conflict |
| `dccc5852` docs: record the PR #152 merge and the O3 lane state | ledger |
| `60c40dea` fix(ops): correct the O3 hold rationale and pin the effective-configuration layer | review R1 to R5 |
| this packet's commit | docs only |

## Verification

| Check | Result | Where |
| --- | --- | --- |
| `python3 -m unittest tests.ops.test_production_runtime_validator` | 27 OK (19 before this lane) | `60c40dea`, lead and re-review |
| `python3 -m unittest discover -s tests/ops -p 'test_*.py'` | 322 OK | `60c40dea`, lead and re-review |
| `ProductionEnvironmentTemplateTest`, `StagingRuntimeValidatorTest` (SQLite) | 86 tests, 2,119 assertions OK | `dccc5852`, review |
| `python3 -I ops/production/build-kit.py --check` | ok, 13 files (the validator is copied, so the delta pin is unchanged) | `60c40dea` |
| `php -l ops/production/validate-runtime.php`, `git diff --check` | clean | `60c40dea` |
| Mutations | 12 at `dccc5852`, all killed. At `60c40dea`, removing or loosening the effective-configuration check (`true`, loop removed, `!== true`) fails the new interpolation test for all four flags plus the shape test. A `== false` mutation is killed only by the shape test | review records |

## Independent review

- [`independent-review-dccc5852-DECISION.md`](independent-review-dccc5852-DECISION.md): **APPROVE**, R1 (P2) and R2 to R6 (P3).
- [`independent-rereview-60c40dea-DECISION.md`](independent-rereview-60c40dea-DECISION.md): **APPROVE**; R1 to R5 fixed, R6
  deferred, one optional nit N1.

## Deferred

- R6 (P3): the four keys are a copy pinned to `CapabilityBoundary` by a test, not loaded from it; that file's docblock still says
  "two validators", and nothing pins the consumer side.
- N1 (P3): two rewrapped prose lines exceed the wrap width (activation packet line 41, production README line 147).

## Untested conditions

No real production host or Forge deploy; `--runtime` host checks were not exercised with the new holds; the full PHPUnit and
browser suites were not run (no application code changed). Rollback switches to an earlier release without re-running the
current validator, which is safe today only because no production release exists.
