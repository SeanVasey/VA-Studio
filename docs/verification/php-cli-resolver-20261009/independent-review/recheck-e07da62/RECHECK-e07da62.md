# M-16 D-C1 re-check: `11d90702..e07da62`

- **Reviewer:** independent. I did not write the code under review.
- **Date:** 2026-10-09. **Host:** PHP 8.4.26 CLI and php-fpm 8.4.26.
- **Subject:** single commit `e07da62f91bcd46685a7ff997dafabf22147ef3c`, parent `11d907026f42…`: "fix(contracts): give the
  generic renderer child its validated loader path".
- **Basis:** `DELTA-DECISION.md` (this directory), condition D-C1 and finding L-D1.
- **Scope:** development-merge review only. This is not Foundation CI, final acceptance or launch evidence.

## Verdict

**APPROVE.** The approval extends to **`e07da62`**. L-D1 is closed. The only product change is the 4-line
`IsolatedContractRenderer` edit. The pins and all other product code are unchanged. D-C2 (the PR body) remains
outside what I can see. The README now says it is met; I have not verified that.

## Findings

### Product scope

Outside `docs/`, the commit changes only two files (`recheck/01`):

- `app/Domain/Contracts/IsolatedContractRenderer.php`: one `use` line, plus
  `$libraries = PhpCliProcess::libraries($binary)` and `LD_LIBRARY_PATH` set only when the list is not empty.
- `tests/Feature/TestIsolatedContractRendererTest.php`: one new test.

These diffs are all empty (rc 0):

- `git diff 11d90702 e07da62 -- resources/contracts app/Domain/Grants app/Support scripts config ops routes bootstrap database .env.example app/Providers`
- `git diff origin/main(e5e500c) e07da62 -- resources/contracts`

The change is the same edit I saw uncommitted in the worktree during the delta review.

### L-D1 closed

| Check | `11d90702` | `e07da62` | Evidence |
| --- | --- | --- | --- |
| Generic child environment, rootless stand-in, simulated `fpm-fcgi` resolver | `LANG LC_ALL TMPDIR TZ`, no `LD_LIBRARY_PATH`; `render_failed` | `LANG LC_ALL TMPDIR TZ` + `LD_LIBRARY_PATH=<canonical sibling …-real>`; renders `790fd796…` | prior `evidence/44`, `recheck/31` |
| Genuine FPM, rootless pool b, generic | `render_failed` | `790fd796…` | prior `evidence/43`, `recheck/41` |
| New unit test with this commit's test file | fails (1 failure, rc 1) | passes | `recheck/20`, `recheck/10` |

The child's library path is computed by the same `PhpCliProcess::libraries()` that the probe and the pinned factory
use. The probe and all four renderer children now agree.

### No byte or behaviour drift

- **CLI generic render** (no binding): `790fd79695a52b85…` on both the `11d90702` and the `e07da62` exports
  (`recheck/30`). In the CLI the generic child now also gets `/usr/lib/x86_64-linux-gnu`, the same as the pinned
  renderers in the CLI. The bytes are unchanged.
- **Genuine FPM at `e07da62`** (`recheck/40`, `41`):

| Pool | Result |
| --- | --- |
| **a** (`/usr/bin/php8.4`) | free `c4e812cf…`, paid `20841190…`, composed production-free `024b4fc9…` (= the stored CLI hashes), generic `790fd796…`; shipped production-free `render_failed` (unbound by design) |
| **b** (rootless stand-in) | all four render the same hashes |
| **c** (unset) | `unconfigured`; every family `render_failed` |

### Documentation

- The README rows for this fix are accurate as far as I checked. I did not run the README's "12 files, 150 tests"
  selection, only the 3 requested files.
- The README now says the skip list is not recorded, which addresses I-D3's evidence point.
- The copy of my review record in `independent-review/delta-11d90702/` is byte-identical to my originals: the
  `DELTA-DECISION.md`, `evidence/` and `probes/` directories, excluding `generic-cli-hash.php`, which I wrote after the
  copy. It correctly excludes my `src/` export (`recheck/01`).

### Still open (unchanged, non-blocking)

- I-D1: a `:` in the install path splits `LD_LIBRARY_PATH`. This now also applies to the generic child, the same as
  the pinned rule.
- I-D2: the cache key omits the lib directory's identity.
- Prior I-1: the "long-lived FPM worker" wording.
- D-C2: the PR body, not verified by me.

## Runs

All runs used a clean `git archive e07da62f91bcd46685a7ff997dafabf22147ef3c` export at `scratchpad/m16-delta/src-e07`:
hardlinked `vendor`, `.env`, `bootstrap/cache` and storage directories set to 755 to match a checkout. I did not modify
any tracked file in `/home/user/wt-m16`, which is clean at `e07da62`. I did not create `public/build`.

The test command was
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress <file>`,
one process per file.

| # | Source | Selection / command | Result | rc |
| --- | --- | --- | --- | --- |
| 10 | e07da62 | `tests/Feature/TestIsolatedContractRendererTest.php` | OK, 23 tests, 93 assertions | 0 |
| 10 | e07da62 | `tests/Feature/IsolatedContractRendererAcceptanceTest.php` | OK, 1 test, 25 assertions | 0 |
| 10 | e07da62 | `tests/Feature/TestContractIssuanceTest.php` | OK, 52 tests, 498 assertions | 0 |
| 20 | 11d90702 export + e07da62's test file only | `TestIsolatedContractRendererTest.php` | 23 tests, 1 failure (the new test) | 1 |
| 30 | 11d90702 / e07da62 | `probes/generic-cli-hash.php` (CLI) | `790fd796…` / `790fd796…` | 0 / 0 |
| 31 | e07da62 | `probes/generic-env.php` (rootless stand-in) | child gets the sibling `LD_LIBRARY_PATH`; OK `790fd796…` | 0 |
| 40 / 41 | e07da62 | genuine php-fpm8.4 pools a, b, c (the reviewer's committed harness and payloads) | see the pool table above | 0 each pool |
| 01 | e07da62 | scope, pins and record-copy diffs | see the product scope and documentation sections | 0 |

Raw output is in `scratchpad/m16-delta/recheck/`; probes are in `scratchpad/m16-delta/probes/`.

**Not run:** the README's 12-file / 150-test selection, the full suite, MySQL, browser specs and Foundation.
